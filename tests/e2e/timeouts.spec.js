// @ts-check
/**
 * Timeouts remaining, derived rather than served.
 *
 * Tested directly rather than through a page (AGENTS.md): this is a pure
 * function of the pool's allowance and the game's events, and the browser adds
 * nothing to the question but a fixture that happens to have taken one.
 *
 * The half-reset is the case worth the tests. It is the part a second copy of
 * this derivation would have got wrong, and it is invisible for the whole first
 * half of every game — so a defect in it would ship, look correct all morning,
 * and start lying after the break.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const Timeouts = require('../../shared/timeouts.js');

/** Two per side, resetting at the break — the WFDF-shaped default. */
const PER_HALF = { timeouts: 2, timeoutsper: 'half' };
/** Two per side for the whole game. */
const PER_GAME = { timeouts: 2, timeoutsper: 'game' };

const to = (isHome, time) => ({ type: 'timeout', ishome: isHome ? 1 : 0, time });
const half = (time) => ({ type: 'half_cap', time });

test.describe('timeouts remaining', () => {
  test('counts each side separately', () => {
    const events = [to(true, 100), to(true, 200), to(false, 300)];
    expect(Timeouts.remaining(PER_GAME, events, true))
      .toEqual({ allowance: 2, used: 2, remaining: 0 });
    expect(Timeouts.remaining(PER_GAME, events, false))
      .toEqual({ allowance: 2, used: 1, remaining: 1 });
  });

  test('a pool that gives no timeouts is null, not zero', () => {
    // Absent is not zero: a pool with no allowance is not a pool where both
    // sides have used everything, and a reader must hide the row rather than
    // draw an empty one.
    for (const pool of [{}, { timeouts: 0 }, { timeouts: null }, { timeouts: 'x' }, null]) {
      expect(Timeouts.remaining(pool, [], true), JSON.stringify(pool)).toBeNull();
      expect(Timeouts.label(pool, [], true), JSON.stringify(pool)).toBeNull();
    }
  });

  test('the allowance resets at the break when it is per half', () => {
    const events = [to(true, 100), to(true, 200), half(1500), to(true, 1600)];
    // Both first-half timeouts are spent, but the break gave them two more.
    expect(Timeouts.remaining(PER_HALF, events, true).remaining).toBe(1);
    // The same events without the reset rule spend all of them and then some.
    expect(Timeouts.remaining(PER_GAME, events, true))
      .toEqual({ allowance: 2, used: 3, remaining: 0 });
  });

  test('before the break, a per-half pool counts the whole game so far', () => {
    const events = [to(true, 100)];
    expect(Timeouts.remaining(PER_HALF, events, true).remaining).toBe(1);
  });

  test('the LAST break is the one that resets', () => {
    // A game can only be in one half, but nothing stops two half_cap events
    // existing — and resetting from the first would credit timeouts that were
    // taken before the second.
    const events = [half(1000), to(true, 1100), half(2000), to(true, 2100)];
    expect(Timeouts.remaining(PER_HALF, events, true).remaining).toBe(1);
  });

  test('a timeout exactly at the break counts as after it', () => {
    // The boundary is inclusive on purpose: an event stamped at the same second
    // as the cap is the one being called at the break, not before it.
    const events = [half(1500), to(true, 1500)];
    expect(Timeouts.remaining(PER_HALF, events, true).used).toBe(1);
  });

  test('more taken than allowed reads as none left, never as negative', () => {
    // A scoresheet can record more than the pool allows. A negative count would
    // render as no ticks at all, which reads as "no allowance" rather than as
    // the "none left" it means.
    const events = [to(true, 1), to(true, 2), to(true, 3)];
    const t = Timeouts.remaining(PER_GAME, events, true);
    expect(t.used).toBe(3);
    expect(t.remaining).toBe(0);
  });

  test('anything that is not a list of events is an empty one', () => {
    for (const bad of [null, undefined, 'timeout', 42, {}]) {
      expect(Timeouts.remaining(PER_GAME, bad, true).remaining, JSON.stringify(bad)).toBe(2);
    }
  });

  test('the label says both numbers, because one is meaningless alone', () => {
    // "1 left" is not readable on air without knowing whether the allowance was
    // one or four.
    expect(Timeouts.label(PER_GAME, [to(true, 1)], true)).toBe('1 of 2 left');
    expect(Timeouts.label(PER_GAME, [], false)).toBe('2 of 2 left');
  });
});
