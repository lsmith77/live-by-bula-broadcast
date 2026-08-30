// @ts-check
/**
 * Build a tree that genuinely has no UltiOrganizer in it.
 *
 * The first version of the standalone tests ran `php -S` against the working
 * copy and asserted "admin gating without Live!" — which proved nothing, because
 * `live/vendor/autoload.php` is two directories up from `shared/auth.php` in a
 * hosted checkout. `Auth::isHosted()` was returning **true** the whole time, so
 * the tests were exercising the hosted path under a standalone name.
 *
 * So the tree is copied somewhere with no host above it. That is the only way to
 * make the detection in `Auth::isHosted()` answer the way it would on a bare PHP
 * server, and the detection is the thing under test.
 *
 * `conf/` is synthesised rather than copied. The suite has to prove that
 * prepared notes are unreachable, and doing that with real notes would mean
 * copying notes about real people into a temp directory to prove they are
 * private. A fixture says the same thing and says it deterministically.
 */
const { mkdtempSync, cpSync, mkdirSync, writeFileSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');

const SOURCE = path.join(__dirname, '..');

/** Everything a standalone installation actually ships. */
const RUNTIME = [
  'app.php', 'login.php', 'index.php', 'scoreboard.php', 'stage.php',
  'commentator.php', 'show.php', 'possession.php', 'lines.php', 'notes.php',
  'colors.php', 'shared', 'images', '.htaccess',
];

function build() {
  const root = mkdtempSync(path.join(tmpdir(), 'overlays-standalone-'));

  for (const entry of RUNTIME) {
    const from = path.join(SOURCE, entry);
    try {
      cpSync(from, path.join(root, entry), { recursive: true });
    } catch (e) {
      if (e.code !== 'ENOENT') throw e;
    }
  }

  // A conf/ holding one of each kind: the two files the stage is allowed to
  // poll, and three that must never be served.
  mkdirSync(path.join(root, 'conf', 'notes'), { recursive: true });
  mkdirSync(path.join(root, 'conf', 'lines'), { recursive: true });
  const w = (p, body) => writeFileSync(path.join(root, 'conf', p), body);
  w('show.json', JSON.stringify({ rev: 1, game: 702, cards: [] }));
  w('possession-702.json', JSON.stringify({ rev: 1 }));
  w('team-colors.json', JSON.stringify({ 304: { primary: '#123456' } }));
  w('show.json.bak', JSON.stringify({ rev: 0 }));
  w(path.join('notes', 'DDDDD.json'), JSON.stringify({
    players: { 1: { text: 'MUST NOT BE SERVED', by: '', at: 0 } },
  }));
  w(path.join('notes', 'show.json'), JSON.stringify({ decoy: true }));
  w(path.join('lines', 'DDDDD.json'), JSON.stringify({ rev: 1 }));

  return root;
}

module.exports = { build, RUNTIME, cleanup: (root) => rmSync(root, { recursive: true, force: true }) };
