// @ts-check
/**
 * The broadcast surfaces: scoreboard and stage.
 *
 * Everything here is a geometry or state assertion, because that is how this
 * code has actually failed. The comments name the specific regression each test
 * exists to catch, so nobody deletes one for looking trivial.
 */
const { test } = require('@playwright/test');
const {
  GAME_ID, expect, loginAsAdmin, readShow, writeShow, withShowRestored,
} = require('./helpers');

test.describe('scoreboard', () => {
  test('renders a live game with a score and no diagnostics on the canvas', async ({ page }) => {
    await page.goto(`/s/${GAME_ID}`);
    await expect(page.locator('#scoreboard')).toBeVisible();

    const score = await page.locator('#scoreboard').innerText();
    expect(score).toMatch(/\d+/);

    // Diagnostics belong on a laptop, never composited over live video. A valid
    // game must produce none. (The wrong-id case is the open risk in
    // docs/STUDIO.md section 11 and is asserted separately below.)
    await expect(page.locator('#errorDisplay')).toBeHidden();
  });

  test('a bad game id is visibly an error, not a blank frame', async ({ page }) => {
    // Documents current behaviour rather than blessing it: the error IS painted
    // on the canvas today. If that is ever gated behind ?debug=1, this test
    // should change with it -- and failing loudly is the point.
    await page.goto('/s/999999');
    await expect(page.locator('#errorDisplay')).toBeVisible();
    await expect(page.locator('#scoreboard')).toBeHidden();
  });

  test('the page background is transparent by default and keyed on request', async ({ page }) => {
    await page.goto(`/s/${GAME_ID}`);
    const bare = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    expect(bare).toMatch(/rgba\(0, 0, 0, 0\)|transparent/);

    await page.goto(`/s/${GAME_ID}/green`);
    const keyed = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    expect(keyed).toBe('rgb(0, 177, 64)');
  });

  test('team names are fitted, not clipped', async ({ page }) => {
    // fitName() once ran while the board was display:none, where scrollWidth
    // and clientWidth are both 0, so it silently did nothing.
    await page.goto(`/s/${GAME_ID}`);
    await expect(page.locator('#scoreboard')).toBeVisible();
    const overflow = await page.evaluate(() =>
      ['#homeName', '#awayName'].map((s) => {
        const el = document.querySelector(s);
        return el ? el.scrollWidth - el.clientWidth : 0;
      }));
    // A pixel of rounding is fine; a clipped name is not.
    overflow.forEach((px) => expect(px).toBeLessThanOrEqual(1));
  });
});

test.describe('stage', () => {
  test('scales its 1920x1080 canvas to fit a smaller window', async ({ page }) => {
    // The stage once rendered its bottom band below the fold in any window
    // shorter than 1080px, which looked like "nothing is showing" and was
    // misdiagnosed twice as browser caching.
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.goto(`/s/${GAME_ID}/overlay`);
    await page.waitForTimeout(2500);

    const fits = await page.evaluate(() => {
      const c = document.querySelector('.stage-canvas');
      if (!c) return null;
      const r = c.getBoundingClientRect();
      return { right: r.right, bottom: r.bottom, w: innerWidth, h: innerHeight };
    });
    expect(fits).not.toBeNull();
    expect(fits.right).toBeLessThanOrEqual(fits.w + 1);
    expect(fits.bottom).toBeLessThanOrEqual(fits.h + 1);
  });

  test('placed cards are mounted even while off air, so revealing is instant', async ({ page }) => {
    // The arm/show split: a card that mounted only when switched on would pop in
    // half-drawn, on air. Mounted must exceed or equal what is painting.
    //
    // Sets up its own cards. Read as it was, this measured whatever the stage
    // happened to be configured with, so it failed the moment somebody cleared
    // the stage — and proved nothing whenever the ambient state was rich.
    await loginAsAdmin(page, test);
    await page.goto('/s/');
    const before = await readShow(page);
    await writeShow(page, [
      { id: 'scoreboard', slot: 'lower-left', visible: true, params: {} },
      { id: 'topplayers', slot: 'center', visible: false, params: {} },
    ], { game: GAME_ID });

    await page.goto(`/s/${GAME_ID}/overlay`);
    await page.waitForTimeout(2500);
    const counts = await page.evaluate(() => ({
      mounted: document.querySelectorAll('.mount').length,
      shown: document.querySelectorAll('.mount.shown').length,
    }));
    await writeShow(page, before.cards, { game: before.game, logo: before.logo });

    expect(counts.mounted).toBeGreaterThan(0);
    expect(counts.mounted).toBeGreaterThanOrEqual(counts.shown);
  });

  test('the tournament logo reserves its corner and nothing else', async ({ page }) => {
    // The logo does not move out of the way — it owns its corner, and the store
    // refuses to place a card there. The centre of the same edge stays usable
    // because the logo is sized to sit flush beside the widest possible bug.
    await loginAsAdmin(page, test);
    await page.goto('/s/');
    const before = await readShow(page);
    try {
      const corner = await writeShow(page, [
        { id: 'scoreboard', slot: 'lower-left', visible: true, params: {} },
      ], { game: GAME_ID, logo: 'bottom-left' });
      expect(corner.body.cards).toHaveLength(0);

      const centre = await writeShow(page, [
        { id: 'scoreboard', slot: 'lower-center', visible: true, params: {} },
      ], { game: GAME_ID, logo: 'bottom-left' });
      expect(centre.body.cards.map((c) => c.slot)).toEqual(['lower-center']);

      // And the two must actually clear each other on the canvas.
      await page.goto(`/s/${GAME_ID}/overlay`);
      await expect(page.locator('.tourney-logo.shown')).toBeVisible({ timeout: 15000 });
      const gap = await page.evaluate(() => {
        const canvas = document.querySelector('.stage-canvas');
        const cr = canvas.getBoundingClientRect();
        const scale = (cr.width / 1920) || 1;
        const toCanvas = (el) => {
          const r = el.getBoundingClientRect();
          return { x: (r.left - cr.left) / scale, right: (r.right - cr.left) / scale };
        };
        const logo = toCanvas(document.querySelector('.tourney-logo.shown'));
        const frame = document.querySelector('.mount.shown iframe');
        const f = toCanvas(frame);
        const b = frame.contentDocument.getElementById('scoreboard').getBoundingClientRect();
        return Math.round((f.x + b.left) - logo.right);
      });
      expect(gap).toBeGreaterThanOrEqual(0);
    } finally {
      await writeShow(page, before.cards, { game: before.game, logo: before.logo });
    }
  });

  test('no two visible cards overlap', async ({ page }) => {
    // The companion card once sat 22px on top of the scoreboard because the
    // bug's height was hard-coded at 138px when it was really 160px. It is
    // measured now, but the invariant is what matters: nothing overlaps.
    //
    // Sets up its own pair rather than measuring whatever is on air: read as it
    // was, this passed or failed on leftover operator state, and once quietly
    // stopped testing anything at all when a session left one card showing.
    await loginAsAdmin(page, test);
    await page.goto('/s/');
    const before = await require('./helpers').readShow(page);
    await writeShow(page, [
      { id: 'scoreboard', slot: 'lower-left', visible: true, params: {} },
      { id: 'lastplay', slot: 'with-scoreboard', visible: true, params: {} },
    ], { game: GAME_ID });

    await page.goto(`/s/${GAME_ID}/overlay`);
    await page.waitForTimeout(3000);

    // Measure DRAWN CONTENT, not mount hosts. A framed card is an iframe that
    // spans the whole canvas with the graphic painted somewhere inside it, so
    // comparing host boxes says every framed card overlaps everything -- which
    // is what the first version of this test wrongly reported.
    const boxes = await page.evaluate(() => {
      // EVERY box in canvas coordinates, and that is the whole point.
      //
      // `.stage-canvas` is CSS-transformed to fit the window, so an element
      // inside it reports scaled viewport pixels, while an element inside a
      // card's iframe reports its own untransformed document's pixels — which
      // are canvas pixels. Adding one to the other, as this test used to, is
      // meaningless anywhere except a window exactly 1920 CSS px wide. The
      // tournament logo shipped on top of the scoreboard for exactly that
      // reason, and this test could not see it.
      const canvas = document.querySelector('.stage-canvas');
      const cr = canvas.getBoundingClientRect();
      const scale = (cr.width / 1920) || 1;
      const toCanvas = (el) => {
        const r = el.getBoundingClientRect();
        return {
          x: (r.left - cr.left) / scale, y: (r.top - cr.top) / scale,
          w: r.width / scale, h: r.height / scale,
        };
      };

      const out = [];
      document.querySelectorAll('.mount.shown').forEach((m) => {
        const frame = m.querySelector('iframe');
        if (frame) {
          const doc = frame.contentDocument;
          const board = doc && doc.getElementById('scoreboard');
          if (!board) return;
          const f = toCanvas(frame);
          const b = board.getBoundingClientRect();
          out.push({ label: 'scoreboard', x: f.x + b.left, y: f.y + b.top, w: b.width, h: b.height });
          return;
        }
        const r = toCanvas(m);
        if (r.w > 0 && r.h > 0) out.push({ label: m.parentElement.className, ...r });
      });

      // The logo is not a card, and is exactly what was overlapping.
      const logo = document.querySelector('.tourney-logo.shown');
      if (logo) out.push({ label: 'tournament logo', ...toCanvas(logo) });
      return out;
    });
    await writeShow(page, before.cards, { game: before.game, logo: before.logo });

    expect(boxes.length).toBeGreaterThan(1);

    for (let i = 0; i < boxes.length; i += 1) {
      for (let j = i + 1; j < boxes.length; j += 1) {
        const a = boxes[i];
        const b = boxes[j];
        const overlap = a.x < b.x + b.w && b.x < a.x + a.w
          && a.y < b.y + b.h && b.y < a.y + a.h;
        expect(overlap, `${a.label} overlaps ${b.label}`).toBe(false);
      }
    }
  });
});

test.describe('self-test', () => {
  test('every diagnostic panel moves independently', async ({ page }) => {
    // The page exists to tell an operator WHICH layer a switcher is failing to
    // run. If its own panels stop moving, it diagnoses nothing.
    await page.goto('/index.php?view=live/overlays/tests/selftest');
    // #wall is the JS timer and #raf is requestAnimationFrame -- two different
    // layers, which is the entire point of the page.
    const read = () => page.evaluate(() =>
      ['#wall', '#raf'].map((s) => (document.querySelector(s) || {}).textContent || ''));
    const first = await read();
    await page.waitForTimeout(2500);
    const second = await read();
    expect(first.every((v) => v !== '')).toBe(true);
    expect(second[0]).not.toBe(first[0]);
    expect(second[1]).not.toBe(first[1]);
  });
});
