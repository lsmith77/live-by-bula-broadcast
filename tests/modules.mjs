/**
 * Every shared module loads under plain CommonJS and publishes what it says.
 *
 * `shared/` modules publish to `window` AND `module.exports`, because they are
 * loaded both by a browser page and by the test runner. The trap AGENTS.md
 * names is that top-level `this` differs between the two loaders, so a module
 * can work perfectly in the browser and fail at IMPORT under Node — which takes
 * the whole suite down with an error that names the loader rather than the bug.
 *
 * This is the cheapest possible guard: require each one and check it came back
 * with something on it. It needs no browser, no instance and no database, which
 * is the entire reason it can run on every push.
 *
 * Run by `.github/workflows/ci.yml`, and by hand with `node tests/modules.mjs`.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const sharedDir = join(here, '..', 'shared');

/**
 * Only the modules that ASK to be dual-published.
 *
 * `shared/` also holds browser-only page scripts — the data client and the demo
 * driver — which never assign `module.exports` and would touch `window` on
 * load. Requiring those would fail for the right reason and the wrong cause, so
 * the preamble is the declaration of intent and the filter.
 */
const all = readdirSync(sharedDir).filter((f) => f.endsWith('.js')).sort();
const files = all.filter(
  (f) => readFileSync(join(sharedDir, f), 'utf8').includes('module.exports'),
);
const browserOnly = all.filter((f) => !files.includes(f));
if (files.length === 0) {
  console.error('no dual-published modules found — this check would pass vacuously');
  process.exit(1);
}

let failed = 0;
for (const file of files) {
  const path = join(sharedDir, file);
  try {
    const mod = require(path);
    const keys = Object.keys(mod || {});
    if (keys.length === 0) {
      // A module that loads but exports nothing is the dual-publish bug's other
      // face: `module.exports` never assigned, so every caller gets undefined
      // at use rather than at import.
      console.error(`FAIL ${file}: loaded but exports nothing`);
      failed += 1;
      continue;
    }
    console.log(`ok   ${file} (${keys.join(', ')})`);
  } catch (e) {
    console.error(`FAIL ${file}: ${e.message}`);
    failed += 1;
  }
}

if (failed) {
  console.error(`\n${failed} of ${files.length} shared modules did not load.`);
  process.exit(1);
}
console.log(`\nall ${files.length} dual-published modules load under CommonJS.`);
if (browserOnly.length) {
  console.log(`(browser-only, not required: ${browserOnly.join(', ')})`);
}
