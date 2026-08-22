/**
 * Live! by BULA video overlays — scripted demo driver
 *
 * Steps the scoreboard through every state it can reach, so a layout change can
 * be judged against all of them instead of against whatever the fixture happens
 * to be showing.
 *
 * It works by fetching ONE real payload and mutating copies of it. That matters:
 * a hand-written fake payload drifts from the API shape, and then the demo starts
 * proving things about a scoreboard nobody ships. Every frame here is the real
 * shape with different numbers in it.
 *
 * It is also the only way to see a running clock. Fixture game 702 has
 * timer_start = null, so the live overlay always falls through to a status word;
 * the demo back-dates timer_start and drives a real one.
 *
 * @version 1.1.0
 */

/**
 * @param {Object}   base     A real payload from entity=games, used as the shape.
 * @param {Function} onFrame  Called with (payload, label) for each step.
 * @param {Object}  [opts]    {stepMs: number, loop: boolean}
 * @returns {{stop: Function}}
 */
function runOverlayDemo(base, onFrame, opts) {
    'use strict';

    var stepMs = (opts && opts.stepMs) || 3200;
    var loop = !(opts && opts.loop === false);

    function clone(o) { return JSON.parse(JSON.stringify(o)); }

    // Point 1 starts with home receiving the pull, so home is on offence and
    // away is the side that would break by scoring.
    var START_OFFENCE_HOME = 1;

    var goals, events, home, away, clockBase;

    function reset() {
        goals = [];
        events = [{ time: 0, ishome: START_OFFENCE_HOME, type: 'offence', info: null }];
        home = 0;
        away = 0;
        clockBase = Math.floor(Date.now() / 1000);
    }

    /** Append a goal, keeping the running score consistent with it. */
    function goal(isHome, atSeconds) {
        if (isHome) { home += 1; } else { away += 1; }
        goals.push({
            game: 702,
            num: goals.length + 1,
            time: atSeconds,
            homescore: home,
            visitorscore: away,
            ishomegoal: isHome ? 1 : 0,
            iscallahan: 0,
            scorer: null,
            assist: null
        });
    }

    /**
     * Build a payload for the accumulated state.
     *
     * `elapsed` drives the clock by back-dating timer_start, which is what makes
     * the centre column show a real ticking time instead of a status word.
     */
    function frame(opt) {
        var p = clone(base);
        var o = opt || {};

        p.game_result.homescore = home;
        p.game_result.visitorscore = away;
        p.game_info.homescore = home;
        p.game_info.visitorscore = away;

        p.game_result.isongoing = o.ongoing === undefined ? 1 : o.ongoing;
        p.game_result.hasstarted = o.hasstarted === undefined ? 1 : o.hasstarted;
        p.game_result.status = o.status || (p.game_result.isongoing ? 'ongoing' : 'completed');
        p.game_result.forfeit = o.forfeit || 0;

        if (o.elapsed === null || o.elapsed === undefined) {
            p.game_result.timer_start = null;
            p.game_result.timer_pause_start = null;
        } else {
            p.game_result.timer_start = clockBase - o.elapsed;
            p.game_result.timer_pause_start = o.paused ? clockBase : null;
        }
        p.game_result.timer_paused_duration = 0;

        p.goals = clone(goals);
        p.gameevents = clone(events);

        // poolinfo.timecap is the game's time limit in MINUTES. The fixture pool
        // has none, so the demo sets one to exercise the countdown — without it
        // there is nothing to count down to and the clock counts up instead.
        if (o.timecap !== undefined) {
            p.poolinfo = p.poolinfo || {};
            p.poolinfo.timecap = o.timecap;
        }

        p.meta = p.meta || {};
        p.meta.generated_timestamp = Math.floor(Date.now() / 1000);
        return p;
    }

    /* ------------------------------------------------------------------
       The script.

       Ordered to reach every distinct display state at least once, with a label
       saying what should be on screen so a viewer can check rather than guess.

       Holds and breaks follow from the goal list alone: whoever conceded
       receives next, so a goal by the receiving team is a hold and a goal by the
       pulling team is a break. No possession data is needed for that, which is
       why it works on real payloads too.
       ------------------------------------------------------------------ */
    var script = [
        function () {
            return [frame({ ongoing: 0, hasstarted: 0, status: 'scheduled', elapsed: null }),
                'Scheduled — no clock, kickoff time in the centre'];
        },
        function () {
            return [frame({ elapsed: 12 }),
                'Live 0-0 — clock running, home received, AWAY tagged ON DEFENCE'];
        },
        function () {
            goal(true, 95);
            return [frame({ elapsed: 95 }), 'HOME scores 1-0 — HOLD (received and scored)'];
        },
        function () {
            goal(false, 240);
            return [frame({ elapsed: 240 }), 'AWAY scores 1-1 — HOLD'];
        },
        function () {
            goal(false, 350);
            return [frame({ elapsed: 350 }), 'AWAY scores 1-2 — BREAK (scored while pulling)'];
        },
        function () {
            events.push({ time: 380, ishome: 1, type: 'timeout', info: null });
            return [frame({ elapsed: 380 }), 'HOME timeout — one tick goes dim'];
        },
        function () {
            goal(true, 430);
            return [frame({ elapsed: 430 }), 'HOME scores 2-2 — HOLD (away pulled after their break)'];
        },
        function () {
            return [frame({ elapsed: 500, paused: true }),
                'Clock PAUSED — dimmed, and it stops ticking'];
        },
        function () {
            return [frame({ elapsed: 540, timecap: 20 }),
                'Time limit set (20 min) — clock now COUNTS DOWN'];
        },
        function () {
            events.push({ time: 560, ishome: 0, type: 'half_cap', info: 8 });
            return [frame({ elapsed: 560, timecap: 20 }),
                'HALFTIME CAP — centre goes amber, new point cap 8'];
        },
        function () {
            goal(true, 900);
            return [frame({ elapsed: 900, timecap: 20 }),
                'Second half — HOME scores 3-2, BREAK (timeouts reset)'];
        },
        function () {
            events.push({ time: 1150, ishome: 0, type: 'time_cap', info: 4 });
            return [frame({ elapsed: 1150, timecap: 20 }),
                'TIME CAP — centre goes red, new point cap 4'];
        },
        function () {
            return [frame({ elapsed: 1250, timecap: 20 }),
                'Past the cap — clock clamps at 0:00, cap badge carries it'];
        },
        function () {
            return [frame({ ongoing: 0, status: 'completed', elapsed: null }),
                'FINAL — no clock, no callout, status word only'];
        }
    ];

    var i = 0;
    var timer = null;
    var stopped = false;

    reset();

    function tick() {
        if (stopped) { return; }
        if (i >= script.length) {
            if (!loop) { return; }
            // Start over, so a demo left on a monitor keeps cycling.
            reset();
            i = 0;
        }
        var out = script[i]();
        i += 1;
        onFrame(out[0], out[1]);
        timer = setTimeout(tick, stepMs);
    }

    tick();

    return {
        stop: function () {
            stopped = true;
            if (timer) { clearTimeout(timer); timer = null; }
        }
    };
}
