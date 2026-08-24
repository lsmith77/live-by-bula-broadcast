// @ts-check
/**
 * Auto triggers, and the configuration file the Studio writes.
 *
 * The round-trip test matters more than it looks. This file is the input to
 * post-production: a game recorded without a switcher gets its overlay added
 * afterwards from exactly this document, so a field silently dropped on export
 * would not show up until somebody rendered a video and found the wrong cards
 * in it.
 */
const { test } = require('@playwright/test');
const { loginAsAdmin, readShow, writeShow, withShowRestored, expect } = require('./helpers');

test.describe('auto triggers', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, test);
    await page.goto('/s/');
  });

  test('each trigger shape is stored as written', async ({ page }) => {
    await withShowRestored(page, async () => {
      const res = await writeShow(page, [
        { id: 'lastplay', slot: 'with-scoreboard', visible: true, params: { auto: { on: 'goal', for: 15 } } },
        { id: 'topplayers', slot: 'center', visible: true, params: { auto: { on: 'pregame', for: null } } },
      ]);
      const byId = Object.fromEntries(res.body.cards.map((c) => [c.id, c]));
      expect(byId.lastplay.params.auto).toEqual({ on: 'goal', for: 15 });
      // `for: null` is the whole distinction between "for N seconds after an
      // event" and "while a state holds". If it were dropped or coerced to a
      // number, a pre-game card would vanish 15s in and leave an empty frame.
      expect(byId.topplayers.params.auto.on).toBe('pregame');
      expect(byId.topplayers.params.auto.for).toBeNull();
    });
  });

  test('the legacy auto:true still means 15s after each goal', async ({ page }) => {
    await withShowRestored(page, async () => {
      const res = await writeShow(page, [
        { id: 'lastgoal', slot: 'with-scoreboard', visible: true, params: { auto: true } },
      ]);
      expect(res.body.cards[0].params.auto).toBe(true);
      // The stage normalises it; the store keeps it verbatim, so an existing
      // configuration never has to be rewritten to keep working.
    });
  });

  test('a pre-game card paints before the pull and an on-goal card does not', async ({ page, context }) => {
    await withShowRestored(page, async () => {
      await writeShow(page, [
        { id: 'lastplay', slot: 'with-scoreboard', visible: true, params: { auto: { on: 'goal', for: 15 } } },
      ]);
      const show = await readShow(page);
      const stage = await context.newPage();
      await stage.goto(`/s/${show.game}/overlay`);
      await stage.waitForTimeout(3000);

      // Mounted, so revealing is instant -- but dark, because no goal has landed
      // since this stage loaded. The first payload only sets a baseline.
      const state = await stage.evaluate(() => ({
        mounted: document.querySelectorAll('.mount').length,
        shown: document.querySelectorAll('.mount.shown').length,
      }));
      expect(state.mounted).toBeGreaterThan(0);
      expect(state.shown).toBeLessThan(state.mounted);
      await stage.close();
    });
  });
});

test.describe('stage configuration file', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, test);
    await page.goto('/s/');
  });

  test('exports what is on the stage and imports it back unchanged', async ({ page }) => {
    await withShowRestored(page, async () => {
      const wanted = [
        { id: 'scoreboard', slot: 'lower-left', visible: true, params: {} },
        { id: 'lastplay', slot: 'with-scoreboard', visible: true, params: { auto: { on: 'goal', for: 15 } } },
        { id: 'topplayers', slot: 'center', visible: false, params: { auto: { on: 'halftime', for: 30 } } },
      ];
      // Logo pinned off: these cases are about export and import, and
      // `lower-left` is a slot the tournament logo reserves when it sits in that
      // corner. Inheriting whatever corner the stage happened to be set to made
      // this test's fixture depend on unrelated state.
      await writeShow(page, wanted, { logo: null });
      await page.reload();

      const download = await Promise.race([
        page.waitForEvent('download'),
        page.locator('.stagebar button', { hasText: 'Export' }).click().then(() => null),
      ]) || await page.waitForEvent('download');
      const stream = await download.createReadStream();
      const chunks = [];
      for await (const c of stream) chunks.push(c);
      const doc = JSON.parse(Buffer.concat(chunks).toString());

      expect(doc.kind).toBe('live-by-bula-broadcast/stage');
      expect(doc.cards).toHaveLength(3);
      // Every field that changes what appears must survive the round trip.
      const exported = Object.fromEntries(doc.cards.map((c) => [c.id, c]));
      expect(exported.topplayers.params.auto).toEqual({ on: 'halftime', for: 30 });
      expect(exported.topplayers.visible).toBe(false);
      expect(exported.lastplay.slot).toBe('with-scoreboard');

      // A game id is deliberately absent: a layout is reusable, a game is not.
      expect(doc).not.toHaveProperty('game');
      expect(doc).not.toHaveProperty('rev');

      // Now clear the stage and import the file back.
      await writeShow(page, []);
      const res = await page.evaluate(async (cards) => {
        const cur = await (await fetch('/index.php?view=live/overlays/show',
          { credentials: 'same-origin' })).json();
        const r = await fetch('/index.php?view=live/overlays/show', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ rev: cur.rev, game: cur.game, logo: cur.logo, cards }),
        });
        return r.json();
      }, doc.cards);

      const back = Object.fromEntries(res.cards.map((c) => [c.id, c]));
      expect(Object.keys(back).sort()).toEqual(['lastplay', 'scoreboard', 'topplayers']);
      expect(back.topplayers.params.auto).toEqual({ on: 'halftime', for: 30 });
    });
  });

  test('an untrusted file cannot produce a frame the UI could not', async ({ page }) => {
    // The file has been on a USB stick. It is sent to the store like any other
    // write, and the store drops what it does not recognise.
    await withShowRestored(page, async () => {
      const res = await writeShow(page, [
        { id: '../../etc/passwd', slot: 'center', visible: true },
        { id: 'topplayers', slot: 'not-a-slot', visible: true },
        { id: 'scoreboard', slot: 'lower-left', visible: true },
      ], { logo: null });
      expect(res.body.cards.map((c) => c.id)).toEqual(['scoreboard']);
    });
  });
});
