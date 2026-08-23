/**
 * What has stopped play, and for how long.
 *
 * A scoreboard's hardest job is explaining why nothing is happening. A goal
 * announces itself, but a stoppage looks exactly like a dull passage of play
 * unless the board says otherwise — so this works out whether something is
 * currently interrupting the game and what it is.
 *
 * Timeouts come from `gameevents`, which UltiOrganizer already records. Other
 * kinds of stoppage — an injury, a discussion — are recorded nowhere and would
 * have to be declared, the way possession is.
 *
 * Kept out of the page so the window logic can be tested directly. It is a pure
 * function of (events, elapsed, length), and testing it through a browser meant
 * fighting Live!'s 30-second payload cache to move a clock — a test that was
 * measuring the previous clock while appearing to measure this one.
 *
 * ES5 on purpose: loaded by the scoreboard, inside a switcher's browser source.
 */
/*
 * Loaded both by a browser page and by the test runner, so it publishes to
 * `window` and to `module.exports` alike. Relying on top-level `this` is not
 * enough: it is `module.exports` under plain CommonJS but `undefined` under the
 * loader Playwright uses, which fails at import time rather than at use.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Stoppage = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * The stoppage in progress, or null.
     *
     * `elapsed` is the GAME clock in seconds, and using it rather than wall time
     * is what makes this work for both scorekeeping habits without knowing which
     * is in use:
     *
     *   - Clock paused for the timeout, which is the common case: the game clock
     *     does not advance, the age stays put, and the tab stays up for exactly
     *     as long as play is stopped. The pause IS the window.
     *   - Clock left running: the age grows and the tab drops off after the
     *     configured length.
     *
     * Only the most recent event counts. An earlier timeout is history, and a
     * goal or a cap since means play restarted — so a stale tab cannot survive
     * into the next passage, which is the way this could put something untrue on
     * air.
     */
    function active(events, elapsed, timeoutLen) {
        if (!Array.isArray(events) || !events.length) { return null; }
        if (typeof elapsed !== 'number' || !isFinite(elapsed)) { return null; }
        var len = Number(timeoutLen);
        if (!isFinite(len) || len <= 0) { return null; }

        // The last event that has actually HAPPENED, not simply the one with the
        // greatest timestamp. Those are the same thing during a live game, where
        // nothing is recorded ahead of the clock -- but not when the board is
        // drawing an earlier moment, which is exactly what post-production does
        // with `?at=`. Reading the greatest timestamp there picks an event from
        // the future of the frame being drawn.
        var last = null;
        for (var i = 0; i < events.length; i += 1) {
            var e = events[i];
            if (!e || typeof e.time === 'undefined') { continue; }
            var when = Number(e.time);
            if (!isFinite(when) || when > elapsed) { continue; }
            if (last === null || when >= Number(last.time)) { last = e; }
        }
        if (!last || last.type !== 'timeout') { return null; }

        var age = elapsed - (Number(last.time) || 0);
        if (age >= len) { return null; }

        return { kind: 'timeout', side: Number(last.ishome) === 1 ? 'home' : 'visitor' };
    }

    return { active: active };
}));
