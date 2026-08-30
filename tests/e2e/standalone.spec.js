// @ts-check
/**
 * The standalone front controller, exercised against PHP's built-in server.
 *
 * This is the file where getting it wrong exposes `conf/notes/` — the
 * commentary desk's prepared notes, which are notes about named people. So it
 * is tested by making the requests rather than by reading the code, and it is
 * tested against `php -S` specifically, because that server **does not read
 * `.htaccess`** and `docs/STANDALONE.md` §8 recommends it as a deployment.
 *
 * That combination was a real hole: before `app.php` grew its router block, a
 * `php -S` deployment served `conf/notes/<room>.json` to anyone who asked. The
 * tests below are the ones that would have caught it.
 *
 * Not part of `npm test`, which drives a hosted instance. Run with:
 *   npx playwright test --config tests/playwright.standalone.config.js
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const BASE = process.env.STANDALONE_URL || 'http://127.0.0.1:8099';

/** Status of a plain GET, without following redirects. */
async function status(request, path) {
  const res = await request.get(`${BASE}${path}`, { maxRedirects: 0 });
  return res.status();
}

test.describe('operator state is default closed', () => {
  test('prepared notes are never served over HTTP', async ({ request }) => {
    // The one that matters. These are notes about named players.
    expect(await status(request, '/conf/notes/DDDDD.json')).toBe(404);
    expect(await status(request, '/conf/notes/')).toBe(404);
  });

  test('only the two files the stage polls are public', async ({ request }) => {
    // show.json and possession-<game>.json are served as static assets on
    // purpose: at a one-second poll, routing them through PHP would be a
    // bootstrap per second per stage. Everything else has a PHP front door.
    expect(await status(request, '/conf/show.json')).toBe(200);
    expect(await status(request, '/conf/team-colors.json')).toBe(404);
    expect(await status(request, '/conf/lines/DDDDD.json')).toBe(404);
  });

  test('a name that merely starts like a public file is not public', async ({ request }) => {
    // The rule anchors on the full name. "show.json.bak" or a nested path that
    // ends in show.json must not inherit its exemption.
    expect(await status(request, '/conf/show.json.bak')).toBe(404);
    expect(await status(request, '/conf/notes/show.json')).toBe(404);
  });
});

test.describe('the view allow-list', () => {
  test('the pages it names are served', async ({ request }) => {
    for (const view of ['index', 'stage', 'commentator']) {
      expect(await status(request, `/app.php?view=${view}`), view).toBe(200);
    }
    // The scoreboard needs a game or a field, and says so with a 400 rather
    // than drawing an empty bug. Asserted with the parameter AND without, so
    // this stays a test of routing rather than of that rule.
    expect(await status(request, '/app.php?view=scoreboard&game=702')).toBe(200);
    expect(await status(request, '/app.php?view=scoreboard')).toBe(400);
  });

  test('a URL copied from a hosted installation still works', async ({ request }) => {
    expect(await status(request, '/app.php?view=live/overlays/stage')).toBe(200);
  });

  test('no view means the picker, not an error', async ({ request }) => {
    expect(await status(request, '/app.php')).toBe(200);
  });

  test('anything not on the list is a 404, traversal included', async ({ request }) => {
    // There is no path resolution to defend: the request never becomes part of
    // a filename. These assert that, rather than asserting a filter works.
    const denied = [
      '../../../etc/passwd',
      '../conf/LocalConfig',
      'shared/auth',
      'conf/show',
      'app',
      'nope',
      '%2e%2e%2fconf%2fnotes',
    ];
    for (const view of denied) {
      expect(await status(request, `/app.php?view=${encodeURIComponent(view)}`), view).toBe(404);
    }
  });
});

test.describe('the pages keep their own guard', () => {
  test('a direct request to a page file is refused', async ({ request }) => {
    // UO_ROUTED_VIEW is undefined on a direct hit, so each page 404s itself.
    // The router refuses .php as well, so this is two locks rather than one.
    for (const file of ['/commentator.php', '/stage.php', '/show.php', '/possession.php']) {
      expect(await status(request, file), file).toBe(404);
    }
  });

  test('shared PHP is not reachable either', async ({ request }) => {
    expect(await status(request, '/shared/auth.php')).toBe(404);
    expect(await status(request, '/shared/show.php')).toBe(404);
  });

  test('but static assets still are, or every page breaks', async ({ request }) => {
    expect(await status(request, '/shared/provider.js')).toBe(200);
    expect(await status(request, '/shared/overlay-base.css')).toBe(200);
  });
});

test.describe('a page renders from a capture, with no Live! at all', () => {
  const { hasCapture } = require('../standalone-setup.js');
  test.skip(!hasCapture(), 'no capture in fixtures/payloads/dev — run tests/capture.mjs');

  test('the commentator page shows the recorded teams and score', async ({ page }) => {
    // The whole point of milestone 2, asserted end to end: real payloads, no
    // UltiOrganizer, no database, no network. If this passes, the browser suite
    // can in principle run here too.
    await page.goto('/app.php?view=commentator&game=702');
    await expect(page.locator('.roster').first()).toBeVisible();

    // Names off the recording, not a placeholder.
    await expect(page.locator('#headHome .nm')).not.toHaveText('—');
    await expect(page.locator('#headAway .nm')).not.toHaveText('—');
    await expect(page.locator('#score')).toHaveText(/^\d+ – \d+$/);

    // A roster with people in it, which is the part that needs entity=teams.
    expect(await page.locator('.roster tbody tr').count()).toBeGreaterThan(5);
  });

  test('the clock reads the minute the capture was taken', async ({ page }) => {
    // The rebase, through the whole stack rather than in a unit test. A
    // recording of the 14th minute must not report three days.
    await page.goto('/app.php?view=scoreboard&game=702');
    const clock = page.locator('#clock, .clock').first();
    if (await clock.count()) {
      const text = (await clock.textContent()) || '';
      // Whatever it says, it must not be an hours-long figure.
      expect(text, 'a rebased clock is minutes, not days').not.toMatch(/\d{3,}:/);
    }
  });
});

test.describe('admin gating without Live!', () => {
  test('this really is a hostless tree', async ({ request }) => {
    // The assertion that gives the rest of this block its meaning. If an
    // UltiOrganizer autoloader is reachable, Auth delegates to Live! and these
    // tests silently become hosted-mode tests. The login page 404s under a
    // host and renders without one, so it reports the mode.
    expect(await status(request, '/app.php?view=login')).toBe(200);
  });

  test('changing what is on air is refused without a session', async ({ request }) => {
    const res = await request.post(`${BASE}/app.php?view=show`, {
      data: { rev: 0, cards: [] },
    });
    expect(res.status()).toBe(403);
  });

  test('reading what is on air is not gated', async ({ request }) => {
    const res = await request.get(`${BASE}/app.php?view=show`);
    expect(res.status()).toBe(200);
    expect((await res.json()).admin).toBe(false);
  });
});
