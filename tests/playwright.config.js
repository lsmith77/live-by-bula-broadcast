// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * End-to-end tests for the broadcast overlays.
 *
 * These exist because of how this project has actually failed. Almost every
 * defect worth catching here was invisible to inspection and obvious to
 * measurement: a companion card overlapping the scoreboard by 22px, a stage that
 * rendered below the fold in a normal window, an auto card flashing the last
 * goal of the match on first paint, a heading that read backwards to a screen
 * reader. None of those are things a unit test would have seen, and all of them
 * are two lines of Playwright.
 *
 * So the suite asserts on GEOMETRY, CONTRAST and STATE TRANSITIONS rather than
 * on markup. `expect(box.right).toBe(other.left)` is a real claim about what
 * goes on air; `expect(html).toContain('<div class="callout">')` is not.
 *
 * Runs against a live dev instance rather than a mock, because the payload shape
 * is exactly the thing that has been wrong before — see docs/PLAN.md on the
 * field names the January code invented.
 *
 * Lives in tests/ rather than at the repo root, which is where Playwright looks
 * by default. The root of this repository is runtime code -- a routed view's
 * path is its URL -- so tooling is kept out of it, the same way docs, fixtures
 * and this suite are. The cost is that `npx playwright test` on its own no
 * longer finds the config; use `npm test`, which passes it.
 *
 *   BASE_URL     where UltiOrganizer is served     (default http://localhost:8080)
 *   ADMIN_PASS   Live! admin password, for the tests that change what is on air
 *   GAME_ID      the fixture's live game           (default 702)
 */
const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';

module.exports = defineConfig({
  // Relative to THIS file, not the repo root -- Playwright resolves testDir
  // against the config's own location.
  testDir: './e2e',

  /**
   * Every run starts from a configured stage, not an empty one.
   *
   * These tests write to the files a real broadcast reads, so "empty" is not a
   * state that occurs in practice and is not one worth testing against. Seeding
   * a messy stage makes a test that reads state it did not set fail
   * immediately, rather than three months later when something unrelated moves.
   * See tests/global-setup.js.
   */
  globalSetup: require.resolve('../tests/global-setup.js'),
  // A broadcast overlay is a shared, stateful surface: two tests writing show
  // state at once would fight over conf/show.json and neither result would mean
  // anything. Correctness here is worth more than wall-clock.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',

  use: {
    baseURL: BASE_URL,
    // The system browser, not a downloaded one. The overlays run in whatever
    // Chromium a switcher ships, so testing against the same engine family the
    // operator has installed is closer than a pinned bundle — and it keeps the
    // dev dependency at three packages instead of a 300MB download.
    channel: 'chrome',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },

  projects: [
    {
      name: 'overlays',
      // 1920x1080 is not a preference here, it is the canvas. A stage bug that
      // only appears at other sizes is a different test (see stage.spec.js).
      use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } },
      testIgnore: /shots\.spec\.js/,
    },
    {
      // Not a test: generates the images in docs/images/ for the README.
      // Separated so a normal run never rewrites committed screenshots -- which
      // means the default `npm test` has to name a project, because running all
      // of them is exactly what would rewrite them.
      name: 'shots',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } },
      testMatch: /shots\.spec\.js/,
    },
  ],
});
