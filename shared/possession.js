/**
 * Reading the operator-declared possession log.
 *
 * One module, because four surfaces ask questions of the same log and must not
 * disagree about the answers: the scoreboard (is there a break chance right now,
 * was that a clean hold), the commentator page (who has the disc, how scrappy is
 * this point), and the half-time and final cards (how many break chances, how
 * many converted). A second implementation of "which events belong to this
 * point" is a second chance to get it wrong on air.
 *
 * The log is append-only and every event carries the score it was made at — see
 * shared/possession.php. So a point is identified by its score, and everything
 * below is a filter over that.
 *
 * ES5 on purpose: this is loaded by the scoreboard, which runs inside a video
 * switcher's browser source alongside the rest of the page scripts.
 */
(function (root) {
    'use strict';

    function scoreKey(home, visitor) {
        return (Number(home) || 0) + '-' + (Number(visitor) || 0);
    }

    /** Events belonging to one point, oldest first. */
    function eventsFor(events, key) {
        var out = [];
        (events || []).forEach(function (e) {
            if (e && e.score === key) { out.push(e); }
        });
        return out;
    }

    /**
     * Does the defence have the disc right now?
     *
     * No events for this score means nobody has said otherwise since the last
     * goal, which is exactly the state a point starts in — the receiving team
     * has it. That is why a point boundary needs no reset: the new score simply
     * has no events yet.
     */
    function defenceHasDisc(events, home, visitor) {
        var mine = eventsFor(events, scoreKey(home, visitor));
        return mine.length > 0 && mine[mine.length - 1].d === 1;
    }

    /**
     * Did the defence ever touch the disc during the point played at this score?
     *
     * Asked of the PREVIOUS score once a goal lands, which is how a clean hold
     * is told from an ordinary one. A mutable flag could not answer this: it
     * would already have been reset by the goal.
     */
    function defenceTouched(events, home, visitor) {
        var mine = eventsFor(events, scoreKey(home, visitor));
        for (var i = 0; i < mine.length; i += 1) {
            if (mine[i].d === 1) { return true; }
        }
        return false;
    }

    /** How many times possession changed during that point. */
    function turnovers(events, home, visitor) {
        var mine = eventsFor(events, scoreKey(home, visitor));
        var count = 0;
        var state = 0;
        for (var i = 0; i < mine.length; i += 1) {
            if (mine[i].d !== state) { count += 1; state = mine[i].d; }
        }
        return count;
    }

    /**
     * Roll the log up against a goal list, for a recap card.
     *
     * Walks the goals in order reconstructing the score before each one, so each
     * point can be asked its own question. Only points the operator was actually
     * tracking are counted — `tracked` says how many those were, and any figure
     * here is meaningless without it. A "2 breaks from 3 chances" claim built on
     * four tracked points out of fourteen is not a statistic, it is a guess with
     * a denominator, and the card must be able to say so.
     *
     * `upTo` limits the walk to the first N goals, which is what the half-time
     * card wants; omit it for the whole game.
     */
    function summarise(events, goals, startingOffence, upTo) {
        var ordered = (goals || []).slice().sort(function (a, b) { return a.num - b.num; });
        if (typeof upTo === 'number') { ordered = ordered.slice(0, upTo); }

        var home = 0, visitor = 0;
        var receiving = startingOffence || null;
        var out = { points: 0, tracked: 0, breaks: 0, holds: 0, cleanHolds: 0,
                    breakChances: 0, converted: 0, turnovers: 0 };

        for (var i = 0; i < ordered.length; i += 1) {
            var g = ordered[i];
            var key = scoreKey(home, visitor);
            var scoredBy = Number(g.ishomegoal) === 1 ? 'home' : 'visitor';
            var touched = defenceTouched(events, home, visitor);
            var seen = eventsFor(events, key).length > 0;

            out.points += 1;
            if (seen) {
                out.tracked += 1;
                out.turnovers += turnovers(events, home, visitor);
                if (touched) { out.breakChances += 1; }
            }

            if (receiving) {
                if (scoredBy === receiving) {
                    out.holds += 1;
                    // Clean only where we were actually watching. An untracked
                    // point is unknown, not clean.
                    if (seen && !touched) { out.cleanHolds += 1; }
                } else {
                    out.breaks += 1;
                    if (seen && touched) { out.converted += 1; }
                }
                receiving = scoredBy === 'home' ? 'visitor' : 'home';
            }

            if (scoredBy === 'home') { home += 1; } else { visitor += 1; }
        }
        return out;
    }

    root.Possession = {
        scoreKey: scoreKey,
        eventsFor: eventsFor,
        defenceHasDisc: defenceHasDisc,
        defenceTouched: defenceTouched,
        turnovers: turnovers,
        summarise: summarise
    };
}(typeof window !== 'undefined' ? window : this));
