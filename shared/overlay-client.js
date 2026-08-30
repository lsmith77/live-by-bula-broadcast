/**
 * Live! by BULA video overlays — data client
 *
 * Polls the Live! JSON API for one game and hands the payload to a renderer.
 *
 * Live! v3 only serves the API through UltiOrganizer's front controller
 * (?view=live/api). Requests straight to live/api.php return 404, so the base
 * URL is injected by the hosting overlay page rather than guessed from the
 * current path — see docs/api-v3-changes.md in the Live! release.
 *
 * @version 2.0.0
 */

class OverlayDataClient {
    /**
     * @param {Object}  options
     * @param {number}  options.gameId       Game to follow.
     * @param {string}  options.apiBase      Routed API base, e.g. "/index.php?view=live/api".
     * @param {number} [options.interval]    Minimum poll interval in ms (default 5000).
     * @param {number} [options.maxInterval] Ceiling for cache-derived backoff (default 60000).
     */
    constructor({ gameId, apiBase, captureBase, interval = 5000, maxInterval = 60000 }) {
        this.gameId = gameId;
        this.apiBase = apiBase;
        // Set when this installation reads a recording instead of Live! — the
        // scoreboard is the one overlay that polls through this class rather
        // than calling the provider directly, so without this it was the one
        // page that still went looking for an API that is not there.
        this.captureBase = captureBase || null;
        this.minInterval = interval;
        this.maxInterval = maxInterval;

        this.callbacks = { data: null, error: null, status: null };
        this.running = false;
        this.timer = null;
        this.consecutiveErrors = 0;
    }

    onData(cb) { this.callbacks.data = cb; return this; }
    onError(cb) { this.callbacks.error = cb; return this; }
    onStatus(cb) { this.callbacks.status = cb; return this; }

    /**
     * Fetch one game payload.
     *
     * Resolves to the parsed body. Rejects with an Error carrying `.status`
     * (HTTP code) and `.fatal` (true when retrying cannot help) — this class
     * uses both to decide whether to back off or stop.
     *
     * WHAT it asks for and HOW the answer is read now live in
     * `shared/provider.js`; what remains here is WHEN to ask, which is this
     * class's actual job. It was the fourth copy of that contract.
     */
    async fetchGame() {
        return this.provider().game(this.gameId);
    }

    /** Lazily built so a page can load this file before shared/provider.js. */
    provider() {
        if (!this._provider) {
            const P = (typeof window !== 'undefined' && window.Provider)
                || (typeof Provider !== 'undefined' ? Provider : null);
            this._provider = P.fromConfig({
                apiBase: this.apiBase, captureBase: this.captureBase,
            });
        }
        return this._provider;
    }

    /**
     * How long to wait before the next poll.
     *
     * The API reports when the cached payload expires. A finished game is cached
     * for a year, an ongoing one for seconds, so following `meta` keeps a live
     * scoreboard responsive without hammering the server for a final score.
     */
    nextDelay(payload) {
        const expires = Number(payload?.meta?.expires_timestamp);
        if (!Number.isFinite(expires)) {
            return this.minInterval;
        }
        const msUntilStale = expires * 1000 - Date.now();
        return Math.min(this.maxInterval, Math.max(this.minInterval, msUntilStale));
    }

    start() {
        if (this.running) return this;
        this.running = true;
        this.poll();
        return this;
    }

    stop() {
        this.running = false;
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
        return this;
    }

    schedule(delay) {
        if (!this.running) return;
        this.timer = setTimeout(() => this.poll(), delay);
    }

    async poll() {
        if (!this.running) return;

        try {
            const payload = await this.fetchGame();
            this.consecutiveErrors = 0;
            this.callbacks.status?.({ connected: true, message: 'Connected' });
            this.callbacks.data?.(payload);
            this.schedule(this.nextDelay(payload));
        } catch (err) {
            this.consecutiveErrors += 1;
            this.callbacks.status?.({ connected: false, message: err.message });
            this.callbacks.error?.({
                message: err.message,
                status: err.status,
                fatal: err.fatal,
                consecutiveErrors: this.consecutiveErrors,
            });

            // A wrong game id or an unpublished event will not fix itself; stop
            // rather than spending the broadcast retrying a request that cannot work.
            if (err.fatal) {
                this.stop();
                return;
            }

            // Exponential backoff, capped, so a server blip does not turn into a
            // request storm from every overlay open in the switcher.
            const backoff = Math.min(
                this.maxInterval,
                this.minInterval * Math.pow(2, Math.min(this.consecutiveErrors, 5))
            );
            this.schedule(backoff);
        }
    }
}

/**
 * Which team started the game on offence, if the scorekeeper recorded it.
 *
 * UltiOrganizer stores this as a `uo_gameevent` row with `type: "offence"`
 * (written by Scorekeeper's "First offence" page), and the Live! API passes it
 * through in `gameevents`. It is optional: a game whose scorekeeper never set it
 * has no such row.
 *
 * @param {Array} gameEvents `gameevents` from the API.
 * @returns {'home'|'visitor'|null}
 */
function startingOffence(gameEvents) {
    if (!Array.isArray(gameEvents)) return null;
    const event = gameEvents.find((e) => e && e.type === 'offence');
    if (!event) return null;
    return Number(event.ishome) === 1 ? 'home' : 'visitor';
}

/**
 * Which team is on offence for the point currently being played.
 *
 * The scoring team pulls, so whoever conceded the last goal receives the next
 * one. Before the first goal it is whoever started on offence — which is only
 * known if the scorekeeper recorded it.
 *
 * @returns {'home'|'visitor'|null} null when it cannot be determined.
 */
function currentOffence(goals, gameEvents) {
    const ordered = Array.isArray(goals) ? [...goals].sort((a, b) => a.num - b.num) : [];
    if (ordered.length === 0) {
        return startingOffence(gameEvents);
    }
    const last = ordered[ordered.length - 1];
    // Conceding team receives, so they are the O-line on the next point.
    return Number(last.ishomegoal) === 1 ? 'visitor' : 'home';
}

/**
 * Classify each point of a game as a hold, a break, or unresolvable.
 *
 * Live! computes this in its React frontend rather than in the API, so an
 * overlay has to derive it from the goal list.
 *
 * The opening pull is the only genuinely unknowable part, and only when the
 * scorekeeper did not record a `type: "offence"` event. Given that event every
 * point resolves; without it the first point (and any point after a gap in the
 * sequence) is reported separately rather than guessed — attributing it would
 * silently inflate whichever column the guess favoured.
 *
 * @param {Array} goals Goals from the API, in `num` order.
 * @param {Array} [gameEvents] `gameevents`, to seed who received the opening pull.
 * @returns {{home: Object, visitor: Object, unresolved: number}}
 */
function classifyPoints(goals, gameEvents) {
    const tally = {
        home: { holds: 0, breaks: 0 },
        visitor: { holds: 0, breaks: 0 },
        unresolved: 0,
    };

    if (!Array.isArray(goals) || goals.length === 0) {
        return tally;
    }

    const ordered = [...goals].sort((a, b) => a.num - b.num);

    // After a goal the scoring team pulls, so the conceding team receives the
    // next point. For the first point, fall back to the recorded starting
    // offence; without it `receiving` stays null and that point is unresolved.
    let receiving = startingOffence(gameEvents);
    let expectedNum = ordered[0].num;

    for (const goal of ordered) {
        const scoredBy = goal.ishomegoal === 1 ? 'home' : 'visitor';

        if (receiving === null || goal.num !== expectedNum) {
            tally.unresolved += 1;
        } else if (scoredBy === receiving) {
            tally[scoredBy].holds += 1;
        } else {
            tally[scoredBy].breaks += 1;
        }

        receiving = scoredBy === 'home' ? 'visitor' : 'home';
        expectedNum = goal.num + 1;
    }

    return tally;
}
