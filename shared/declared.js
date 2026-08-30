/**
 * A fact about the game that nothing upstream records, declared at a desk.
 *
 * The gender ratio and the line size are the same shape: somebody watching the
 * game says what is true, it is SHARED through the possession store when the
 * desk holds the operator's code, it is kept on THIS SCREEN when it does not,
 * and a shared value replaces a local one the moment one appears — leaving a
 * one-shot note saying so, because a value changing under a commentator with
 * no explanation is worse than the disagreement it resolves.
 *
 * That shape was written twice. Each copy had a storage key, a local read, a
 * local write, a reconcile, an is-local test, a setter and a note, differing
 * only in how a value is validated and which store field carries it — and the
 * copies had already drifted once: the size setter had to clear a ratio that no
 * longer fitted, and nothing would have caught that rule being absent from one
 * of them. Written once, that class of bug cannot recur.
 *
 * Pure: it is handed the store's current value and a way to push, and knows
 * nothing about fetch, rendering or the possession endpoint.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Declared = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * @param opts.key      localStorage key, already scoped to the game
     * @param opts.read     () -> the shared value, or null when nobody shared one
     * @param opts.parse    (stored) -> a valid value, or null. The ONLY validator:
     *                      storage is a place a stale build or another tab wrote.
     * @param opts.push     (value) -> Promise, called only when the desk can share
     * @param opts.canShare () -> whether this desk may write to the room
     * @param opts.onLocal  optional (value) -> void, after a local change lands —
     *                      where a value that invalidates another one says so
     * @param opts.storage  optional, for tests; defaults to window.localStorage
     */
    function value(opts) {
        var store = opts.storage
            || (typeof window !== 'undefined' ? window.localStorage : null);

        function local() {
            try {
                if (!store) { return null; }
                return opts.parse(store.getItem(opts.key));
            } catch (e) { return null; }        // private mode, or a hostile value
        }

        function remember(v) {
            try {
                if (!store) { return; }
                if (v === null || v === undefined || v === '') { store.removeItem(opts.key); }
                else { store.setItem(opts.key, String(v)); }
            } catch (e) { /* private mode: the value simply does not persist */ }
        }

        return {
            /** The value in force: shared first, then this screen's own. */
            get: function () {
                var shared = opts.read();
                return (shared === null || shared === undefined || shared === '')
                    ? local() : shared;
            },

            /** True when the value showing is this screen's and nobody else's. */
            isLocal: function () {
                var shared = opts.read();
                return (shared === null || shared === undefined || shared === '')
                    && local() !== null;
            },

            /**
             * Shared wins; local only ever fills a gap. Run before every render.
             *
             * Returns {local, shared} when a shared value displaced a DIFFERENT
             * local one and the desk should be told, else null. A shared value
             * that merely agrees retires the local copy silently.
             */
            reconcile: function () {
                var mine = local();
                var shared = opts.read();
                if (mine === null || shared === null || shared === undefined || shared === '') {
                    return null;
                }
                var note = String(shared) !== String(mine) ? { local: mine, shared: shared } : null;
                remember(null);
                return note;
            },

            /** Declare it — to the room if this desk may, else to this screen. */
            set: function (v) {
                if (!opts.canShare()) {
                    remember(v);
                    if (opts.onLocal) { opts.onLocal(v); }
                    return Promise.resolve(null);
                }
                return opts.push(v).then(function (state) {
                    // The room now carries it, so the local copy would only be
                    // a second answer to a settled question.
                    remember(null);
                    return state;
                });
            },

            /** For the caller that has to drop a local value on its own terms. */
            clearLocal: function () { remember(null); },
            localValue: local
        };
    }

    return { value: value };
}));
