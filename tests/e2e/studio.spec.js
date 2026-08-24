// @ts-check
/**
 * The Studio, and the rules the store enforces on its behalf.
 *
 * These need the Live! admin session and are skipped without ADMIN_PASS, so a
 * clone with no credentials still gets a useful run from the other specs.
 *
 * The important ones are the store-side invariants. A rule enforced only in
 * index.php is a rule any other client can ignore, and "the UI would not let you
 * do that" has never been a guarantee about what reaches the air.
 */
const { test } = require('@playwright/test');
const {
  GAME_ID, loginAsAdmin, readShow, writeShow, writePossession,
  withShowRestored, expect,
} = require('./helpers');

test.describe('studio', () => {
  test('is public and read-only without a login', async ({ page }) => {
    // A camera operator at a field should not need a password to find a URL --
    // least of all in auto mode, where there is no operator at all.
    await page.goto('/s/');
    await expect(page.locator('h1')).toBeVisible();
    await expect(page.locator('.cardrow').first()).toBeVisible();

    const editable = await page.evaluate(() =>
      [...document.querySelectorAll('.switch, .picker .cell, .autobtn')]
        .filter((b) => !b.disabled).length);
    expect(editable, 'no control should be live for an anonymous visitor').toBe(0);

    // And it says why, with somewhere to go.
    await expect(page.locator('#authAction a')).toBeVisible();
  });

  test.describe('logged in', () => {
    test.beforeEach(async ({ page }) => {
      await loginAsAdmin(page, test);
    });

    test('the store rejects frames the UI would never build', async ({ page }) => {
      await page.goto('/s/');
      await withShowRestored(page, async () => {
        // Unknown card, and a card in a slot it does not fit: both dropped.
        let res = await writeShow(page, [
          { id: 'not-a-card', slot: 'center', visible: true },
          { id: 'topplayers', slot: 'with-scoreboard', visible: true },
        ]);
        expect(res.status).toBe(200);
        expect(res.body.cards.length).toBe(0);
        expect(res.body.warning).toBeTruthy();

        // Two visible in one slot: one survives.
        res = await writeShow(page, [
          { id: 'scoreboard', slot: 'lower-left', visible: true },
          { id: 'lastplay', slot: 'lower-left', visible: true },
        ]);
        expect(res.body.cards.filter((c) => c.visible).length).toBe(1);

        // A fullscreen takeover blanks everything else.
        res = await writeShow(page, [
          { id: 'topplayers', slot: 'fullscreen', visible: true },
          { id: 'scoreboard', slot: 'lower-left', visible: true },
        ]);
        expect(res.body.cards.filter((c) => c.visible).length).toBe(1);
        expect(res.body.cards.find((c) => c.visible).slot).toBe('fullscreen');
      });
    });

    test('a stale rev is refused rather than silently overwriting', async ({ page }) => {
      await page.goto('/s/');
      await withShowRestored(page, async () => {
        const res = await page.evaluate(async () => {
          const r = await fetch('/index.php?view=live/overlays/show', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rev: 0, game: null, cards: [] }),
          });
          return { status: r.status, body: await r.json() };
        });
        expect(res.status).toBe(409);
        // The current state comes back so the UI can reapply rather than guess.
        expect(res.body.state).toBeTruthy();
      });
    });

    test('all off air clears visibility and keeps every position', async ({ page }) => {
      await page.goto('/s/');
      await withShowRestored(page, async () => {
        await writeShow(page, [
          { id: 'scoreboard', slot: 'lower-left', visible: true },
          { id: 'topplayers', slot: 'center', visible: false },
        ]);
        await page.reload();
        await page.locator('.clearall').click();
        await expect(page.locator('.clearall')).toBeDisabled();

        const after = await readShow(page);
        expect(after.cards.every((c) => !c.visible)).toBe(true);
        // Placement survives, so the cards stay armed and putting the stage back
        // is as fast as switching them on again.
        expect(after.cards.find((c) => c.id === 'scoreboard').slot).toBe('lower-left');
        expect(after.cards.find((c) => c.id === 'topplayers').slot).toBe('center');
      });
    });

    test('auto mode is stored per card, not globally', async ({ page }) => {
      await page.goto('/s/');
      await withShowRestored(page, async () => {
        const res = await writeShow(page, [
          { id: 'scoreboard', slot: 'lower-left', visible: true, params: {} },
          { id: 'lastplay', slot: 'with-scoreboard', visible: true, params: { auto: true } },
        ]);
        const lastplay = res.body.cards.find((c) => c.id === 'lastplay');
        const board = res.body.cards.find((c) => c.id === 'scoreboard');
        expect(lastplay.params.auto).toBe(true);
        expect(board.params.auto).toBeUndefined();
      });
    });

    test('a code holder can set possession but cannot grant themselves more', async ({ page, request }) => {
      await page.goto('/s/');
      await writePossession(page, { enabled: true, game: GAME_ID, code: 'ABCDE' });
      try {
        // `request`, NOT `page.request`. The latter shares the page's cookies,
        // so it would carry the admin session and this would quietly test that
        // an admin can do admin things -- which is not the question. The first
        // version of this test did exactly that and passed for the wrong reason.
        // Every request names a game now: the store is one document per game.
        expect((await (await request.get(
          `/index.php?view=live/overlays/possession&game=${GAME_ID}`)).json()).admin,
        'the anonymous context must not be logged in').toBe(false);

        // The one thing the code is for.
        const ok = await request.post('/index.php?view=live/overlays/possession', {
          data: { game: GAME_ID, code: 'ABCDE', score: '0-0', defence: true },
        });
        expect(ok.status()).toBe(200);

        // And the things it is not: turning the mode off, or renaming the code.
        await request.post('/index.php?view=live/overlays/possession', {
          data: { game: GAME_ID, code: 'ABCDE', enabled: false, code2: 'XXXXX' },
        });
        const state = await page.evaluate(async (game) => {
          const r = await fetch(`/index.php?view=live/overlays/possession&game=${game}`,
            { credentials: 'same-origin' });
          return r.json();
        }, GAME_ID);
        expect(state.enabled, 'a code holder must not be able to disable the mode').toBe(true);
        expect(state.code, 'nor rename it').toBe('ABCDE');
      } finally {
        await writePossession(page, { enabled: false, game: GAME_ID, code: null });
      }
    });
  });
});
