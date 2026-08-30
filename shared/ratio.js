/**
 * WFDF's prescribed gender ratio, in one place.
 *
 * The pattern, the two ratios in play, and how a ratio is written. It was
 * duplicated between the commentator page and the stage's progression card,
 * which is how the card came to be printing `4MMP/3FMP` after the commentator
 * had moved to `4MMP` — the same rule stated twice, drifting.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Ratio = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * Which points repeat the first point's ratio.
     *
     * A-B-B-A over successive points, so points 1, 4, 5, 8, 9, 12 … carry
     * whatever was set for point 1. Taken from UltiOrganizer's own WFDF
     * scoresheet (`cust/wfdf/pdfscoresheet.php:1254`), which marks exactly those
     * points with an asterisk — the same rule, so a commentator reading a screen
     * and a scorekeeper reading paper cannot disagree.
     */
    function slot(pointNumber) {
        var i = Number(pointNumber) || 1;
        return (i % 4 === 0 || (i - 1) % 4 === 0) ? 'A' : 'B';
    }

    /** The line sizes a desk can declare, largest first. */
    var SIZES = [7, 6, 5, 4];

    function sizes() { return SIZES.slice(); }

    function isSize(n) { return SIZES.indexOf(Number(n)) !== -1; }

    /**
     * The line size to assume when nobody has declared one.
     *
     * Indoor and beach are five a side, outdoor seven — same source as the
     * scoresheet (`pdfscoresheet.php:461-471`). It is a default and not a fact:
     * `seasoninfo.type` is a SURFACE, and UltiOrganizer records players per
     * side nowhere at all, so a 6v6 or 4v4 game has to be declared by hand.
     */
    function defaultSize(seasonType) {
        var type = String(seasonType || 'outdoor').toLowerCase();
        return (type === 'indoor' || type === 'beach') ? 5 : 7;
    }

    /**
     * The ratios available at a line size — TWO at an odd size, ONE at an even.
     *
     * The prescribed ratio is only ever a question of which matching gets the
     * odd player. At 7 that is 4/3 either way round and at 5 it is 3/2; at 6
     * and 4 the split is even, so there is nothing to decide and nothing to
     * alternate — which is why an even size has no selector and no ABBA.
     */
    function pairForSize(size) {
        var n = Number(size);
        if (!isSize(n)) { return null; }
        var big = Math.ceil(n / 2);
        var small = Math.floor(n / 2);
        if (big === small) { return [big + 'MMP/' + small + 'FMP']; }
        return [big + 'MMP/' + small + 'FMP', small + 'MMP/' + big + 'FMP'];
    }

    /** Whether the ratio is a choice at this size, rather than forced. */
    function isChoice(size) { return Number(size) % 2 === 1; }

    /** The ratios for a season type's default size. */
    function pair(seasonType) { return pairForSize(defaultSize(seasonType)); }

    /**
     * How a ratio is WRITTEN: one side, not two.
     *
     * `4MMP/3FMP` becomes `4MMP`. On a seven-a-side line four MMP means three
     * FMP and there is nothing else it could be, so the second half says only
     * what the reader already knows — and writing both forces a decision about
     * which category is printed first. There is no good answer to that, and
     * naming the majority side means never having to give one.
     *
     * An EVEN split keeps both halves, because the convention depends on there
     * being a majority to name: shortening `3MMP/3FMP` to `3MMP` would say
     * three MMP and two FMP, which is a different line.
     *
     * The stored value keeps both halves: it is a data format with a validator,
     * not something anybody reads.
     */
    function short(full) {
        var parts = String(full || '').split('/');
        if (parts.length !== 2) { return String(full || ''); }
        var a = parseInt(parts[0], 10);
        var b = parseInt(parts[1], 10);
        if (!isFinite(a) || !isFinite(b)) { return String(full); }
        if (a === b) { return String(full); }
        return a > b ? parts[0] : parts[1];
    }

    /** Mixed is decided by the division name, as the scoresheet decides it. */
    function isMixed(seriesName) {
        return String(seriesName || '').toLowerCase().indexOf('mixed') !== -1;
    }

    /**
     * A stored ratio as per-matching quotas: '4MMP/3FMP' -> {MMP: 4, FMP: 3}.
     *
     * Null for anything else, because a line built from a half-parsed ratio
     * would be confidently wrong.
     */
    function counts(full) {
        var m = /^(\d+)(MMP|FMP)\/(\d+)(MMP|FMP)$/.exec(
            String(full || '').toUpperCase().replace(/\s+/g, ''));
        if (!m || m[2] === m[4]) { return null; }
        var out = {};
        out[m[2]] = parseInt(m[1], 10);
        out[m[4]] = parseInt(m[3], 10);
        return out;
    }

    return {
        slot: slot, pair: pair, short: short, isMixed: isMixed, counts: counts,
        sizes: sizes, isSize: isSize, defaultSize: defaultSize,
        pairForSize: pairForSize, isChoice: isChoice
    };
}));
