// @ts-check
/**
 * Declared values: shared when the desk is linked, local when it is not.
 *
 * Tested directly rather than through the page (AGENTS.md): this decides what a
 * commentator sees when their own answer and the room's disagree, and the
 * browser adds nothing to that question. Every case here is one the two
 * hand-written copies had to get right separately.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const Declared = require('../../shared/declared.js');

/** A localStorage that behaves, and one that throws like a locked-down browser. */
function fakeStore(initial) {
  const map = new Map(Object.entries(initial || {}));
  return {
    getItem: (k) => (map.has(k) ? map.get(k) : null),
    setItem: (k, v) => map.set(k, String(v)),
    removeItem: (k) => map.delete(k),
    _map: map,
  };
}

const throwingStore = {
  getItem() { throw new Error('denied'); },
  setItem() { throw new Error('denied'); },
  removeItem() { throw new Error('denied'); },
};

/** A value shaped like the line size: an integer from a small set. */
function sizeValue(over) {
  const state = { shared: null, pushed: [], canShare: false, local: [] };
  const v = Declared.value({
    key: 'k',
    storage: over && over.storage !== undefined ? over.storage : fakeStore(over && over.initial),
    read: () => state.shared,
    parse: (raw) => ([4, 5, 6, 7].includes(Number(raw)) ? Number(raw) : null),
    push: (n) => { state.pushed.push(n); state.shared = n; return Promise.resolve({ size: n }); },
    canShare: () => state.canShare,
    onLocal: (n) => state.local.push(n),
  });
  return { v, state };
}

test.describe('a declared value', () => {
  test('falls back to the local answer, and prefers the shared one', () => {
    const { v, state } = sizeValue({ initial: { k: '6' } });
    expect(v.get()).toBe(6);
    expect(v.isLocal()).toBe(true);

    state.shared = 5;
    expect(v.get()).toBe(5);
    expect(v.isLocal(), 'a shared value is nobody screen\'s own').toBe(false);
  });

  test('an unlinked desk writes to its own screen, not the room', async () => {
    const { v, state } = sizeValue();
    await v.set(7);
    expect(state.pushed, 'nothing may reach the room without the code').toEqual([]);
    expect(v.get()).toBe(7);
    expect(state.local, 'the caller is told, so it can drop what no longer fits').toEqual([7]);
  });

  test('a linked desk writes to the room and keeps no second copy', async () => {
    const { v, state } = sizeValue();
    state.canShare = true;
    await v.set(6);
    expect(state.pushed).toEqual([6]);
    expect(v.localValue(), 'a local copy would be a second answer').toBeNull();
    expect(v.isLocal()).toBe(false);
  });

  test('a shared value that disagrees displaces the local one, and says so', () => {
    const { v, state } = sizeValue({ initial: { k: '7' } });
    state.shared = 6;
    const note = v.reconcile();
    expect(note).toEqual({ local: 7, shared: 6 });
    expect(v.localValue(), 'the local copy is retired either way').toBeNull();
  });

  test('a shared value that agrees retires the local copy silently', () => {
    const { v, state } = sizeValue({ initial: { k: '6' } });
    state.shared = 6;
    expect(v.reconcile(), 'nothing changed, so there is nothing to report').toBeNull();
    expect(v.localValue()).toBeNull();
  });

  test('nothing to reconcile is not a note', () => {
    const { v, state } = sizeValue();
    expect(v.reconcile(), 'no local value').toBeNull();
    state.shared = 6;
    expect(v.reconcile(), 'no local value, shared only').toBeNull();
  });

  test('a stored value that is no longer valid reads as absent', () => {
    // Storage is a place an older build, another tab or a person can write.
    for (const bad of ['3', '8', 'seven', '', 'null']) {
      const { v } = sizeValue({ initial: { k: bad } });
      expect(v.get(), JSON.stringify(bad)).toBeNull();
      expect(v.isLocal(), JSON.stringify(bad)).toBe(false);
    }
  });

  test('a browser that refuses storage still works, it just does not persist', async () => {
    // Private mode throws on access rather than returning null.
    const { v, state } = sizeValue({ storage: throwingStore });
    expect(v.get()).toBeNull();
    await v.set(6);
    expect(v.get(), 'nothing persisted, and nothing threw').toBeNull();
    expect(state.local).toEqual([6]);
  });
});
