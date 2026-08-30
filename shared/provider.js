/**
 * Where a payload comes from.
 *
 * Every page here reads the same handful of things — one game, the game list,
 * a team's roster, a player's history, the event's reference data and Live!'s
 * config — and until now each page built those URLs and handled their errors
 * itself. Eighteen entity reads across four files, and **two** copies of the
 * response contract: one in `shared/tracking.js` and a second in `index.php`,
 * which is the duplication `tracking.js` was written to end and did not reach.
 *
 * This is that read side, once. A caller says what it wants, not how to ask.
 *
 * WHY IT IS A SEAM AND NOT JUST A TIDY-UP
 *
 * `docs/STANDALONE.md` proposes running these overlays without UltiOrganizer,
 * where the same questions are answered from local files instead of the API.
 * The rule there is **one renderer, one payload shape, two providers** — so
 * this is the joint that a second provider would plug into. Nothing above it
 * learns which one it is talking to.
 *
 * That is also why the shape it returns is Live!'s, warts included. A provider
 * that tidied the payload would create a second contract for every consumer to
 * branch on, and `docs/PLAN.md` already lists the field names that have caught
 * people out (`teams.hometeam`, not `awayteam`).
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Provider = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * The API's response contract, in one place.
     *
     * 503 is the maintenance splash and is HTML rather than JSON, so it must
     * never be parsed. An error body is `{"error": "<string>"}` — a plain
     * string, not an object. And 400 or 403 mean the id is unknown, belongs to
     * another event, or the event is not published: none of those is fixed by
     * asking again, so they are marked fatal and a caller that polls can stop
     * rather than spend a broadcast retrying.
     *
     * Each of those is a thing Live! v3 changed, and each is a thing a second
     * copy of this function eventually gets wrong. There were two.
     */
    function readJson(response) {
        if (response.status === 503) {
            throw fail('Live! is in maintenance mode.', 503, false);
        }
        return response.json().then(function (body) {
            if (!response.ok || (body && typeof body.error === 'string')) {
                var message = (body && body.error) || ('HTTP ' + response.status);
                throw fail(message, response.status,
                    response.status === 400 || response.status === 403);
            }
            return body;
        }, function () {
            // A body that is not JSON at all. Distinguished from an error body
            // because the message a caller shows should not be a parser's.
            throw fail('Unparseable response (HTTP ' + response.status + ')',
                response.status, false);
        });
    }

    /** An Error carrying what a polling caller needs to decide whether to retry. */
    function fail(message, status, fatal) {
        var err = new Error(message);
        err.status = status;
        err.fatal = Boolean(fatal);
        return err;
    }

    /**
     * A reader bound to one API base.
     *
     * `apiBase` is the routed URL — Live! v3 serves the API only through
     * UltiOrganizer's front controller (`?view=live/api`), and a request
     * straight to `live/api.php` is a 404 — so it is injected by the hosting
     * page rather than guessed from the current path.
     *
     * Mirrors `Tracking.client()`: the caller supplies the one thing only it
     * knows, and nothing else.
     */
    function live(opts) {
        var apiBase = (opts || {}).apiBase;
        var fetchImpl = (opts || {}).fetch
            || (typeof fetch === 'function' ? fetch : null);

        function get(query) {
            return fetchImpl(apiBase + query, { credentials: 'same-origin' })
                .then(readJson);
        }

        return {
            /** One game's full payload — the thing every overlay renders. */
            game: function (id) {
                return get('&entity=games&id=' + encodeURIComponent(id));
            },
            /** Every game in the event. */
            games: function () { return get('&entity=games'); },
            /** One team, with the roster and its tournament totals. */
            team: function (id) {
                return get('&entity=teams&id=' + encodeURIComponent(id));
            },
            /** Every team in the event. */
            teams: function () { return get('&entity=teams'); },
            /** One player's game-by-game history. */
            playerEvents: function (id) {
                return get('&entity=playerevents&id=' + encodeURIComponent(id));
            },
            /**
             * Event reference data. Carries `reservations[].fieldname`, which is
             * how a field name is resolved for a whole event in one request
             * rather than one game-detail fetch per row.
             */
            reference: function () { return get('&entity=reference'); },
            /** Live!'s own configuration. */
            config: function () { return get('&entity=config'); }
        };
    }

    return { live: live, readJson: readJson, fail: fail };
}));
