// @ts-check
/**
 * Generates the images in docs/images/ for the README.
 *
 * Not a test -- it asserts almost nothing and it writes files into the working
 * tree. It lives in the Playwright suite anyway because it needs exactly what
 * the tests need: a real browser, a real dev instance, and the same login and
 * state-restoration helpers. Keeping a second harness alive for screenshots
 * would guarantee the screenshots drift from what the tests exercise.
 *
 *   npm run shots
 *
 * Everything it changes is put back afterwards, because the instance it borrows
 * may be the one an operator is using.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test } = require('@playwright/test');
const { GAME_ID, loginAsAdmin, readShow, writeShow, writePossession, expect } = require('./helpers');

const OUT = path.join(__dirname, '..', '..', 'docs', 'images');
fs.mkdirSync(OUT, { recursive: true });

/** A scoreboard on a flat backdrop, so a transparent PNG is not invisible on GitHub. */
const BACKDROP = '1d2b3a';

/**
 * The tightest box containing everything the bug actually draws.
 *
 * `.overlay-container` is a full-canvas positioning wrapper, so clipping to it
 * yields 1920x1080 of mostly empty backdrop with a scoreboard in one corner.
 * The graphic is the union of its visible children -- the callout tab, the
 * board, the ribbon -- which is what a reader wants to see.
 */
async function bugBox(page, pad = 18) {
  const box = await page.evaluate((pad) => {
    const root = document.querySelector('.overlay-container');
    if (!root) return null;
    let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
    root.querySelectorAll('*').forEach((el) => {
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return;
      const r = el.getBoundingClientRect();
      if (r.width < 2 || r.height < 2) return;
      x1 = Math.min(x1, r.left); y1 = Math.min(y1, r.top);
      x2 = Math.max(x2, r.right); y2 = Math.max(y2, r.bottom);
    });
    if (!isFinite(x1)) return null;
    return {
      x: Math.max(0, x1 - pad), y: Math.max(0, y1 - pad),
      width: Math.min(innerWidth, x2 + pad) - Math.max(0, x1 - pad),
      height: Math.min(innerHeight, y2 + pad) - Math.max(0, y1 - pad),
    };
  }, pad);
  return box;
}

test.describe.configure({ mode: 'serial' });

test('scoreboard bug', async ({ page }) => {
  await page.goto(`/s/${GAME_ID}/${BACKDROP}`);
  await expect(page.locator('#scoreboard')).toBeVisible();
  await page.waitForTimeout(1200);
  await page.screenshot({ path: path.join(OUT, 'scoreboard.png'), clip: await bugBox(page) });
});

test('commentator, daylight', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 980 });
  await page.goto(`/c/${GAME_ID}`);
  await expect(page.locator('.roster').first()).toBeVisible();
  await page.waitForTimeout(900);
  await page.screenshot({ path: path.join(OUT, 'commentator-daylight.png') });
});

test('commentator, night', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 980 });
  await page.goto(`/c/${GAME_ID}`);
  await expect(page.locator('.roster').first()).toBeVisible();
  await page.locator('#themeBtn').click();
  await page.waitForTimeout(500);
  await page.screenshot({ path: path.join(OUT, 'commentator-night.png') });
});

test('commentator, player sheet', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 980 });
  await page.goto(`/c/${GAME_ID}`);
  // A player with a scoring history, so the game-by-game section has content.
  const named = page.locator('.roster td.who button', { hasText: 'Ace' }).first();
  const target = (await named.count()) ? named : page.locator('.roster td.who button').first();
  await target.click();
  await page.waitForTimeout(1800);
  await page.locator('#sheetCard').screenshot({ path: path.join(OUT, 'player-sheet.png') });
});

test('studio', async ({ page }) => {
  await loginAsAdmin(page, test);
  await page.setViewportSize({ width: 1440, height: 1100 });
  await page.goto('/s/');
  await expect(page.locator('.cardrow').first()).toBeVisible();
  await page.waitForTimeout(900);
  await page.screenshot({ path: path.join(OUT, 'studio.png'), fullPage: false });
});

/**
 * An animated GIF of the scoreboard reacting to a goal.
 *
 * Frames are captured from ?demo=1 rather than by scoring into the database:
 * the demo walks every display state from one real payload, which is both
 * reproducible and the only way to show a running clock without a live game.
 *
 * ffmpeg is optional. Without it the frames are still written and the test says
 * so rather than failing -- a missing GIF is not a broken build.
 */
test('scoreboard animation', async ({ page }) => {
  const frames = path.join(OUT, '.frames');
  fs.rmSync(frames, { recursive: true, force: true });
  fs.mkdirSync(frames, { recursive: true });

  await page.goto(`/s/${GAME_ID}/${BACKDROP}?demo=1`);
  await expect(page.locator('#scoreboard')).toBeVisible();

  // Fixed for the whole run: a per-frame box would jitter as the callout tab
  // appears and disappears, and a GIF that resizes mid-loop is unreadable.
  await page.waitForTimeout(1500);
  const box = await bugBox(page, 24);
  const shots = 48;
  for (let i = 0; i < shots; i += 1) {
    await page.screenshot({
      path: path.join(frames, `f${String(i).padStart(3, '0')}.png`),
      clip: box,
    });
    await page.waitForTimeout(250);
  }

  try {
    execFileSync('ffmpeg', [
      '-y', '-framerate', '4', '-i', path.join(frames, 'f%03d.png'),
      '-vf', 'scale=640:-1:flags=lanczos,split[a][b];[a]palettegen=stats_mode=diff[p];[b][p]paletteuse=dither=bayer',
      '-loop', '0', path.join(OUT, 'scoreboard.gif'),
    ], { stdio: 'pipe' });
    fs.rmSync(frames, { recursive: true, force: true });
    console.log('wrote docs/images/scoreboard.gif');
  } catch (e) {
    console.log('ffmpeg unavailable or failed; frames left in docs/images/.frames');
  }
});

test('restore whatever the shots disturbed', async ({ page }) => {
  await loginAsAdmin(page, test);
  await page.goto('/s/');
  // The shots above only read, but the login and any stray click are enough
  // reason to leave the instance provably where it was found.
  const state = await readShow(page);
  expect(state).toHaveProperty('cards');
  await writePossession(page, { enabled: false, code: null });
});
