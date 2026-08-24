/**
 * The player-bio round trip: export a team's roster, have the players fill it in,
 * import it back.
 *
 *     export CSV (id + name, empty prompt columns)
 *       -> team uploads it to a shared sheet
 *       -> each player edits their own row
 *       -> export CSV
 *       -> import here
 *
 * The point of the round trip is that the PLAYER writes their own entry. That is
 * the principle docs/COMMENTATOR.md section 5 argues for the upstream profile
 * fields — self-declared rather than second-hand — reached without waiting for a
 * schema change, and it gives a player a real moment to adapt or remove what is
 * said about them before the game.
 *
 * ---------------------------------------------------------------------------
 * THE IDENTIFIER IS NEVER TRUSTED, AND THAT IS THIS FILE'S REASON TO EXIST.
 * ---------------------------------------------------------------------------
 *
 * The CSV spends its life in a document an entire team can edit. Anyone with the
 * link can change an identifier, or add a row carrying somebody else's. Used
 * blindly, that is a way to write arbitrary text against a named player on the
 * OPPOSING team — text a commentator then reads out on air, in good faith,
 * believing it came from that player.
 *
 * So the identifier is only ever a lookup key inside one team's roster, never an
 * instruction about who to write to:
 *
 *   1. An import is scoped to ONE team, chosen by the commentator. The candidate
 *      set is that team's roster and nothing else, so no row can reach a player
 *      on the other team however its identifier was edited.
 *   2. The exported NAME travels with the identifier and is checked against the
 *      roster on the way back. If they disagree, the row is rejected and both
 *      names are shown. That catches the within-team version of the same trick,
 *      where somebody retargets a teammate's row.
 *   3. Nothing is applied until the reader has seen the tally.
 *
 * Every rejection is reported rather than dropped. A silent import is how the
 * wrong thing ends up on the desk in front of somebody about to say it.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Bios = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    var ID_HEADER = 'Player ID';
    var NUMBER_HEADER = 'Number';
    var NAME_HEADER = 'Name';

    /**
     * The empty columns the export ships with.
     *
     * These are the fields section 5 asks upstream for, as prompts. A blank
     * column gets a blank answer; a column that asks a question gets an answer.
     * A team is free to delete or add columns — the import maps by header, so it
     * does not care which of these survived.
     */
    var PROMPTS = [
        'Other sports played',
        'How you came to ultimate',
        'Occupation or study',
        'Interests outside ultimate',
        'Clubs played for before'
    ];

    var ID_ALIASES = ['player id', 'player_id', 'playerid', 'id', 'identifier'];
    var NAME_ALIASES = ['name', 'player', 'player name', 'full name'];
    var NUMBER_ALIASES = ['number', 'no', 'no.', 'num', '#', 'jersey', 'jersey number'];

    function norm(s) {
        return String(s === null || s === undefined ? '' : s).trim().toLowerCase();
    }

    /**
     * Compare names for "is this the same person".
     *
     * Loose on purpose: accents, punctuation, case and double spaces vary between
     * a database, a spreadsheet and whatever a player typed, and rejecting on any
     * of those would block honest imports. It is strict about the actual letters,
     * which is what the check is for.
     */
    function sameName(a, b) {
        var clean = function (s) {
            var t = String(s === null || s === undefined ? '' : s).trim().toLowerCase();
            if (t.normalize) { t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
            return t.replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
        };
        return clean(a) === clean(b);
    }

    function findHeader(headers, aliases) {
        for (var i = 0; i < headers.length; i++) {
            if (aliases.indexOf(norm(headers[i])) !== -1) { return headers[i]; }
        }
        return null;
    }

    /** Everything that is not an identity column carries content. */
    function contentHeaders(headers) {
        return headers.filter(function (h) {
            var n = norm(h);
            return ID_ALIASES.indexOf(n) === -1
                && NAME_ALIASES.indexOf(n) === -1
                && NUMBER_ALIASES.indexOf(n) === -1;
        });
    }

    /**
     * Turn one row's content columns into a note.
     *
     * One filled column becomes its value alone — labelling a single answer with
     * its own question is noise. Several become labelled lines, which is how the
     * structured fields section 5 wants upstream would arrive if they existed.
     */
    function compose(row, headers) {
        var filled = headers.filter(function (h) {
            return String(row[h] === undefined ? '' : row[h]).trim() !== '';
        });
        if (!filled.length) { return ''; }
        if (filled.length === 1) { return String(row[filled[0]]).trim(); }
        return filled.map(function (h) {
            return h + ': ' + String(row[h]).trim();
        }).join('\n');
    }

    /** The rows to hand a team, in roster order. */
    function exportRows(roster) {
        return roster.map(function (p) {
            var row = {};
            row[ID_HEADER] = p.id;
            row[NUMBER_HEADER] = (p.num === null || p.num === undefined) ? '' : p.num;
            row[NAME_HEADER] = p.name;
            PROMPTS.forEach(function (h) { row[h] = ''; });
            return row;
        });
    }

    function exportHeaders() {
        return [ID_HEADER, NUMBER_HEADER, NAME_HEADER].concat(PROMPTS);
    }

    /**
     * Decide what an imported file would do, without doing any of it.
     *
     * `roster` is ONE team's players ({id, num, name}); `existing` maps player id
     * to a note already written. Returns a report the page renders as a preview
     * and then applies verbatim — the decision lives here, tested, rather than in
     * the click handler.
     *
     * @return {{
     *   accepted: Array, rejected: Array, kept: Array, empty: number,
     *   idHeader: ?string, contentHeaders: Array, matched: number, total: number
     * }}
     */
    function match(parsed, roster, existing) {
        existing = existing || {};
        var headers = parsed.headers || [];
        var idHeader = findHeader(headers, ID_ALIASES);
        var nameHeader = findHeader(headers, NAME_ALIASES);
        var content = contentHeaders(headers);

        var report = {
            accepted: [], rejected: [], kept: [], empty: 0,
            idHeader: idHeader, contentHeaders: content,
            matched: 0, total: (parsed.rows || []).length
        };
        if (!idHeader) {
            report.fatal = 'No identifier column. Expected a "' + ID_HEADER + '" column, '
                + 'as produced by the export.';
            return report;
        }
        if (!content.length) {
            report.fatal = 'No content columns — every column in this file is an '
                + 'identity column, so there is nothing to import.';
            return report;
        }

        // The candidate set is this team and only this team. Rule 1.
        var byId = {};
        roster.forEach(function (p) { byId[String(p.id)] = p; });

        var seen = {};
        (parsed.rows || []).forEach(function (row, i) {
            var line = i + 2;                       // 1-based, plus the header row
            var raw = String(row[idHeader] === undefined ? '' : row[idHeader]).trim();
            var name = nameHeader ? row[nameHeader] : '';

            if (!raw) {
                report.rejected.push({ line: line, name: name, why: 'no identifier' });
                return;
            }
            if (!/^[0-9]+$/.test(raw)) {
                report.rejected.push({ line: line, name: name, why: 'identifier is not a number' });
                return;
            }

            var player = byId[raw];
            if (!player) {
                // Either a different team's sheet, or an edited identifier. Both
                // are the same refusal: this row cannot name anybody here.
                report.rejected.push({
                    line: line, name: name,
                    why: 'not on this roster'
                });
                return;
            }

            // Rule 2: the name that left with this identifier must come back with
            // it. Only checked when the file actually carries a name column.
            if (nameHeader && String(name).trim() && !sameName(name, player.name)) {
                report.rejected.push({
                    line: line, name: name,
                    why: 'name does not match the roster (roster says "' + player.name + '")'
                });
                return;
            }

            if (seen[raw]) {
                report.rejected.push({
                    line: line, name: player.name,
                    why: 'a second row for this player; the first one is used'
                });
                return;
            }
            seen[raw] = true;
            report.matched += 1;

            var text = compose(row, content);
            if (!text) { report.empty += 1; return; }

            // Never overwrite what a commentator typed. Their own observations
            // have no other home; a player's answers can be re-imported.
            if (existing[raw] && String(existing[raw]).trim()) {
                report.kept.push({ line: line, name: player.name });
                return;
            }
            report.accepted.push({ player: player.id, name: player.name, text: text });
        });

        return report;
    }

    return {
        ID_HEADER: ID_HEADER,
        NAME_HEADER: NAME_HEADER,
        NUMBER_HEADER: NUMBER_HEADER,
        PROMPTS: PROMPTS,
        exportHeaders: exportHeaders,
        exportRows: exportRows,
        contentHeaders: contentHeaders,
        compose: compose,
        sameName: sameName,
        match: match
    };
}));
