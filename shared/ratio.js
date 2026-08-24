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

    /**
     * The two ratios available, by season type.
     *
     * Indoor and beach are five a side, outdoor seven — same source as the
     * scoresheet (`pdfscoresheet.php:461-471`).
     */
    function pair(seasonType) {
        var type = String(seasonType || 'outdoor').toLowerCase();
        return (type === 'indoor' || type === 'beach')
            ? ['3MMP/2FMP', '2MMP/3FMP']
            : ['4MMP/3FMP', '3MMP/4FMP'];
    }

    /**
     * How a ratio is WRITTEN: one side, not two.
     *
     * `4MMP/3FMP` becomes `4MMP`. On a seven-a-side line four MMP means three
     * FMP and there is nothing else it could be, so the second half says only
     * what the reader already knows — and writing both forces a decision about
     * which category is printed first. There is no good answer to that, and
     * naming the majority side means never having to give one.
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
        return a >= b ? parts[0] : parts[1];
    }

    /** Mixed is decided by the division name, as the scoresheet decides it. */
    function isMixed(seriesName) {
        return String(seriesName || '').toLowerCase().indexOf('mixed') !== -1;
    }

    return { slot: slot, pair: pair, short: short, isMixed: isMixed };
}));
