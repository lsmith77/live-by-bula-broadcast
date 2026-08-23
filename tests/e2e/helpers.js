// @ts-check
/**
 * Shared helpers for the overlay end-to-end tests.
 *
 * Two things live here that the specs should never repeat: logging in as the
 * Live! admin, and restoring whatever state a test changed. Both matter more
 * than usual because these tests write to the same files a real broadcast reads
 * — a suite that left show state altered would change what is on air.
 */
const { expect } = require('@playwright/test');

const GAME_ID = Number(process.env.GAME_ID || 702);
const ADMIN_PASS = process.env.ADMIN_PASS || '';

/** Log the browser context in as the Live! admin, or skip the test. */
async function loginAsAdmin(page, test) {
  test.skip(!ADMIN_PASS, 'ADMIN_PASS is not set; skipping the tests that change what is on air');
  await page.goto('/index.php?view=live/admin');
  const form = page.locator('#login-form');
  if (await form.count()) {
    await form.locator('input[name="password"]').fill(ADMIN_PASS);
    await form.locator('input[type="submit"]').click();
    await page.waitForLoadState('networkidle');
  }
}

/** Current show state, straight from the endpoint. */
async function readShow(page) {
  return page.evaluate(async () => {
    const r = await fetch('/index.php?view=live/overlays/show', { credentials: 'same-origin' });
    return r.json();
  });
}

/** Replace show state wholesale, handling the optimistic-lock rev for you. */
async function writeShow(page, cards, extra = {}) {
  return page.evaluate(async ({ cards, extra }) => {
    const cur = await (await fetch('/index.php?view=live/overlays/show',
      { credentials: 'same-origin' })).json();
    const r = await fetch('/index.php?view=live/overlays/show', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rev: cur.rev, game: cur.game, logo: cur.logo, cards, ...extra }),
    });
    return { status: r.status, body: await r.json() };
  }, { cards, extra });
}

async function readPossession(page) {
  return page.evaluate(async () => {
    const r = await fetch('/index.php?view=live/overlays/possession', { credentials: 'same-origin' });
    return r.json();
  });
}

async function writePossession(page, change) {
  return page.evaluate(async (change) => {
    const r = await fetch('/index.php?view=live/overlays/possession', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(change),
    });
    return { status: r.status, body: await r.json() };
  }, change);
}

/**
 * Run a body with show state restored afterwards, pass or fail.
 *
 * Not a nicety: without it a failing test leaves an operator's stage rearranged,
 * and the next run starts from a state nobody chose.
 */
async function withShowRestored(page, body) {
  const before = await readShow(page);
  try {
    await body(before);
  } finally {
    await writeShow(page, before.cards, { game: before.game, logo: before.logo });
  }
}

/** WCAG relative luminance contrast between an element's text and its background. */
async function contrastOf(page, selector) {
  return page.evaluate((sel) => {
    const lum = (c) => {
      const s = c.map((v) => {
        v /= 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
      });
      return 0.2126 * s[0] + 0.7152 * s[1] + 0.0722 * s[2];
    };
    const parse = (s) => (s.match(/[\d.]+/g) || [0, 0, 0]).map(Number).slice(0, 3);
    const el = document.querySelector(sel);
    if (!el) return null;
    let n = el;
    let bg = null;
    while (n && !bg) {
      const b = getComputedStyle(n).backgroundColor;
      if (b && !/rgba\(0, 0, 0, 0\)|transparent/.test(b)) bg = parse(b);
      n = n.parentElement;
    }
    if (!bg) bg = parse(getComputedStyle(document.body).backgroundColor);
    const fg = lum(parse(getComputedStyle(el).color));
    const b = lum(bg);
    return Math.round(((Math.max(fg, b) + 0.05) / (Math.min(fg, b) + 0.05)) * 100) / 100;
  }, selector);
}

module.exports = {
  GAME_ID,
  ADMIN_PASS,
  loginAsAdmin,
  readShow,
  writeShow,
  readPossession,
  writePossession,
  withShowRestored,
  contrastOf,
  expect,
};
