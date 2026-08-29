// @ts-check
/**
 * Prepared talking points about a player.
 *
 * The feature exists because at a smaller tournament the commentary position is
 * staffed only for the finals, and the material reaches it as whatever the two
 * teams hand over an hour before the pull. See docs/COMMENTATOR.md section 5a.
 *
 * Three of these tests exist for specific ways this could fail silently, which is
 * the characteristic failure of this whole project:
 *
 *   - a note typed into the detail overlay is invisible from the roster, so
 *     without a marker a commentator would have to open 28 players to find the
 *     three with anything written about them;
 *   - the dialog's Tab trap enumerates focusable children by selector, so a
 *     textarea missing from that selector is in the DOM, visibly focusable with a
 *     mouse, and completely unreachable by keyboard;
 *   - notes are keyed by CODE and not by game on purpose. If that ever regressed
 *     to game-scoping, everything would still work perfectly for one match and a
 *     desk would lose every note the moment it moved to the next round.
 *
 * Every test sets the state it asserts on. The suite writes to the same files a
 * real desk reads, so a test that assumed an empty room would be counting
 * whatever the last run left behind.
 */
const { test } = require('@playwright/test');
const { GAME_ID, expect } = require('./helpers');

// Distinct from anything a person would be handed, so a run cannot land in a
// real desk's room and edit its notes.
const CODE = 'ZTEST';
const NOTES = '/index.php?view=live/overlays/notes';

/** Empty the room, so a test never counts what an earlier run left. */
async function resetRoom(request) {
  const current = await (await request.get(`${NOTES}&code=${CODE}`)).json();
  for (const id of Object.keys(current.players || {})) {
    await request.post(NOTES, { data: { code: CODE, player: Number(id), text: '' } });
  }
}

/** Open the commentator page already in the test room, as a named desk. */
async function openPage(page, game = GAME_ID) {
  await page.addInitScript(({ game, code }) => {
    localStorage.setItem(`uo-lines-code-${game}`, code);
    localStorage.setItem('uo-commentator-name', 'Tester');
  }, { game, code: CODE });
  await page.goto(`/c/${game}`);
  await expect(page.locator('.roster').first()).toBeVisible();
}

test.describe('prepared notes', () => {
  test.beforeEach(async ({ request }) => {
    await resetRoom(request);
  });
  test.afterAll(async ({ request }) => {
    await resetRoom(request);
  });

  test('a note typed in the overlay is stored and survives a reload', async ({ page, request }) => {
    await openPage(page);
    await page.locator('.roster td.who button').first().click();

    const area = page.locator('#sheet .note textarea');
    await expect(area).toBeVisible();
    await expect(area).toHaveValue('');

    await area.fill('Played handball for the national U19 side.');
    await area.blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');

    // The store, not the browser: this is the whole point of the feature.
    const stored = await (await request.get(`${NOTES}&code=${CODE}`)).json();
    const texts = Object.values(stored.players).map((p) => p.text);
    expect(texts).toContain('Played handball for the national U19 side.');

    await page.reload();
    await expect(page.locator('.roster').first()).toBeVisible();
    await page.locator('.roster td.who button').first().click();
    await expect(page.locator('#sheet .note textarea'))
      .toHaveValue('Played handball for the national U19 side.');
  });

  test('the roster marks who has a note, in words as well as colour', async ({ page }) => {
    await openPage(page);
    const rows = page.locator('.roster').first().locator('td.who button');

    // Nothing is marked before anything is written -- otherwise this test would
    // pass on a marker that is simply always there.
    expect(await page.locator('.roster .who .dot').count()).toBe(0);

    await rows.first().click();
    await page.locator('#sheet .note textarea').fill('Captain. Two-time national champion.');
    await page.locator('#sheet .note textarea').blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');
    await page.keyboard.press('Escape');

    // Exactly one row is marked, and it is the row that was written.
    await expect(page.locator('.roster .who .dot')).toHaveCount(1);
    const marked = rows.first();
    await expect(marked.locator('.dot')).toBeVisible();
    await expect(marked).toHaveAttribute('title', /talking points/);
    // The dot is decorative; the meaning has to survive without it.
    expect(await marked.locator('.dot').getAttribute('aria-hidden')).toBe('true');
    await expect(marked.locator('.sr-only')).toHaveText(/has talking points/);
  });

  test('clearing a note removes it and its marker', async ({ page, request }) => {
    await openPage(page);
    const first = page.locator('.roster td.who button').first();
    await first.click();
    await page.locator('#sheet .note textarea').fill('Temporary.');
    await page.locator('#sheet .note textarea').blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');
    await expect(page.locator('.roster .who .dot')).toHaveCount(1);
    await page.keyboard.press('Escape');

    await first.click();
    await page.locator('#sheet .note textarea').fill('');
    await page.locator('#sheet .note textarea').blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');
    await page.keyboard.press('Escape');

    await expect(page.locator('.roster .who .dot')).toHaveCount(0);
    // Cleared means gone from the store, not stored as an empty string: a blank
    // entry would still count against the room's cap and expire on its own clock.
    const stored = await (await request.get(`${NOTES}&code=${CODE}`)).json();
    expect(Object.keys(stored.players)).toHaveLength(0);
  });

  test('the notes box is reachable by keyboard', async ({ page }) => {
    // The dialog traps Tab and sends focus back to the first focusable child on
    // leaving the last. Anything the trap's selector misses is therefore not
    // merely last in the order -- it is unreachable. A mouse would never show it.
    await openPage(page);
    await page.locator('.roster td.who button').first().click();
    await expect(page.locator('#sheet .note textarea')).toBeVisible();

    let reached = false;
    for (let i = 0; i < 8 && !reached; i += 1) {
      await page.keyboard.press('Tab');
      reached = await page.evaluate(() =>
        document.activeElement === document.querySelector('#sheet .note textarea'));
    }
    expect(reached, 'Tab must reach the notes textarea inside the dialog').toBe(true);
  });

  test('a note says who wrote it, because nothing else will', async ({ page }) => {
    // These are one person's words about another, repeated on air. A commentator
    // should be able to tell their partner's note from their own.
    await openPage(page);
    await page.locator('.roster td.who button').first().click();
    await page.locator('#sheet .note textarea').fill('Throws lefty.');
    await page.locator('#sheet .note textarea').blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');
    await expect(page.locator('#sheet .note .meta .by')).toHaveText(/Written by Tester/);
  });

  test('a second desk on the same code sees the note', async ({ page, request }) => {
    await openPage(page);
    // Written by "somebody else" -- straight to the store, as another browser would.
    const roster = await (await request.get(`${NOTES}&code=${CODE}`)).json();
    expect(Object.keys(roster.players)).toHaveLength(0);

    await page.locator('.roster td.who button').first().click();
    await page.locator('#sheet .note textarea').fill('From the other desk.');
    await page.locator('#sheet .note textarea').blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');
    await page.keyboard.press('Escape');

    const stored = await (await request.get(`${NOTES}&code=${CODE}`)).json();
    const [id] = Object.keys(stored.players);
    expect(stored.players[id].text).toBe('From the other desk.');

    // A different browser, same code, same notes.
    const second = await page.context().newPage();
    await second.addInitScript(({ game, code }) => {
      localStorage.setItem(`uo-lines-code-${game}`, code);
    }, { game: GAME_ID, code: CODE });
    await second.goto(`/c/${GAME_ID}`);
    await expect(second.locator('.roster').first()).toBeVisible();
    await expect(second.locator('.roster .who .dot')).toHaveCount(1);
    await second.close();
  });

  test('a failed save says so, and keeps the text', async ({ page }) => {
    // Sharing is best-effort, and the failure mode that matters is the dishonest
    // one: reporting "Saved" for a write that never landed would have a
    // commentator believe their partner can see a note they cannot. The local
    // text is still good and still usable, so the box says exactly that.
    await openPage(page);
    // A glob will not do it: the endpoint is a query parameter, not a path
    // segment, so `**/view=live/overlays/notes**` matches nothing and the test
    // passes while blocking no request at all.
    let blocked = 0;
    await page.route((u) => u.href.includes('view=live/overlays/notes'), (r) => {
      blocked += 1;
      return r.abort();
    });

    await page.locator('.roster td.who button').first().click();
    await page.locator('#sheet .note textarea').fill('Never reaches the store.');
    await page.locator('#sheet .note textarea').blur();

    await expect(page.locator('#sheet .note .state'))
      .toHaveText('Not shared — kept on this screen');
    expect(blocked, 'the request must genuinely have been blocked').toBeGreaterThan(0);
    await expect(page.locator('#sheet .note textarea'))
      .toHaveValue('Never reaches the store.');
  });

  test('the box says who can read a note and that it expires', async ({ page }) => {
    // An expiry nobody was told about is indistinguishable from losing data, and
    // this is the one store holding one person's notes about another.
    await openPage(page);
    await page.locator('.roster td.who button').first().click();
    const meta = page.locator('#sheet .note .meta .by');
    await expect(meta).toHaveText(/room code/);
    await expect(meta).toHaveText(/Deleted \d+ days after the last edit/);
  });

  test('the bio round trip: export, edit, import, and refuse the tampering', async ({ page }, info) => {
    // One browser test for the WIRING. The decisions are unit-tested in
    // bios.spec.js; what this covers is the part that only exists in the page --
    // download, file picker, preview, batch write, markers.
    const fs = require('fs');
    await openPage(page);

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('.biobar').first().locator('button.barbtn').first().click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/\.csv$/);
    const exported = info.outputPath('export.csv');
    await download.saveAs(exported);

    const text = fs.readFileSync(exported, 'utf8').replace(/^﻿/, '');
    const lines = text.split('\r\n').filter(Boolean);
    expect(lines[0]).toContain('Player ID');
    expect(lines.length).toBeGreaterThan(3);

    // Play the part of the team: two players answer honestly (one declares
    // pronouns as well), one row is retargeted at a teammate, and one row
    // carries a foreign identifier. Columns are found by header, the way the
    // import itself finds them, so the test survives a column being added.
    const headers = lines[0].split(',');
    const col = (h) => headers.indexOf(h);
    const [r1, r2, r3] = [1, 2, 3].map((i) => lines[i].split(','));
    r1[col('Other sports played')] = 'Handball';
    r1[col('How you came to ultimate')] = '"Followed a sibling, then stayed"';
    r1[col('Pronouns')] = 'she/her';
    r2[col('Other sports played')] = 'Athletics';
    r3[col('Name')] = 'NOT THE RIGHT NAME';
    r3[col('Other sports played')] = 'sneaky';
    const foreign = headers.map((h, i) => ({
      [col('Player ID')]: '999999',
      [col('Number')]: '9',
      [col('Name')]: 'Opposing Player',
      [col('Other sports played')]: 'bogus',
    }[i] || '')).join(',');
    const edited = info.outputPath('edited.csv');
    fs.writeFileSync(edited, `﻿${[lines[0], r1.join(','), r2.join(','), r3.join(','), foreign].join('\r\n')}\r\n`);

    await page.locator('.biobar').first().locator('input[type=file]').setInputFiles(edited);
    await expect(page.locator('#sheet')).toHaveClass(/open/);

    const preview = page.locator('#sheetCard');
    await expect(preview).toContainText('not on this roster');
    await expect(preview).toContainText('name does not match the roster');
    await expect(preview.locator('.rejects div')).toHaveCount(2);

    await preview.locator('.barbtn.primary').click();
    await expect(page.locator('#importFlash')).toContainText('2 notes imported');

    // The tampering must not have crossed to the other team. This is the
    // assertion the whole identifier design exists for.
    const rosters = page.locator('.cols').last().locator('.panel');
    await expect(rosters.nth(0).locator('.roster .who .dot')).toHaveCount(2);
    await expect(rosters.nth(1).locator('.roster .who .dot')).toHaveCount(0);

    // Two filled columns compose into labelled lines, one does not — and the
    // pronouns imported into their own field, beside the name rather than
    // inside the note.
    await expect(rosters.nth(0).locator('.roster td.who .say')).toHaveText(['she/her']);
    await rosters.nth(0).locator('.roster td.who button').first().click();
    await expect(page.locator('#sheet .note textarea'))
      .toHaveValue('Other sports played: Handball\nHow you came to ultimate: Followed a sibling, then stayed');
    await expect(page.locator('#sheet .note .fields input').nth(1)).toHaveValue('she/her');
  });

  test('the structured fields are shown beside the name, not buried in a note', async ({ page, request }) => {
    await openPage(page);
    await page.locator('.roster td.who button').first().click();

    const fields = page.locator('#sheet .note .fields input');
    await fields.nth(0).fill('Ace');
    await fields.nth(1).fill('she/her');
    await fields.nth(2).fill('OW-er');
    await fields.nth(2).blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');
    await page.keyboard.press('Escape');

    // Beside the name on the roster -- and no talking-points dot, because
    // nothing was said about the player, only how to refer to them.
    await expect(page.locator('.roster td.who .say').first())
      .toHaveText('“Ace” · she/her · say OW-er');
    await expect(page.locator('.roster .who .dot')).toHaveCount(0);

    // Stored as fields, not composed into the text.
    const stored = await (await request.get(`${NOTES}&code=${CODE}`)).json();
    const [id] = Object.keys(stored.players);
    expect(stored.players[id].nickname).toBe('Ace');
    expect(stored.players[id].pronouns).toBe('she/her');
    expect(stored.players[id].pronunciation).toBe('OW-er');
    expect(stored.players[id].text).toBe('');

    // And they survive a reload, back into their own inputs.
    await page.reload();
    await expect(page.locator('.roster').first()).toBeVisible();
    await page.locator('.roster td.who button').first().click();
    await expect(page.locator('#sheet .note .fields input').nth(1)).toHaveValue('she/her');
    await expect(page.locator('#sheetCard .sub.say')).toHaveText('“Ace” · she/her · say OW-er');
  });

  test('an import fills a missing pronoun but never the typed note', async ({ page }, info) => {
    // Fill-what-is-empty holds per CHANNEL: the desk's note stands, the pronouns
    // nobody had arrive anyway.
    const fs = require('fs');
    await openPage(page);

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('.biobar').first().locator('button.barbtn').first().click(),
    ]);
    const exported = info.outputPath('export3.csv');
    await download.saveAs(exported);
    const lines = fs.readFileSync(exported, 'utf8').replace(/^﻿/, '')
      .split('\r\n').filter(Boolean);
    const headers = lines[0].split(',');
    const col = (h) => headers.indexOf(h);

    await page.locator('.roster td.who button').first().click();
    await page.locator('#sheet .note textarea').fill('Typed at the desk.');
    await page.locator('#sheet .note textarea').blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');
    await page.keyboard.press('Escape');

    const r1 = lines[1].split(',');
    r1[col('Other sports played')] = 'From the sheet';
    r1[col('Pronouns')] = 'they/them';
    const edited = info.outputPath('edited3.csv');
    fs.writeFileSync(edited, `${[lines[0], r1.join(',')].join('\r\n')}\r\n`);
    await page.locator('.biobar').first().locator('input[type=file]').setInputFiles(edited);
    await expect(page.locator('#sheet')).toHaveClass(/open/);
    await page.locator('#sheetCard .barbtn.primary').click();
    await expect(page.locator('#importFlash')).toContainText('1 note imported');

    await page.locator('.roster td.who button').first().click();
    await expect(page.locator('#sheet .note textarea')).toHaveValue('Typed at the desk.');
    await expect(page.locator('#sheet .note .fields input').nth(1)).toHaveValue('they/them');
  });

  test('re-importing does not overwrite what the desk typed', async ({ page }, info) => {
    // The promise the preview makes, enforced server-side rather than only in
    // the page: a partner writing during a preview must not be overwritten by an
    // import that never saw their note.
    const fs = require('fs');
    await openPage(page);

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('.biobar').first().locator('button.barbtn').first().click(),
    ]);
    const exported = info.outputPath('export2.csv');
    await download.saveAs(exported);
    const lines = fs.readFileSync(exported, 'utf8').replace(/^﻿/, '')
      .split('\r\n').filter(Boolean);

    // Type a note first.
    await page.locator('.roster td.who button').first().click();
    await page.locator('#sheet .note textarea').fill('Typed at the desk.');
    await page.locator('#sheet .note textarea').blur();
    await expect(page.locator('#sheet .note .state')).toHaveText('Saved');
    await page.keyboard.press('Escape');

    // Now import a file that would replace it.
    const col = (h) => lines[0].split(',').indexOf(h);
    const r1 = lines[1].split(',');
    r1[col('Other sports played')] = 'From the sheet';
    const edited = info.outputPath('edited2.csv');
    fs.writeFileSync(edited, `${[lines[0], r1.join(',')].join('\r\n')}\r\n`);
    await page.locator('.biobar').first().locator('input[type=file]').setInputFiles(edited);
    await expect(page.locator('#sheet')).toHaveClass(/open/);
    await expect(page.locator('#sheetCard')).toContainText('already written here, kept');
    // Nothing to import means no Import button at all.
    await expect(page.locator('#sheetCard .barbtn.primary')).toHaveCount(0);
    await page.locator('#sheetCard .barbtn').click();

    await page.locator('.roster td.who button').first().click();
    await expect(page.locator('#sheet .note textarea')).toHaveValue('Typed at the desk.');
  });

  test('the room is the code alone, so notes outlive one fixture', async ({ request }) => {
    // The structural guarantee behind "a note is worth the same in the final as
    // in the quarter". If this endpoint ever grew a game parameter that scoped
    // the room, every note would silently vanish between rounds.
    await request.post(NOTES, { data: { code: CODE, player: 4242, text: 'Across fixtures.' } });

    const plain = await (await request.get(`${NOTES}&code=${CODE}`)).json();
    const withGame = await (await request.get(`${NOTES}&code=${CODE}&game=${GAME_ID}`)).json();
    const otherGame = await (await request.get(`${NOTES}&code=${CODE}&game=999999`)).json();

    expect(plain.players['4242'].text).toBe('Across fixtures.');
    expect(withGame.players).toEqual(plain.players);
    expect(otherGame.players).toEqual(plain.players);
  });
});
