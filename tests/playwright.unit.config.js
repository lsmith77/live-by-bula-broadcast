// @ts-check
/**
 * The specs that need neither a browser nor a running instance.
 *
 * Everything in `shared/` that decides something is a pure function, tested
 * directly (AGENTS.md) — the ratio and its line sizes, the line picker's
 * grouping, declared values, and the CSV round trip. None of them opens a page.
 *
 * They are split out with a config of their own because the main one has a
 * `globalSetup` that seeds a live instance over HTTP, and a `channel: 'chrome'`
 * that expects a browser. Neither exists in continuous integration: these
 * overlays run inside an UltiOrganizer installation with Live! by BULA, which
 * is third-party software distributed under a signed Terms of Use and cannot be
 * checked out by a public workflow. See `.github/workflows/ci.yml`.
 *
 * So this is not the whole suite and does not pretend to be. It is the part
 * that can be proved on every push, and it happens to be the part carrying the
 * rules a defect would be quietest in — an off-by-one in a quota, a ratio
 * shortened wrongly, an import overwriting a note somebody typed.
 *
 * The browser suite remains `npm test`, run locally against a real instance.
 */
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './e2e',

  // Named individually rather than matched by a pattern: a spec that quietly
  // starts using a page would otherwise be silently dropped from the run here
  // and nobody would notice it had stopped being covered.
  testMatch: ['lineup.spec.js', 'declared.spec.js', 'bios.spec.js', 'timeouts.spec.js'],

  // No globalSetup, no baseURL, no browser. A test in here that reaches for a
  // page or an instance should fail loudly rather than be quietly accommodated.
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: process.env.CI ? [['github'], ['list']] : [['list']],
});
