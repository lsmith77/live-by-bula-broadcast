/**
 * Every spec is run by something, and the ones that could run in CI do.
 *
 * There are three configs now, and two of them name their specs individually:
 *
 *   playwright.config.js             the hosted browser suite — everything
 *                                    except shots and standalone
 *   playwright.unit.config.js        the pure specs, by name
 *   playwright.standalone.config.js  the standalone specs, by name
 *
 * Naming specs individually is deliberate — a spec that quietly starts opening
 * a page should not be silently accommodated — but it has a failure mode nobody
 * would notice: add a pure spec, forget to list it, and it still runs locally
 * under the hosted config while **never running in CI at all**. It passes, it
 * looks covered, and the machine has never seen it.
 *
 * So: a spec that uses neither `page` nor `request` needs no browser and no
 * server, and belongs in the unit config. This asserts that, and it is the only
 * way the unit list stays honest as specs are added.
 *
 *   node tests/suites.mjs
 */
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');

const unitConfig = read('tests/playwright.unit.config.js');
const standaloneConfig = read('tests/playwright.standalone.config.js');
const mainConfig = read('tests/playwright.config.js');

// readdirSync rather than fs.globSync, which is Node 22 and up — see
// tests/links.mjs for the version this cost.
const specs = readdirSync(path.join(ROOT, 'tests', 'e2e'))
  .filter((f) => f.endsWith('.spec.js')).sort();

if (specs.length === 0) {
  console.error('No specs found — this check would pass vacuously.');
  process.exit(1);
}

/** Does this spec ask Playwright for anything that needs a browser or a server? */
function needsRuntime(body) {
  // The fixtures, as destructured in a test signature: ({ page }), ({ request }),
  // ({ page, request }). A mention in a comment is not a use.
  return /async\s*\(\s*\{[^}]*\b(page|request|context|browser)\b/.test(body);
}

let failed = 0;
const rows = [];

for (const spec of specs) {
  const body = read(path.join('tests', 'e2e', spec));
  const pure = !needsRuntime(body);

  const inUnit = unitConfig.includes(`'${spec}'`);
  const inStandalone = standaloneConfig.includes(`'${spec}'`);
  const stem = spec.replace('.spec.js', '');

  // The main config has two projects. `overlays` takes everything it does not
  // ignore; `shots` takes only what it names — that one regenerates the
  // committed screenshots, so it is deliberately not in a normal run.
  const ignoredByMain = new RegExp(`\\b${stem}\\b`)
    .test((mainConfig.match(/testIgnore:\s*\/([^/]+)\//) || [])[1] || '');
  const namedByProject = [...mainConfig.matchAll(/testMatch:\s*\/([^/]+)\//g)]
    .some((m) => new RegExp(`\\b${stem}\\b`).test(m[1]));

  let where = [];
  if (inUnit) where.push('unit');
  if (inStandalone) where.push('standalone');
  if (namedByProject) where.push('shots');
  if (!ignoredByMain && !inStandalone && !namedByProject) where.push('hosted');

  const note = [];
  if (where.length === 0) {
    note.push('RUN BY NOTHING');
    failed += 1;
  }
  if (pure && !inUnit && !namedByProject) {
    // The case this file exists for.
    note.push('PURE BUT NOT IN THE UNIT CONFIG — it will never run in CI');
    failed += 1;
  }
  if (!pure && inUnit) {
    note.push('IN THE UNIT CONFIG BUT USES A FIXTURE — it will fail there');
    failed += 1;
  }

  rows.push({ spec, pure, where: where.join(', ') || '-', note: note.join('; ') });
}

const width = Math.max(...rows.map((r) => r.spec.length));
for (const r of rows) {
  const kind = r.pure ? 'pure ' : 'runtime';
  console.log(`${r.note ? 'FAIL' : 'ok  '} ${r.spec.padEnd(width)}  ${kind}  ${r.where}${r.note ? '  <- ' + r.note : ''}`);
}

if (failed) {
  console.error(`\n${failed} problem${failed === 1 ? '' : 's'} across ${specs.length} specs.`);
  process.exit(1);
}

console.log(`\nall ${specs.length} specs are run by a config, and every pure one runs in CI.`);
