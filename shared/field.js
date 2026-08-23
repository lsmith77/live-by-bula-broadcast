/**
 * Resolving "whichever game is live on field 3" to a game id.
 *
 * At a tournament one camera covers one field all day while the games on it
 * change every ninety minutes. Pinning an overlay URL to a game id means somebody
 * walks to the switcher between rounds and retypes it — on an on-screen keyboard,
 * usually while the next game is already pulling. A field-following URL is set up
 * once in the morning and left alone.
 *
 * The join is not direct: `entity=games` carries a `reservation`, and
 * `entity=reference` carries `reservations[].fieldname`. Both are cached payloads
 * the overlays already fetch, so this costs no new server work.
 *
 * ES5 on purpose: loaded by the scoreboard, which runs in a switcher's browser
 * source.
 */
(function (root) {
    'use strict';

    /**
     * Which game a field is showing right now.
     *
     * Preference order, and the reasoning matters more than the code:
     *
     *   1. A game in progress. Unambiguous — that is what the camera is pointed at.
     *   2. Otherwise the next one scheduled on that field, so between rounds the
     *      overlay shows who is about to play rather than going blank. A camera
     *      operator framing up wants the teams that are warming up.
     *   3. Otherwise the most recently finished, so after the last game of the day
     *      the final score stays up instead of vanishing.
     *
     * Never guesses across fields: with no game on that field at all it returns
     * null and the caller shows nothing, which is correct. An overlay that
     * silently followed a different field would be worse than a blank one.
     */
    function resolveFieldGame(apiBase, fieldname) {
        var want = String(fieldname == null ? '' : fieldname).trim().toLowerCase();
        if (!want) { return Promise.resolve(null); }

        var get = function (entity) {
            return fetch(apiBase + '&entity=' + entity, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .catch(function () { return null; });
        };

        return Promise.all([get('games'), get('reference')]).then(function (res) {
            var games = (res[0] && res[0].games) || [];
            var reservations = (res[1] && res[1].reservations) || [];
            if (!games.length || !reservations.length) { return null; }

            // Reservation id -> field name, so a game can be asked which field
            // it is on. Matched case-insensitively on the trimmed name, because
            // a field is called "1" in one place and " 1" in another.
            var fieldOf = {};
            reservations.forEach(function (r) {
                if (r && r.id !== undefined) {
                    fieldOf[String(r.id)] = String(r.fieldname == null ? '' : r.fieldname)
                        .trim().toLowerCase();
                }
            });

            var here = games.filter(function (g) {
                return fieldOf[String(g.reservation)] === want;
            });
            if (!here.length) { return null; }

            var live = here.filter(function (g) { return g.status === 'ongoing'; });
            if (live.length) { return Number(live[0].game_id); }

            var byTime = function (a, b) {
                return String(a.time || '').localeCompare(String(b.time || ''));
            };

            var upcoming = here.filter(function (g) { return g.status === 'scheduled'; });
            if (upcoming.length) { return Number(upcoming.sort(byTime)[0].game_id); }

            var done = here.filter(function (g) { return g.status === 'completed'; });
            if (done.length) { return Number(done.sort(byTime)[done.length - 1].game_id); }

            return null;
        });
    }

    root.FieldResolver = { resolveFieldGame: resolveFieldGame };
}(typeof window !== 'undefined' ? window : this));
