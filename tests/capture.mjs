/**
 * Record one game's payloads from a live instance.
 *
 *   node tests/capture.mjs --game 702 --out fixtures/payloads/hrn2026
 *   node tests/capture.mjs --game 702 --base http://localhost:8080
 *
 * WHY IT DRIVES THE PROVIDER RATHER THAN CURL
 *
 * `shared/provider.js` is what the pages read through, so recording through it
 * means the capture is *by construction* in the shape the provider returns.
 * There is no third format to keep in step, and no way for the recorder to
 * drift from the reader — they are the same code. `shared/demo.js` already
 * argues the general point: a hand-written payload drifts from the API shape,
 * and then the thing built on it proves things about a scoreboard nobody ships.
 *
 * WHAT IT WRITES
 *
 * One flat directory, one file per request, named after the request so a person
 * can read it — which matters, because the first thing anyone does with a bug
 * report is look inside. Plus `manifest.json`, which is not bookkeeping: it
 * carries the instant of capture, and without it the replayed clock is wrong by
 * however long ago the recording was taken. See `docs/STANDALONE.md` §8.
 *
 * WHAT IT DOES NOT DO
 *
 * It does not edit, redact or tidy. A capture that has been adjusted by hand is
 * no longer evidence of what Live! sends; it is a fake with extra steps. If a
 * capture must not contain real names, capture a fixture rather than a real
 * tournament — or write a separate anonymiser and be honest that its output is
 * derived rather than recorded.
 */
import { mkdirSync, writeFileSync, readFileSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire(import.meta.url);
const Provider = require('../shared/provider.js');

function arg(name, fallback = null) {
  const i = process.argv.indexOf(`--${name}`);
  return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const gameId = arg('game');
const base = arg('base', 'http://localhost:8080');
const out = arg('out', path.join('fixtures', 'payloads', String(gameId ?? 'capture')));

if (!gameId) {
  console.error('Usage: node tests/capture.mjs --game <id> [--base <url>] [--out <dir>]');
  process.exit(1);
}

const apiBase = `${base.replace(/\/$/, '')}/index.php?view=live/api`;
const api = Provider.live({ apiBase });

mkdirSync(out, { recursive: true });

const written = [];
function save(name, body) {
  writeFileSync(path.join(out, name), `${JSON.stringify(body, null, 2)}\n`);
  written.push(name);
}

/** Record one read, reporting rather than aborting when it is not available. */
async function grab(label, name, run) {
  try {
    save(name, await run());
    console.log(`  ok   ${name}`);
    return true;
  } catch (e) {
    // A missing playerevents or config is a thinner capture, not a failed one.
    console.log(`  --   ${name} (${label}: ${e.message})`);
    return false;
  }
}

// The instant matters more than anything else here: it is what lets a replayed
// clock be rebased, and it cannot be recovered afterwards.
const capturedAt = Math.floor(Date.now() / 1000);

console.log(`Capturing game ${gameId} from ${base} into ${out}/`);

const game = await api.game(gameId);
save(Provider.keyFor('games', gameId), game);
console.log(`  ok   ${Provider.keyFor('games', gameId)}`);

await grab('game list', Provider.keyFor('games'), () => api.games());
await grab('team list', Provider.keyFor('teams'), () => api.teams());
await grab('reference', Provider.keyFor('reference'), () => api.reference());
await grab('config', Provider.keyFor('config'), () => api.config());

// Both rosters, and then every player's history — bounded by the squads, which
// is around sixty files for two 28-player teams.
const sides = game.teams || {};
const teamIds = [sides.hometeam, sides.visitorteam]
  .filter(Boolean).map((t) => t.team_id).filter(Boolean);

const playerIds = new Set();
for (const id of teamIds) {
  const ok = await grab('roster', Provider.keyFor('teams', id), () => api.team(id));
  if (!ok) continue;
  const team = JSON.parse(
    // Re-read what was written rather than holding it, so the capture on disk
    // is what the player list is derived from.
    (await import('node:fs')).readFileSync(path.join(out, Provider.keyFor('teams', id)), 'utf8'),
  );
  for (const p of team.players || []) {
    if (p.player_id) playerIds.add(p.player_id);
  }
}

for (const pid of playerIds) {
  await grab('player history', Provider.keyFor('playerevents', pid), () => api.playerEvents(pid));
}

// Merged rather than overwritten, so a directory can hold several games. Each
// game keeps its OWN instant: two games captured ten minutes apart replayed
// from one timestamp would put one of their clocks ten minutes out, silently.
let manifest = { games: {} };
const manifestPath = path.join(out, 'manifest.json');
if (existsSync(manifestPath)) {
  try {
    manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
    manifest.games ||= {};
  } catch { /* a corrupt manifest is replaced, not repaired */ }
}

manifest.games[String(gameId)] = capturedAt;
// Kept for a single-game capture read by something that predates `games`.
manifest.captured_at = capturedAt;
manifest.captured_iso = new Date(capturedAt * 1000).toISOString();
manifest.season = game.seasoninfo?.season_id ?? manifest.season ?? null;
manifest.source = base;
save('manifest.json', manifest);

console.log(`\n${written.length} files. Replay with:`);
console.log(`  Provider.recorded({ base: '${out}' })`);
