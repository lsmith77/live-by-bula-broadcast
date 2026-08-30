// @ts-check
/**
 * The recorded provider: replaying a capture as if it were Live!.
 *
 * Tested directly with a stub `fetch` over an in-memory capture, because what
 * matters is the arithmetic and the file naming, and a browser adds nothing to
 * either.
 *
 * The clock is why most of these exist. A capture is a snapshot; `timer_start`
 * is an absolute unix timestamp; the scoreboard computes `now - timer_start`.
 * Replay a Saturday recording on Tuesday without rebasing and the overlay
 * confidently reports a game that has been running for three days — which is
 * precisely the failure this project keeps naming: a graphic quietly asserting
 * something untrue, and one that looks completely normal in a screenshot.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const Provider = require('../../shared/provider.js');

const CAPTURED_AT = 1_700_000_000;
/** The game had been running 14 minutes when the capture was taken. */
const TIMER_START = CAPTURED_AT - 840;

/** A capture in memory, served by a stub fetch that records what was asked. */
function capture(files) {
  const asked = [];
  const fetchImpl = (url) => {
    asked.push(url);
    const name = String(url).split('/').pop();
    if (!(name in files)) {
      return Promise.resolve({ ok: false, status: 404, json: () => Promise.resolve(null) });
    }
    // Fresh clone per read: the rebase mutates what it is handed, and a shared
    // object would have each read shifting the previous read's result.
    const body = JSON.parse(JSON.stringify(files[name]));
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
  };
  return { fetchImpl, asked };
}

const GAME = {
  game_result: { homescore: 8, visitorscore: 6, timer_start: TIMER_START,
    timer_paused_duration: 0, timer_pause_start: 0 },
  teams: {},
};

const FILES = {
  'manifest.json': { captured_at: CAPTURED_AT, game: 702 },
  'games-702.json': GAME,
  'games.json': { games: [{ game_id: 702 }] },
  'teams-304.json': { players: [{ player_id: 1 }] },
  'reference.json': { reservations: [] },
};

/** A recorded provider reading `files`, with `now` frozen at `nowMs`. */
function reader(files, nowMs, extra = {}) {
  const c = capture(files);
  return {
    api: Provider.recorded({
      base: '/cap', fetch: c.fetchImpl, now: () => nowMs, ...extra,
    }),
    asked: c.asked,
  };
}

test.describe('reading a capture', () => {
  test('each read asks for the file named after the request', async () => {
    const { api, asked } = reader(FILES, CAPTURED_AT * 1000);
    await api.game(702);
    await api.games();
    await api.team(304);
    await api.reference();
    expect(asked.filter((u) => !u.endsWith('manifest.json'))).toEqual([
      '/cap/games-702.json', '/cap/games.json', '/cap/teams-304.json', '/cap/reference.json',
    ]);
  });

  test('the payload comes back as recorded, field for field', async () => {
    // The point of a recording is that nothing tidies it: same shape, warts
    // included, or every consumer gets a second contract to branch on.
    const { api } = reader(FILES, CAPTURED_AT * 1000);
    const games = await api.games();
    expect(games).toEqual({ games: [{ game_id: 702 }] });
  });

  test('a file the capture does not hold names itself', async () => {
    // "Not in this capture: teams-999.json" beats a parse error, because the
    // fix is to capture more rather than to debug the reader.
    const { api } = reader(FILES, CAPTURED_AT * 1000);
    await expect(api.team(999)).rejects.toThrow(/Not in this capture: teams-999\.json/);
  });

  test('a filename can never be steered by an id', async () => {
    const { asked } = reader(FILES, CAPTURED_AT * 1000);
    expect(Provider.keyFor('games', '../../etc/passwd')).toBe('games-etcpasswd.json');
    expect(Provider.keyFor('teams', '3/0?x=1')).toBe('teams-30x1.json');
    expect(asked).toEqual([]);
  });
});

test.describe('the clock, rebased', () => {
  test('a recording of the 14th minute is the 14th minute, replayed whenever', async () => {
    // Freeze is the default: the elapsed time a caller computes must be what it
    // was at capture, today and in a year.
    for (const daysLater of [0, 1, 365]) {
      const now = (CAPTURED_AT + daysLater * 86400) * 1000;
      const { api } = reader(FILES, now);
      const p = await api.game(702);
      const elapsed = Math.floor(now / 1000) - p.game_result.timer_start;
      expect(elapsed, `${daysLater} days later`).toBe(840);
    }
  });

  test('without rebasing, the same recording would claim days', async () => {
    // The failure this guards against, asserted rather than described.
    const stale = Math.floor((CAPTURED_AT + 3 * 86400)) - TIMER_START;
    expect(stale).toBeGreaterThan(86400 * 3);
  });

  test('rebase: run starts where the capture stopped, then advances', async () => {
    let now = CAPTURED_AT * 1000;
    const c = capture(FILES);
    const api = Provider.recorded({
      base: '/cap', fetch: c.fetchImpl, now: () => now, rebase: 'run',
    });

    const first = await api.game(702);
    expect(Math.floor(now / 1000) - first.game_result.timer_start).toBe(840);

    now += 60_000;
    const later = await api.game(702);
    // A minute of real time is a minute of game time — the anchor is fixed at
    // the first read rather than moving with each one.
    expect(Math.floor(now / 1000) - later.game_result.timer_start).toBe(900);
  });

  test('a clock that never started is not a clock to shift', async () => {
    // null means the scorekeeper never started it, which is a fact about the
    // game. Shifting it would manufacture a clock that never existed.
    const files = {
      ...FILES,
      'games-702.json': { game_result: { timer_start: null, timer_pause_start: 0 } },
    };
    const { api } = reader(files, (CAPTURED_AT + 86400) * 1000);
    const p = await api.game(702);
    expect(p.game_result.timer_start).toBeNull();
    expect(p.game_result.timer_pause_start).toBe(0);
  });

  test('a paused game keeps the length of its pause', async () => {
    // timer_pause_start is an instant and moves; timer_paused_duration is a
    // duration and must not.
    const files = {
      ...FILES,
      'games-702.json': {
        game_result: {
          timer_start: TIMER_START,
          timer_pause_start: CAPTURED_AT - 60,
          timer_paused_duration: 120,
        },
      },
    };
    const now = (CAPTURED_AT + 86400) * 1000;
    const { api } = reader(files, now);
    const p = await api.game(702);
    expect(Math.floor(now / 1000) - p.game_result.timer_pause_start).toBe(60);
    expect(p.game_result.timer_paused_duration).toBe(120);
  });

  test('a capture with no manifest leaves the clock alone rather than guessing', async () => {
    // An unshifted clock is wrong in a way somebody notices. A clock shifted by
    // a guessed amount is wrong in a way nobody does.
    const files = { ...FILES };
    delete files['manifest.json'];
    const { api } = reader(files, (CAPTURED_AT + 86400) * 1000);
    const p = await api.game(702);
    expect(p.game_result.timer_start).toBe(TIMER_START);
  });

  test('the manifest is read once, not per call', async () => {
    const { api, asked } = reader(FILES, CAPTURED_AT * 1000);
    await api.game(702);
    await api.game(702);
    await api.game(702);
    expect(asked.filter((u) => u.endsWith('manifest.json')).length).toBe(1);
  });
});
