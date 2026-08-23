// @ts-check
/**
 * Deterministic rendering, and the three cards that are not about a goal.
 *
 * The offline mode is the foundation of post-production: a game recorded
 * without a switcher gets its overlay added afterwards by rendering this URL
 * once per second of video. So the property that matters is not "it looks
 * right" but "the same URL always produces the same frame" -- a renderer that
 * drifted by a second would smear a whole video.
 *
 * The clock assertions are exact on purpose. The first version of this mode read
 * 9:57 for at=600, because render() recomputed a server skew from the payload's
 * generated_timestamp and the cache happened to be three seconds stale. Nothing
 * about that was visible without measuring it.
 */
const { test } = require('@playwright/test');
const { GAME_ID, loginAsAdmin, writeShow, withShowRestored, expect } = require('./helpers');

/** Wait for the single frame, which flags itself when drawn. */
async function frame(page, query) {
  await page.goto(`/s/${GAME_ID}?${query}`);
  await page.waitForFunction(() => document.documentElement.dataset.rendered === '1',
    null, { timeout: 15000 });
  return page.evaluate(() => {
    const text = (s) => {
      const el = document.querySelector(s);
      return el ? el.textContent.trim() : null;
    };
    const clock = document.querySelector('#clock');
    return {
      home: text('#homeScore'),
      away: text('#awayScore'),
      clock: clock && !clock.className.includes('hide') ? clock.textContent.trim() : null,
      centre: text('#segment'),
    };
  });
}

test.describe('deterministic rendering', () => {
  test('the clock reads exactly what it was told', async ({ page }) => {
    for (const [at, expected] of [[0, '0:00'], [60, '1:00'], [600, '10:00'], [1260, '21:00']]) {
      const f = await frame(page, `at=${at}&goals=1`);
      expect(f.clock, `at=${at}`).toBe(expected);
    }
  });

  test('the score follows the goal count it was given', async ({ page }) => {
    const none = await frame(page, 'at=0&goals=0&phase=pre');
    expect(`${none.home}-${none.away}`).toBe('0-0');

    const some = await frame(page, 'at=600&goals=5');
    const all = await frame(page, 'at=2400&goals=99');
    // Truncation is by count, never by the clock: plenty of tournaments record
    // no goal times at all, so the caller owns that decision entirely.
    expect(Number(some.home) + Number(some.away)).toBe(5);
    expect(Number(all.home) + Number(all.away)).toBeGreaterThan(Number(some.home) + Number(some.away));
  });

  test('each phase renders its own centre', async ({ page }) => {
    const pre = await frame(page, 'at=0&goals=0&phase=pre');
    const live = await frame(page, 'at=600&goals=5&phase=live');
    const done = await frame(page, 'at=2400&goals=99&phase=final');

    expect(pre.clock, 'a game that has not started has no clock').toBeNull();
    expect(live.clock).toBe('10:00');
    expect(done.clock, 'a finished game has no clock').toBeNull();
    expect(done.centre).toBe('Final');
  });

  test('the same URL renders the same frame twice', async ({ page }) => {
    const a = await frame(page, 'at=900&goals=7&phase=live');
    await page.waitForTimeout(2500);
    const b = await frame(page, 'at=900&goals=7&phase=live');
    // If anything were still deriving time from the wall clock, these would
    // differ by the delay above.
    expect(b).toEqual(a);
  });

  test('a cap appears only once the goal that called it has been reached', async ({ page }) => {
    const before = await frame(page, 'at=300&goals=3');
    const after = await frame(page, 'at=1400&goals=9');
    expect(before.centre).not.toMatch(/cap/i);
    expect(after.centre).toMatch(/cap/i);
  });
});

test.describe('summary cards', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, test);
    await page.goto('/s/');
    // The card list is built from fetches, so it is not there on load.
    await page.waitForSelector('.cardrow', { timeout: 15000 });
  });

  for (const [card, title] of [['pregame', 'Coming up'], ['halftime', 'Half time'], ['postgame', 'Full time']]) {
    test(`${card} renders its own framing`, async ({ page, context }) => {
      await withShowRestored(page, async (before) => {
        await writeShow(page, [{ id: card, slot: 'center', visible: true, params: {} }]);
        const stage = await context.newPage();
        await stage.goto(`/s/${before.game || GAME_ID}/overlay`);
        await stage.waitForSelector('.summarycard', { timeout: 15000 });

        const shown = await stage.evaluate(() => {
          const c = document.querySelector('.summarycard');
          const r = c.getBoundingClientRect();
          const name = c.querySelector('.summaryname');
          return {
            title: c.querySelector('.statcard-head').textContent.trim(),
            sides: c.querySelectorAll('.summaryside').length,
            hasScore: !!c.querySelector('.summaryscore'),
            nameRight: name.getBoundingClientRect().right,
            midLeft: c.querySelector('.summarymid').getBoundingClientRect().left,
            clipped: name.scrollWidth > name.clientWidth + 1,
            width: r.width,
          };
        });

        expect(shown.title).toBe(title);
        expect(shown.sides).toBe(2);
        // Pre-game has no score to show; the other two do.
        expect(shown.hasScore).toBe(card !== 'pregame');
        // A long team name must not run into the score. `1fr` would let it,
        // because a plain fr track will not shrink below nowrap content.
        expect(shown.midLeft).toBeGreaterThan(shown.nameRight);
        expect(shown.clipped, 'the name should ellipsise rather than overflow').toBe(false);
        await stage.close();
      });
    });
  }

  test('the control page groups coordinated cards above takeovers', async ({ page }) => {
    // The order of the store's card list IS the order of this page, and the
    // grouping is the point: the bug and the strips that ride with it need
    // positioning relative to each other, and the rest do not.
    const names = await page.evaluate(() =>
      [...document.querySelectorAll('.cardrow .cardname')].map((n) => n.textContent));
    const coordinated = names.slice(0, 4);
    expect(coordinated[0]).toBe('Scoreboard');
    coordinated.slice(1).forEach((n) => expect(n).toMatch(/^Last goal/));
    expect(names.slice(4)).toEqual(
      ['Score progression', 'Top scorers', 'Pre-game', 'Half time', 'Full time']);
  });

  test('each summary card offers only its own moment', async ({ page }) => {
    const options = await page.evaluate(() => {
      const out = {};
      document.querySelectorAll('.cardrow').forEach((row) => {
        const sel = row.querySelector('.autosel');
        if (sel) {
          out[row.querySelector('.cardname').textContent] =
            [...sel.options].map((o) => o.value);
        }
      });
      return out;
    });
    // Offering the others would only be a way to put a half-time card on air at
    // full time.
    expect(options['Pre-game']).toEqual(['off', 'pregame']);
    expect(options['Half time']).toEqual(['off', 'halftime']);
    expect(options['Full time']).toEqual(['off', 'final']);
    expect(options['Last goal — scorer']).toEqual(['off', 'goal']);
  });
});
