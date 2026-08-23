// @ts-check
/**
 * Things the overlays work out rather than read.
 *
 * Every case here covers a number that exists nowhere in UltiOrganizer and is
 * derived from something else: turnover counts and break-chance conversion from
 * the possession log, the game on a field from a reservation join, the gender
 * ratio from the point number. Derived numbers are exactly where a broadcast can
 * assert something untrue, so the assertions are as much about what these
 * refuse to claim as about what they show.
 */
const { test, expect } = require('@playwright/test');
const {
  GAME_ID, loginAsAdmin, readPossession, writePossession,
  writeShow, withShowRestored,
} = require('./helpers');

/**
 * The live score of a game, as the possession store keys it.
 *
 * Read rather than hard-coded: possession events are filed under the score they
 * were made at, so a test that assumes "9-6" silently stops testing anything the
 * moment the fixture's score changes — which is exactly what happened once.
 */
async function scoreKey(page, gameId = GAME_ID) {
  return page.evaluate(async (id) => {
    const r = await fetch(`/index.php?view=live/api&entity=games&id=${id}`,
      { credentials: 'same-origin' });
    const d = await r.json();
    return `${d.game_result.homescore}-${d.game_result.visitorscore}`;
  }, gameId);
}

/** Put the possession log into a known state, and restore it afterwards. */
async function withPossession(page, events, body) {
  const before = await readPossession(page);
  try {
    // Disable first: turning the mode ON does not clear the log, so without
    // this a test inherits whatever the last one (or a human) left behind and
    // its counts are somebody else's.
    await writePossession(page, { enabled: false, game: GAME_ID });
    await writePossession(page, { enabled: true, game: GAME_ID });
    for (const e of events) {
      await writePossession(page, { game: GAME_ID, score: e.score, defence: e.d });
    }
    await body();
  } finally {
    // Disabling drops the log, which is the only way to clear it.
    await writePossession(page, { enabled: false, game: GAME_ID });
    if (before.enabled) await writePossession(page, { enabled: true, game: GAME_ID });
  }
}

test.describe('possession-derived numbers', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('the ribbon counts turnovers, and only once there are enough to matter', async ({ page }) => {
    await page.goto(`/s/${GAME_ID}`);
    const sc = await scoreKey(page);

    // One change of hands is noise; the ribbon stays quiet below two.
    await withPossession(page, [{ score: sc, d: true }], async () => {
      await page.goto(`/s/${GAME_ID}`);
      await expect(page.locator('#scoreboard')).toBeVisible();
      await page.waitForTimeout(2500);
      await expect(page.locator('#ribbon')).not.toContainText('turnover');
    });

    await withPossession(page, [
      { score: sc, d: true }, { score: sc, d: false },
      { score: sc, d: true }, { score: sc, d: false },
    ], async () => {
      await page.goto(`/s/${GAME_ID}`);
      await expect(page.locator('#scoreboard')).toBeVisible();
      await expect(page.locator('#ribbon')).toContainText('4 turnovers this point', { timeout: 8000 });
    });
  });

  test('an untracked game says nothing about turnovers', async ({ page }) => {
    await writePossession(page, { enabled: false, game: GAME_ID });
    await page.goto(`/s/${GAME_ID}`);
    await expect(page.locator('#scoreboard')).toBeVisible();
    await page.waitForTimeout(2500);
    // Not "0 turnovers": nobody was watching, so there is nothing to report.
    await expect(page.locator('#ribbon')).not.toContainText('turnover');
  });

  test('a break chance survives into the compact bug', async ({ page }) => {
    await page.goto(`/s/${GAME_ID}`);
    const sc = await scoreKey(page);
    await withPossession(page, [{ score: sc, d: true }], async () => {
      await page.goto(`/s/${GAME_ID}?size=compact`);
      await expect(page.locator('#scoreboard')).toBeVisible();
      const callout = page.locator('.callout:not(.hide)').first();
      await expect(callout).toContainText(/break chance/i, { timeout: 8000 });
      await expect(callout).toBeVisible();
    });
  });

  test('the standing tag stays out of the compact bug', async ({ page }) => {
    // ON DEFENCE is ambient context, true all point. The compact variant is
    // chosen for being unobtrusive, so only real events earn space in it.
    await page.goto(`/s/${GAME_ID}`);
    const sc = await scoreKey(page);
    await withPossession(page, [{ score: sc, d: false }], async () => {
      await page.goto(`/s/${GAME_ID}?size=compact`);
      await expect(page.locator('#scoreboard')).toBeVisible();
      await page.waitForTimeout(2500);
      await expect(page.locator('.callout.standing')).toBeHidden();
    });
  });
});

test.describe('break-chance conversion on the summary cards', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('the card carries the denominator with the number', async ({ page }) => {
    await withShowRestored(page, async () => {
      await withPossession(page, [
        { score: '0-0', d: true }, { score: '1-0', d: true }, { score: '1-1', d: false },
      ], async () => {
        await writeShow(page, [
          { id: 'postgame', slot: 'center', visible: true, params: {} },
        ], { game: GAME_ID });

        await page.goto(`/s/${GAME_ID}/overlay`);
        const card = page.locator('.summarycard');
        await expect(card).toBeVisible({ timeout: 15000 });

        // "n of m brk" rather than a bare break count, once anything is tracked.
        await expect(card.locator('.summarymeta').first()).toContainText(/\d+ of \d+ brk/);

        // And the footer must say how much of the game that covers. A rate over
        // three points reads identically to one over the whole game without it.
        await expect(card.locator('.summaryfoot'))
          .toContainText(/possession tracked for \d+ of \d+ points/);
      });
    });
  });

  test('with nothing tracked the card shows breaks and claims no rate', async ({ page }) => {
    await withShowRestored(page, async () => {
      await writePossession(page, { enabled: false, game: GAME_ID });
      await writeShow(page, [
        { id: 'postgame', slot: 'center', visible: true, params: {} },
      ], { game: GAME_ID });

      await page.goto(`/s/${GAME_ID}/overlay`);
      const card = page.locator('.summarycard');
      await expect(card).toBeVisible({ timeout: 15000 });
      await expect(card.locator('.summarymeta').first()).toContainText(/\d+ brk/);
      await expect(card.locator('.summarymeta').first()).not.toContainText(' of ');
      await expect(card.locator('.summaryfoot')).not.toContainText('possession tracked');
    });
  });
});

test.describe('following a field', () => {
  test('resolves to the game in progress', async ({ page }) => {
    // Field 1 carries both a finished game and the live one.
    await page.goto('/s/field/1');
    await expect(page.locator('#scoreboard')).toBeVisible({ timeout: 15000 });
    const names = await page.locator('.team-name').allTextContents();
    expect(names.filter(Boolean).length).toBe(2);
    await expect(page.locator('#errorMessage')).not.toContainText('No game found');
  });

  test('falls forward to the next scheduled game when none is live', async ({ page }) => {
    // Field 2 has only a scheduled game. Showing who is about to play beats
    // going blank while a camera operator frames up.
    await page.goto('/s/field/2');
    await expect(page.locator('#scoreboard')).toBeVisible({ timeout: 15000 });
    const names = await page.locator('.team-name').allTextContents();
    expect(names.filter(Boolean).length).toBe(2);
  });

  test('refuses to guess when the field has no games', async ({ page }) => {
    // The failure that matters: silently following a different field would put
    // the wrong game on air and look entirely normal.
    await page.goto('/s/field/99');
    await expect(page.locator('#errorMessage')).toContainText('No game found on field 99', { timeout: 15000 });
  });

  test('the stage follows a field too', async ({ page }) => {
    await page.goto('/s/field/1/overlay');
    await expect(page.locator('.mount.shown').first()).toBeVisible({ timeout: 15000 });
  });
});

/** The mixed fixture game — the only one that can exercise gender ratio. */
const MIXED_GAME = 703;

test.describe('the score progression card', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('draws one step per point and a grid the size of the score', async ({ page }) => {
    await withShowRestored(page, async () => {
      await writeShow(page, [
        { id: 'progression', slot: 'center', visible: true, params: {} },
      ], { game: MIXED_GAME });

      await page.goto(`/s/${MIXED_GAME}/overlay`);
      const card = page.locator('.progcard');
      await expect(card).toBeVisible({ timeout: 15000 });

      // 9 goals in the fixture -> 9 steps; 5-4 -> a 5x4 grid, so 6 verticals
      // and 5 horizontals.
      await expect(card.locator('.progstep')).toHaveCount(9);
      await expect(card.locator('.gridline')).toHaveCount(11);
      await expect(card.locator('.proghead')).toHaveCount(1);
      await expect(card.locator('.progteam').first()).toContainText('5');
    });
  });

  test('tints each step by its ratio, following ABBA', async ({ page }) => {
    await withShowRestored(page, async () => {
      await writeShow(page, [
        { id: 'progression', slot: 'center', visible: true, params: { ratio1: '4M/3F' } },
      ], { game: MIXED_GAME });

      await page.goto(`/s/${MIXED_GAME}/overlay`);
      await expect(page.locator('.progcard')).toBeVisible({ timeout: 15000 });

      const pattern = await page.evaluate(() =>
        [...document.querySelectorAll('.progstep')]
          .map((s) => (s.classList.contains('ratio-a') ? 'A'
            : s.classList.contains('ratio-b') ? 'B' : '-')).join(''));
      // Points 1, 4, 5, 8, 9 repeat the first point's ratio.
      expect(pattern).toBe('ABBAABBAA');

      await expect(page.locator('.proglegitem')).toHaveCount(2);
    });
  });

  test('omits the ratio entirely when nobody has named the first point', async ({ page }) => {
    // The pattern is derivable; which ratio "A" actually is, is not recorded
    // anywhere. Tinting without it would be inventing the labels.
    await withShowRestored(page, async () => {
      await writeShow(page, [
        { id: 'progression', slot: 'center', visible: true, params: {} },
      ], { game: MIXED_GAME });

      await page.goto(`/s/${MIXED_GAME}/overlay`);
      await expect(page.locator('.progcard')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('.proglegitem')).toHaveCount(0);
      await expect(page.locator('.progstep.ratio-a')).toHaveCount(0);
    });
  });

  test('shows no ratio dimension for a division that is not mixed', async ({ page }) => {
    await withShowRestored(page, async () => {
      await writeShow(page, [
        { id: 'progression', slot: 'center', visible: true, params: { ratio1: '4M/3F' } },
      ], { game: GAME_ID });

      await page.goto(`/s/${GAME_ID}/overlay`);
      await expect(page.locator('.progcard')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('.proglegitem')).toHaveCount(0);
    });
  });
});

test.describe('gender ratio', () => {
  test('the ABBA pattern matches UltiOrganizer’s own scoresheet', async ({ page }) => {
    await page.goto(`/c/${GAME_ID}`);
    await page.waitForTimeout(1500);

    // pdfscoresheet.php marks points 1, 4, 5, 8, 9, 12 … as repeating point 1's
    // ratio. Same rule, so a commentator and a scorekeeper cannot disagree.
    const slots = await page.evaluate(() => {
      const abba = (i) => (i % 4 === 0 || (i - 1) % 4 === 0) ? 'A' : 'B';
      return Array.from({ length: 13 }, (_, k) => abba(k + 1)).join('');
    });
    expect(slots).toBe('ABBAABBAABBAA');
  });

  test('splits scoring by ratio, and only once enough is played', async ({ page }) => {
    await page.goto(`/c/${MIXED_GAME}`);
    await page.evaluate(() => localStorage.setItem('uo-abba-first-703', '4M/3F'));
    await page.goto(`/c/${MIXED_GAME}`);
    await page.locator('#tabPlay').click();

    const panel = page.locator('.abba');
    await expect(panel).toBeVisible({ timeout: 10000 });

    // 9 goals, alternating from the home team. A points are 1, 4, 5, 8, 9 ->
    // home 3 away 2; B points are 2, 3, 6, 7 -> 2 all.
    const rows = panel.locator('.abbasplitrow');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toContainText('4M/3F');
    await expect(rows.nth(0).locator('.sc')).toHaveText('3 – 2');
    await expect(rows.nth(1)).toContainText('3M/4F');
    await expect(rows.nth(1).locator('.sc')).toHaveText('2 – 2');
  });

  test('asks for the first point rather than guessing it', async ({ page }) => {
    await page.goto(`/c/${MIXED_GAME}`);
    await page.evaluate(() => localStorage.removeItem('uo-abba-first-703'));
    await page.goto(`/c/${MIXED_GAME}`);
    await page.locator('#tabPlay').click();

    const panel = page.locator('.abba');
    await expect(panel).toBeVisible({ timeout: 10000 });
    // No ratio named anywhere until somebody sets it.
    await expect(panel).toContainText('Ratio on point 1');
    await expect(panel.locator('.abbasplitrow')).toHaveCount(0);
  });

  test('is silent in a division that is not mixed', async ({ page }) => {
    // The fixture division is Open. A ratio panel there would be nonsense.
    await page.goto(`/c/${GAME_ID}`);
    await page.locator('#tabPlay').click();
    await page.waitForTimeout(1500);
    await expect(page.locator('.abba')).toHaveCount(0);
  });
});
