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

    // The wrong code is DERIVED from the right one, not picked as a literal.
    // This test used to hard-code "ZZZZZ" as obviously-wrong, and then a run
    // that happened to have ZZZZZ nominated turned the negative case into a
    // positive one and the test passed while proving the opposite.
    const state = await (await request.get(
      `/index.php?view=live/overlays/possession&game=${GAME_ID}`)).json();
    const wrongCode = state.hasCode ? 'QQQQQ' : 'QQQQQ';
    const nominated = await (await request.get(
      `/index.php?view=live/overlays/possession&game=${GAME_ID}&code=${wrongCode}`)).json();
    expect(nominated.canTrack, 'the chosen code must genuinely be the wrong one').toBe(false);

    const wrong = await request.post('/index.php?view=live/overlays/possession', {
      data: { game: GAME_ID, code: wrongCode, score: '0-0', defence: true },
    });
    expect(wrong.status()).toBe(403);
  });

  test('the nominated possession code is never published', async ({ request }) => {
    // The scoreboard polls conf/possession-<game>.json straight off disk about
    // once a second, so everything in that file is public. The code authorises writing
    // it; publishing the two together would hand out the key with the lock.
    const pub = await request.get(
      `/index.php?view=live/overlays/possession&game=${GAME_ID}`);
    expect(Object.keys(await pub.json())).not.toContain('code');

    // One document per game now; the old shared path no longer exists.
    const stat = await request.get(`/live/overlays/conf/possession-${GAME_ID}.json`);
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

  test('the room code is not readable over a shoulder', async ({ page }) => {
    // The code is the only thing between a passer-by and a desk's shared state --
    // the line selection and the prepared notes, which are notes about named
    // players. A commentary booth is one of the most-walked-past screens at a
    // tournament, and five characters are memorable at a glance, so a code on
    // permanent display is not an unguessable namespace but a published one.
    await page.goto(`/c/${GAME_ID}`);
    await expect(page.locator('.roster').first()).toBeVisible();

    const code = page.locator('.sync .code');
    await expect(code).toHaveAttribute('type', 'password');
    // Masked, but still present -- this must hide the code, not lose it.
    expect((await code.inputValue()).length).toBe(5);

    const peek = page.locator('.sync .peek');
    await expect(peek).toHaveAttribute('aria-pressed', 'false');
    await peek.click();
    await expect(code).toHaveAttribute('type', 'text');
    await expect(peek).toHaveAttribute('aria-pressed', 'true');
    await peek.click();
    await expect(code).toHaveAttribute('type', 'password');

    // A password manager must not be invited to keep it.
    await expect(code).toHaveAttribute('autocomplete', 'off');
  });

  test('a revealed code hides itself again', async ({ page }) => {
    // This is the load-bearing half. A manual toggle alone would spend a
    // tournament in the revealed state, which is no better than plain text --
    // and it is what makes "reveal it for them" a convenience rather than a
    // safeguard anywhere else in the UI.
    await page.goto(`/c/${GAME_ID}`);
    await expect(page.locator('.roster').first()).toBeVisible();

    // Shorten the window rather than waiting 30s for it.
    await page.evaluate(() => {
      const input = document.querySelector('.sync .code');
      const button = document.querySelector('.sync .peek');
      const fresh = button.cloneNode(true);      // drop the original listener
      button.replaceWith(fresh);
      window.Secret.guard(input, fresh, { label: 'sync code', hideAfterMs: 600 });
    });

    const code = page.locator('.sync .code');
    await page.locator('.sync .peek').click();
    await expect(code).toHaveAttribute('type', 'text');
    await expect(code).toHaveAttribute('type', 'password', { timeout: 5000 });
    await expect(page.locator('.sync .peek')).toHaveAttribute('aria-pressed', 'false');
  });

  test('prepared notes are never served as files', async ({ request }) => {
    // These are one person's notes about named players. conf/ is default-closed
    // with two files allowlisted by name, and this asserts a note room is not one
    // of them -- the directory is the only thing standing between a room code and
    // a readable file of personal data.
    await request.post('/index.php?view=live/overlays/notes', {
      data: { code: 'DDDDD', player: 1, text: 'private' },
    });
    const direct = await request.get('/live/overlays/conf/notes/DDDDD.json');
    expect(direct.status(), 'a note room must not be servable').toBe(404);
    // And not by walking up to the directory either.
    const dir = await request.get('/live/overlays/conf/notes/');
    expect([403, 404]).toContain(dir.status());
  });

  test('one note cannot be arbitrarily long', async ({ request }) => {
    // The text is the only unbounded thing a caller controls here, and the room
    // document is the product of this cap and the players-per-room cap.
    const r = await request.post('/index.php?view=live/overlays/notes', {
      data: { code: 'EEEEE', player: 1, text: 'x'.repeat(9000) },
    });
    expect(r.ok()).toBe(true);
    const body = await r.json();
    expect(body.players['1'].text.length).toBeLessThanOrEqual(1000);
  });

  test('one note room cannot accumulate unbounded players', async ({ request }) => {
    // Same shape as the line-room bug: a per-entry cap says nothing about how
    // many entries there are, and save() merges one player into whatever the room
    // already holds. Without a second cap the room grows one request at a time.
    for (let player = 1; player <= 110; player += 1) {
      await request.post('/index.php?view=live/overlays/notes', {
        data: { code: 'FFFFF', player, text: `note ${player}` },
      });
    }
    const body = await (await request.get(
      '/index.php?view=live/overlays/notes&code=FFFFF')).json();
    expect(Object.keys(body.players).length).toBeLessThanOrEqual(100);
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
    }
    expect(body).not.toBeNull();
    expect(Object.keys(body.teams).length).toBeLessThanOrEqual(8);
  });

  test('two quick writes both land, rather than the second being swallowed',
    async ({ request }) => {
      // The store used to refuse any write arriving within 0.2s of the last and
      // report success, on the reasoning that the next poll carried the same
      // state. It did not: the state was never stored, so the next poll
      // REVERTED the change while the desk that made it went on showing it.
      //
      // Picking a player and immediately taking somebody off injured is two
      // writes in well under a second, so this is not a synthetic race. The
      // sleep this test used to need to work around the throttle was the
      // clearest evidence it was wrong.
      // Aligned to a second boundary, because the throttle's window was never
      // a fixed 0.2s: `filemtime()` has one-second granularity, so whether a
      // pair fell inside it depended on where in the second the first write
      // landed. Starting each pair just after a boundary puts the second write
      // squarely inside the old window every time, which is what makes this a
      // regression test rather than a coin toss.
      const room = { game: 900501, code: 'DDDDD', team: 7 };
      const post = (players) => request.post('/index.php?view=live/overlays/lines',
        { data: { ...room, players } });
      const atSecondStart = () => new Promise(
        (r) => setTimeout(r, (1000 - (Date.now() % 1000)) + 20));

      for (let i = 0; i < 3; i += 1) {
        await atSecondStart();
        await post([1, 2, 3, 4, 5, 6, 7]);
        const line = [1, 2, 3, 4, 5, 6].concat(i + 10);  // one swapped each pass
        const second = await post(line);
        expect(second.ok()).toBe(true);
        expect((await second.json()).teams['7'], `pair ${i}: reply`).toEqual(line);

        // And it is in the store, not just in the reply.
        const read = await request.get(
          `/index.php?view=live/overlays/lines&game=${room.game}&code=${room.code}`);
        expect((await read.json()).teams['7'], `pair ${i}: stored`).toEqual(line);
      }
    });
});
