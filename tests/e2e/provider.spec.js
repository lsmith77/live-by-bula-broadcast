// @ts-check
/**
 * The payload provider: what it asks for, and what it does with the answer.
 *
 * Tested directly rather than through a page (AGENTS.md) with a stub `fetch`,
 * because the two things worth pinning here are both invisible from a browser:
 * the exact URL each read builds, and the error contract — 503 that must never
 * be parsed, `{"error": "..."}` bodies, and which failures are worth retrying.
 *
 * That contract existed twice before this module (in `shared/tracking.js` and
 * again in `index.php`) and the copies had already drifted: only one of them
 * distinguished a fatal failure from a transient one, which is the difference
 * between a scoreboard that stops on a bad game id and one that retries it for
 * the length of a broadcast.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const Provider = require('../../shared/provider.js');

const BASE = '/index.php?view=live/api';

/** A fetch that records its URL and answers with whatever it was given. */
function stub(response) {
  const calls = [];
  const fn = (url, opts) => {
    calls.push({ url, opts });
    return Promise.resolve(response);
  };
  return { fn, calls };
}

const ok = (body) => ({ status: 200, ok: true, json: () => Promise.resolve(body) });
const err = (status, body) => ({
  status, ok: false, json: () => Promise.resolve(body),
});

test.describe('provider URLs', () => {
  test('each read asks for exactly one entity', async () => {
    const cases = [
      ['game', [702], '&entity=games&id=702'],
      ['games', [], '&entity=games'],
      ['team', [304], '&entity=teams&id=304'],
      ['teams', [], '&entity=teams'],
      ['playerEvents', [91], '&entity=playerevents&id=91'],
      ['reference', [], '&entity=reference'],
      ['config', [], '&entity=config'],
    ];
    for (const [method, args, expected] of cases) {
      const s = stub(ok({}));
      await Provider.live({ apiBase: BASE, fetch: s.fn })[method](...args);
      expect(s.calls[0].url, method).toBe(BASE + expected);
    }
  });

  test('ids are encoded, never concatenated raw', async () => {
    // A game id arriving from a URL is attacker-controlled in the only sense
    // that matters here: it is not necessarily a number.
    const s = stub(ok({}));
    await Provider.live({ apiBase: BASE, fetch: s.fn }).game('7 0&entity=teams');
    expect(s.calls[0].url).toBe(BASE + '&entity=games&id=7%200%26entity%3Dteams');
  });

  test('the session rides along, or a private event answers 403', async () => {
    const s = stub(ok({}));
    await Provider.live({ apiBase: BASE, fetch: s.fn }).games();
    expect(s.calls[0].opts.credentials).toBe('same-origin');
  });
});

test.describe('the API response contract', () => {
  test('a good body comes back as itself', async () => {
    const body = { games: [{ game_id: 702 }] };
    const s = stub(ok(body));
    const got = await Provider.live({ apiBase: BASE, fetch: s.fn }).games();
    expect(got).toEqual(body);
  });

  test('503 is never parsed — it is the maintenance splash, and it is HTML', async () => {
    // .json() would reject on HTML, and the message a user sees would be a
    // parser's rather than "Live! is in maintenance mode".
    let parsed = false;
    const s = stub({
      status: 503,
      ok: false,
      json: () => { parsed = true; return Promise.resolve({}); },
    });
    await expect(Provider.live({ apiBase: BASE, fetch: s.fn }).games())
      .rejects.toThrow(/maintenance mode/);
    expect(parsed, '503 body must not be parsed').toBe(false);
  });

  test('an error body is a string, not an object', async () => {
    // v3 serves {"error": "<string>"}. Reading it as an object yields
    // "[object Object]" on screen.
    const s = stub(err(400, { error: 'Unknown game' }));
    await expect(Provider.live({ apiBase: BASE, fetch: s.fn }).game(9))
      .rejects.toThrow('Unknown game');
  });

  test('a 200 carrying an error body still fails', async () => {
    // v3 does this. A caller that trusted the status code would render an
    // error object as if it were a game.
    const s = stub(ok({ error: 'Event not published' }));
    await expect(Provider.live({ apiBase: BASE, fetch: s.fn }).game(9))
      .rejects.toThrow('Event not published');
  });

  test('400 and 403 are fatal; everything else is worth retrying', async () => {
    // This is the distinction the two older copies disagreed about. A wrong
    // game id or an unpublished event will not fix itself, and a poller must
    // stop rather than spend the broadcast asking again.
    for (const [status, fatal] of [[400, true], [403, true], [500, false], [502, false]]) {
      const s = stub(err(status, {}));
      const e = await Provider.live({ apiBase: BASE, fetch: s.fn }).game(9).catch((x) => x);
      expect(e.status, `HTTP ${status} status`).toBe(status);
      expect(e.fatal, `HTTP ${status} fatal`).toBe(fatal);
    }
  });

  test('a body that is not JSON at all says so, and is not fatal', async () => {
    // A proxy error page, say. Retrying can genuinely help, and the message
    // should not be the parser's.
    const s = stub({ status: 502, ok: false, json: () => Promise.reject(new Error('bad json')) });
    const e = await Provider.live({ apiBase: BASE, fetch: s.fn }).games().catch((x) => x);
    expect(e.message).toMatch(/Unparseable response \(HTTP 502\)/);
    expect(e.fatal).toBe(false);
  });

  test('an error with no message falls back to the status', async () => {
    const s = stub(err(418, {}));
    await expect(Provider.live({ apiBase: BASE, fetch: s.fn }).games())
      .rejects.toThrow('HTTP 418');
  });
});
