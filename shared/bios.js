/**
 * The player-bio round trip: export a team's roster, have the players fill it in,
 * import it back.
 *
 *     export CSV (id + name, empty field and prompt columns)
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
     * Whose sheet this is, on every row.
     *
     * The CSV is team-specific, and it spends its life being forwarded around a
     * team — so it says whose it is twice over: `Team` is the human check (a
     * player opening the sheet sees at a glance whether they were sent the
     * right one), `Team ID` is the machine check (an import refuses another
     * team's file outright instead of rejecting twenty-eight rows one by one).
     * Deleting the columns disables only this check; the per-row roster guard
     * stands regardless.
     */
    var TEAM_HEADER = 'Team';
    var TEAM_ID_HEADER = 'Team ID';

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

    /**
     * The structured columns: answers that are looked up at speed rather than
     * read out, so they must not disappear into the composed note. Each imports
     * into its own field on the player's entry and is shown beside the name.
     *
     * Pronouns are here for the reason section 5 gives: self-declared or absent,
     * never guessed — and a player filling in their own row IS the
     * self-declaration. Nickname exists upstream (`uo_player_profile.nickname`)
     * but no Live! payload carries it, so the round trip is how it reaches the
     * desk today.
     */
    var FIELDS = [
        { key: 'nickname', header: 'Nickname', aliases: ['nickname', 'nick name', 'nick'] },
        { key: 'pronouns', header: 'Pronouns', aliases: ['pronouns', 'pronoun'] },
        {
            key: 'pronunciation', header: 'Name pronunciation',
            aliases: ['name pronunciation', 'pronunciation', 'pronounced', 'pronounce', 'how to say it']
        },
        {
            key: 'matching', header: 'Matching (FMP/MMP)',
            aliases: ['matching (fmp/mmp)', 'matching', 'fmp/mmp', 'fmp or mmp', 'gender matching']
        }
    ];

    /**
     * `matching` is a competition designation with exactly two values — the
     * sport's own terms, never a gender letter (docs/STUDIO.md §10.5). A cell
     * that cannot say FMP or MMP is not supplying this data and imports as
     * blank rather than as a guess.
     */
    function fieldValue(key, raw) {
        var v = String(raw === undefined || raw === null ? '' : raw).trim();
        if (key !== 'matching') { return v; }
        v = v.toUpperCase();
        return v === 'FMP' || v === 'MMP' ? v : '';
    }

    var ID_ALIASES = ['player id', 'player_id', 'playerid', 'id', 'identifier'];
    var NAME_ALIASES = ['name', 'player', 'player name', 'full name'];
    var NUMBER_ALIASES = ['number', 'no', 'no.', 'num', '#', 'jersey', 'jersey number'];
    var TEAM_ALIASES = ['team', 'team name', 'club'];
    var TEAM_ID_ALIASES = ['team id', 'team_id', 'teamid'];

    /**
     * The TEAM row: material that belongs to nobody's row.
     *
     * History, achievements — asked once per sheet, on a final row whose
     * identifier is the literal `TEAM`. Its answers import as the team's note,
     * under the same rules as a player row: the exported team name travels
     * with it and is checked on the way back, and an import fills the note
     * only where the desk has not written one.
     */
    var TEAM_ROW_ID = 'TEAM';
    var TEAM_PROMPTS = ['Team history', 'Team achievements'];
    var TEAM_PROMPT_ALIASES = ['team history', 'team achievements'];

    var FIELD_ALIASES = FIELDS.reduce(function (all, f) {
        return all.concat(f.aliases);
    }, []);

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

    /** Everything that is not an identity or structured-field column composes into the note. */
    function contentHeaders(headers) {
        return headers.filter(function (h) {
            var n = norm(h);
            return ID_ALIASES.indexOf(n) === -1
                && NAME_ALIASES.indexOf(n) === -1
                && NUMBER_ALIASES.indexOf(n) === -1
                && TEAM_ALIASES.indexOf(n) === -1
                && TEAM_ID_ALIASES.indexOf(n) === -1
                && TEAM_PROMPT_ALIASES.indexOf(n) === -1
                && FIELD_ALIASES.indexOf(n) === -1;
        });
    }

    /** Which structured fields this file carries: field key -> the file's header. */
    function fieldHeaders(headers) {
        var found = {};
        FIELDS.forEach(function (f) {
            var h = findHeader(headers, f.aliases);
            if (h !== null) { found[f.key] = h; }
        });
        return found;
    }

    /**
     * One player's existing entry, however the caller shaped it.
     *
     * The page passes full entries; older callers and half the tests pass the
     * note text alone. Both mean the same thing: what is already written here.
     */
    function existingOf(value) {
        var entry = { text: '', nickname: '', pronouns: '', pronunciation: '', matching: '' };
        if (typeof value === 'string') { entry.text = value; return entry; }
        if (value && typeof value === 'object') {
            Object.keys(entry).forEach(function (k) {
                if (typeof value[k] === 'string') { entry[k] = value[k]; }
            });
        }
        return entry;
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

    /** The rows to hand a team, in roster order, plus the TEAM row last. `team` is {id, name}. */
    function exportRows(roster, team) {
        var blank = function (row) {
            FIELDS.forEach(function (f) { row[f.header] = ''; });
            PROMPTS.forEach(function (h) { row[h] = ''; });
            TEAM_PROMPTS.forEach(function (h) { row[h] = ''; });
            row[TEAM_HEADER] = team && team.name ? team.name : '';
            row[TEAM_ID_HEADER] = team && team.id !== null && team.id !== undefined ? team.id : '';
            return row;
        };
        var rows = roster.map(function (p) {
            var row = {};
            row[ID_HEADER] = p.id;
            row[NUMBER_HEADER] = (p.num === null || p.num === undefined) ? '' : p.num;
            row[NAME_HEADER] = p.name;
            return blank(row);
        });
        var teamRow = {};
        teamRow[ID_HEADER] = TEAM_ROW_ID;
        teamRow[NUMBER_HEADER] = '';
        teamRow[NAME_HEADER] = team && team.name ? team.name : '';
        rows.push(blank(teamRow));
        return rows;
    }

    function exportHeaders() {
        return [ID_HEADER, NUMBER_HEADER, NAME_HEADER, TEAM_HEADER, TEAM_ID_HEADER]
            .concat(FIELDS.map(function (f) { return f.header; }))
            .concat(PROMPTS)
            .concat(TEAM_PROMPTS);
    }

    /**
     * Decide what an imported file would do, without doing any of it.
     *
     * `roster` is ONE team's players ({id, num, name}); `existing` maps player id
     * to what is already written — a full entry, or the note text alone. Returns
     * a report the page renders as a preview and then applies verbatim — the
     * decision lives here, tested, rather than in the click handler.
     *
     * "Import fills what is empty and never overwrites" holds per CHANNEL: the
     * note and each structured field are filled or kept independently, so a row
     * whose note was typed at the desk can still bring pronouns nobody had.
     *
     * @return {{
     *   accepted: Array, rejected: Array, kept: Array, empty: number,
     *   idHeader: ?string, contentHeaders: Array, fieldHeaders: Object,
     *   matched: number, total: number
     * }}
     */
    function match(parsed, roster, existing, team) {
        existing = existing || {};
        var headers = parsed.headers || [];
        var idHeader = findHeader(headers, ID_ALIASES);
        var nameHeader = findHeader(headers, NAME_ALIASES);
        var teamIdHeader = findHeader(headers, TEAM_ID_ALIASES);
        var teamNameHeader = findHeader(headers, TEAM_ALIASES);
        var content = contentHeaders(headers);
        var fields = fieldHeaders(headers);
        var fieldKeys = Object.keys(fields);

        var report = {
            accepted: [], rejected: [], kept: [], empty: 0,
            idHeader: idHeader, contentHeaders: content, fieldHeaders: fields,
            matched: 0, total: (parsed.rows || []).length
        };
        if (!idHeader) {
            report.fatal = 'No identifier column. Expected a "' + ID_HEADER + '" column, '
                + 'as produced by the export.';
            return report;
        }

        // Whose file is this? Refused as a FILE, before any row is looked at:
        // rejecting twenty-eight rows one by one says "something is wrong",
        // where this says what happened — the wrong team's sheet was picked.
        // Only when the file names a team at all, and never when any row claims
        // the importing team (a merged or hand-edited file falls through to the
        // per-row roster guard, which decides row by row either way).
        if (teamIdHeader && team && team.id !== null && team.id !== undefined) {
            var claimed = [];
            (parsed.rows || []).forEach(function (row) {
                var v = String(row[teamIdHeader] === undefined ? '' : row[teamIdHeader]).trim();
                if (v && claimed.indexOf(v) === -1) { claimed.push(v); }
            });
            if (claimed.length && claimed.indexOf(String(team.id)) === -1) {
                var fileTeam = '';
                if (teamNameHeader) {
                    var first = (parsed.rows || [])[0] || {};
                    fileTeam = String(first[teamNameHeader] === undefined ? '' : first[teamNameHeader]).trim();
                }
                report.wrongTeam = { id: claimed[0], name: fileTeam };
                report.fatal = 'This is ' + (fileTeam ? '"' + fileTeam + '"' : 'another team')
                    + '’s sheet (team ' + claimed[0] + '), not '
                    + (team.name || 'this team') + '’s.';
                return report;
            }
        }

        var teamPrompts = headers.filter(function (h) {
            return TEAM_PROMPT_ALIASES.indexOf(norm(h)) !== -1;
        });

        if (!content.length && !fieldKeys.length && !teamPrompts.length) {
            report.fatal = 'No content columns — every column in this file is an '
                + 'identity column, so there is nothing to import.';
            return report;
        }

        // The candidate set is this team and only this team. Rule 1.
        var byId = {};
        roster.forEach(function (p) { byId[String(p.id)] = p; });

        var seen = {};
        var seenTeamRow = false;
        (parsed.rows || []).forEach(function (row, i) {
            var line = i + 2;                       // 1-based, plus the header row
            var raw = String(row[idHeader] === undefined ? '' : row[idHeader]).trim();
            var name = nameHeader ? row[nameHeader] : '';

            if (!raw) {
                report.rejected.push({ line: line, name: name, why: 'no identifier' });
                return;
            }

            // The TEAM row: the same discipline as a player row — the exported
            // name travels with the identifier and is checked, a second row
            // does not silently win, and the desk's own note is never
            // overwritten.
            if (raw.toUpperCase() === TEAM_ROW_ID) {
                if (seenTeamRow) {
                    report.rejected.push({
                        line: line, name: name,
                        why: 'a second TEAM row; the first one is used'
                    });
                    return;
                }
                seenTeamRow = true;
                if (
                    nameHeader && String(name).trim() && team && team.name
                    && !sameName(name, team.name)
                ) {
                    report.rejected.push({
                        line: line, name: name,
                        why: 'team name does not match (this sheet is for "' + team.name + '")'
                    });
                    return;
                }
                var teamText = compose(row, teamPrompts);
                if (!teamText) { report.empty += 1; return; }
                if (existingOf(existing[TEAM_ROW_ID]).text.trim()) {
                    report.kept.push({
                        line: line,
                        name: (team && team.name ? team.name : 'Team') + ' — team note'
                    });
                    return;
                }
                report.accepted.push({
                    player: TEAM_ROW_ID,
                    name: (team && team.name ? team.name : 'Team') + ' — team note',
                    text: teamText, fields: {}
                });
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
            var cur = existingOf(existing[raw]);

            // Fill what is empty, keep what was written — per channel. What a
            // commentator typed has no other home; a player's answers can simply
            // be imported again.
            var toText = '';
            var toFields = {};
            var any = false;
            if (text) {
                any = true;
                if (!cur.text.trim()) { toText = text; }
            }
            fieldKeys.forEach(function (k) {
                var v = fieldValue(k, row[fields[k]]);
                if (!v) { return; }
                any = true;
                if (!cur[k].trim()) { toFields[k] = v; }
            });

            if (!any) { report.empty += 1; return; }
            if (!toText && !Object.keys(toFields).length) {
                // Everything this row carries is already written here.
                report.kept.push({ line: line, name: player.name });
                return;
            }
            report.accepted.push({
                player: player.id, name: player.name,
                text: toText, fields: toFields
            });
        });

        return report;
    }

    return {
        ID_HEADER: ID_HEADER,
        NAME_HEADER: NAME_HEADER,
        NUMBER_HEADER: NUMBER_HEADER,
        PROMPTS: PROMPTS,
        FIELDS: FIELDS,
        TEAM_ROW_ID: TEAM_ROW_ID,
        TEAM_PROMPTS: TEAM_PROMPTS,
        exportHeaders: exportHeaders,
        exportRows: exportRows,
        contentHeaders: contentHeaders,
        fieldHeaders: fieldHeaders,
        compose: compose,
        sameName: sameName,
        match: match
    };
}));
