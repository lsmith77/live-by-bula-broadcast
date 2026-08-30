/**
 * Grouping a mixed line picker by matching, against this point's ratio.
 *
 * Clicking a legal mixed line together means answering "how many FMP are still
 * missing" seven times under time pressure. Given each player's matching and
 * the point's ratio, the picker can answer it structurally instead:
 *
 *   - the MAJORITY matching lists first — it is the group the point needs four
 *     of rather than three, so it is the longer row and the bulk of the
 *     clicking, and putting it on top keeps the picker's first row the busy
 *     one on every point instead of alternating with the ratio;
 *   - a group whose quota is exactly filled HIDES its unpicked players: every
 *     one of them could only make the line illegal, and unpicking somebody
 *     from the group brings them all back (the picked stay visible for that);
 *   - players with no matching data are always listed, in their own group —
 *     absent is not a matching, and hiding somebody because a spreadsheet
 *     cell was empty would make the roster wrong in the worst way.
 *
 * Hiding is a deliberate exception to "never block the click — show the
 * consequence" (AGENTS.md): a displaced card is usually intended, an eighth
 * FMP never is, and the hidden chips return the moment the quota reopens.
 *
 * Pure and tested directly, per AGENTS.md: this decides what a picker offers,
 * so it lives in shared/ rather than in the page.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Lineup = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * @param players [{id, matching}] in display order
     * @param quotas  {MMP: n, FMP: m} from Ratio.counts(), or null
     * @param picked  ids on the line
     * @return null when there is nothing to group by, else
     *         { groups: [{matching, quota, picked, full, players}], unknown: [] }
     *         — groups ordered majority first (FMP first on a tie).
     */
    function groups(players, quotas, picked) {
        if (!quotas || !isFinite(quotas.MMP) || !isFinite(quotas.FMP)) { return null; }

        var onLine = {};
        (picked || []).forEach(function (id) { onLine[String(id)] = true; });

        var by = { MMP: [], FMP: [] };
        var unknown = [];
        (players || []).forEach(function (p) {
            var m = String(p.matching || '').toUpperCase();
            if (m === 'MMP' || m === 'FMP') { by[m].push(p); } else { unknown.push(p); }
        });

        var order = quotas.FMP >= quotas.MMP ? ['FMP', 'MMP'] : ['MMP', 'FMP'];
        return {
            groups: order.map(function (m) {
                var count = by[m].filter(function (p) { return onLine[String(p.id)]; }).length;
                return {
                    matching: m,
                    quota: quotas[m],
                    picked: count,
                    full: count >= quotas[m],
                    players: by[m]
                };
            }),
            unknown: unknown
        };
    }

    return { groups: groups };
}));
