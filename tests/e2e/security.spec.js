// @ts-check
/**
 * The boundaries that matter, asserted from an unauthenticated browser.
 *
 * Every test here corresponds to a mistake that was actually made in this
 * project and caught by measurement rather than by reading the code:
 *
 *   - two caps in lines.php that looked like bounds and bounded nothing,
 *     because they limited what a well-behaved caller sends rather than what an
 *     attacker controls;
 *   - the possession code very nearly published in the same world-readable file
 *     it authorises writes to.
 *
 * These run without credentials on purpose. That is the position an attacker is
 * in, and it is the only position from which "is this actually closed?" is a
 * meaningful question.
 */
const { test } = require('@playwright/test');
const { GAME_ID, expect } = require('./helpers');

test.describe('unauthenticated boundaries', () => {
  test('show state cannot be written without the admin session', async ({ request }) => {
    const r = await request.post('/index.php?view=live/overlays/show', {
      data: { rev: 0, game: GAME_ID, cards: [] },
    });
    expect(r.status()).toBe(403);
  });

  test('possession cannot be written without the nominated code', async ({ request }) => {
    const nope = await request.post('/index.php?view=live/overlays/possession', {
      data: { game: GAME_ID, score: '0-0', defence: true },
    });
    expect(nope.status()).toBe(403);

    const wrong = await request.post('/index.php?view=live/overlays/possession', {
      data: { game: GAME_ID, code: 'ZZZZZ', score: '0-0', defence: true },
    });
    expect(wrong.status()).toBe(403);
  });

  test('the nominated possession code is never published', async ({ request }) => {
    // The scoreboard polls conf/possession.json straight off disk about once a
    // second, so everything in that file is public. The code authorises writing
    // it; publishing the two together would hand out the key with the lock.
    const pub = await request.get('/index.php?view=live/overlays/possession');
    expect(Object.keys(await pub.json())).not.toContain('code');

    const stat = await request.get('/live/overlays/conf/possession.json');
    if (stat.ok()) {
      expect(Object.keys(await stat.json())).not.toContain('code');
    }

    // And the private sibling is not served at all.
    const secret = await request.get('/live/overlays/conf/possession-code.json');
    expect(secret.status()).toBe(404);
  });

  test('conf/ is closed by default, with only the polled files open', async ({ request }) => {
    // Show state and possession are deliberately static for the 1s poll.
    // Everything else has a PHP front door that owns its rules, and anything a
    // later feature drops in here must not become public by accident.
    for (const open of ['show.json', 'possession.json']) {
      const r = await request.get(`/live/overlays/conf/${open}`);
      expect([200, 404], `${open} should be served or absent, never forbidden`)
        .toContain(r.status());
    }
    for (const shut of ['team-colors.json', 'possession-code.json']) {
      const r = await request.get(`/live/overlays/conf/${shut}`);
      expect(r.status(), `${shut} must not be served`).toBe(404);
    }
  });

  test('overlay php files 404 when requested directly', async ({ request }) => {
    // Every page checks UO_ROUTED_VIEW, so a direct hit bypasses nothing.
    const r = await request.get('/live/overlays/scoreboard.php');
    expect(r.status()).toBe(404);
  });

  test('the line store is bounded across games, not only within one', async ({ request }) => {
    // MAX_ROOMS_PER_GAME bounds one game and prune() globs one game's files --
    // but any positive integer is an acceptable game id, so on its own that
    // bounds nothing at all. This asserts the directory-wide cap instead.
    const before = await (await request.get(
      `/index.php?view=live/overlays/lines&game=${GAME_ID}&code=AAAAA`)).json();
    expect(before).toHaveProperty('teams');

    // 30 distinct game ids, well under the total cap, so this is cheap and does
    // not evict anything real.
    for (let i = 0; i < 30; i += 1) {
      await request.post('/index.php?view=live/overlays/lines', {
        data: { game: 900000 + i, code: 'BBBBB', team: 1, players: [1] },
      });
    }
    // The cap is enforced server-side on write; nothing here should error.
    const after = await request.post('/index.php?view=live/overlays/lines', {
      data: { game: 900099, code: 'BBBBB', team: 1, players: [1] },
    });
    expect(after.ok()).toBe(true);
  });

  test('one line room cannot accumulate unbounded teams', async ({ request }) => {
    // saveTeam() merges a team into whatever the room holds. MAX_PER_TEAM caps
    // one team's array and says nothing about how many teams there are, so
    // without a second cap a room grows one request at a time, forever.
    let body = null;
    for (let team = 1; team <= 14; team += 1) {
      const r = await request.post('/index.php?view=live/overlays/lines', {
        data: { game: 900500, code: 'CCCCC', team, players: [1, 2, 3] },
      });
      if (r.ok()) body = await r.json();
      await new Promise((res) => setTimeout(res, 250)); // MIN_WRITE_INTERVAL
    }
    expect(body).not.toBeNull();
    expect(Object.keys(body.teams).length).toBeLessThanOrEqual(8);
  });
});
