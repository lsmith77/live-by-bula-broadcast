/**
 * A committed capture is complete and internally consistent.
 *
 * `fixtures/payloads/dev` is a recording of the dev instance, and the standalone
 * suite renders real pages from it. That makes it a dependency, and a
 * dependency nothing checks: the recording cannot notice that
 * `fixtures/dev-fixture.sql` changed underneath it, and a capture missing a
 * roster fails as "Loading…" forever rather than as an error.
 *
 * This cannot verify the capture is CURRENT — that needs a live instance, which
 * CI does not have, and is why `docs/STANDALONE.md` §7b lists staleness as an
 * open gap. What it can prove is that the recording is whole: every game the
 * manifest claims is present, every team those games name is recorded, and
 * every player on those rosters has the history a player sheet will ask for.
 *
 *   node tests/capture-check.mjs [dir]
 */
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = process.argv[2]
  || path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'fixtures', 'payloads', 'dev');

if (!existsSync(dir)) {
  console.log(`No capture at ${dir} — nothing to check.`);
  process.exit(0);
}

const read = (name) => JSON.parse(readFileSync(path.join(dir, name), 'utf8'));
const has = (name) => existsSync(path.join(dir, name));

let problems = 0;
const bad = (msg) => { console.error(`FAIL ${msg}`); problems += 1; };

if (!has('manifest.json')) {
  bad('manifest.json is missing — without it the replayed clock cannot be rebased');
  process.exit(1);
}

const manifest = read('manifest.json');
const games = Object.keys(manifest.games || {});

if (games.length === 0) {
  bad('the manifest names no games');
}

// Every game file present should be in the manifest, and vice versa. A game
// recorded without its timestamp replays with the wrong clock, silently.
const gameFiles = readdirSync(dir)
  .filter((f) => /^games-\d+\.json$/.test(f))
  .map((f) => f.replace(/^games-|\.json$/g, ''));

for (const id of gameFiles) {
  if (!games.includes(id)) {
    bad(`games-${id}.json is not in the manifest, so its clock cannot be rebased`);
  }
}
for (const id of games) {
  if (!has(`games-${id}.json`)) {
    bad(`the manifest names game ${id} but games-${id}.json is not here`);
  }
  const taken = Number(manifest.games[id]);
  if (!Number.isFinite(taken) || taken <= 0) {
    bad(`game ${id} has no usable captured_at (${manifest.games[id]})`);
  }
}

// The reads a page actually performs, followed through.
let players = 0;
for (const id of games) {
  if (!has(`games-${id}.json`)) continue;
  const game = read(`games-${id}.json`);

  const sides = game.teams || {};
  const teamIds = [sides.hometeam, sides.visitorteam]
    .filter(Boolean).map((t) => t.team_id).filter(Boolean);

  if (teamIds.length !== 2) {
    bad(`game ${id} names ${teamIds.length} teams, so a page cannot draw both sides`);
  }

  for (const teamId of teamIds) {
    if (!has(`teams-${teamId}.json`)) {
      // The commentary desk shows "No roster available for this team."
      bad(`game ${id} needs teams-${teamId}.json and it is not here`);
      continue;
    }
    for (const p of read(`teams-${teamId}.json`).players || []) {
      if (!p.player_id) continue;
      players += 1;
      if (!has(`playerevents-${p.player_id}.json`)) {
        // A player sheet opens and never stops saying "Loading…".
        bad(`player ${p.player_id} (team ${teamId}) has no playerevents file`);
      }
    }
  }
}

for (const name of ['games.json', 'teams.json', 'reference.json', 'config.json']) {
  if (!has(name)) {
    bad(`${name} is missing — the Studio reads it to list games and fields`);
  }
}

if (problems) {
  console.error(`\n${problems} problem${problems === 1 ? '' : 's'} in ${dir}.`);
  console.error('Re-record with: node tests/capture.mjs --game <id> --out ' + path.relative(process.cwd(), dir));
  process.exit(1);
}

console.log(
  `capture ok: ${games.length} game${games.length === 1 ? '' : 's'} `
  + `(${games.join(', ')}), ${players} players, all rosters and histories present.`,
);
