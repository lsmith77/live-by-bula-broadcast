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

  test('the matching tags clear AAA in both themes', async ({ page, request }) => {
    // The FMP tag was drawn in teal-800 on teal-100: 6.73:1, under this page's
    // bar, and no contrast test covered the matching tints at all. They are
    // read at a desk under whatever light the venue has.
    // The mixed fixture, because a matching only renders in mixed — and the
    // player has to be one of ITS players, not the open fixture's.
    await page.evaluate(() => localStorage.setItem('uo-lines-code-703', 'ZTEST'));
    await page.goto('/c/703');
    await expect(page.locator('.roster').first()).toBeVisible();
    const id = await page.locator('.roster').first()
      .locator('td.who button[data-player]').first().getAttribute('data-player');
    await request.post('/index.php?view=live/overlays/notes', {
      data: { code: 'ZTEST', player: Number(id), text: '', matching: 'FMP', by: 'T' },
    });

    for (const theme of ['daylight', 'night']) {
      await page.evaluate(({ g, t }) => {
        localStorage.setItem(`uo-lines-code-${g}`, 'ZTEST');
        localStorage.setItem('uo-commentator-theme', t);
      }, { g: 703, t: theme });
      await page.goto('/c/703');
      await expect(page.locator('.roster').first()).toBeVisible();

      const tag = page.locator('.roster td.who .say .mt').first();
      await expect(tag, `${theme}: a tag to measure`).toBeVisible();
      const ratio = await contrastOf(page, '.roster td.who .say .mt');
      expect(ratio, `${theme}: matching tag contrast`).toBeGreaterThanOrEqual(AAA);
    }
  });

  test('the notes box and its roster marker clear AAA too', async ({ page }) => {
    // The marker was first drawn in --accent, which is a FILL colour: it is the
    // background of a pressed button, with white text on top. Against the panel
    // it measured 2.59:1 in the night theme -- below even the 3:1 floor for a
    // meaningful graphic, for the only visual sign that a player has a note.
    // Pin the room. Without this the page generates a fresh random code per run
    // and every run leaves a new room behind on disk.
    await page.evaluate((g) => {
      localStorage.setItem(`uo-lines-code-${g}`, 'ZTEST');
    }, GAME_ID);
    await page.reload();
    await expect(page.locator('.roster').first()).toBeVisible();

    await page.locator('.roster td.who button').first().click();
    await page.locator('#sheet .note textarea').fill('contrast probe');
    await page.locator('#sheet .note textarea').blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');
    await page.keyboard.press('Escape');

    for (const sel of ['.note textarea', '.note .meta .by', '.roster .who .dot']) {
      const ratio = await contrastOf(page, sel);
      expect(ratio, `${sel} must be measurable`).not.toBeNull();
      expect(ratio, `${sel} contrast`).toBeGreaterThanOrEqual(AAA);
    }

    // Leave the room as it was found; this spec shares its code with any desk.
    await page.locator('.roster td.who button').first().click();
    await page.locator('#sheet .note textarea').fill('');
    await page.locator('#sheet .note textarea').blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');
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

  test('the player sheet opens at the top, even when it has to scroll', async ({ page }) => {
    // The regression: focus goes to Close in the footer, and focusing it
    // normally scrolls the footer into view — so a sheet long enough to scroll
    // opened at the BOTTOM, past the name and the numbers it was opened for.
    // Squeezed deliberately, because the sheet fits a real desk's screen.
    await page.setViewportSize({ width: 1512, height: 400 });
    await page.locator('.roster td.who button').first().click();
    await expect(page.locator('#sheet .note textarea')).toBeVisible();

    const m = await page.evaluate(() => {
      const c = document.querySelector('#sheet .card');
      return { top: c.scrollTop, overflowing: c.scrollHeight > c.clientHeight };
    });
    expect(m.overflowing, 'the viewport must be short enough to prove anything').toBe(true);
    expect(m.top).toBe(0);
  });

  test('the sheet fits without scrolling on a desk-sized screen', async ({ page }) => {
    // The stats grid and the facts sit side by side rather than stacked for
    // exactly this reason; stacked, the sheet scrolled at 1080.
    await page.locator('.roster td.who button').first().click();
    await expect(page.locator('#sheet .note textarea')).toBeVisible();
    // The game-by-game list arrives after the sheet does, and it is the tallest
    // block on the card.
    await expect(page.locator('#sheet .card')).not.toContainText('Loading…');

    const m = await page.evaluate(() => {
      const c = document.querySelector('#sheet .card');
      return { scroll: c.scrollHeight, client: c.clientHeight };
    });
    expect(m.scroll).toBeLessThanOrEqual(m.client);
  });

  test('the completed-games caveat is an (i), not a paragraph', async ({ page }) => {
    await page.locator('.roster td.who button').first().click();
    const info = page.locator('#sheet .grid .info');
    await expect(info).toBeVisible();
    // Hover text, but reachable: it carries the sentence as a label too.
    await expect(info).toHaveAttribute('title', /completed games/);
    await expect(info).toHaveAttribute('aria-label', /completed games/);
    // And it hangs off the row it qualifies, not the whole card.
    await expect(page.locator('#sheet .grid .h', { hasText: 'Tournament' }).locator('.info'))
      .toHaveCount(1);
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
