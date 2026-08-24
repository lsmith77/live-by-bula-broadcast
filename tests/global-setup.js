// @ts-check
/**
 * Start every run from a deliberately messy stage.
 *
 * These tests write to the same files a real broadcast reads, so the state they
 * begin with is whatever the last operator — or the last test run — left behind.
 * A test that reads state it did not set therefore passes or fails on history,
 * and eight of them did over this project's life: measuring whatever cards
 * happened to be placed, asserting a possession count without clearing the log,
 * expecting no tournament logo because no test had set one.
 *
 * Each was found the same way: something unrelated changed, and a test that had
 * been quietly asserting nothing started failing. Fixing them one at a time does
 * not stop the ninth.
 *
 * So the suite now begins from a stage that is CONFIGURED rather than empty: a
 * logo in a corner, cards placed and on air, both fixture games tracked with a
 * code, a gender ratio set, a stoppage running. Nothing here is unusual — it is
 * what a stage looks like ten minutes into a tournament. A test that cannot
 * survive it is a test that was reading somebody else's state.
 *
 * This does not replace per-test setup; it makes the absence of it visible.
 */
const BASE = process.env.BASE_URL || 'http://localhost:8080';
const GAME = Number(process.env.GAME_ID || 702);
const OTHER = Number(process.env.OTHER_GAME_ID || 703);
const ADMIN_PASS = process.env.ADMIN_PASS || '';

async function login(ctx) {
  const page = await ctx.newPage();
  await page.goto(`${BASE}/index.php?view=live/admin`);
  const form = page.locator('#login-form');
  if (await form.count()) {
    await form.locator('input[name="password"]').fill(ADMIN_PASS);
    await form.locator('input[type="submit"]').click();
    await page.waitForLoadState('networkidle');
  }
  return page;
}

module.exports = async () => {
  if (!ADMIN_PASS) {
    // Without a login the seeding endpoints answer 403, and the tests that
    // would care are skipped anyway.
    return;
  }

  const { chromium } = require('@playwright/test');
  const browser = await chromium.launch({ channel: 'chrome' });
  const ctx = await browser.newContext();
  try {
    const page = await login(ctx);

    await page.evaluate(async ({ game, other }) => {
      const post = (view, body) => fetch(`/index.php?view=live/overlays/${view}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }).then((r) => r.json());

      const show = await (await fetch('/index.php?view=live/overlays/show',
        { credentials: 'same-origin' })).json();

      // A stage mid-tournament: logo in a corner, cards placed, one on air.
      await post('show', {
        rev: show.rev,
        game: other,
        logo: 'bottom-left',
        cards: [
          { id: 'scoreboard', slot: 'lower-center', visible: true, params: {} },
          {
            id: 'lastplay',
            slot: 'with-scoreboard',
            visible: true,
            params: { auto: { on: 'goal', for: 15 } },
          },
          { id: 'summary', slot: 'center', visible: false, params: { when: 'final' } },
        ],
      });

      // Both games tracked, with everything a desk can declare.
      for (const g of [game, other]) {
        // A code that reads as seeded: when this collided with a test's
        // hard-coded "obviously wrong" value, the negative case silently became
        // a positive one.
        await post('possession', { enabled: true, game: g, code: 'SEED9', ratio1: '3MMP/4FMP' });
        for (const defence of [true, false, true]) {
          await post('possession', { game: g, score: '1-1', defence });
        }
        await post('possession', { game: g, score: '1-1', stoppage: true });
      }
    }, { game: GAME, other: OTHER });
  } finally {
    await ctx.close();
    await browser.close();
  }
};
