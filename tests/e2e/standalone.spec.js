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

  test('the short URLs a switcher would be typing', async ({ request }) => {
    // Not a test convenience: these exist because typing
    // index.php?view=live/overlays/scoreboard&game=702 on a switcher's
    // on-screen keyboard is the problem they were introduced to solve, and a
    // standalone installation has it too. Apache rewrites them internally;
    // here they redirect, because filter_input reads the original request.
    const cases = [
      ['/c/702', 'commentator'], ['/s/702', 'scoreboard'], ['/s/702/green', 'scoreboard'],
      ['/s/702/overlay', 'stage'], ['/s/stage', 'stage'], ['/s/', 'index'],
    ];
    for (const [url, view] of cases) {
      const res = await request.get(url, { maxRedirects: 0 });
      expect(res.status(), url).toBe(302);
      expect(res.headers().location, url).toContain(`view=${view}`);
    }
  });

  test('a short URL that matches nothing is not the picker', async ({ request }) => {
    // It fell through to the dispatcher, which defaults to index — so /s/999x
    // answered 200 with a page that had nothing to do with what was asked.
    for (const url of ['/s/999x', '/s/702/notacolour', '/c/abc']) {
      expect(await status(request, url), url).toBe(404);
    }
  });

  test('extra parameters survive a short URL, as [QSA] does', async ({ request }) => {
    const res = await request.get('/s/702?debug=1', { maxRedirects: 0 });
    expect(res.headers().location).toMatch(/debug=1/);
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

  test('the scoreboard draws the recorded game', async ({ page }) => {
    // The scoreboard is the one overlay that polls through OverlayDataClient
    // rather than calling the provider directly, and it was therefore the one
    // page still looking for an API that is not there — it drew "Unparseable
    // response (HTTP 404)" over the canvas while every other page was fine.
    const errors = [];
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.url()); });

    await page.goto('/app.php?view=scoreboard&game=702');
    await expect(page.locator('#homeName, .team .name').first()).toBeVisible();

    const text = await page.locator('body').innerText();
    expect(text, 'the recorded teams').toMatch(/MOSQUITOS|LEAMINGTON/i);
    expect(text, 'no diagnostics on the canvas').not.toMatch(/Unparseable|No game data|Invalid ID/i);
    expect(errors.filter((u) => u.includes('view=live/api')), 'no API calls').toEqual([]);
  });

  test('every page loads without reaching for an API that is not there', async ({ page }) => {
    for (const url of ['/app.php?view=index', '/app.php?view=stage',
      '/app.php?view=commentator&game=702', '/app.php?view=scoreboard&game=702']) {
      const api = [];
      page.removeAllListeners('response');
      page.on('response', (r) => { if (r.url().includes('view=live/api')) api.push(r.url()); });
      await page.goto(url);
      await page.waitForLoadState('networkidle');
      expect(api, url).toEqual([]);
    }
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

test.describe('the standalone login', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  /** Sign in through the page a person would use, and keep the cookies. */
  async function signIn(page, password) {
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(password);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
  }

  test('the right password opens the door, and the wrong one does not', async ({ page }) => {
    // Auth::attempt() is the one piece of security code this project owns
    // rather than borrows from Live!, and hosted it can never be exercised —
    // those tests skip without ADMIN_PASS. Here the password is ours.
    await signIn(page, 'not the password');
    await expect(page.locator('.msg.bad')).toContainText('was not accepted');

    await signIn(page, ADMIN_PASSWORD);
    await expect(page.locator('.msg.good')).toContainText('Logged in');
  });

  test('a session earned at the login is honoured by the endpoints', async ({ page }) => {
    // The whole point: signing in must actually let this browser change what is
    // on air. A session key that varied between requests would pass the test
    // above and fail this one.
    await signIn(page, ADMIN_PASSWORD);

    const before = await page.request.get('/app.php?view=show');
    expect((await before.json()).admin).toBe(true);

    const res = await page.request.post('/app.php?view=show', {
      data: { rev: 0, cards: [] },
    });
    expect(res.status(), 'an admin may write').not.toBe(403);
  });

  test('the session survives arriving by a different URL', async ({ page }) => {
    // The key is derived from where this installation lives, not from
    // SCRIPT_NAME — which the built-in server reports differently for `/` and
    // for `/app.php`, and which would therefore drop the login on one of them.
    await signIn(page, ADMIN_PASSWORD);
    for (const url of ['/app.php?view=show', '/?view=show']) {
      const res = await page.request.get(url);
      expect((await res.json()).admin, url).toBe(true);
    }
  });

  test('signing out closes it again', async ({ page }) => {
    await signIn(page, ADMIN_PASSWORD);
    await page.locator('button[name=logout]').click();
    await page.waitForLoadState('networkidle');
    const res = await page.request.get('/app.php?view=show');
    expect((await res.json()).admin).toBe(false);
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
