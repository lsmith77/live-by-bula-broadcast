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

    /**
     * The file a capture stores each answer in.
     *
     * Flat, named after the request rather than hashed, so a capture directory
     * can be read by a person — which matters because the next thing anyone
     * does with a bug report is look inside it.
     */
    function keyFor(entity, id) {
        return id === undefined || id === null || id === ''
            ? entity + '.json'
            : entity + '-' + String(id).replace(/[^0-9A-Za-z_-]/g, '') + '.json';
    }

    /**
     * A reader answering from a capture on disk instead of from Live!.
     *
     * Same interface, same shape, same field names — `docs/STANDALONE.md` §7:
     * one renderer, one payload shape, two providers. A caller cannot tell.
     *
     * THE CLOCK, WHICH IS THE WHOLE SUBTLETY
     *
     * A capture is a snapshot and the clock is not. `timer_start` is absolute
     * unix seconds and the scoreboard computes `now - timer_start`, so a
     * payload recorded on Saturday and replayed on Tuesday shows a game that
     * has been running for three days.
     *
     * So the recorded game is rebased by the age of the capture. Doing that on
     * EVERY read holds the clock exactly where it was when the capture was
     * taken, which is the behaviour a recording should have: a recording of a
     * 14th minute is a recording of the 14th minute whenever you play it, and a
     * test asserting on it cannot go stale overnight. Pass `rebase: 'run'` for
     * a clock that starts from the captured moment and then advances, which is
     * what a demo wants and a test does not.
     *
     * This is the third time this project has manufactured a `timer_start` —
     * `shared/demo.js` does it because fixture 702 has none, and the
     * post-production frame does it to draw one deterministic frame. Third time
     * is where it stops being a coincidence.
     */
    function recorded(opts) {
        var base = String((opts || {}).base || '').replace(/\/$/, '');
        var fetchImpl = (opts || {}).fetch
            || (typeof fetch === 'function' ? fetch : null);
        var nowFn = (opts || {}).now || function () { return Date.now(); };
        var mode = (opts || {}).rebase === 'run' ? 'run' : 'freeze';
        var manifest = null;
        var startedAt = null;

        function read(name) {
            return fetchImpl(base + '/' + name, { credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        // Absent is absent, and says which file. A capture with a
                        // missing roster should name it rather than fail as if
                        // the whole recording were bad.
                        throw fail('Not in this capture: ' + name, response.status, true);
                    }

                    return response.json();
                });
        }

        function capturedAt() {
            if (manifest) { return Promise.resolve(manifest); }

            return read('manifest.json').then(function (m) {
                manifest = m || {};

                return manifest;
            }, function () {
                // A capture without a manifest still serves every payload; only
                // the clock cannot be rebased, and a clock left alone is more
                // honest than one shifted by a guessed amount.
                manifest = {};

                return manifest;
            });
        }

        /** Shift the absolute clock fields by the capture's age. */
        function rebase(payload, gameId) {
            return capturedAt().then(function (m) {
                // Per game first: one directory can hold several, recorded
                // minutes apart, and replaying both from one instant puts one
                // of the clocks out by the gap without anything saying so.
                var perGame = m.games && gameId !== undefined && gameId !== null
                    ? m.games[String(gameId)]
                    : undefined;
                var taken = Number(perGame !== undefined ? perGame : m.captured_at);
                var result = payload && payload.game_result;
                if (!isFinite(taken) || !result) { return payload; }

                if (startedAt === null) { startedAt = Math.floor(nowFn() / 1000); }
                var anchor = mode === 'run' ? startedAt : Math.floor(nowFn() / 1000);
                var shift = anchor - taken;

                ['timer_start', 'timer_pause_start'].forEach(function (field) {
                    var v = Number(result[field]);
                    // Only a real timestamp moves. null means "never started",
                    // which is a fact about the game and not a clock to shift.
                    if (isFinite(v) && v > 0) { result[field] = v + shift; }
                });

                return payload;
            });
        }

        return {
            game: function (id) {
                return read(keyFor('games', id)).then(function (p) {
                    return rebase(p, id);
                });
            },
            games: function () { return read(keyFor('games')); },
            team: function (id) { return read(keyFor('teams', id)); },
            teams: function () { return read(keyFor('teams')); },
            playerEvents: function (id) { return read(keyFor('playerevents', id)); },
            reference: function () { return read(keyFor('reference')); },
            config: function () { return read(keyFor('config')); },
            manifest: capturedAt
        };
    }

    /**
     * The provider a page should use, from what the page was told.
     *
     * One place decides, so four pages do not each grow their own `if`. A
     * capture base wins when one is configured — that is what "this
     * installation has no Live!" looks like from in here.
     */
    function fromConfig(cfg) {
        cfg = cfg || {};

        return cfg.captureBase
            ? recorded({ base: cfg.captureBase, rebase: cfg.rebase })
            : live({ apiBase: cfg.apiBase });
    }

    return {
        live: live,
        recorded: recorded,
        fromConfig: fromConfig,
        keyFor: keyFor,
        readJson: readJson,
        fail: fail
    };
}));
