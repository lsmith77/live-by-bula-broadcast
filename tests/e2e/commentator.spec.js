// @ts-check
/**
 * The commentator page.
 *
 * The accessibility assertions here are not box-ticking. Two of them exist
 * because a change that was right for sighted readers -- naming each team once
 * in a header positioned over its column -- silently removed every heading from
 * the document and left two roster tables with no accessible name. Proximity is
 * not available to a screen reader, and nothing but a test remembers that.
 *
 * The contrast floor is 7:1 (AAA, not AA) because this page is used beside a
 * pitch in sunlight. See docs/COMMENTATOR.md.
 */
const { test } = require('@playwright/test');
const { GAME_ID, contrastOf, expect } = require('./helpers');

const AAA = 7;

test.describe('commentator', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`/c/${GAME_ID}`);
    await expect(page.locator('.roster').first()).toBeVisible();
  });

  test('defaults to the daylight theme', async ({ page }) => {
    // Not a style preference: a dark screen in sunlight becomes a mirror.
    const bg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    expect(bg).toBe('rgb(255, 255, 255)');
    expect(await page.getAttribute('html', 'data-theme')).toBe('day');
  });

  test('every text pair clears WCAG AAA in daylight', async ({ page }) => {
    const targets = [
      '.roster td.who button', '.roster th', '.roster td.n',
      '.panel p.muted', '.teamhead .nm', '.scoreline', '.badge', '.chip',
    ];
    for (const sel of targets) {
      const ratio = await contrastOf(page, sel);
      if (ratio === null) continue;
      expect(ratio, `${sel} contrast`).toBeGreaterThanOrEqual(AAA);
    }
  });

  test('the theme choice survives a reload and applies before first paint', async ({ page }) => {
    await page.locator('#themeBtn').click();
    expect(await page.getAttribute('html', 'data-theme')).toBe('night');
    await page.reload();
    // Set by the inline head script, not the page script at the end of the body
    // -- otherwise a night reader gets a full white flash.
    expect(await page.getAttribute('html', 'data-theme')).toBe('night');
    await page.locator('#themeBtn').click();
    expect(await page.getAttribute('html', 'data-theme')).toBe('day');
  });

  test('the header names each team once, structurally as well as visually', async ({ page }) => {
    const headings = await page.evaluate(() =>
      [...document.querySelectorAll('h1, h2')].map((h) => h.tagName));
    expect(headings).toContain('H1');
    expect(headings.filter((h) => h === 'H2').length).toBe(2);

    // Every panel and table points back at the heading that names its team.
    const labelled = await page.evaluate(() => ({
      panels: [...document.querySelectorAll('.panel[aria-labelledby]')]
        .map((p) => p.getAttribute('aria-labelledby')),
      tables: [...document.querySelectorAll('table.roster')]
        .map((t) => t.getAttribute('aria-label')),
    }));
    expect(labelled.panels).toEqual(expect.arrayContaining(['headHome', 'headAway']));
    labelled.tables.forEach((name) => expect(name).toBeTruthy());
  });

  test('the away heading reads forwards', async ({ page }) => {
    // It is right-aligned with flex-direction: row-reverse. Reversing the DOM
    // instead would right-align it too -- and make a screen reader announce
    // "team, seed 2, Leamington Lemmings".
    const away = await page.locator('h2.teamhead.away').innerText();
    const name = await page.locator('h2.teamhead.away .nm').innerText();
    expect(away.replace(/\s+/g, ' ').trim().startsWith(name.trim())).toBe(true);
  });

  test('the player sheet is a real dialog and returns focus', async ({ page }) => {
    const trigger = page.locator('.roster td.who button').first();
    await trigger.focus();
    await trigger.click();

    const sheet = page.locator('#sheet');
    await expect(sheet).toHaveAttribute('role', 'dialog');
    await expect(sheet).toHaveAttribute('aria-modal', 'true');
    expect(await page.evaluate(() =>
      document.getElementById('sheet').contains(document.activeElement))).toBe(true);

    await page.keyboard.press('Escape');
    expect(await page.evaluate(() =>
      document.getElementById('sheet').classList.contains('open'))).toBe(false);
    // Focus must come back to where it started, or a keyboard user is stranded.
    expect(await page.evaluate(() =>
      document.activeElement === document.querySelector('.roster td.who button'))).toBe(true);
  });

  test('toggles expose their state to assistive technology, not only by colour', async ({ page }) => {
    const pressed = await page.evaluate(() =>
      [...document.querySelectorAll('.chip')].map((c) => c.getAttribute('aria-pressed')));
    expect(pressed.filter((p) => p === 'true').length).toBe(1);
    expect(await page.getAttribute('#tabPrep', 'aria-pressed')).toBe('true');
    expect(await page.getAttribute('#tabPlay', 'aria-pressed')).toBe('false');
  });

  test('the blocks column follows the data, not a preference', async ({ page }) => {
    // Absent means "this installation does not track blocks" and the column must
    // not exist; a present zero means "none yet" and it must. So the column and
    // the sort chip have to agree with each other, always.
    const state = await page.evaluate(() => ({
      header: [...document.querySelectorAll('.roster th')].some((h) => h.textContent === 'Blk'),
      chip: [...document.querySelectorAll('.chip')].some((c) => c.textContent === 'Blocks'),
    }));
    expect(state.header).toBe(state.chip);
  });
});
