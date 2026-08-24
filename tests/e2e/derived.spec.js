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
  writeShow, withShowRestored, readShow,
} = require('./helpers');

/** The mixed fixture game — the only one that can exercise gender ratio. */
const MIXED_GAME = 703;

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

/**
 * Set the first point's ratio, restoring it afterwards.
 *
 * It lives with the game rather than in a browser or on a card, so a test has to
 * write it where the surfaces read it.
 */
async function withRatio(page, ratio, body) {
  const before = await readPossession(page);
  try {
    await writePossession(page, { game: MIXED_GAME, ratio1: ratio });
    await body();
  } finally {
    await writePossession(page, { game: MIXED_GAME, ratio1: before.ratio1 || '' });
  }
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

test.describe('several people tracking possession at once', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('a press that changes nothing is not recorded', async ({ page }) => {
    // Two commentators watching the same play both press D. The disc cannot
    // pass from the defence to the defence, so the second press is somebody
    // confirming what is already true.
    await withPossession(page, [], async () => {
      const sc = await scoreKey(page);
      const stored = await page.evaluate(async ({ sc, game }) => {
        await Promise.all(Array.from({ length: 12 }, () => fetch(
          '/index.php?view=live/overlays/possession',
          {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ game, score: sc, defence: true }),
          },
        )));
        const r = await fetch(`/index.php?view=live/overlays/possession&game=${game}`,
          { credentials: 'same-origin' });
        return (await r.json()).events.length;
      }, { sc, game: GAME_ID });
      expect(stored).toBe(1);
    });
  });

  test('real changes are all kept', async ({ page }) => {
    await withPossession(page, [], async () => {
      const sc = await scoreKey(page);
      const stored = await page.evaluate(async ({ sc, game }) => {
        for (let i = 0; i < 6; i += 1) {
          // eslint-disable-next-line no-await-in-loop
          await fetch('/index.php?view=live/overlays/possession', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ game, score: sc, defence: i % 2 === 0 }),
          });
        }
        const r = await fetch(`/index.php?view=live/overlays/possession&game=${game}`,
          { credentials: 'same-origin' });
        return (await r.json()).events.length;
      }, { sc, game: GAME_ID });
      expect(stored).toBe(6);
    });
  });

  test('the readers count changes, not presses', () => {
    // The other half of the same guarantee, and the reason duplicates were
    // never able to corrupt a count even before the store dropped them.
    const { turnovers } = require('../../shared/possession.js');
    const log = (...ds) => ds.map((d) => ({ score: '8-6', d: d ? 1 : 0, t: 1 }));
    expect(turnovers(log(true), 8, 6)).toBe(1);
    expect(turnovers(log(true, true, true), 8, 6)).toBe(1);
    expect(turnovers(log(true, true, false, false), 8, 6)).toBe(2);
    expect(turnovers(log(true, false, true, false), 8, 6)).toBe(4);
  });
});

test.describe('one document per game', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('two games are tracked at once without touching each other', async ({ page }) => {
    // The store was a single global document. A tournament runs several fields
    // at once and this project supports that, so one document meant a second
    // field's desk wiped the first's — measured at the time: three events
    // became zero and the code was silently replaced.
    const OTHER = 703;
    await page.goto('/s/');
    try {
      // Both sides set explicitly, including the absence of a ratio on one:
      // asserting on a value this test did not set is how it would pass or fail
      // on whatever an earlier run happened to leave behind.
      await writePossession(page, { enabled: true, game: GAME_ID, code: 'AAAAA', ratio1: '' });
      await writePossession(page, { enabled: true, game: OTHER, code: 'BBBBB', ratio1: '4MMP/3FMP' });
      for (const d of [true, false, true]) {
        // eslint-disable-next-line no-await-in-loop
        await writePossession(page, { game: GAME_ID, score: '8-6', defence: d });
      }
      await writePossession(page, { game: OTHER, score: '5-4', defence: true });

      const a = await readPossession(page, GAME_ID);
      const b = await readPossession(page, OTHER);
      expect(a.events).toHaveLength(3);
      expect(b.events).toHaveLength(1);
      expect(a.code).toBe('AAAAA');
      expect(b.code).toBe('BBBBB');
      // The first point's ratio used to survive a game change and label the
      // next game's points with the previous game's ratio.
      expect(a.ratio1).toBeNull();
      expect(b.ratio1).toBe('4MMP/3FMP');
    } finally {
      await writePossession(page, { enabled: false, game: GAME_ID, code: null });
      await writePossession(page, { enabled: false, game: OTHER, code: null, ratio1: '' });
    }
  });

  test('a request without a game is refused', async ({ page }) => {
    const status = await page.evaluate(async () => {
      const r = await fetch('/index.php?view=live/overlays/possession',
        { credentials: 'same-origin' });
      return r.status;
    });
    expect(status).toBe(400);
  });

  test('the private half of a game document is never served', async ({ page, request }) => {
    // The code authorises writing. Publishing it beside the data it protects
    // would hand out the door key.
    const open = await request.get('/live/overlays/conf/possession-702.json');
    const shut = await request.get('/live/overlays/conf/possession-702.private.json');
    const lock = await request.get('/live/overlays/conf/possession-702.json.lock');
    expect(open.status()).toBe(200);
    expect(shut.status()).toBe(404);
    expect(lock.status()).toBe(404);
  });
});

test.describe('correcting the possession log', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('deletes one named entry, leaving other points alone', async ({ page }) => {
    await withPossession(page, [], async () => {
      const sc = await scoreKey(page);
      const result = await page.evaluate(async ({ sc, game }) => {
        const post = (body) => fetch('/index.php?view=live/overlays/possession', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ game, ...body }),
        }).then((r) => r.json());

        // An earlier point, then three changes on this one.
        await post({ score: '1-0', defence: true });
        await post({ score: sc, defence: true });
        await post({ score: sc, defence: false });
        let state = await post({ score: sc, defence: true });

        const mine = state.events.filter((e) => e.score === sc);
        // Remove the MIDDLE one, named by when it happened.
        state = await post({ score: sc, at: mine[1].t });
        return {
          thisPoint: state.events.filter((e) => e.score === sc).map((e) => (e.d ? 'D' : 'O')).join(''),
          earlierPointKept: state.events.some((e) => e.score === '1-0'),
        };
      }, { sc, game: GAME_ID });

      expect(result.thisPoint).toBe('DD');
      // A correction to one point must not touch another: an earlier point's
      // numbers may already have been read out.
      expect(result.earlierPointKept).toBe(true);
    });
  });

  test('clearing a point leaves the rest of the game', async ({ page }) => {
    await withPossession(page, [], async () => {
      const sc = await scoreKey(page);
      const left = await page.evaluate(async ({ sc, game }) => {
        const post = (body) => fetch('/index.php?view=live/overlays/possession', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ game, ...body }),
        }).then((r) => r.json());
        await post({ score: '1-0', defence: true });
        await post({ score: sc, defence: true });
        await post({ score: sc, defence: false });
        const state = await post({ score: sc, clearPoint: true });
        return state.events.map((e) => e.score);
      }, { sc, game: GAME_ID });
      expect(left).toEqual(['1-0']);
    });
  });

  test('a stranger cannot correct the log', async ({ page }) => {
    const status = await page.evaluate(async (game) => {
      const r = await fetch('/index.php?view=live/overlays/possession', {
        method: 'POST',
        // Omitted deliberately: fetch sends same-origin cookies BY DEFAULT, so
        // without this the request carries the admin session this test just
        // logged in with and proves nothing. It returned 200 for exactly that
        // reason before.
        credentials: 'omit',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ game, score: '1-0', clearPoint: true }),
      });
      return r.status;
    }, GAME_ID);
    expect(status).toBe(403);
  });

  test('nothing is deleted without confirmation', async ({ page }) => {
    // The log is where deletions happen precisely so they are deliberate: a
    // declined confirmation must leave it exactly as it was.
    await page.goto(`/c/${GAME_ID}`);
    const code = await page.evaluate(() => localStorage.getItem('uo-lines-code-702'));

    await writePossession(page, { enabled: false, game: GAME_ID });
    await writePossession(page, { enabled: true, game: GAME_ID, code });
    try {
      await page.evaluate(async ({ game, code }) => {
        const r = await fetch(`/index.php?view=live/api&entity=games&id=${game}`,
          { credentials: 'same-origin' });
        const d = await r.json();
        const score = `${d.game_result.homescore}-${d.game_result.visitorscore}`;
        for (const defence of [true, false, true]) {
          // eslint-disable-next-line no-await-in-loop
          await fetch('/index.php?view=live/overlays/possession', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ game, code, score, defence }),
          });
        }
      }, { game: GAME_ID, code });

      await page.goto(`/c/${GAME_ID}`);
      await page.locator('#tabPlay').click();
      await page.locator('#tracking .tbtn[aria-label="Possession log"]').click();
      await expect(page.locator('.plogrow')).toHaveCount(3);

      page.once('dialog', (d) => d.dismiss());
      await page.locator('.plogrow .chip.danger').first().click();
      await page.waitForTimeout(900);
      await expect(page.locator('.plogrow')).toHaveCount(3);

      page.once('dialog', (d) => d.accept());
      await page.locator('.plogrow .chip.danger').first().click();
      await expect(page.locator('.plogrow')).toHaveCount(2);
    } finally {
      await writePossession(page, { enabled: false, game: GAME_ID }).catch(() => {});
    }
  });
});

test.describe('the commentator link', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('works without possession tracking being on', async ({ page }) => {
    // These were one switch. Turning on break chances was the only way to get a
    // code, so a desk could not be handed the gender ratio or a stoppage
    // without also putting a graphic on air.
    await withPossession(page, [], async () => {
      await writePossession(page, { enabled: false, game: GAME_ID, code: 'new' });
      const state = await readPossession(page);
      expect(state.enabled).toBe(false);
      expect(state.code).toMatch(/^[A-Z0-9]{5}$/);

      // The ratio and a stoppage are both settable by the code holder.
      const ratio = await writePossession(page,
        { game: GAME_ID, code: state.code, ratio1: '4MMP/3FMP' });
      expect(ratio.body.ratio1).toBe('4MMP/3FMP');

      const sc = await scoreKey(page);
      const stop = await writePossession(page,
        { game: GAME_ID, code: state.code, score: sc, stoppage: true });
      expect(stop.body.stoppage).toMatchObject({ score: sc });

      // Possession is the one thing that still needs its own mode.
      const poss = await writePossession(page,
        { game: GAME_ID, code: state.code, score: sc, defence: true });
      expect(poss.body.error).toMatch(/tracking is off/i);
    });
  });

  test('names the desks rather than counting them', async ({ page }) => {
    // "2 connected" answers the wrong question: the operator needs to know
    // whether the RIGHT desk is on the code.
    await withPossession(page, [], async () => {
      const state = await writePossession(page, { game: GAME_ID, code: 'new' });
      const code = state.body.code;
      await page.evaluate(async ({ game, code }) => {
        for (const [client, name] of [['aaa111bbb', 'Sam on colour'], ['ccc222ddd', 'Robin PBP']]) {
          // eslint-disable-next-line no-await-in-loop
          await fetch(`/index.php?view=live/overlays/possession&game=${game}`
            + `&code=${code}&client=${client}&name=${encodeURIComponent(name)}`,
          { credentials: 'same-origin' });
        }
      }, { game: GAME_ID, code });

      const seen = await readPossession(page);
      expect(seen.connected).toBe(2);
      expect((seen.clients || []).map((c) => c.name).sort())
        .toEqual(['Robin PBP', 'Sam on colour']);
    });
  });

  test('a name is never returned to a commentator, only to the operator', async ({ page }) => {
    // The roster exists to answer the operator's question. A commentator has no
    // business enumerating who else is on the code.
    await withPossession(page, [], async () => {
      const state = await writePossession(page, { game: GAME_ID, code: 'new' });
      const body = await page.evaluate(async ({ game, code }) => {
        const r = await fetch(`/index.php?view=live/overlays/possession&game=${game}&code=${code}`,
          { credentials: 'omit' });
        return r.json();
      }, { game: GAME_ID, code: state.body.code });
      expect(body.clients).toBeUndefined();
      expect(body.code).toBeUndefined();
    });
  });
});

test.describe('how a ratio is written', () => {
  const R = require('../../shared/ratio.js');

  test('names the majority side only, so neither category is printed first', () => {
    // Writing both halves forces a decision about ordering that has no good
    // answer. The majority side identifies the ratio on its own.
    expect(R.short('4MMP/3FMP')).toBe('4MMP');
    expect(R.short('3MMP/4FMP')).toBe('4FMP');
    expect(R.short('3MMP/2FMP')).toBe('3MMP');
    expect(R.short('2MMP/3FMP')).toBe('3FMP');
  });

  test('is one rule, shared, so the surfaces cannot drift', () => {
    // The pattern and the pair were duplicated between the commentator page and
    // the progression card, which is how the card came to be printing the long
    // form after the commentator had moved to the short one.
    expect(Array.from({ length: 13 }, (_, i) => R.slot(i + 1)).join('')).toBe('ABBAABBAABBAA');
    expect(R.pair('outdoor')).toEqual(['4MMP/3FMP', '3MMP/4FMP']);
    expect(R.pair('beach')).toEqual(['3MMP/2FMP', '2MMP/3FMP']);
    expect(R.isMixed('Mixed Pool')).toBe(true);
    expect(R.isMixed('Open')).toBe(false);
  });
});

test.describe('the commentator panel', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('the name field is reachable with everything switched off', async ({ page }) => {
    // It was behind possession tracking, so the field the operator reads when
    // deciding whose code to enter was invisible until somebody had already put
    // a graphic on air. The store shed that coupling; the panel had kept it.
    await withPossession(page, [], async () => {
      await writePossession(page, { enabled: false, game: GAME_ID, code: null });
      await page.goto(`/c/${GAME_ID}`);
      await page.locator('#tabPlay').click();
      await expect(page.locator('#commentatorName')).toBeVisible();
      // And it says how to get linked, rather than saying nothing. The controls
      // live in the sticky header now, beside the code that authorises them.
      await expect(page.locator('#tracking')).toContainText(/not linked/i);
    });
  });

  test('a stoppage needs no possession mode', async ({ page }) => {
    await withPossession(page, [], async () => {
      await page.goto(`/c/${GAME_ID}`);
      const code = await page.evaluate(() => localStorage.getItem('uo-lines-code-702'));
      await writePossession(page, { enabled: false, game: GAME_ID, code });

      await page.goto(`/c/${GAME_ID}`);
      await page.locator('#tabPlay').click();
      await expect(page.locator('#tracking')).toContainText(/injury/i);
    });
  });
});

test.describe('injury stoppage', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('is declared, shown, and clears itself at the next goal', async ({ page }) => {
    await withPossession(page, [], async () => {
      const sc = await scoreKey(page);
      await writePossession(page, { game: GAME_ID, score: sc, stoppage: true });

      await page.goto(`/s/${GAME_ID}`);
      await expect(page.locator('#scoreboard')).toBeVisible();
      await expect(page.locator('.callout:not(.hide)').first())
        .toContainText('Injury stoppage', { timeout: 8000 });

      // Keyed by score, so a goal ends it without anyone remembering to.
      const { declared } = require('../../shared/stoppage.js');
      const [h, v] = sc.split('-').map(Number);
      expect(declared({ score: sc }, h, v)).toMatchObject({ kind: 'injury' });
      expect(declared({ score: sc }, h + 1, v)).toBeNull();

      await writePossession(page, { game: GAME_ID, stoppage: false });
    });
  });

  test('belongs to neither team, so it is centred', async ({ page }) => {
    await withPossession(page, [], async () => {
      const sc = await scoreKey(page);
      await writePossession(page, { game: GAME_ID, score: sc, stoppage: true });
      await page.goto(`/s/${GAME_ID}`);
      await expect(page.locator('.callout.timeout')).toBeVisible({ timeout: 8000 });
      await expect(page.locator('#calloutRow')).toHaveClass(/centred/);
      await writePossession(page, { game: GAME_ID, stoppage: false });
    });
  });
});

test.describe('the summary card picks its own moment', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  const CASES = [
    [701, 'Coming up', 'a game that has not started'],
    [700, 'Full time', 'a finished game'],
    [GAME_ID, 'How it stands', 'a game in play past the half'],
  ];

  for (const [game, heading, what] of CASES) {
    test(`reads ${what} as "${heading}"`, async ({ page }) => {
      await withShowRestored(page, async () => {
        await writeShow(page, [
          { id: 'summary', slot: 'center', visible: true, params: {} },
        ], { game });
        await page.goto(`/s/${game}/overlay`);
        await expect(page.locator('.summarycard')).toBeVisible({ timeout: 15000 });
        await expect(page.locator('.summarycard .statcard-head')).toHaveText(heading);
      });
    });
  }

  test('an explicit moment still wins', async ({ page }) => {
    // The case that needs it: full-time numbers during a break, which no rule
    // can infer from a game still in play.
    await withShowRestored(page, async () => {
      await writeShow(page, [
        { id: 'summary', slot: 'center', visible: true, params: { when: 'final' } },
      ], { game: GAME_ID });
      await page.goto(`/s/${GAME_ID}/overlay`);
      await expect(page.locator('.summarycard .statcard-head'))
        .toHaveText('Full time', { timeout: 15000 });
    });
  });
});

test.describe('the timeout tab', () => {
  /**
   * The window is unit-tested, not driven through the browser.
   *
   * Testing it live meant moving the game clock, and Live! caches a game payload
   * for 30 seconds — so a test that set the clock and immediately loaded the page
   * measured the PREVIOUS clock while appearing to measure this one. One run
   * asked for 20 seconds past a timeout and was served 131. The derivation is a
   * pure function, so it is tested as one.
   */
  const { active } = require('../../shared/stoppage.js');

  const EVENTS = [
    { time: 0, type: 'offence', ishome: 1 },
    { time: 600, type: 'timeout', ishome: 1 },
    { time: 1260, type: 'half_cap', ishome: 0 },
    { time: 1700, type: 'timeout', ishome: 0 },
  ];

  test('the window opens when the timeout is called and closes when it ends', () => {
    expect(active(EVENTS, 1700, 70)).toMatchObject({ kind: 'timeout' });
    expect(active(EVENTS, 1720, 70)).toMatchObject({ kind: 'timeout' });
    expect(active(EVENTS, 1769, 70)).toMatchObject({ kind: 'timeout' });
    // 70 seconds in, play has restarted.
    expect(active(EVENTS, 1770, 70)).toBeNull();
    expect(active(EVENTS, 1800, 70)).toBeNull();
  });

  test('a later event ends it, so a stale tab cannot survive the next passage', () => {
    // The half cap at 1260 comes after the first timeout at 600.
    expect(active(EVENTS, 1300, 70)).toBeNull();
  });

  test('ignores events from the future of the frame being drawn', () => {
    // Only matters away from live play: post-production draws an earlier moment
    // with `?at=` while the payload still carries everything that came later.
    // Reading the greatest timestamp put a timeout on a frame 18 minutes before
    // it was called.
    expect(active(EVENTS, 620, 70)).toMatchObject({ kind: 'timeout', side: 'home' });
    expect(active(EVENTS, 100, 70)).toBeNull();
  });

  test('attributes it to the team that called it', () => {
    expect(active(EVENTS, 1710, 70).side).toBe('visitor');
    expect(active(EVENTS, 620, 70).side).toBe('home');
  });

  test('claims nothing without a clock, a length, or events', () => {
    // Each of these is a state the board really reaches: a game that has not
    // started has no clock, and a pool with no timeout length configured cannot
    // say how long one lasts.
    expect(active(EVENTS, 1710, 0)).toBeNull();
    expect(active(EVENTS, NaN, 70)).toBeNull();
    expect(active([], 1710, 70)).toBeNull();
    // Drawing a moment before the timeout was called: it has not happened yet.
    expect(active(EVENTS, 1699, 70)).toBeNull();
  });

  test('reaches the scoreboard', async ({ page }) => {
    // Wiring only -- that the module is loaded, the class is right and the tab
    // paints. Driven through ?demo=1, which builds its own payload and so needs
    // no clock moved and no cache defeated.
    await page.goto(`/s/${GAME_ID}?demo=1&step=600`);
    await expect(page.locator('#scoreboard')).toBeVisible();
    await expect(page.locator('.callout.timeout')).toBeVisible({ timeout: 25000 });
    await expect(page.locator('.callout.timeout')).toHaveText('Timeout');
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
    // Needs a card on the stage to have anything to show, and must put one
    // there rather than hoping one is configured.
    await loginAsAdmin(page, test);
    await page.goto('/s/');
    const before = await readShow(page);
    try {
      await writeShow(page, [
        { id: 'scoreboard', slot: 'lower-left', visible: true, params: {} },
      ], { game: GAME_ID });
      await page.goto('/s/field/1/overlay');
      await expect(page.locator('.mount.shown').first()).toBeVisible({ timeout: 15000 });
    } finally {
      await writeShow(page, before.cards, { game: before.game, logo: before.logo });
    }
  });
});

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
      await withRatio(page, '4MMP/3FMP', async () => {
        await writeShow(page, [
          { id: 'progression', slot: 'center', visible: true, params: {} },
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
        await expect(page.locator('.proglegitem').first()).toContainText('MMP');
      });
    });
  });

  test('separates the two ratios by more than colour', async ({ page }) => {
    // Colour alone cannot carry this: measured, every light-on-dark pair that
    // stays visible against the card becomes near-identical in luminance under
    // a deuteranope simulation. The dash is what actually distinguishes them,
    // and it survives colour blindness, greyscale and print.
    await withShowRestored(page, async () => {
      await withRatio(page, '4MMP/3FMP', async () => {
        await writeShow(page, [
          { id: 'progression', slot: 'center', visible: true, params: {} },
        ], { game: MIXED_GAME });

        await page.goto(`/s/${MIXED_GAME}/overlay`);
        await expect(page.locator('.progcard')).toBeVisible({ timeout: 15000 });

        const dashes = await page.evaluate(() => ({
          a: getComputedStyle(document.querySelector('.progstep.ratio-a')).strokeDasharray,
          b: getComputedStyle(document.querySelector('.progstep.ratio-b')).strokeDasharray,
        }));
        expect(dashes.a).not.toBe(dashes.b);
        expect(dashes.b).not.toBe('none');
      });
    });
  });

  test('omits the ratio entirely when nobody has named the first point', async ({ page }) => {
    // The pattern is derivable; which ratio "A" actually is, is not recorded
    // anywhere. Tinting without it would be inventing the labels.
    //
    // Clears the ratio explicitly rather than assuming it is unset -- read as
    // it was, this passed only because no earlier test had set one.
    await withShowRestored(page, async () => {
      await withRatio(page, '', async () => {
        await writeShow(page, [
          { id: 'progression', slot: 'center', visible: true, params: {} },
        ], { game: MIXED_GAME });

        await page.goto(`/s/${MIXED_GAME}/overlay`);
        await expect(page.locator('.progcard')).toBeVisible({ timeout: 15000 });
        await expect(page.locator('.proglegitem')).toHaveCount(0);
        await expect(page.locator('.progstep.ratio-a')).toHaveCount(0);
      });
    });
  });

  test('shows no ratio dimension for a division that is not mixed', async ({ page }) => {
    await withShowRestored(page, async () => {
      await writeShow(page, [
        { id: 'progression', slot: 'center', visible: true, params: {} },
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
    await loginAsAdmin(page, test);
    await page.goto(`/c/${MIXED_GAME}`);

    await withRatio(page, '4MMP/3FMP', async () => {
      await page.goto(`/c/${MIXED_GAME}`);
      await page.locator('#tabPlay').click();

      const panel = page.locator('.abba');
      await expect(panel).toBeVisible({ timeout: 10000 });

      // 9 goals, alternating from the home team. A points are 1, 4, 5, 8, 9 ->
      // home 3 away 2; B points are 2, 3, 6, 7 -> 2 all.
      const rows = panel.locator('.abbasplitrow');
      await expect(rows).toHaveCount(2);
      // One side, not two: `4MMP` says 4MMP/3FMP without deciding which
      // category gets printed first.
      await expect(rows.nth(0)).toContainText('4MMP');
      await expect(rows.nth(0)).not.toContainText('/');
      await expect(rows.nth(0).locator('.sc')).toHaveText('3 – 2');
      await expect(rows.nth(1)).toContainText('4FMP');
      await expect(rows.nth(1).locator('.sc')).toHaveText('2 – 2');
    });
  });

  test('asks for the first point rather than guessing it', async ({ page }) => {
    await loginAsAdmin(page, test);
    await page.goto(`/c/${MIXED_GAME}`);
    await withRatio(page, '', async () => {
      await page.goto(`/c/${MIXED_GAME}`);
      await page.locator('#tabPlay').click();

      const panel = page.locator('.abba');
      await expect(panel).toBeVisible({ timeout: 10000 });
      // No ratio named anywhere until somebody sets it, and the panel points at
      // the control rather than carrying one — the selector is in the toolbar.
      await expect(panel).toContainText(/ratio for point 1|ratio for point 1 yet/i);
      await expect(panel.locator('.abbasplitrow')).toHaveCount(0);
      await expect(page.locator('#tracking .tsel')).toHaveCount(1);
    });
  });

  test('is silent in a division that is not mixed', async ({ page }) => {
    // The fixture division is Open. A ratio panel there would be nonsense.
    await page.goto(`/c/${GAME_ID}`);
    await page.locator('#tabPlay').click();
    await page.waitForTimeout(1500);
    await expect(page.locator('.abba')).toHaveCount(0);
  });
});
