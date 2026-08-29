// @ts-check
/**
 * The mixed line picker's grouping: who is offered, in what order, and who is
 * set aside once a quota is filled.
 *
 * Tested directly rather than through the page (AGENTS.md): this decides what
 * a picker offers a commentator mid-tournament, and the browser adds nothing
 * to that question but a fixture with a declared ratio.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const Ratio = require('../../shared/ratio.js');
const Lineup = require('../../shared/lineup.js');

const P = (id, matching) => ({ id, matching });
const SQUAD = [
  P(1, 'MMP'), P(2, 'FMP'), P(3, 'MMP'), P(4, 'FMP'), P(5, 'MMP'),
  P(6, 'FMP'), P(7, 'MMP'), P(8, 'FMP'), P(9, 'MMP'), P(10, ''),
];

test.describe('ratio quotas', () => {
  test('a stored ratio parses into per-matching quotas', () => {
    expect(Ratio.counts('4MMP/3FMP')).toEqual({ MMP: 4, FMP: 3 });
    expect(Ratio.counts('2mmp/3fmp')).toEqual({ MMP: 2, FMP: 3 });
  });

  test('anything else is null, not a guess', () => {
    for (const bad of ['', null, '4M/3F', '4MMP', '4MMP/3MMP', 'x']) {
      expect(Ratio.counts(bad), JSON.stringify(bad)).toBeNull();
    }
  });
});

test.describe('lineup grouping', () => {
  test('the minority matching lists first — the short list is clicked first', () => {
    const out = Lineup.groups(SQUAD, Ratio.counts('4MMP/3FMP'), []);
    expect(out.groups.map((g) => g.matching)).toEqual(['FMP', 'MMP']);
    const flipped = Lineup.groups(SQUAD, Ratio.counts('3MMP/4FMP'), []);
    expect(flipped.groups.map((g) => g.matching)).toEqual(['MMP', 'FMP']);
  });

  test('a filled quota marks its group done', () => {
    const out = Lineup.groups(SQUAD, Ratio.counts('4MMP/3FMP'), [2, 4, 6]);
    const fmp = out.groups[0];
    expect(fmp.matching).toBe('FMP');
    expect(fmp.picked).toBe(3);
    expect(fmp.full).toBe(true);
    expect(out.groups[1].full).toBe(false);
  });

  test('players without a matching are their own group, never hidden by a quota', () => {
    // Absent is not a matching: an empty spreadsheet cell must not make a
    // player disappear from the picker.
    const out = Lineup.groups(SQUAD, Ratio.counts('4MMP/3FMP'), [2, 4, 6]);
    expect(out.unknown.map((p) => p.id)).toEqual([10]);
  });

  test('no ratio means no grouping — the flat list stands', () => {
    expect(Lineup.groups(SQUAD, null, [])).toBeNull();
    expect(Lineup.groups(SQUAD, Ratio.counts('nonsense'), [])).toBeNull();
  });

  test('an over-picked group still reports honestly', () => {
    // The store cannot be written illegally from here, but a line picked
    // before the ratio was declared can exceed the quota — the group must say
    // so rather than clamp.
    const out = Lineup.groups(SQUAD, Ratio.counts('4MMP/3FMP'), [2, 4, 6, 8]);
    expect(out.groups[0].picked).toBe(4);
    expect(out.groups[0].full).toBe(true);
  });
});
