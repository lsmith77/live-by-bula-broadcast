/**
 * Timeouts remaining, per side.
 *
 * The API serves the allowance but not the count. `poolinfo.timeouts` is how
 * many each side gets; each one taken is a `gameevents` entry of type
 * `timeout` carrying `ishome`. So "how many are left" is a derivation, and it
 * has one subtlety that is easy to get wrong in a second copy:
 *
 *   `timeoutsper: "half"` means the allowance RESETS at the break, so only
 *   timeouts taken since halftime count in the second half. Halftime is marked
 *   by a `half_cap` event (UO's GameCapEventTypes()), and a game with no such
 *   event yet is still in the first half.
 *
 * It lived inline in `scoreboard.php` while the scoreboard was the only thing
 * that knew about timeouts. It is here because the commentary desk and the
 * Studio need the same answer, and `AGENTS.md` is explicit about why that move
 * comes first: a derivation used twice is a derivation about to be written
 * twice, which is how the gender ratio came to be printed two different ways
 * before `shared/ratio.js` existed.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Timeouts = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * @param pool       `poolinfo` — `timeouts` is the allowance, `timeoutsper`
     *                   is 'half' when it resets at the break
     * @param gameEvents `gameevents`, or anything that is not an array
     * @param isHome     which side
     * @return {{allowance: number, used: number, remaining: number}}, or NULL
     *         when this pool does not give timeouts at all.
     *
     * Null rather than zero, and the distinction is the project's standing one:
     * absent is not zero. A pool with no allowance recorded is not a pool where
     * both sides have used everything — the scoreboard hides the ticks rather
     * than drawing an empty row, and every other reader should hide it too.
     */
    function remaining(pool, gameEvents, isHome) {
        var allowance = Number((pool || {}).timeouts);
        if (!isFinite(allowance) || allowance <= 0) { return null; }

        var events = Array.isArray(gameEvents) ? gameEvents : [];

        // The last half_cap, not the first: a game can only be in one half, and
        // the most recent break is the one the allowance resets from.
        var since = 0;
        if ((pool || {}).timeoutsper === 'half') {
            events.forEach(function (e) {
                if (e && e.type === 'half_cap' && Number(e.time) > since) {
                    since = Number(e.time);
                }
            });
        }

        var used = events.filter(function (e) {
            return e && e.type === 'timeout'
                && (Number(e.ishome) === 1) === !!isHome
                && Number(e.time) >= since;
        }).length;

        return {
            allowance: allowance,
            used: used,
            // Clamped, because a scoresheet can record more timeouts than the
            // pool allows and a negative count would render as nothing at all
            // rather than as the "none left" it means.
            remaining: Math.max(0, allowance - used)
        };
    }

    /** "2 of 3 left", or null when the pool gives none. For a text surface. */
    function label(pool, gameEvents, isHome) {
        var t = remaining(pool, gameEvents, isHome);
        if (!t) { return null; }
        return t.remaining + ' of ' + t.allowance + ' left';
    }

    return { remaining: remaining, label: label };
}));
