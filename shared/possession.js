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
     * Break chances and how many became breaks, per side.
     *
     * A break chance is a point where the defending team got the disc. That is
     * only knowable where somebody was tracking, so this counts `tracked` as
     * well — and every caller must show it. "Two breaks from three chances" over
     * four tracked points out of fourteen is not a statistic, it is a guess with
     * a denominator attached, and a card that omits the denominator is asserting
     * the stronger claim.
     *
     * Walks the goals reconstructing the score before each one, mirroring
     * classifyPoints(): whoever concedes receives next, so the side NOT
     * receiving is the one defending and the one a break chance belongs to.
     */
    function conversion(events, goals, startingOffenceSide) {
        var out = {
            home: { chances: 0, converted: 0 },
            visitor: { chances: 0, converted: 0 },
            tracked: 0,
            points: 0
        };
        var ordered = (goals || []).slice().sort(function (a, b) { return a.num - b.num; });
        var receiving = startingOffenceSide || null;
        var home = 0, visitor = 0;

        for (var i = 0; i < ordered.length; i += 1) {
            var g = ordered[i];
            var scoredBy = Number(g.ishomegoal) === 1 ? 'home' : 'visitor';
            out.points += 1;

            if (receiving) {
                var defending = receiving === 'home' ? 'visitor' : 'home';
                if (eventsFor(events, scoreKey(home, visitor)).length > 0) {
                    out.tracked += 1;
                    if (defenceTouched(events, home, visitor)) {
                        out[defending].chances += 1;
                        if (scoredBy === defending) { out[defending].converted += 1; }
                    }
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
        conversion: conversion
    };
}(typeof window !== 'undefined' ? window : this));
