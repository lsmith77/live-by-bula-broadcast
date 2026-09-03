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

  test('each team header says how many timeouts it has left', async ({ page }) => {
    // The fixture exercises the half-reset, which is the whole reason this is a
    // derivation: home took one at 600s, the break is at 1260s, away took one
    // at 1700s. Per half, so home's is spent and forgiven and away's is not —
    // 2 of 2 for home, 1 of 2 for away. A count that ignored the reset would
    // say 1 of 2 for both and look perfectly plausible.
    const home = page.locator('#headHome .touts');
    const away = page.locator('#headAway .touts');
    await expect(home).toBeVisible();

    await expect(home.locator('.tout')).toHaveCount(2);
    await expect(home.locator('.tout.on')).toHaveCount(2);
    await expect(away.locator('.tout')).toHaveCount(2);
    await expect(away.locator('.tout.on')).toHaveCount(1);

    // Never ticks alone: the count is words in the title, and says which
    // period it counts, because "1 of 2 left" means different things per half
    // and per game.
    await expect(away).toHaveAttribute('title', /1 of 2 left this half/);
    await expect(away).toHaveAttribute('aria-label', /1 of 2 left this half/);
  });

  test('a player whose only entry is a captaincy still gets an identity line', async ({ page }) => {
    // The identity line had two renderers: one drew the spans, the other built
    // the hover title and decided whether the line existed at all. Adding a
    // field to the first without the second made it invisible — drawn by one,
    // declared absent by the other, so never created. They share one list now.
    await page.evaluate((g) => localStorage.setItem(`uo-lines-code-${g}`, 'ZTEST'), GAME_ID);
    await page.reload();
    await expect(page.locator('.roster').first()).toBeVisible();

    const id = await page.locator('.roster').first()
      .locator('td.who button[data-player]').first().getAttribute('data-player');
    await page.request.post('/index.php?view=live/overlays/notes', {
      data: { code: 'ZTEST', player: Number(id), text: '', role: 'Captain', by: 'T' },
    });
    await page.reload();
    await expect(page.locator('.roster').first()).toBeVisible();

    await page.locator(`.roster td.who button[data-player="${id}"]`).click();
    const say = page.locator('#sheet .sub.say');
    await expect(say, 'the line exists at all').toBeVisible();
    await expect(say).toContainText('Captain');

    // Leave the room as it was found, and leave it EMPTY. Two things bite here,
    // and both have bitten before (AGENTS.md): a save is a delta, so clearing
    // one channel leaves the others to accumulate run over run; and the open
    // sheet flushes on close, which re-saves whatever its inputs still hold and
    // would undo a cleanup that ran while it was still open.
    await page.keyboard.press('Escape');
    const cleared = { code: 'ZTEST', player: Number(id), text: '', by: 'T' };
    for (const field of ['nickname', 'pronouns', 'pronunciation', 'matching',
      'role', 'nationality', 'position', 'hand']) {
      cleared[field] = '';
    }
    await page.request.post('/index.php?view=live/overlays/notes', { data: cleared });
    // This player specifically, not the whole room: the code is shared with
    // every other spec here, and one of them legitimately leaves a matching
    // behind. Asserting the room is empty asserts something about them.
    const left = await (await page.request.get(
      '/index.php?view=live/overlays/notes&code=ZTEST',
    )).json();
    expect(left.players[String(id)], 'this player is gone').toBeUndefined();
  });

  test('empty fields collapse, and a hidden one is not a cleared one', async ({ page }) => {
    // Eight inputs for a player who declared nothing is a card of blank boxes,
    // which is what pushed this sheet towards a scrollbar. They hide — but the
    // desk still has to be able to add one, and, more dangerously, a hidden
    // empty field must not be saved as an instruction to clear anything.
    await page.evaluate((g) => {
      localStorage.setItem(`uo-lines-code-${g}`, 'ZTEST');
      localStorage.removeItem('uo-note-fields');
    }, GAME_ID);
    await page.reload();
    await expect(page.locator('.roster').first()).toBeVisible();

    const id = await page.locator('.roster').first()
      .locator('td.who button[data-player]').first().getAttribute('data-player');
    // One field declared: it shows, the other seven do not.
    await page.request.post('/index.php?view=live/overlays/notes', {
      data: { code: 'ZTEST', player: Number(id), text: '', nationality: 'GBR', by: 'T' },
    });
    await page.reload();
    await expect(page.locator('.roster').first()).toBeVisible();
    await page.locator(`.roster td.who button[data-player="${id}"]`).click();

    await expect(page.locator('#sheet .note .fields .field')).toHaveCount(1);
    await expect(page.locator('#sheet .fieldtoggle')).toContainText('Add details');

    // Typing in the note saves the whole entry. The seven hidden fields must
    // ride along untouched rather than being written as blanks.
    await page.locator('#sheet .note textarea').fill('A note.');
    await page.locator('#sheet .note textarea').blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');

    const stored = await (await page.request.get(
      '/index.php?view=live/overlays/notes&code=ZTEST',
    )).json();
    expect(stored.players[String(id)].nationality, 'survived a save it was hidden from')
      .toBe('GBR');

    // And the toggle brings the rest back so the desk can add one. Seven, not
    // eight: matching only offers itself in a mixed division, and this fixture
    // is open.
    await page.locator('#sheet .fieldtoggle').click();
    await expect(page.locator('#sheet .note .fields .field')).toHaveCount(7);

    await page.evaluate(() => localStorage.removeItem('uo-note-fields'));
    await page.keyboard.press('Escape');
    const cleared = { code: 'ZTEST', player: Number(id), text: '', by: 'T' };
    for (const f of ['nickname', 'pronouns', 'pronunciation', 'matching',
      'role', 'nationality', 'position', 'hand']) { cleared[f] = ''; }
    await page.request.post('/index.php?view=live/overlays/notes', { data: cleared });
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
