<?php
/**
 * Live! by BULA video overlays — Studio.
 *
 *   /index.php?view=live/overlays/index      (also /s/)
 *
 * The operator's page: lists the event's games with ready-to-paste browser-source
 * URLs, and — for a logged-in Live! admin — controls what the stage is showing
 * and which kit each team is wearing. Not an overlay itself.
 *
 * **Public, read-only, by design.** Everything needed to point a switcher at a
 * game is visible without logging in: the game list, the field, and the URLs.
 * None of it is secret, and someone setting up a camera at a field should not
 * need an admin password to find a URL — least of all in auto mode, where there
 * is no operator at all. Logging in adds the controls; it does not unlock the
 * information.
 *
 * The lists are fetched in the browser rather than server-side. That keeps the
 * page free of any Live! bootstrap knowledge, and means it reads the event
 * through exactly the same routed endpoint the overlays do — if a game is listed
 * here, an overlay for it will work.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../conf/LocalConfig.php';

$base = rtrim(defined('UO_URL_PREFIX') ? UO_URL_PREFIX : '/', '/');

/**
 * Asset URL stamped with the file's modification time.
 *
 * Same reasoning as the overlays: nothing sends a Cache-Control header for these
 * files, so a browser caches them heuristically and can hold a stale copy
 * indefinitely. Here that would mean an operator's controls silently running
 * last week's logic.
 */
$assetUrl = static function (string $relative) use ($base): string {
    $relative = ltrim($relative, '/');
    $path = __DIR__ . '/' . $relative;
    $version = is_file($path) ? (string) filemtime($path) : '0';
    return $base . '/live/overlays/' . $relative . '?v=' . $version;
};
$json = static fn ($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Video overlays</title>
<style>
    :root { color-scheme: dark; }
    body {
        margin: 0; padding: 2rem;
        font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
        background: #0f172a; color: #e2e8f0;
    }
    h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
    p.lede { margin: 0 0 2rem; color: #94a3b8; }
    table { border-collapse: collapse; width: 100%; max-width: 1100px; }
    th, td { text-align: left; padding: .6rem .75rem; border-bottom: 1px solid #1e293b; }
    th { color: #94a3b8; font-weight: 600; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
    tbody tr:hover td { background: #16213a; }
    .badge { display: inline-block; padding: .1rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
    .badge.live { background: #14532d; color: #4ade80; }
    .badge.done { background: #1e293b; color: #94a3b8; }
    .badge.soon { background: #1e3a5f; color: #7dd3fc; }
    .score { font-variant-numeric: tabular-nums; font-weight: 700; }
    a.url { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem;
            color: #7dd3fc; word-break: break-all; }
    .panel { max-width: 1100px; margin-top: 2rem; padding: 1rem 1.25rem; background: #16213a;
             border-left: 3px solid #38bdf8; border-radius: 4px; color: #cbd5e1; }
    .panel code { background: #0f172a; padding: .1rem .35rem; border-radius: 3px; }
    .err { max-width: 1100px; padding: 1rem 1.25rem; background: #3f1d1d;
           border-left: 3px solid #ef4444; border-radius: 4px; }
    .muted { color: #94a3b8; }
    a.action { color: #a5b4fc; text-decoration: none; font-size: .85rem; white-space: nowrap; }
    a.action:hover { text-decoration: underline; }

    h2 { font-size: 1.05rem; margin: 2.5rem 0 .25rem; }

    /* Kit colour sits against the team it belongs to, on the matchup line.
       The hex value lives inside the native picker, which already offers hex
       entry — a separate text field duplicated it and cost width. */
    .matchup { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
               margin-top: .25rem; }
    .matchup .vs { color: #475569; font-size: .75rem; }
    .kitside { display: inline-flex; align-items: center; gap: .35rem; }
    .kitteam { color: #cbd5e1; font-size: .8rem; max-width: 13rem; overflow: hidden;
               text-overflow: ellipsis; white-space: nowrap; }
    .kitswatch { width: 22px; height: 22px; padding: 0; border: 1px solid #475569;
                 border-radius: 4px; background: none; cursor: pointer; flex-shrink: 0; }
    .kitswatch:disabled { cursor: not-allowed; opacity: .6; }
    /* Unset must not look like a chosen grey. */
    .kitswatch.unset { border-style: dashed; border-color: #334155; opacity: .45; }
    .kitclear { background: none; border: 0; color: #64748b; cursor: pointer;
                font-size: .9rem; line-height: 1; padding: 0 .1rem; }
    .kitclear:hover { color: #f87171; }

    /* Header: identity and the login affordance. Someone arriving read-only
       needs to know that is why the controls are inert, and where to go. */
    .topbar { display: flex; align-items: baseline; gap: 1rem; max-width: 1100px;
              flex-wrap: wrap; }
    .topbar h1 { flex: 1; }
    .who { font-size: .85rem; color: #94a3b8; }
    .who .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%;
                background: #475569; margin-right: .4rem; vertical-align: middle; }
    .who.admin .dot { background: #4ade80; }
    a.btn { display: inline-block; background: #1d4ed8; color: #fff; text-decoration: none;
            font-weight: 600; font-size: .85rem; padding: .45rem 1rem; border-radius: 4px; }
    a.btn:hover { background: #2563eb; }
    a.btn.ghost { background: none; border: 1px solid #334155; color: #94a3b8; }
    a.btn.ghost:hover { background: none; border-color: #475569; color: #e2e8f0; }

    td.where { color: #94a3b8; font-size: .85rem; white-space: nowrap; }
    td.where strong { color: #cbd5e1; font-weight: 600; }
    td.when { color: #94a3b8; font-size: .85rem; white-space: nowrap; }

    /* Stage controls — one row per piece of content.
       Placement is setup and stays small; the on-air switch is the live action
       and is deliberately the largest thing in the row. */
    .stagebar { display: flex; flex-wrap: wrap; align-items: baseline; gap: .75rem;
                max-width: 1100px; margin-bottom: .75rem; }
    .cardlist { max-width: 1100px; }
    .cardrow { display: flex; align-items: center; gap: 1rem; padding: .7rem .75rem;
               border: 1px solid #1e293b; border-radius: 6px; margin-bottom: .5rem;
               background: #0f1a30; }
    .cardrow.live { border-color: #4ade80; background: #10231c; }
    .cardname { flex: 1; font-weight: 600; }

    /* Warned in advance: this row is what the hovered control would take off
       air. Amber rather than red — it is a consequence, not an error. */
    .cardrow.losing { border-color: #fbbf24; background: #2a2312; }
    .cardrow.losing .cardname::after {
        content: ' — will be turned off';
        color: #fbbf24; font-weight: 500; font-size: .82rem;
    }
    /* The pointer says it too, before anything is read. */
    .displaces:not(:disabled) { cursor: alias; }

    /* A 3x3 map of the frame. Spatial, because the thing being chosen is a
       position; a list of words like "lower-left" makes the reader translate. */
    .picker { display: grid; grid-template-columns: repeat(3, 20px);
              grid-auto-rows: 14px; gap: 3px; flex-shrink: 0; }
    .picker .cell { padding: 0; border: 1px solid #334155; border-radius: 2px;
                    background: #0b1220; cursor: pointer; }
    .picker .cell:hover:not(:disabled) { border-color: #64748b; }
    .picker .cell.span { grid-column: 1 / -1; }
    .picker .cell.here { background: #38bdf8; border-color: #7dd3fc; }
    .picker .cell.taken { background: #334155; }
    .picker .cell.unfit { background: #0b1220; border-style: dashed; border-color: #1e293b; }
    /* Taken by the logo — a different thing from "does not fit", so it looks
       different: occupied rather than unavailable. */
    .picker .cell.blocked { background: repeating-linear-gradient(45deg,
        #1e293b 0 3px, #0b1220 3px 6px); border-color: #334155; }
    .picker .cell:disabled { cursor: not-allowed; opacity: .55; }

    /* Possession: two states, one key each, sized to be hit without looking. */
    .stagebar.possession { border-top: 1px solid #1e293b; padding-top: .75rem; margin-top: .25rem; }
    .poss { background: #0b1220; border: 1px solid #334155; color: #cbd5e1; font: inherit;
            font-weight: 700; font-size: .82rem; padding: .4rem .9rem; border-radius: 5px;
            cursor: pointer; white-space: nowrap; }
    .poss.on { background: #1d4ed8; border-color: #60a5fa; color: #fff; }
    /* Defence-in-possession is the state that puts a red tab on air, so it is
       the one that must never be mistaken for the other at a glance. */
    .poss.d.on { background: #b91c1c; border-color: #f87171; }
    .poss:disabled { opacity: .4; cursor: not-allowed; }
    /* A correction is not a state, so it does not get a state colour. */
    .poss.undo { font-weight: 600; color: #94a3b8; border-color: #334155; }
    .poss.undo:not(:disabled):hover { color: #e2e8f0; border-color: #64748b; }
    .connected { font-size: .78rem; color: #64748b; white-space: nowrap; }
    .connected.on { color: #4ade80; }
    .codein { width: 5.6rem; background: #0b1220; border: 1px solid #334155; color: #7dd3fc;
              border-radius: 4px; padding: .3rem .45rem; font-weight: 700; letter-spacing: .12em;
              text-transform: uppercase; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

    .autosel { background: #0b1220; border: 1px dashed #475569; color: #94a3b8; font: inherit;
               font-size: .78rem; padding: .3rem .4rem; border-radius: 4px; cursor: pointer; }
    .autosel.on { border-style: solid; border-color: #38bdf8; color: #7dd3fc; }
    .autosel:disabled { opacity: .4; cursor: not-allowed; }

    .autobtn { background: none; border: 1px dashed #475569; color: #94a3b8; font: inherit;
               font-size: .78rem; padding: .35rem .7rem; border-radius: 4px; cursor: pointer;
               white-space: nowrap; }
    .autobtn.on { border-style: solid; border-color: #38bdf8; color: #7dd3fc; background: #0b2536; }
    .autobtn:disabled { opacity: .4; cursor: not-allowed; }

    .fullbtn { background: none; border: 1px solid #334155; color: #94a3b8;
               border-radius: 4px; padding: .3rem .6rem; font-size: .75rem; cursor: pointer;
               white-space: nowrap; }
    .fullbtn.here { border-color: #38bdf8; color: #7dd3fc; }
    .fullbtn:disabled { opacity: .4; cursor: not-allowed; }

    .switch { min-width: 6.5rem; padding: .6rem 1rem; border: 0; border-radius: 5px;
              background: #334155; color: #cbd5e1; font: inherit; font-weight: 700;
              font-size: .9rem; letter-spacing: .04em; cursor: pointer; }
    /* #16a34a under white is 3.3:1 — below AA for 14.4px bold. #15803d is 5.02:1
       and still reads unmistakably as live. The label carries the state in words
       too ("ON AIR" / "Off"), so it never rests on colour alone. */
    .switch.on { background: #15803d; color: #fff; }
    .switch:disabled { opacity: .4; cursor: not-allowed; }

    .undo { background: none; border: 1px solid #334155; color: #94a3b8; border-radius: 4px;
            padding: .35rem .7rem; font-size: .8rem; cursor: pointer; }
    .undo:disabled { opacity: .35; cursor: not-allowed; }
    /* The panic button: red-edged so it reads as the one control that changes
       everything, but not a filled red block that invites a stray click. */
    .undo.clearall { border-color: #7f1d1d; color: #fca5a5; }
    .undo.clearall:not(:disabled):hover { background: #450a0a; border-color: #b91c1c; color: #fecaca; }
    .flash { font-size: .85rem; color: #fbbf24; opacity: 0; transition: opacity .2s; }
    .flash.on { opacity: 1; }
</style>
</head>
<body>

<!--
  Landmarks, so the page can be navigated by structure rather than by reading it
  top to bottom. The commentator page had these; this one did not, and it is the
  larger of the two.
-->
<header class="topbar">
    <h1>Studio</h1>
    <span class="who" id="who"><span class="dot"></span>Checking…</span>
    <span id="authAction"></span>
</header>
<main>
<p class="lede">Browser-source URLs for a video switcher (OBS, Magewell Director Mini, Yolobox).</p>

<h2 style="margin-top:1.5rem">Stage</h2>
<p class="muted" style="margin-bottom:1rem">
    Pick the game you are covering, point the switcher at the URL below once, and change what
    is on screen from this page. Several games can run at the same time — one stage URL each,
    one switcher each. The game list further down gives single-graphic scoreboard URLs instead,
    for a switcher that wants one fixed overlay and no control.
</p>
<div id="stagePanel"><p class="muted">Loading stage state…</p></div>

<h2>Games</h2>
<div id="games"><p class="muted">Loading games…</p></div>


<div class="panel">
    <strong>Short URLs</strong> — short enough to type on a switcher's on-screen keyboard:
    <ul>
        <li><code>/s/stage</code> — the stage: one URL for the whole broadcast, contents
            controlled from this page. Usually the only one you need.</li>
        <li><code>/s/702</code> — scoreboard for game 702, transparent background. A fixed
            single graphic, for a switcher that wants one source per game instead.</li>
        <li><code>/s/702/green</code> — on a chroma-key background. Also <code>blue</code>,
            <code>magenta</code>, <code>black</code>, or any six-digit hex
            (<code>/s/702/FF00FF</code>). Only needed if your switcher cannot key on alpha.</li>
        <li><code>/s/</code> — this page</li>
    </ul>
    <code>/live/overlays/702</code> and <code>/live/overlays/702/green</code> do the same thing and
    keep working even if the root shortcut is removed.

    <p><strong>Full parameters</strong> — on the long form
    (<code>?view=live/overlays/scoreboard&amp;game=702</code>):</p>
    <ul>
        <li><code>position</code> — <code>top-left</code>, <code>top-center</code> (default),
            <code>top-right</code>, <code>bottom-left</code>, <code>bottom-center</code>,
            <code>bottom-right</code></li>
        <li><code>homecolor</code>, <code>awaycolor</code> — six-digit hex, overriding the pool colour
            (<code>&amp;homecolor=FF0000</code>)</li>
        <li><code>interval</code> — minimum poll interval in ms (default 5000). The overlay also honours
            the API's own cache expiry, so a finished game stops being re-fetched.</li>
        <li><code>bg</code> — page background, as <code>/s/&lt;id&gt;/&lt;colour&gt;</code> above.</li>
    </ul>
    Set the browser source to <strong>1920&times;1080</strong> with a transparent background.
</div>

</main>
<script src="<?= htmlspecialchars($assetUrl('shared/possession.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($assetUrl('shared/ratio.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($assetUrl('shared/tracking.js'), ENT_QUOTES) ?>"></script>
<script>
(function () {
    'use strict';

    var BASE = <?= $json($base) ?>;
    var API = BASE + '/index.php?view=live/api';
    var POSSESSION_URL = BASE + '/index.php?view=live/overlays/possession';
    var container = document.getElementById('games');

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function fail(title, detail) {
        container.replaceChildren();
        var box = el('div', 'err');
        box.append(el('strong', null, title));
        if (detail) box.append(el('p', null, detail));
        container.append(box);
    }

    function classify(game) {
        if (Number(game.isongoing) === 1) return ['live', 'Live'];
        if (game.status === 'completed') return ['done', 'Final'];
        return ['soon', 'Scheduled'];
    }

    // Live games first, then upcoming, then finished — the order an operator wants.
    function rank(game) {
        return Number(game.isongoing) === 1 ? 0 : (game.status === 'completed' ? 2 : 1);
    }

    function renderGames(games) {
        if (!games.length) {
            fail('No games in this event yet.');
            return;
        }

        games.sort(function (a, b) {
            return rank(a) - rank(b) || String(a.time || '').localeCompare(String(b.time || ''));
        });

        var table = el('table');
        var head = el('thead');
        var headRow = el('tr');
        ['Game', 'Where', 'When', 'Status', 'Score', 'Overlay URL', 'Enter scores']
            .forEach(function (label) {
                headRow.append(el('th', null, label));
            });
        head.append(headRow);
        table.append(head);

        var body = el('tbody');
        games.forEach(function (game) {
            var id = Number(game.game_id);
            if (!id) return;

            var state = classify(game);
            // Short form, for typing on a switcher's on-screen keyboard. Served
            // by the rewrite in the repo-root .htaccess; live/overlays/<id> is
            // the self-contained fallback if that block is ever removed.
            var url = window.location.origin + BASE + '/s/' + id;

            var row = el('tr');

            // Round *and* teams. The round name alone ("Semi-final") does not
            // identify a game to someone walking the site with six pitches in
            // play, and it is what the kit colours below refer to.
            var nameCell = el('td');
            nameCell.append(el('div', null, game.gamename || ('Game ' + id)));
            var matchup = el('div', 'matchup');
            matchup.append(teamWithKit(game, 'home', game.hometeam));
            matchup.append(el('span', 'vs', 'v'));
            matchup.append(teamWithKit(game, 'visitor', game.visitorteam));
            nameCell.append(matchup);
            row.append(nameCell);

            // Which field, so an operator walking the site can match a row to
            // the pitch in front of them. The games list carries only a
            // reservation id; entity=reference resolves it for the whole event
            // in one fetch rather than one per game.
            var res = reservations[String(game.reservation)] || null;
            var whereCell = el('td', 'where');
            if (res) {
                whereCell.append(el('strong', null,
                    res.fieldname ? 'Field ' + res.fieldname : (res.name || '')));
                if (res.fieldname && res.name) {
                    whereCell.append(document.createTextNode(' · ' + res.name));
                }
            } else {
                whereCell.append(document.createTextNode('—'));
            }
            row.append(whereCell);

            // Date dropped when every game is on the same day, which is the
            // common case within a tournament and pure noise on every row.
            var when = String(game.time || '');
            row.append(el('td', 'when', when
                ? (singleDay ? when.slice(11, 16) : when.slice(0, 16).replace('T', ' '))
                : '—'));

            var statusCell = el('td');
            statusCell.append(el('span', 'badge ' + state[0], state[1]));
            row.append(statusCell);

            var hasScore = game.homescore !== undefined && game.homescore !== null;
            row.append(el('td', 'score',
                hasScore ? game.homescore + '–' + game.visitorscore : '—'));


            var link = el('a', 'url', url);
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener';
            var urlCell = el('td');
            urlCell.append(link);
            row.append(urlCell);

            // Where the score is actually entered. Scorekeeper has its own login;
            // an unauthenticated click lands on its login page, not an error.
            var keeper = el('a', 'action', 'Scorekeeper ↗');
            keeper.href = BASE + '/scorekeeper/?view=addscoresheet&game=' + id;
            keeper.target = '_blank';
            keeper.rel = 'noopener';
            var keeperCell = el('td');
            keeperCell.append(keeper);
            row.append(keeperCell);

            body.append(row);
        });
        table.append(body);

        container.replaceChildren(table);
    }

    // Shared response handling: the API is JSON for every outcome except the
    // maintenance splash, which is HTML and must not be parsed.
    function readJson(response) {
        if (response.status === 503) {
            throw new Error('Live! is in maintenance mode.');
        }
        return response.json().then(function (body) {
            if (!response.ok || typeof body.error === 'string') {
                // An event that is not published externally answers 403 here,
                // and overlays are gated by that same boundary.
                throw new Error(body.error || ('HTTP ' + response.status));
            }
            return body;
        });
    }

    fetch(API + '&entity=games', { credentials: 'same-origin' })
        .then(readJson)
        .then(function (body) { renderGames(body.games || []); })
        .catch(function (error) {
            fail('The Live! API did not return games.', error.message);
        });

    // ---- kit colours -------------------------------------------------------
    //
    // Entered per game, on the game's own row, by an operator looking at the
    // jerseys minutes before the pull.

    var COLORS_URL = BASE + '/index.php?view=live/overlays/colors';

    var state = { games: {}, admin: false, writable: false };
    var teamIndex = {};
    var gamesList = [];
    var possession = { rev: 0, enabled: false, game: null, events: [], admin: false, writable: false };

    // pool id -> division name, and the season type. Both come from
    // entity=reference, which the page already fetches for field names, and both
    // exist only to answer "is this game mixed, and which ratios apply".
    var seriesIndex = {};
    var seasonType = 'outdoor';
    var reservations = {};
    var singleDay = false;

    // -- stage / show state ---------------------------------------------------

    var SHOW_URL = BASE + '/index.php?view=live/overlays/show';
    var stagePanel = document.getElementById('stagePanel');
    var show = { rev: 0, game: null, cards: [], admin: false, writable: false,
                 slots: [], knownCards: [] };

    function showCanEdit() { return show.admin && show.writable; }

    /** This card's entry in the show state, placed or not. */
    function entryFor(cardId) {
        var found = null;
        (show.cards || []).forEach(function (c) { if (c.id === cardId) { found = c; } });
        return found;
    }

    /** Whether a card fits a position, per the store's own declaration. */
    function fitsIn(cardId, slot) {
        var allowed = (show.cardSlots || {})[cardId];
        return Array.isArray(allowed) ? allowed.indexOf(slot) !== -1 : true;
    }

    /** Which card is currently ON AIR in a slot, ignoring `except`. */
    function onAirIn(slot, except) {
        var found = null;
        (show.cards || []).forEach(function (c) {
            if (c.slot === slot && c.visible && c.id !== except) { found = c.id; }
        });
        return found;
    }

    /** How many cards are configured into a slot, ignoring `except`. */
    function sharing(slot, except) {
        return (show.cards || []).filter(function (c) {
            return c.slot === slot && c.id !== except;
        }).length;
    }

    function saveShow(next) {
        return fetch(SHOW_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(next)
        }).then(function (r) {
            return r.json().then(function (body) {
                if (!r.ok) {
                    // 409 means somebody else moved it. Reload and show theirs
                    // rather than clobbering it with a stale view.
                    if (r.status === 409 && body.state) {
                        show = Object.assign(show, body.state);
                        renderStage();
                    }
                    throw new Error(body.error || ('HTTP ' + r.status));
                }
                show = body;
                renderStage();
                return body;
            });
        });
    }

    /**
     * Put a card in a space, or take it out of one.
     *
     * Placement is NOT exclusive: several cards may be configured into the same
     * space, which is how an operator prepares alternatives and flips between
     * them mid-game. Nothing is displaced here — the exclusivity lives on the
     * on-air switch instead.
     */
    function place(cardId, slot) {
        var entry = entryFor(cardId);

        // A card that is on air stays on air when it is moved. Dropping it off
        // air because the operator repositioned it would be a second, unasked-for
        // action — and mid-broadcast it reads as the graphic having failed.
        var staysOnAir = Boolean(entry && entry.visible);

        // Moving something live into a space that is already live displaces the
        // occupant, exactly as switching one on there would. Allowed, because it
        // is usually what was meant, but never silent.
        var replaced = (slot && staysOnAir) ? onAirIn(slot, cardId) : null;

        var cards = (show.cards || [])
            .filter(function (c) { return c.id !== cardId; })
            .map(function (c) {
                return c.id === replaced
                    ? { id: c.id, slot: c.slot, visible: false, params: c.params || {} }
                    : c;
            });

        if (slot) {
            cards.push({
                id: cardId,
                slot: slot,
                visible: staysOnAir,
                params: (entry && entry.params) || {}
            });
        }

        pushUndo();
        saveShow({ rev: show.rev, game: show.game, logo: show.logo, cards: cards })
            .then(function () {
                if (replaced) { flash(labelOf(replaced) + ' went off air — same position.'); }
            })
            .catch(function (e) { alert(e.message); });
    }

    /**
     * The live action: put a card on air, or take it off.
     *
     * Visibility IS exclusive per space — two cards in one position would draw
     * over each other — so switching one on switches off whatever was there.
     * That is the flip an operator wants, done in one click rather than two.
     */
    function toggle(cardId) {
        var entry = entryFor(cardId);
        if (!entry) { return; }
        var turningOn = !entry.visible;
        var replaced = turningOn ? onAirIn(entry.slot, cardId) : null;

        var cards = (show.cards || []).map(function (c) {
            if (c.id === cardId) {
                return { id: c.id, slot: c.slot, visible: turningOn, params: c.params || {} };
            }
            // Anything sharing the space steps aside — still placed and armed,
            // just no longer on air.
            if (turningOn && c.slot === entry.slot) {
                return { id: c.id, slot: c.slot, visible: false, params: c.params || {} };
            }
            return c;
        });

        pushUndo();
        saveShow({ rev: show.rev, game: show.game, logo: show.logo, cards: cards })
            .then(function () {
                if (replaced) { flash(labelOf(replaced) + ' went off air — same position.'); }
            })
            .catch(function (e) { alert(e.message); });
    }

    /**
     * Clear the frame: everything off air, nothing moved.
     *
     * The one action an operator needs to reach without thinking — a graphic is
     * wrong, or the director calls for a clean picture, and the fix has to be
     * one click rather than one click per card. Placement is deliberately left
     * alone: the cards stay set up and preloaded, so putting the stage back is
     * as fast as switching them on again, and undo restores the whole frame.
     */
    function allOffAir() {
        var cards = (show.cards || []).map(function (c) {
            return { id: c.id, slot: c.slot, visible: false, params: c.params || {} };
        });
        var count = (show.cards || []).filter(function (c) { return c.visible; }).length;
        if (!count) { return; }

        pushUndo();
        saveShow({ rev: show.rev, game: show.game, logo: show.logo, cards: cards })
            .then(function () {
                flash(count === 1
                    ? 'Stage cleared — 1 card off air.'
                    : 'Stage cleared — ' + count + ' cards off air.');
            })
            .catch(function (e) { alert(e.message); });
    }

    /**
     * Cards that can run themselves.
     *
     * Only the last-play family, because only they are ABOUT an event. A
     * scoreboard has something to say continuously and a stat card has
     * something to say whenever anyone looks; a "last goal" card is worth
     * exactly the few seconds after a goal and is clutter for the rest of the
     * point. That is the test for adding another one here.
     */
    /**
     * What each card can run itself off.
     *
     * The moment has to suit the card. A last-goal card is about a goal and is
     * clutter for the rest of the point; a stats card has something to say at
     * half time and nothing to say during a pull. So the offer is per card
     * rather than one global switch.
     */
    var AUTO_OPTIONS = [
        { key: 'off', label: 'Manual', hint: 'Stays up while it is on air.' },
        { key: 'goal', label: 'On goals', secs: 15, hint: '15s after each goal.' },
        { key: 'halftime', label: 'At half time', secs: 30, hint: '30s when the halftime cap is called.' },
        { key: 'final', label: 'At full time', secs: 45, hint: '45s once the game is final.' },
        { key: 'pregame', label: 'Before the pull', secs: null, hint: 'Up until the game starts, then gone.' },
    ];

    var AUTO_FOR = {
        lastgoal: ['off', 'goal'],
        lastassist: ['off', 'goal'],
        lastplay: ['off', 'goal'],
        summary: ['off', 'pregame', 'halftime', 'final', 'goal'],
        progression: ['off', 'halftime', 'final'],
        topplayers: ['off', 'halftime', 'final', 'pregame'],
        // Each of these has exactly one moment. Offering the others would only
        // be a way to put a half-time card on air at full time.
        pregame: ['off', 'pregame'],
        halftime: ['off', 'halftime'],
        postgame: ['off', 'final'],
    };

    function autoChoices(cardId) { return AUTO_FOR[cardId] || null; }

    /**
     * Does the tournament logo take this position out of use?
     *
     * Same table as the store, sent with the show state so the two cannot drift.
     * The centre of an edge is blocked as well as the matching corner, because
     * the scoreboard bug is 78% of the frame wide and reaches the far corner
     * from the middle — see LOGO_BLOCKS in shared/show.php.
     */
    function logoBlocks(slot) {
        var blocked = (show.logoBlocks || {})[show.logo || ''] || [];
        return blocked.indexOf(slot) !== -1;
    }

    /**
     * The two ratios in play, or null when the question does not arise.
     *
     * Mixed divisions only, decided by the division name exactly as
     * UltiOrganizer's own scoresheet decides it. Offering a ratio control on an
     * Open game would be inviting an operator to record something meaningless.
     */
    function ratioChoices() {
        var game = null;
        gamesList.forEach(function (g) {
            if (Number(g.game_id) === Number(show.game)) { game = g; }
        });
        if (!game) { return null; }
        if (!window.Ratio.isMixed(seriesIndex[String(game.pool)])) { return null; }
        return window.Ratio.pair(seasonType);
    }



    function autoOf(entry) {
        var a = entry && entry.params && entry.params.auto;
        if (!a) { return 'off'; }
        if (a === true) { return 'goal'; }
        return a.on || 'goal';
    }

    function setAuto(cardId, choice) {
        var spec = null;
        AUTO_OPTIONS.forEach(function (o) { if (o.key === choice) { spec = o; } });

        var cards = (show.cards || []).map(function (c) {
            if (c.id !== cardId) { return c; }
            var params = {};
            Object.keys(c.params || {}).forEach(function (k) { params[k] = c.params[k]; });
            if (!spec || spec.key === 'off') {
                delete params.auto;
            } else {
                params.auto = { on: spec.key, 'for': spec.secs };
            }
            return { id: c.id, slot: c.slot, visible: c.visible, params: params };
        });

        pushUndo();
        saveShow({ rev: show.rev, game: show.game, logo: show.logo, cards: cards })
            .then(function () {
                flash(spec && spec.key !== 'off'
                    ? labelOf(cardId) + ': ' + spec.hint
                    : labelOf(cardId) + ' is back to manual.');
            })
            .catch(function (e) { alert(e.message); });
    }

    /* ---------------------------------------------------------------
       Export and import
       --------------------------------------------------------------- */

    /**
     * A stage configuration as a file.
     *
     * Three jobs, and the third is the reason it earns its place. It carries a
     * setup from one field to the next without rebuilding it by hand; it makes a
     * known-good arrangement something you can keep in a repository rather than
     * in an operator's memory; and it is the input to post-production, where a
     * game recorded without a switcher gets the same overlay added afterwards.
     * The card set and the auto timings a broadcast used live ARE the ones the
     * recording should get, so authoring them twice would only be a way to make
     * them differ.
     *
     * Deliberately NOT included: `rev`, which belongs to one store and would be
     * meaningless elsewhere, and `game`, because a layout is reusable and a game
     * id is not. Importing therefore never moves the stage to another game.
     */
    var CONFIG_VERSION = 1;

    function exportConfig() {
        var doc = {
            kind: 'live-by-bula-broadcast/stage',
            version: CONFIG_VERSION,
            logo: show.logo || '',
            cards: (show.cards || []).map(function (c) {
                return { id: c.id, slot: c.slot, visible: Boolean(c.visible),
                         params: c.params && Object.keys(c.params).length ? c.params : {} };
            }),
        };
        var text = JSON.stringify(doc, null, 2) + '\n';
        var blob = new Blob([text], { type: 'application/json' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'stage-config.json';
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
        flash('Exported ' + doc.cards.length + ' cards.');
    }

    /**
     * Read a configuration back.
     *
     * Nothing here is trusted: the file has been on a USB stick and through
     * somebody's downloads folder. It is sent to the store like any other write,
     * and the store drops what it does not recognise -- unknown cards, positions
     * a card does not fit, a second visible card in one slot. So a corrupt or
     * hostile file cannot produce a frame the UI could not have produced.
     */
    function importConfig(file) {
        var reader = new FileReader();
        reader.onload = function () {
            var doc;
            try { doc = JSON.parse(String(reader.result)); } catch (e) {
                alert('That is not a JSON file.');
                return;
            }
            if (!doc || doc.kind !== 'live-by-bula-broadcast/stage' || !Array.isArray(doc.cards)) {
                alert('That is not a stage configuration.');
                return;
            }
            if (Number(doc.version) > CONFIG_VERSION) {
                alert('That file was written by a newer version than this one.');
                return;
            }
            pushUndo();
            saveShow({ rev: show.rev, game: show.game, logo: doc.logo || show.logo,
                       cards: doc.cards })
                .then(function (state) {
                    var kept = (state.cards || []).length;
                    flash(kept === doc.cards.length
                        ? 'Imported ' + kept + ' cards.'
                        : 'Imported ' + kept + ' of ' + doc.cards.length
                          + ' cards; the rest were not valid here.');
                })
                .catch(function (e) { alert(e.message); });
        };
        reader.readAsText(file);
    }

    // One level of undo, which is all this needs: the mistakes worth reversing
    // are "I just displaced something" and "I switched off the wrong card", and
    // both are the immediately preceding action.
    var undoState = null;

    function pushUndo() {
        undoState = { game: show.game, logo: show.logo, cards: JSON.parse(JSON.stringify(show.cards || [])) };
    }

    function undo() {
        if (!undoState) { return; }
        var restore = undoState;
        undoState = null;
        saveShow({ rev: show.rev, game: restore.game, logo: show.logo, cards: restore.cards })
            .catch(function (e) { alert(e.message); });
    }

    /**
     * Flag, on hover, which cards a click is about to take off air.
     *
     * Blocking the click would be the wrong trade: displacing something is often
     * exactly what the operator intends, and a disabled control gives them no
     * route to it. Showing the consequence in advance keeps the action available
     * while removing the surprise — the warning lands on the card that would
     * lose, which is where an operator is looking for it.
     */
    function warnOnHover(node, victimIds) {
        var ids = Array.isArray(victimIds) ? victimIds : [victimIds];
        if (!ids.length) { return; }

        var mark = function (on) {
            ids.forEach(function (v) {
                var row = document.querySelector('.cardrow[data-card="' + v + '"]');
                if (row) { row.classList.toggle('losing', on); }
            });
        };
        node.classList.add('displaces');
        node.addEventListener('mouseenter', function () { mark(true); });
        node.addEventListener('mouseleave', function () { mark(false); });
        // Keyboard users get the same warning; a tab-through should not be a
        // worse experience than a hover.
        node.addEventListener('focus', function () { mark(true); });
        node.addEventListener('blur', function () { mark(false); });
    }

    function labelOf(cardId) {
        return ({
            scoreboard: 'Scoreboard',
            summary: 'Summary — picks its own moment',
            progression: 'Score progression',
            topplayers: 'Top scorers',
            lastgoal: 'Last goal — scorer',
            lastassist: 'Last goal — assist',
            lastplay: 'Last goal — scorer + assist',
            pregame: 'Pre-game',
            halftime: 'Half time',
            postgame: 'Full time'
        })[cardId] || cardId;
    }

    var flashTimer = null;

    function flash(message) {
        var node = document.getElementById('stageFlash');
        if (!node) { return; }
        node.textContent = message;
        node.className = 'flash on';
        if (flashTimer) { clearTimeout(flashTimer); }
        flashTimer = setTimeout(function () { node.className = 'flash'; }, 6000);
    }

    function setStageGame(gameId) {
        saveShow({ rev: show.rev, game: gameId ? Number(gameId) : null, cards: show.cards || [] })
            .catch(function (e) { alert(e.message); });
    }

    /* ---------------------------------------------------------------
       Possession — break chance and clean holds
       --------------------------------------------------------------- */

    /**
     * The live score of the pinned game, as the store's key.
     *
     * Every possession event is stamped with the score it was made at, which is
     * what makes a point self-identifying and a goal self-resetting. So the
     * Studio has to know the score to write one, and it already does — the game
     * list it renders carries it.
     */
    function currentScoreKey() {
        var id = show.game;
        var game = null;
        gamesList.forEach(function (g) { if (Number(g.game_id) === Number(id)) { game = g; } });
        if (!game || game.homescore === undefined || game.homescore === null) { return null; }
        return (Number(game.homescore) || 0) + '-' + (Number(game.visitorscore) || 0);
    }

    /**
     * A client for whichever game the stage is on.
     *
     * Rebuilt per call rather than held, because the operator can repin the
     * stage at any moment and each game has its own document — there is no
     * "current" store to keep a handle on.
     */
    function trackFor(game) {
        return window.Tracking.client({ endpoint: POSSESSION_URL, game: game });
    }

    function postPossession(change) {
        if (!show.game) {
            return Promise.reject(new Error('Pick a game first.'));
        }
        return trackFor(show.game).write(change).then(function (state) {
            possession = state;
            renderStage();
            return state;
        });
    }

    function setPossessionMode(on) {
        postPossession({ enabled: on, game: show.game || null })
            .then(function () {
                flash(on
                    ? 'Break chance mode on — press O and D as possession changes.'
                    : 'Break chance mode off. The scoreboard falls back to ON DEFENCE.');
            })
            .catch(function (e) { alert(e.message); });
    }

    /**
     * Undo the last press, or clear the point.
     *
     * Scoped to the point on the clock, which is what makes it safe to put
     * beside the live controls: it changes a number nobody has read out yet.
     */
    function correct(what) {
        var key = currentScoreKey();
        if (!key) { return; }
        var body = { game: show.game || null, score: key };
        Object.keys(what).forEach(function (k) { body[k] = what[k]; });
        postPossession(body)
            .then(function () {
                flash(what.clearPoint
                    ? 'Point ' + key + ' cleared.'
                    : 'Last press undone.');
            })
            .catch(function (e) { alert(e.message); });
    }

    /** Flag or clear an injury stoppage for the point on the clock. */
    function toggleStoppage() {
        var key = currentScoreKey();
        if (!key) { flash('No live score for this game yet.'); return; }
        var on = Boolean(possession.stoppage && possession.stoppage.score === key);
        postPossession({ game: show.game || null, score: key, stoppage: !on })
            .then(function () {
                flash(on ? 'Stoppage ended.' : 'Injury stoppage on air.');
            })
            .catch(function (e) { alert(e.message); });
    }

    function setDefence(hasDisc) {
        if (!possession.enabled) { return; }
        var key = currentScoreKey();
        if (!key) { flash('No live score for this game yet.'); return; }
        postPossession({ game: show.game || null, score: key, defence: hasDisc })
            .catch(function (e) { alert(e.message); });
    }

    function defenceHasDisc() {
        var key = currentScoreKey();
        if (!key || !window.Possession) { return false; }
        var parts = key.split('-');
        return window.Possession.defenceHasDisc(possession.events, parts[0], parts[1]);
    }

    /**
     * O and D, on the keyboard.
     *
     * Possession changes several times in a scrappy point and an operator is
     * watching the programme output, not this page. Hunting for a button with a
     * mouse each time is the thing that makes operator-declared possession fail
     * in practice, so the two states get one key each and nothing else does.
     *
     * Ignored while typing, so a colour hex or a game filter cannot flip what is
     * on air mid-word.
     */
    document.addEventListener('keydown', function (e) {
        if (e.metaKey || e.ctrlKey || e.altKey) { return; }
        var tag = (e.target && e.target.tagName) || '';
        if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || e.target.isContentEditable) { return; }
        var key = (e.key || '').toLowerCase();
        if (key !== 'o' && key !== 'd' && key !== 'u' && key !== 'i') { return; }
        if (!showCanEdit()) { return; }
        // I is independent of possession tracking: a stoppage is its own fact.
        if (key === 'i') { e.preventDefault(); toggleStoppage(); return; }
        if (!possession.enabled) { return; }
        e.preventDefault();
        // U undoes, because a mis-key needs unmaking at the same speed it was
        // made -- reaching for a mouse mid-point is how the wrong thing stays up.
        if (key === 'u') { correct({ undo: true }); return; }
        setDefence(key === 'd');
    });

    /**
     * The commentary link: one code, and who is on it.
     *
     * Separate from what is being tracked, and that separation is the point. The
     * code used to be created as a side effect of turning break chances on,
     * which meant an operator could not hand the desk the gender ratio or an
     * injury stoppage without also switching on a graphic they might not want.
     * The link is now its own thing; each trackable fact decides its own
     * conditions.
     */
    function linkBar() {
        var bar = el('div', 'stagebar possession');
        bar.append(el('span', 'muted', 'Commentator link'));

        var codeIn = document.createElement('input');
        codeIn.type = 'text';
        codeIn.className = 'codein';
        codeIn.maxLength = 5;
        codeIn.placeholder = '\u2014\u2014\u2014\u2014\u2014';
        codeIn.value = possession.code || '';
        codeIn.disabled = !showCanEdit();
        codeIn.setAttribute('aria-label', 'Commentator room code');
        codeIn.title = 'The 5-character code shown on the commentator page. '
            + 'Only that code may track anything; clear the field to take it back.';
        codeIn.addEventListener('change', function () {
            var v = codeIn.value.toUpperCase().trim();
            postPossession({ game: show.game || null, code: v || null })
                .then(function (state) {
                    flash(state.code ? 'Code ' + state.code + ' can now track.'
                        : 'Commentator tracking revoked.');
                })
                .catch(function (e) { alert(e.message); });
        });
        bar.append(codeIn);

        var gen = el('button', 'undo', '\u21ba New code');
        gen.type = 'button';
        gen.disabled = !showCanEdit();
        gen.title = 'Generate one to read out. Left to invent a code people pick "12345".';
        gen.addEventListener('click', function () {
            postPossession({ game: show.game || null, code: 'new' })
                .then(function (state) { flash('Code is ' + state.code + '.'); })
                .catch(function (e) { alert(e.message); });
        });
        bar.append(gen);

        // Who is actually out there, BY NAME. "2 connected" answers the wrong
        // question: the operator needs to know whether the right desk is on this
        // code, which a count cannot tell them.
        var clients = possession.clients || [];
        if (!possession.code) {
            bar.append(el('span', 'connected', 'no code set'));
        } else if (!clients.length) {
            bar.append(el('span', 'connected', 'nobody connected'));
        } else {
            var list = el('span', 'connected on');
            list.textContent = clients.map(function (c) {
                return c.name || 'unnamed desk';
            }).join(' \u00b7 ');
            list.title = 'Commentator pages polling with this code right now.';
            bar.append(list);
        }
        return bar;
    }

    function possessionBar() {
        var bar = el('div', 'stagebar possession');
        var on = Boolean(possession.enabled);

        var mode = el('button', 'autobtn' + (on ? ' on' : ''));
        mode.type = 'button';
        mode.disabled = !showCanEdit();
        // Named for what it switches on, not for one of the things it feeds.
        // "Break chance" described a single graphic; tracking possession also
        // powers clean holds, the turnover count on the bug, and the conversion
        // figures on the summary cards.
        mode.textContent = on ? 'Possession tracking: on' : 'Possession tracking: off';
        mode.title = on
            ? 'Stop declaring possession. The scoreboard falls back to the standing ON DEFENCE tag.'
            : 'Declare possession by hand. Feeds BREAK CHANCE, clean holds, the turnover count and break-chance conversion.';
        mode.setAttribute('aria-pressed', on ? 'true' : 'false');
        mode.addEventListener('click', function () { setPossessionMode(!on); });
        bar.append(mode);

        // The first point's ratio sits here, not on the progression card.
        //
        // It is the same kind of thing as possession: a fact about the game that
        // UltiOrganizer does not record, collected by hand. Putting it on a card
        // made it a property of one graphic, which meant the commentator panel
        // and the card each had their own copy and could disagree on air.
        var ratios = ratioChoices();
        if (!ratios) {
            // Say why rather than showing nothing. An absent control is
            // indistinguishable from a missing feature, and the first question
            // asked of this panel was "where do I enter the gender ratio".
            bar.append(el('span', 'muted',
                show.game ? 'No gender ratio: this division is not mixed.'
                    : 'Pick a game to set the gender ratio.'));
        }
        if (ratios) {
            var rWrap = el('span', 'kitside');
            rWrap.append(el('span', 'muted', 'Ratio on point 1'));
            var rsel = document.createElement('select');
            var chosen = possession.ratio1 || '';
            rsel.className = 'autosel' + (chosen ? ' on' : '');
            rsel.disabled = !showCanEdit();
            rsel.title = 'Circled on the paper scoresheet, and recorded nowhere else. '
                + 'Until it is set, nothing can name which ratio a point was played at.';
            [['', 'not set']].concat(ratios.map(function (r) {
                return [r, window.Ratio.short(r)];
            }))
                .forEach(function (o) {
                    var opt = document.createElement('option');
                    opt.value = o[0];
                    opt.textContent = o[1];
                    if (o[0] === chosen) { opt.selected = true; }
                    rsel.append(opt);
                });
            rsel.addEventListener('change', function () {
                postPossession({ game: show.game || null, ratio1: rsel.value })
                    .then(function (state) {
                        flash(state.ratio1
                            ? 'Point 1 was ' + state.ratio1 + '.'
                            : 'Ratio cleared \u2014 points are no longer labelled.');
                    })
                    .catch(function (e) { alert(e.message); });
            });
            rWrap.append(rsel);
            bar.append(rWrap);
        }

        if (!on) {
            bar.append(el('span', 'muted',
                'Off: the scoreboard shows ON DEFENCE, which is always true but never an event.'));
        }

        // Hand tracking to the commentary desk by naming their room code.
        //
        // The commentator is better placed to do this than the operator —
        // watching the play IS their job, whereas the operator is choosing and
        // timing graphics. Naming one code rather than accepting any is what
        // keeps this a single-entry allowlist: clearing the field revokes it.

        var key = currentScoreKey();
        var d = defenceHasDisc();

        // Several people may track at once, and that is safe by construction.
        //
        // The log records the STATE of possession, not transitions, and every
        // reader counts changes rather than entries -- so two people both
        // pressing D is D then D, which is not a change and is already ignored.
        // Measured: one press, two presses and three presses all yield one
        // turnover. The store drops the repeat on the way in as well, so the log
        // does not fill with them.
        //
        // What this does NOT survive is two people who disagree, one pressing O
        // while the other presses D. That flaps, and no locking fixes it because
        // both writes are real. Hence the count below rather than a lockout: the
        // operator can see how many people are on the code and sort it out.
        [['o', 'O \u2014 offence', false], ['d', 'D \u2014 defence', true]].forEach(function (spec) {
            var active = (spec[2] === d);
            var b = el('button', 'poss' + (active ? ' on' : '') + (spec[2] ? ' d' : ''));
            b.type = 'button';
            b.disabled = !showCanEdit() || !key;
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
            b.textContent = spec[1];
            b.title = 'Keyboard: ' + spec[0].toUpperCase();
            b.addEventListener('click', function () { setDefence(spec[2]); });
            bar.append(b);
        });

        // Corrections, next to the presses they correct.
        //
        // A mis-key on air is the ordinary case, not the exceptional one, and
        // until now the only remedy was to press the right thing instead --
        // which fixes the current state but leaves the wrong flip in the log,
        // inflating that point's turnover count for good.
        var pressed = key && window.Possession
            ? window.Possession.eventsFor(possession.events, key).length : 0;

        var undo = el('button', 'poss undo');
        undo.type = 'button';
        undo.textContent = '\u21b6 Undo';
        undo.disabled = !showCanEdit() || !pressed;
        undo.title = pressed
            ? 'Remove the last press of this point'
            : 'Nothing pressed this point';
        undo.addEventListener('click', function () { correct({ undo: true }); });
        bar.append(undo);

        if (pressed > 1) {
            // Only once there is a mess worth clearing; with one press, undo is
            // the same thing and less alarming.
            var clr = el('button', 'poss undo');
            clr.type = 'button';
            clr.textContent = 'Clear point';
            clr.disabled = !showCanEdit();
            clr.title = 'Forget all ' + pressed + ' presses of this point and start it again';
            clr.addEventListener('click', function () { correct({ clearPoint: true }); });
            bar.append(clr);
        }

        // Injury stoppage: the third declared fact, and independent of the other
        // two. Nothing records these, so somebody flags it; keyed by score, so
        // it clears itself at the next goal.
        var stopOn = Boolean(key && possession.stoppage && possession.stoppage.score === key);
        var stop = el('button', 'poss' + (stopOn ? ' on d' : ''));
        stop.type = 'button';
        stop.disabled = !showCanEdit() || !key;
        stop.setAttribute('aria-pressed', stopOn ? 'true' : 'false');
        stop.textContent = stopOn ? '\u25a0 End stoppage' : '\u2691 Injury stoppage';
        stop.title = 'Keyboard: I';
        stop.addEventListener('click', toggleStoppage);
        bar.append(stop);

        var trackers = Number(possession.connected) || 0;
        if (possession.code && trackers > 1) {
            bar.append(el('span', 'muted',
                trackers + ' commentators are on code ' + possession.code
                + ' \u2014 agree who is calling possession, or they will contradict each other.'));
        }

        bar.append(el('span', 'muted', key
            ? 'Point at ' + key + ' \u00b7 resets to offence on every goal'
            : 'Waiting for a live score'));
        return bar;
    }

    /**
     * Follow possession, do not only drive it.
     *
     * A commentator with the room code may be pressing O and D from another
     * device, and an operator whose buttons showed only their own last press
     * would be looking at a lie. Cheap: the file is tiny and this is the same
     * channel the stage already polls.
     */
    function startPossessionPoll() {
        // Who is connected is NOT part of `rev`.
        //
        // `rev` counts writes to a game's possession document; a commentator
        // simply polling does not write to it, so the roster changes underneath
        // an unchanged rev. Comparing rev alone meant the operator typed a code,
        // saw "nobody connected", and kept seeing it after the desk joined.
        var rosterOf = function (state) {
            return (state.clients || []).map(function (c) {
                return c.name || '?';
            }).join('\u0000');
        };

        setInterval(function () {
            if (!show.game) { return; }
            trackFor(show.game).read()
                .then(function (state) {
                    if (state.rev === possession.rev
                        && rosterOf(state) === rosterOf(possession)) { return; }
                    possession = state;
                    renderStage();
                })
                .catch(function () { /* transient; the next tick retries */ });
        }, 2000);
    }

    function renderStage() {
        stagePanel.replaceChildren();

        // Pick a game, get its URL. Nothing else: every stage is pinned to one
        // game, so listing every game's URL at once was noise — the operator
        // wants the one they are covering.
        var origin = window.location.origin + BASE;

        var gameBar = el('div', 'stagebar');
        gameBar.append(el('span', 'muted', 'Game:'));
        var sel = document.createElement('select');
        sel.disabled = !showCanEdit();
        var none = document.createElement('option');
        none.value = '';
        none.textContent = '— none —';
        sel.append(none);
        gamesList.forEach(function (g) {
            var o = document.createElement('option');
            o.value = g.game_id;
            var res = reservations[String(g.reservation)];
            o.textContent = (g.gamename || ('Game ' + g.game_id))
                + (res && res.fieldname ? ' · Field ' + res.fieldname : '')
                + (Number(g.isongoing) === 1 ? ' · LIVE' : '');
            if (Number(show.game) === Number(g.game_id)) { o.selected = true; }
            sel.append(o);
        });
        sel.addEventListener('change', function () { setStageGame(sel.value); });
        gameBar.append(sel);
        stagePanel.append(gameBar);

        // The one URL a switcher needs for this game.
        var urlBar = el('div', 'stagebar');
        urlBar.append(el('span', 'muted', 'Stage URL:'));
        if (show.game) {
            var u = origin + '/s/' + show.game + '/overlay';
            var a = el('a', 'url', u);
            a.href = u;
            a.target = '_blank';
            a.rel = 'noopener';
            a.title = 'Point the switcher here — a stage pinned to this game';
            urlBar.append(a);
        } else {
            urlBar.append(el('span', 'muted', 'select a game first'));
        }
        stagePanel.append(urlBar);

        // Set once for the event, not per URL. The image itself is Live!'s
        // TV_SCREEN_LOGO_PATH, which is installation-wide — see docs/STUDIO.md for
        // the per-tournament data ask.
        var logoBar = el('div', 'stagebar');
        logoBar.append(el('span', 'muted', 'Tournament logo:'));
        var logoSel = document.createElement('select');
        logoSel.disabled = !showCanEdit();
        [['', 'off']].concat((show.logoCorners || []).map(function (c) {
            return [c, c.replace('-', ' ')];
        })).forEach(function (pair) {
            var o = document.createElement('option');
            o.value = pair[0];
            o.textContent = pair[1];
            if ((show.logo || '') === pair[0]) { o.selected = true; }
            logoSel.append(o);
        });
        logoSel.addEventListener('change', function () {
            pushUndo();
            saveShow({ rev: show.rev, game: show.game, logo: logoSel.value,
                       cards: show.cards || [] })
                .catch(function (e) { alert(e.message); });
        });
        logoBar.append(logoSel);
        logoBar.append(el('span', 'muted', 'keeps clear of anything in the same corner'));
        stagePanel.append(logoBar);

        /* One row per piece of content, not per position.
         *
         * The two decisions have very different frequencies: where a card lives
         * is settled once during setup, whether it is on air is pressed many
         * times during a game. So they get very different weights — a small
         * spatial picker for placement, a large unmistakable switch for on/off.
         *
         * Placement doubles as arming: a placed card is mounted and preloaded by
         * the stage even while switched off, so turning it on is instant. */
        var list = el('div', 'cardlist');

        (show.knownCards || []).forEach(function (id) {
            var entry = entryFor(id);
            var placed = entry ? entry.slot : null;
            var on = Boolean(entry && entry.visible);

            var row = el('div', 'cardrow' + (on ? ' live' : ''));
            row.dataset.card = id;
            row.append(el('div', 'cardname', labelOf(id)));

            // A 3x3 map of the frame plus a full-frame option. Spatial, because
            // the thing being chosen is a position — a dropdown of words like
            // "lower-left" makes the reader translate.
            var picker = el('div', 'picker');
            ['upper-left', 'upper-center', 'upper-right',
             'center', 'center', 'center',
             'lower-left', 'lower-center', 'lower-right'].forEach(function (slot, i) {
                // The middle row is one wide cell, matching the stage layout.
                if (slot === 'center' && i !== 4) { return; }
                // A card is only offered positions it physically fits — the
                // store rejects the rest anyway, so offering them would just
                // produce a silent no-op.
                var fits = fitsIn(id, slot);
                // The tournament logo owns its corner and does not move out of
                // the way, so the positions it blocks are shown as blocked. The
                // store refuses them regardless; without this the operator would
                // click, see nothing happen, and have to read a warning to find
                // out why.
                var blocked = fits && logoBlocks(slot);
                var b = el('button', 'cell'
                    + (placed === slot ? ' here' : '')
                    + (fits ? '' : ' unfit')
                    + (blocked ? ' blocked' : '')
                    + (fits && !blocked && onAirIn(slot, id) ? ' taken' : '')
                    + (slot === 'center' ? ' span' : ''));
                b.type = 'button';
                b.disabled = !showCanEdit() || !fits || blocked;

                // Sharing a position costs nothing — several cards live there
                // and the operator flips between them — so this only reports it.
                var shared = fits ? sharing(slot, id) : 0;
                b.title = !fits
                    ? slot.replace('-', ' ') + ' — too small for this card'
                    : blocked
                    ? slot.replace('-', ' ') + ' — the tournament logo is in this corner'
                    : slot.replace('-', ' ')
                        + (shared ? ' — shared with ' + shared + ' other card'
                            + (shared > 1 ? 's' : '') : '');
                // Placed-or-not is a state, not a label: without this the only
                // difference between the chosen cell and the other eight is a
                // background colour.
                b.setAttribute('aria-pressed', placed === slot ? 'true' : 'false');
                b.addEventListener('click', function () {
                    place(id, placed === slot ? null : slot);
                });
                picker.append(b);
            });
            row.append(picker);

            // Two positions that are not cells on the frame grid, so they get
            // their own buttons rather than being squeezed into the map.
            [['fullscreen', 'Full frame', 'Takeover — hides everything else while on'],
             ['with-scoreboard', 'With scoreboard',
              'Rides with the scoreboard, flipping above or below it so the two never overlap']
            ].forEach(function (spec) {
                if (!fitsIn(id, spec[0])) { return; }
                var b = el('button', 'fullbtn' + (placed === spec[0] ? ' here' : ''));
                b.type = 'button';
                b.disabled = !showCanEdit();
                b.textContent = spec[1];
                b.title = spec[2];
                b.addEventListener('click', function () {
                    place(id, placed === spec[0] ? null : spec[0]);
                });
                row.append(b);
            });

            // Auto sits beside the switch rather than replacing it, because it
            // does not replace it: the card is still switched on, still owns its
            // position, and auto only decides whether it is painting right now.
            var choices = autoChoices(id);
            if (choices) {
                var current = autoOf(entry);
                var sel = document.createElement('select');
                sel.className = 'autosel' + (current === 'off' ? '' : ' on');
                sel.disabled = !showCanEdit() || !placed;
                sel.title = placed ? 'When this card shows itself' : 'Give it a position first';
                AUTO_OPTIONS.forEach(function (o) {
                    if (choices.indexOf(o.key) === -1) { return; }
                    var opt = document.createElement('option');
                    opt.value = o.key;
                    opt.textContent = o.label;
                    opt.title = o.hint;
                    if (o.key === current) { opt.selected = true; }
                    sel.append(opt);
                });
                sel.addEventListener('change', function () { setAuto(id, sel.value); });
                row.append(sel);
            }

            var sw = el('button', 'switch' + (on ? ' on' : ''));
            sw.type = 'button';
            sw.disabled = !showCanEdit() || !placed;
            sw.textContent = on ? 'ON AIR' : 'Off';
            sw.title = placed ? '' : 'Give it a position first';

            // Switching ON takes the position from whatever holds it, and a
            // full-frame card blanks the lot. Both are worth knowing before the
            // click rather than after, so the warning lands on the rows that
            // would go dark.
            if (!on && placed) {
                var victims = placed === 'fullscreen'
                    ? (show.cards || []).filter(function (c) {
                        return c.id !== id && c.visible;
                    }).map(function (c) { return c.id; })
                    : [onAirIn(placed, id)].filter(Boolean);

                if (victims.length) {
                    sw.title = placed === 'fullscreen'
                        ? 'Takeover — this will clear everything else'
                        : 'Takes this position from ' + labelOf(victims[0]);
                    warnOnHover(sw, victims);
                }
            }

            // The state is otherwise carried only by colour and the word on the
            // button face, which a screen reader gets as a label rather than as
            // a state. This is the control that decides what is on air.
            sw.setAttribute('aria-pressed', on ? 'true' : 'false');
            sw.addEventListener('click', function () { toggle(id); });
            row.append(sw);

            list.append(row);
        });
        stagePanel.append(list);

        var bottom = el('div', 'stagebar');
        var liveCount = (show.cards || []).filter(function (c) { return c.visible; }).length;
        var clearBtn = el('button', 'undo clearall');
        clearBtn.type = 'button';
        clearBtn.textContent = '⏻ All off air';
        clearBtn.disabled = !showCanEdit() || !liveCount;
        clearBtn.title = liveCount
            ? 'Takes all ' + liveCount + ' on-air card' + (liveCount === 1 ? '' : 's')
              + ' off air. Positions are kept.'
            : 'Nothing is on air.';
        clearBtn.addEventListener('click', allOffAir);
        if (liveCount) {
            warnOnHover(clearBtn, (show.cards || [])
                .filter(function (c) { return c.visible; })
                .map(function (c) { return c.id; }));
        }
        bottom.append(clearBtn);

        var exportBtn = el('button', 'undo');
        exportBtn.type = 'button';
        exportBtn.textContent = '⇩ Export';
        exportBtn.title = 'Save this arrangement as a file — to reuse on another field, '
            + 'or to add the same overlay to a recording afterwards.';
        exportBtn.disabled = !(show.cards || []).length;
        exportBtn.addEventListener('click', exportConfig);
        bottom.append(exportBtn);

        var importBtn = el('button', 'undo');
        importBtn.type = 'button';
        importBtn.textContent = '⇧ Import';
        importBtn.title = 'Load a saved arrangement. Anything invalid here is dropped.';
        importBtn.disabled = !showCanEdit();
        // Hidden and driven by the labelled button above — the ordinary pattern
        // for a file input. `display: none` already keeps it out of the
        // accessibility tree and the tab order; the label is here so that stays
        // true if the styling ever changes.
        var picker = document.createElement('input');
        picker.type = 'file';
        picker.accept = 'application/json,.json';
        picker.setAttribute('aria-label', 'Stage configuration file');
        picker.tabIndex = -1;
        picker.style.display = 'none';
        picker.addEventListener('change', function () {
            if (picker.files && picker.files[0]) { importConfig(picker.files[0]); }
            picker.value = '';
        });
        importBtn.addEventListener('click', function () { picker.click(); });
        bottom.append(importBtn, picker);

        var undoBtn = el('button', 'undo');
        undoBtn.type = 'button';
        undoBtn.textContent = '↶ Undo';
        undoBtn.disabled = !showCanEdit() || !undoState;
        undoBtn.addEventListener('click', undo);
        bottom.append(undoBtn);
        var flashNode = el('span', 'flash');
        flashNode.id = 'stageFlash';
        // The sole report that something went off air. Announced, not just shown:
        // an operator watching the program monitor is not looking at this bar.
        flashNode.setAttribute('role', 'status');
        flashNode.setAttribute('aria-live', 'polite');
        bottom.append(flashNode);
        stagePanel.append(bottom);
        stagePanel.append(linkBar());
        stagePanel.append(possessionBar());

        var note = el('p', 'muted');
        note.style.marginTop = '.5rem';
        note.textContent = showCanEdit()
            ? 'Position is setup; the switch is the live action. A placed card is preloaded even while off, so switching it on is instant.'
            : (show.admin
                ? 'live/overlays/conf/ is not writable by the web server, so the stage cannot be changed.'
                : 'Read-only. With nothing configured the stage runs in auto mode: scoreboard only.');
        stagePanel.append(note);
    }

    // -- who am I -------------------------------------------------------------

    function renderAuth() {
        var who = document.getElementById('who');
        var action = document.getElementById('authAction');
        who.className = 'who' + (state.admin ? ' admin' : '');
        who.replaceChildren(el('span', 'dot'),
            document.createTextNode(state.admin ? 'Logged in as Live! admin' : 'Read-only'));

        action.replaceChildren();
        var link = el('a', 'btn' + (state.admin ? ' ghost' : ''),
            state.admin ? 'Live! admin ↗' : 'Log in to control');
        link.href = BASE + '/index.php?view=live/admin';
        link.target = '_blank';
        link.rel = 'noopener';
        action.append(link);
    }

    function hexOf(input) { return input.value.replace(/^#/, '').toUpperCase(); }

    function post(body) {
        return fetch(COLORS_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(readJson);
    }

    function absorb(body) {
        state.games = body.games || {};
        state.admin = Boolean(body.admin);
        state.writable = Boolean(body.writable);
    }

    /** Both conditions matter: an admin still cannot save if conf/ is read-only. */
    function canEdit() { return state.admin && state.writable; }

    // -- per-game kit colours -------------------------------------------------
    //
    // A colour picker and a hex field per side, nothing more. The operator is
    // standing at the field looking at the jerseys minutes before the pull, so
    // reading a colour off a shirt and entering it is the whole task; prepared
    // palettes were more machinery than that job needs.
    //
    // The scoreboard uses them only once BOTH sides are set — one real kit
    // beside a placeholder reads as that team's colour, which is worse than
    // showing neither.

    /** Enough of a team name to identify it in a narrow cell. */
    function shortName(name) {
        return name.length > 18 ? name.slice(0, 17) + '…' : name;
    }

    function saveKit(gameId, side, value) {
        var body = { game: gameId };
        body[side] = value;   // null clears
        post(body).then(function (b) { absorb(b); renderAll(); })
                  .catch(function (e) { alert(e.message); });
    }

    /**
     * A team's name with its kit swatch attached.
     *
     * The swatch sits against the team it belongs to rather than in a separate
     * column: an operator matching jerseys to a screen should not have to work
     * out which of two anonymous swatches is which side.
     *
     * The hex value lives inside the native colour picker, which already offers
     * hex entry — a second text field alongside was duplicating that and costing
     * width the row does not have.
     */
    function teamWithKit(game, side, teamId) {
        var pick = state.games[String(game.game_id)] || {};
        var current = pick[side] || null;
        var teamName = teamIndex[String(teamId)] || ('Team ' + teamId);

        var wrap = el('span', 'kitside');

        var swatch = document.createElement('input');
        swatch.type = 'color';
        swatch.className = 'kitswatch' + (current ? '' : ' unset');
        swatch.value = '#' + (current || '888888');
        swatch.disabled = !canEdit();
        swatch.title = current
            ? teamName + ' — wearing #' + current + ' (click to change)'
            : teamName + ' — no kit set';
        swatch.addEventListener('change', function () {
            saveKit(game.game_id, side, hexOf(swatch));
        });
        wrap.append(swatch);

        wrap.append(el('span', 'kitteam', teamName));

        // Clearing needs its own affordance: a colour input always holds a
        // value, so there is no way to express "unset" through it.
        if (current && canEdit()) {
            var clear = el('button', 'kitclear', '×');
            clear.type = 'button';
            clear.title = 'Clear ' + teamName + "'s kit";
            clear.addEventListener('click', function () {
                saveKit(game.game_id, side, null);
            });
            wrap.append(clear);
        }

        return wrap;
    }

    function renderAll() {
        renderAuth();
        renderStage();
        renderGames(gamesList);
    }

    Promise.all([
        fetch(API + '&entity=games', { credentials: 'same-origin' }).then(readJson),
        fetch(API + '&entity=teams', { credentials: 'same-origin' }).then(readJson),
        fetch(COLORS_URL, { credentials: 'same-origin' }).then(readJson),
        // Fields live here, keyed by the reservation id each game carries. One
        // fetch for the whole event beats one game-detail fetch per row.
        fetch(API + '&entity=reference', { credentials: 'same-origin' }).then(readJson),
        fetch(SHOW_URL, { credentials: 'same-origin' }).then(readJson),
        // Deferred: the game is not known until show state arrives.
        Promise.resolve(null)
    ])
        .then(function (results) {
            gamesList = results[0].games || [];
            // entity=teams returns an object keyed by team id, not an array.
            var raw = results[1].teams || results[1];
            Object.keys(raw).forEach(function (id) {
                teamIndex[String(raw[id].team_id || id)] = raw[id].name || ('Team ' + id);
            });
            absorb(results[2]);

            (results[3].reservations || []).forEach(function (r) {
                reservations[String(r.id)] = r;
            });

            var seriesNames = {};
            (results[3].series || []).forEach(function (s) {
                seriesNames[String(s.series_id)] = s.name || '';
            });
            (results[3].pools || []).forEach(function (pool) {
                seriesIndex[String(pool.pool_id)] = seriesNames[String(pool.series_id)] || '';
            });
            seasonType = String((results[3].season && results[3].season.type) || 'outdoor')
                .toLowerCase();

            // Show only the time when the whole event is on one day.
            var days = {};
            gamesList.forEach(function (g) {
                if (g.time) { days[String(g.time).slice(0, 10)] = true; }
            });
            singleDay = Object.keys(days).length <= 1;

            show = results[4];
            renderAll();
            // The possession document is per game, so it cannot be fetched
            // until show state has said which game the stage is on. The poll
            // below picks it up on its first tick.
            if (show.game) {
                trackFor(show.game).read()
                    .then(function (state) { possession = state; renderStage(); })
                    .catch(function () { /* the poll will retry */ });
            }
            startPossessionPoll();
        })
        .catch(function (error) {
            fail('The Live! API did not return games.', error.message);
            stagePanel.replaceChildren();
            var box = el('div', 'err');
            box.append(el('strong', null, 'Stage controls unavailable.'),
                       el('p', null, error.message));
            stagePanel.append(box);
        });

}());
</script>

</body>
</html>
