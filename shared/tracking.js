/**
 * Talking to the collected-facts store.
 *
 * Possession, the first point's gender ratio, and injury stoppages are all
 * things UltiOrganizer does not record, so somebody watching declares them. Two
 * surfaces do the declaring — the Studio and the commentator page — and they had
 * grown their own copies of every call: `readJson`, `setDefence`,
 * `toggleStoppage`, `correct`. Same protocol, written twice, differing only in
 * where the game id and the authorising code came from.
 *
 * This is that protocol, once. Each caller supplies how to answer those two
 * questions and nothing else.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Tracking = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * The API's error contract, in one place.
     *
     * 503 is HTML rather than JSON, an error body is `{"error": "<string>"}`
     * rather than an object, and 400/403 are fatal rather than worth retrying.
     * Each of those is a thing Live! v3 changed and a thing a second copy of
     * this function would eventually get wrong.
     */
    function readJson(response) {
        if (response.status === 503) {
            throw new Error('Live! is in maintenance mode.');
        }
        return response.json().then(function (body) {
            if (!response.ok || typeof body.error === 'string') {
                throw new Error(body.error || ('HTTP ' + response.status));
            }
            return body;
        });
    }

    /**
     * A client bound to one game.
     *
     * `opts.endpoint` is the routed URL, `opts.game` the game id, and
     * `opts.code` an optional function returning the authorising room code —
     * the Studio has an admin session instead and supplies none.
     */
    function client(opts) {
        var endpoint = opts.endpoint;
        var game = Number(opts.game) || 0;
        var codeOf = opts.code || function () { return null; };
        var clientId = opts.clientId || null;
        var nameOf = opts.name || function () { return ''; };

        /** The public document for THIS game, read straight off disk. */
        function stateUrl(assetBase) {
            return assetBase + '/conf/possession-' + game + '.json';
        }

        function pollUrl() {
            var code = codeOf();
            return endpoint
                + '&game=' + encodeURIComponent(game)
                + (code ? '&code=' + encodeURIComponent(code) : '')
                + (clientId ? '&client=' + encodeURIComponent(clientId) : '')
                + (nameOf() ? '&name=' + encodeURIComponent(nameOf()) : '');
        }

        function read() {
            return fetch(pollUrl(), { credentials: 'same-origin' }).then(readJson);
        }

        /** Every write carries the game and, where there is one, the code. */
        function write(change) {
            var body = { game: game };
            var code = codeOf();
            if (code) { body.code = code; }
            Object.keys(change).forEach(function (k) { body[k] = change[k]; });
            return fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(readJson);
        }

        return {
            game: game,
            stateUrl: stateUrl,
            read: read,
            write: write,
            setDefence: function (hasDisc, score) {
                return write({ score: score, defence: Boolean(hasDisc) });
            },
            setStoppage: function (on, score) {
                return on ? write({ score: score, stoppage: true })
                    : write({ stoppage: false });
            },
            setRatio: function (ratio) { return write({ ratio1: ratio || '' }); },
            setSize: function (size) { return write({ size: size || '' }); },
            undoLast: function (score) { return write({ score: score, undo: true }); },
            clearPoint: function (score) { return write({ score: score, clearPoint: true }); },
            deleteAt: function (score, at) { return write({ score: score, at: at }); }
        };
    }

    return { readJson: readJson, client: client };
}));
