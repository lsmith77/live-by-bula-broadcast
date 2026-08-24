<?php
/**
 * Live! by BULA — Commentator.
 *
 *   /index.php?view=live/overlays/commentator&game=702
 *   /s/702/talk                                          (short form)
 *
 * A second-screen reference for the person TALKING over the broadcast, not a
 * graphic. Nothing here reaches a viewer, which inverts most of the constraints
 * the overlays work under: it can be dense, it can be a few seconds behind, and
 * it can show data with a caveat that a lower-third would have to refuse.
 *
 * Public and read-only. A commentator preparing for a game should not need an
 * admin password, and there is nothing here that is not already on the public
 * tournament site.
 *
 * Two modes, because the job has two shapes:
 *
 *   PREP  before and between games — rosters, per-player numbers, team form,
 *         and links into the tournament site for anything deeper.
 *   PLAY  during a point — who is on the field, in type big enough to read at a
 *         glance rather than study.
 *
 * See docs/COMMENTATOR.md for the reasoning, in particular why jersey number is the
 * primary index rather than player name.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../conf/LocalConfig.php';
require_once __DIR__ . '/shared/lines.php';

use Overlays\Lines;

$gameId = filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

/**
 * A fresh room code, generated server-side so it is properly random.
 *
 * The page offers one rather than asking for one. Left to invent a code people
 * reach for `12345` or `test`, and two commentary pairs at the same tournament
 * then land in the same room and silently edit each other's lines mid-game.
 * Random by default removes that; the field stays editable so the second person
 * can type whatever the first one reads out.
 */
$suggestedCode = (new Lines())->generate();
$mode = filter_input(INPUT_GET, 'mode') === 'play' ? 'play' : 'prep';

$base = rtrim(defined('UO_URL_PREFIX') ? UO_URL_PREFIX : '/', '/');
$json = static fn ($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Commentator</title>
<script>
// Applied before the first paint, not with the rest of the script at the end of
// the body. A reader who chose night gets a full white flash otherwise — which
// is exactly the reader least able to tolerate one, and in a dark booth it is
// worse than the mismatch it corrects.
try {
    if (localStorage.getItem('uo-commentator-theme') === 'night') {
        document.documentElement.setAttribute('data-theme', 'night');
    }
} catch (e) { /* private window, blocked storage: day is the default anyway */ }
</script>
<style>
    /* ---- palette -------------------------------------------------------
       Two themes, and DAY IS THE DEFAULT, because this page is used at the
       side of a pitch.

       A dark interface is the wrong instrument in sunlight. A screen cannot
       out-emit the sun, so under a bright sky its dark pixels stop being dark:
       the glass turns into a mirror and the reader sees their own reflection
       where the text should be. A light background puts the panel's brightest
       pixels everywhere, so reflected glare is a smaller fraction of what comes
       back, and dark ink on it survives. This is why every outdoor sign and
       e-reader is dark-on-light and not the other way round.

       So the day palette is not the night one inverted. It is built to a
       different rule: every text pair measured at 7:1 or better (WCAG AAA,
       not AA), borders solid enough to survive glare instead of the hairlines
       that read as texture indoors, and no information carried by a mid-tone.

       Night is kept for a commentary booth, an indoor hall, or an evening game,
       and the choice is remembered per device.
       -------------------------------------------------------------------- */
    :root {
        color-scheme: light;

        --bg: #ffffff;
        --panel: #ffffff;
        --panel-alt: #f1f5f9;
        --sunk: #e2e8f0;

        --ink: #0a0f1a;
        --ink-2: #1e293b;
        --ink-mute: #44546b;

        --line: #94a3b8;
        --line-soft: #cbd5e1;

        --link: #0b4f8a;
        --accent: #1e3a8a;
        --accent-ink: #ffffff;
        --accent-soft: #dbeafe;

        --ok: #166534;
        --bad: #991b1b;

        --badge-live-bg: #dcfce7;  --badge-live-ink: #14532d;
        --badge-done-bg: #e2e8f0;  --badge-done-ink: #334155;
        --badge-soon-bg: #e0f2fe;  --badge-soon-ink: #1e3a5f;
        --err-bg: #fee2e2;         --err-ink: #7f1d1d;   --err-edge: #b91c1c;

        /* Glare eats thin strokes before it eats thick ones. */
        --rule: 1px;
        --rule-strong: 2px;
    }

    :root[data-theme="night"] {
        color-scheme: dark;

        --bg: #0b1220;
        --panel: #0f1a30;
        --panel-alt: #131c33;
        --sunk: #16213a;

        --ink: #e2e8f0;
        --ink-2: #cbd5e1;
        --ink-mute: #94a3b8;

        --line: #1e293b;
        --line-soft: #16213a;

        --link: #7dd3fc;
        --accent: #1d4ed8;
        --accent-ink: #ffffff;
        --accent-soft: #1e3a5f;

        --ok: #4ade80;
        --bad: #f87171;

        --badge-live-bg: #14532d;  --badge-live-ink: #4ade80;
        --badge-done-bg: #1e293b;  --badge-done-ink: #94a3b8;
        --badge-soon-bg: #1e3a5f;  --badge-soon-ink: #7dd3fc;
        --err-bg: #3f1d1d;         --err-ink: #fecaca;   --err-edge: #ef4444;

        --rule: 1px;
        --rule-strong: 1px;
    }

    * { box-sizing: border-box; }
    body {
        margin: 0; padding: 1.25rem 1.5rem 3rem;
        font: 16px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif;
        background: var(--bg); color: var(--ink);
    }
    .muted { color: var(--ink-mute); }
    a { color: var(--link); }

    /* ---- header ----
       The header is also the column header for everything below it. The page is
       two columns of team content all the way down, so naming the teams once at
       the top — home over the left column, away over the right — labels every
       panel underneath and buys back the vertical space three repeated headings
       were costing. It sticks, so the label survives a scroll. */
    .top { position: sticky; top: 0; z-index: 20; background: var(--bg);
           display: grid; grid-template-columns: 1fr auto 1fr; align-items: center;
           gap: .5rem 1rem; padding: .15rem 0 .55rem;
           border-bottom: var(--rule-strong) solid var(--line); }
    /* Available to assistive technology, absent from the layout. Used for the
       fixture heading, which the visible header states as two names either side
       of a score rather than as a sentence. */
    .sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0;
               overflow: hidden; clip-path: inset(50%); white-space: nowrap; border: 0; }

    /* .teamhead is an <h2>: reset the UA heading box so the semantics cost
       nothing visually. */
    .teamhead { display: flex; align-items: baseline; gap: .45rem; min-width: 0;
                margin: 0; font-size: 1rem; font-weight: 400; }
    .teamhead .nm { font-size: 1.25rem; font-weight: 800; line-height: 1.15;
                    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .teamhead .seed { color: var(--ink-mute); font-size: .75rem; font-weight: 600; white-space: nowrap; }
    .teamhead a { font-size: .72rem; white-space: nowrap; }
    .teamhead.away { flex-direction: row-reverse; justify-content: flex-start; text-align: right; }
    .mid { text-align: center; }
    .scoreline { font-size: 1.6rem; font-weight: 800; font-variant-numeric: tabular-nums;
                 line-height: 1.1; }
    .ctx { font-size: .8rem; color: var(--ink-mute); }
    .toolbar { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
               padding: .55rem 0 0; }
    .toolbar .tabs { margin-left: auto; }
    @media (max-width: 820px) {
        .top { grid-template-columns: 1fr auto; }
        .teamhead.away { grid-column: 1 / -1; flex-direction: row; justify-content: flex-start; text-align: left; }
    }
    .badge { display: inline-block; padding: .1rem .5rem; border-radius: 999px;
             font-size: .75rem; font-weight: 700; }
    .badge.live { background: var(--badge-live-bg); color: var(--badge-live-ink); }
    .badge.done { background: var(--badge-done-bg); color: var(--badge-done-ink); }
    .badge.soon { background: var(--badge-soon-bg); color: var(--badge-soon-ink); }

    .tabs { display: flex; gap: .35rem; }
    .tabs button { background: var(--sunk); border: 1px solid var(--line); color: var(--ink-2);
                   padding: .45rem 1rem; border-radius: 5px; font: inherit; font-weight: 600;
                   font-size: .85rem; cursor: pointer; }
    .tabs button.on { background: var(--accent); border-color: var(--accent); color: #fff; }

    /* ---- two-column layouts ---- */
    .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-top: 1rem; }
    @media (max-width: 820px) { .cols { grid-template-columns: 1fr; } }
    .panel { background: var(--panel); border: var(--rule-strong) solid var(--line); border-radius: 6px; padding: .85rem 1rem; }
    .panel h2 { margin: 0 0 .6rem; font-size: .95rem; display: flex; align-items: baseline; gap: .5rem; }
    .panel h2 .seed { color: var(--ink-mute); font-size: .8rem; font-weight: 600; }
    .panel h2 a { margin-left: auto; font-size: .75rem; }

    /* ---- roster ---- */
    table.roster { width: 100%; border-collapse: collapse; }
    .roster th { text-align: right; font-size: .78rem; text-transform: uppercase;
                 letter-spacing: .06em; color: var(--ink-mute); font-weight: 700; padding: 0 .3rem .35rem;
                 border-bottom: var(--rule) solid var(--line); }
    .roster th.n, .roster th.who { text-align: left; }
    .roster td { padding: .28rem .3rem; border-top: var(--rule) solid var(--line-soft);
                 font-variant-numeric: tabular-nums; text-align: right; }
    .roster td.n { width: 2.6rem; text-align: left; color: var(--ink-mute); font-weight: 700; }
    .roster td.who { text-align: left; }
    .roster td.who button { background: none; border: 0; color: var(--ink); font: inherit;
                            font-weight: 600; cursor: pointer; padding: 0; text-align: left; }
    .roster td.who button:hover { color: var(--link); text-decoration: underline; }
    /* A player with no points yet is the DEFAULT state, not an exception — on a
       fresh game it is most of the roster. So the row dims only its statistics,
       never the jersey number or the name, which are the two things a
       commentator is looking the row up BY. */
    .roster tr.quiet td:not(.n):not(.who) { color: var(--ink-mute); }

    /* ---- team stats ---- */
    .stat { display: flex; justify-content: space-between; gap: 1rem; padding: .25rem 0;
            border-top: 1px solid var(--sunk); font-variant-numeric: tabular-nums; }
    .stat:first-of-type { border-top: 0; }
    .stat b { font-weight: 700; }

    /* ---- gender ratio ---- */
    .abba { margin-top: 1rem; padding: .75rem 1rem; border-radius: 6px;
            background: var(--panel); border: var(--rule-strong) solid var(--line); }
    .abbahead { font-size: .68rem; text-transform: uppercase; letter-spacing: .08em;
                font-weight: 700; margin-bottom: .5rem; }
    .abbaask { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .abbarun { display: flex; gap: .6rem; margin-bottom: .6rem; }
    .abbapt { flex: 1; padding: .5rem .6rem; border-radius: 5px; text-align: center;
              background: var(--panel-alt); border: 1px solid var(--line); }
    .abbapt b { display: block; font-size: 1.15rem; font-weight: 800;
                font-variant-numeric: tabular-nums; }
    .abbapt span { font-size: .72rem; color: var(--ink-mute); }
    /* The point being played is the one the commentator is talking over. */
    .abbapt.now { background: var(--accent); border-color: var(--accent); }
    .abbapt.now b, .abbapt.now span { color: var(--accent-ink); }

    .abbasplit { margin: .6rem 0; display: flex; flex-direction: column; gap: .3rem; }
    .abbasplitrow { display: flex; align-items: baseline; gap: .6rem; padding: .3rem .5rem;
                    border-radius: 4px; background: var(--panel-alt); }
    .abbasplitrow b { min-width: 4.2rem; font-weight: 700; }
    .abbasplitrow .sc { font-size: 1.05rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .abbasplitrow .who { font-size: .75rem; color: var(--ink-mute); margin-left: auto;
                         overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* ---- possession ---- */
    .possbox { margin-top: 1rem; padding: .85rem 1rem; border-radius: 6px;
               background: var(--panel); border: var(--rule-strong) solid var(--line); }
    .possbox.quiet { background: var(--panel-alt); }
    .possbox .code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                     letter-spacing: .12em; color: var(--link); }
    .possbtns { display: flex; gap: .75rem; }
    /* Big, because it is pressed repeatedly by someone looking at the field. */
    .possbig { flex: 1; display: flex; align-items: baseline; justify-content: center;
               gap: .6rem; padding: .85rem 1rem; border-radius: 6px; cursor: pointer;
               background: var(--panel-alt); border: var(--rule-strong) solid var(--line);
               color: var(--ink); font: inherit; }
    .possbig .k { font-size: 1.5rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .possbig .w { font-size: .9rem; font-weight: 600; color: var(--ink-mute); }
    .possbig.on { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
    .possbig.on .w { color: var(--accent-ink); }
    /* Defence-in-possession is what puts a red tab on air, so it must never be
       mistaken for the other state at a glance. */
    .possbig.d.on { background: var(--bad); border-color: var(--bad); color: #fff; }
    .possbig.d.on .w { color: #fff; }
    .possbig:disabled { opacity: .45; cursor: not-allowed; }
    .namefield { display: flex; align-items: center; gap: .5rem; margin-bottom: .6rem; }
    .namefield label { font-size: .8rem; color: var(--ink-mute); }
    .namefield input { flex: 1; max-width: 16rem; background: var(--panel-alt);
                       border: 1px solid var(--line); color: var(--ink); font: inherit;
                       font-size: .85rem; border-radius: 4px; padding: .3rem .5rem; }
    .possfix { display: flex; gap: .5rem; margin-top: .6rem; }

    /* ---- possession log ----
       Rows are spaced in proportion to the time between them, so the shape of a
       point is visible before any number is read: a cluster is a scramble, a
       long gap is a settled possession. */
    .plog { margin: .8rem 0; }
    .plogrow { display: flex; align-items: baseline; gap: .6rem; padding: .4rem .6rem;
               border-radius: 5px; background: var(--panel-alt);
               border-left: 4px solid var(--accent); }
    /* Defence in possession is the state that puts a tab on air. */
    .plogrow.d { border-left-color: var(--bad); }
    .plogrow b { min-width: 4.6rem; font-weight: 700; }
    .plogrow .gap { font-variant-numeric: tabular-nums; font-weight: 700; }
    .plogrow .abs { font-size: .78rem; color: var(--ink-mute); margin-left: auto; }
    .plogrow .chip.danger { border-color: var(--bad); color: var(--bad); }
    .plogrow .chip.danger:hover { background: var(--bad); color: #fff; }
    .posscounts { display: flex; gap: 1.5rem; margin-top: .7rem; }
    .posscount b { font-size: 1.3rem; font-weight: 800; font-variant-numeric: tabular-nums;
                   margin-right: .35rem; }
    .posscount span { font-size: .85rem; color: var(--ink-mute); }
    .posscount.hot b { color: var(--bad); }

    /* ---- play mode ---- */
    .step { margin-top: 1rem; }
    .pickhead { display: flex; align-items: baseline; gap: .75rem; flex-wrap: wrap;
                margin-bottom: .4rem; }
    .pickhead .count { font-weight: 700; font-variant-numeric: tabular-nums; }
    .pickhead .count.ok { color: var(--ok); }
    .pickhead .count.over { color: var(--bad); }
    .nums { display: flex; flex-wrap: wrap; gap: .35rem; }
    .nums button { min-width: 3.1rem; padding: .5rem .4rem; border-radius: 5px;
                   border: 1px solid var(--line); background: var(--bg); color: var(--ink-2);
                   font: inherit; font-weight: 700; font-variant-numeric: tabular-nums;
                   cursor: pointer; line-height: 1.1; }
    .nums button small { display: block; font-size: .62rem; font-weight: 500;
                         color: var(--ink-mute); letter-spacing: .02em; }
    .nums button.on { background: var(--accent); border-color: var(--accent); color: #fff; }
    .nums button.on small { color: var(--accent-soft); }

    .onfield { margin-top: .75rem; }
    .onfield .side { padding: .6rem .9rem; border-radius: 6px; background: var(--panel);
                     border: 1px solid var(--line); margin-bottom: .6rem; }
    .onfield .side.away { background: var(--panel-alt); }
    .onfield .side h3 { margin: 0 0 .35rem; font-size: .8rem; text-transform: uppercase;
                        letter-spacing: .1em; color: var(--ink-mute); font-weight: 700; }
    .onfield .line { display: flex; flex-wrap: wrap; gap: .35rem 1.4rem; }
    .onfield .p { font-size: 1.5rem; font-weight: 700; line-height: 1.25; }
    .onfield .p span { color: var(--ink-mute); font-size: 1.05rem; font-weight: 600; margin-right: .3rem;
                       font-variant-numeric: tabular-nums; }
    .onfield .empty { color: var(--ink-mute); font-size: .95rem; }
    .barbtn { background: var(--sunk); border: 1px solid var(--line); color: var(--ink-2); font: inherit;
              font-size: .82rem; padding: .4rem .8rem; border-radius: 5px; cursor: pointer; }
    .barbtn.primary { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }

    /* ---- player detail overlay ---- */
    .sheet { position: fixed; inset: 0; background: rgb(2 6 16 / 72%); display: none;
             align-items: center; justify-content: center; padding: 1rem; z-index: 50; }
    .sheet.open { display: flex; }
    .sheet .card { background: var(--panel); border: 1px solid var(--line); border-radius: 8px;
                   padding: 1.1rem 1.3rem; max-width: 540px; width: 100%;
                   max-height: 90vh; overflow-y: auto; }
    .sheet h3 { margin: 0 0 .1rem; font-size: 1.35rem; }
    .sheet .sub { color: var(--ink-mute); font-size: .85rem; margin-bottom: .8rem; }
    .sheet .grid { display: grid; gap: .3rem .8rem; font-variant-numeric: tabular-nums; }
    .sheet .grid .h { font-size: .68rem; text-transform: uppercase; letter-spacing: .06em;
                      color: var(--ink-mute); font-weight: 600; }
    .sheet .grid .v { text-align: right; font-weight: 700; }
    .sheet h4 { margin: 1rem 0 .4rem; font-size: .7rem; text-transform: uppercase;
                letter-spacing: .08em; color: var(--ink-mute); font-weight: 700; }
    .sheet .facts { display: grid; grid-template-columns: 1fr auto; gap: .2rem .8rem;
                    font-size: .87rem; font-variant-numeric: tabular-nums; }
    .sheet .facts .k { color: var(--ink-mute); }
    .sheet .facts .v { font-weight: 700; text-align: right; }
    /* Game-by-game: the line a commentator reaches for when a name comes up. */
    .hist { border-top: 1px solid var(--sunk); padding: .35rem 0; display: flex;
            gap: .6rem; align-items: baseline; font-size: .87rem; }
    .hist .opp { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis;
                 white-space: nowrap; }
    .hist .res { color: var(--ink-mute); font-variant-numeric: tabular-nums; }
    .hist .res.w { color: var(--ok); font-weight: 700; }
    .hist .res.l { color: var(--bad); }
    .hist .ga { font-weight: 700; font-variant-numeric: tabular-nums; min-width: 4.2rem;
                text-align: right; }
    .sheet footer { display: flex; gap: .6rem; align-items: center; margin-top: 1rem; }
    .sheet footer .barbtn { margin-left: auto; }

    /* Room code: shown, not asked for. A random one is generated on first load
       so two commentary pairs at one tournament cannot both pick "12345". */
    .sync { display: flex; align-items: center; gap: .4rem; font-size: .8rem; }
    .sync .code { background: var(--bg); border: 1px solid var(--line); color: var(--link);
                  border-radius: 4px; padding: .3rem .45rem; font-weight: 700;
                  letter-spacing: .12em; text-transform: uppercase;
                  font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    /* A 28-player squad needs its own scroll; the panel header stays visible. */
    .scroll { max-height: 46vh; overflow-y: auto; overscroll-behavior: contain; }
    .scroll table { width: 100%; }
    .roster thead th { position: sticky; top: 0; background: var(--panel); z-index: 1; }

    .chip { background: var(--sunk); border: 1px solid var(--line); color: var(--ink-2); font: inherit;
            font-size: .78rem; font-weight: 600; padding: .25rem .6rem; border-radius: 999px;
            cursor: pointer; }
    .chip.on { background: var(--accent); border-color: var(--accent); color: #fff; }

    .err { padding: 1rem 1.25rem; background: var(--err-bg); color: var(--err-ink); border-left: 3px solid var(--err-edge);
           border-radius: 4px; margin-top: 1rem; }
</style>
</head>
<body>

<!--
  The visible header names each team once, positioned over that team's column.
  That works by proximity, which a screen reader does not have, so the same
  naming is carried structurally: an <h1> stating the fixture, an <h2> per team
  that is visually the header text itself, and aria-labelledby on every panel
  below pointing back at it. One naming, two ways of reaching it.
-->
<header class="top">
    <h1 class="sr-only" id="fixture">Commentator</h1>
    <h2 class="teamhead home" id="headHome"></h2>
    <div class="mid">
        <div class="scoreline" id="score" aria-labelledby="fixture">–</div>
        <div class="ctx" id="ctx"></div>
    </div>
    <h2 class="teamhead away" id="headAway"></h2>
</header>
<div class="toolbar">
    <button id="themeBtn" class="chip" type="button" aria-pressed="false"></button>
    <div class="sync" id="sync"></div>
    <div class="tabs" role="group" aria-label="View">
        <button id="tabPrep" type="button" aria-pressed="true">Prep</button>
        <button id="tabPlay" type="button" aria-pressed="false">Play-by-play</button>
    </div>
</div>

<main id="body"><p class="muted">Loading…</p></main>

<div class="sheet" id="sheet" role="dialog" aria-modal="true" aria-labelledby="sheetName">
    <div class="card" id="sheetCard"></div>
</div>

<script src="<?= $base ?>/live/overlays/shared/possession.js"></script>
<script>
(function () {
    'use strict';

    var CONFIG = {
        base: <?= $json($base) ?>,
        api: <?= $json($base . '/index.php?view=live/api') ?>,
        gameId: <?= $json($gameId ?: null) ?>,
        mode: <?= $json($mode) ?>,
        linesUrl: <?= $json($base . '/index.php?view=live/overlays/lines') ?>,
        possessionUrl: <?= $json($base . '/index.php?view=live/overlays/possession') ?>,
        suggestedCode: <?= $json($suggestedCode) ?>,
        codeLength: <?= (int) Lines::CODE_LENGTH ?>
    };

    var el = function (tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    };
    var link = function (href, text, cls) {
        var a = el('a', cls, text);
        a.href = href;
        a.target = '_blank';
        a.rel = 'noopener';
        return a;
    };

    /* ---------------------------------------------------------------
       Daylight and night
       --------------------------------------------------------------- */

    /**
     * Day is the default because the pitch is the default.
     *
     * Deliberately NOT `prefers-color-scheme`: that reports what the reader
     * chose for their operating system months ago, indoors. It says nothing
     * about whether the sun is currently on the screen, which is the only thing
     * that matters here. So it is a decision the reader makes on the spot, and
     * the page remembers it per device.
     *
     * localStorage can throw outright in a private window or with site data
     * blocked, so every access is guarded and the page renders correctly with no
     * stored value.
     */
    var THEME_KEY = 'uo-commentator-theme';

    function storedTheme() {
        try { return localStorage.getItem(THEME_KEY); } catch (e) { return null; }
    }

    function setTheme(mode) {
        var night = mode === 'night';
        document.documentElement.setAttribute('data-theme', night ? 'night' : 'day');
        var b = document.getElementById('themeBtn');
        // The label names what a click DOES, not what is currently on — a
        // control read at a glance in bad light should not need interpreting.
        b.textContent = night ? '\u2600 Daylight' : '\u263E Night';
        b.title = night
            ? 'Switch to the high-contrast daylight theme for use in sunlight'
            : 'Switch to the dark theme for a booth or an evening game';
        b.setAttribute('aria-pressed', night ? 'true' : 'false');
        try { localStorage.setItem(THEME_KEY, night ? 'night' : 'day'); } catch (e) { /* fine */ }
    }

    setTheme(document.documentElement.getAttribute('data-theme') === 'night'
        || storedTheme() === 'night' ? 'night' : 'day');
    document.getElementById('themeBtn').addEventListener('click', function () {
        setTheme(document.documentElement.getAttribute('data-theme') === 'night' ? 'day' : 'night');
    });

    var body = document.getElementById('body');
    var state = {
        payload: null,
        teams: {},          // team_id -> full team payload (roster, stats)
        mode: CONFIG.mode,
        // Which players are on the field, per team. Local to this browser for
        // now; docs/COMMENTATOR.md 6 describes sharing it between two commentators,
        // which needs a store and is deliberately not in this first version.
        line: {},
        step: 1,
        lastScore: null
    };

    /* ---------------------------------------------------------------
       Data
       --------------------------------------------------------------- */

    function readJson(r) {
        if (r.status === 503) { throw new Error('Live! is in maintenance mode.'); }
        return r.json().then(function (b) {
            if (!r.ok || typeof b.error === 'string') {
                throw new Error(b.error || ('HTTP ' + r.status));
            }
            return b;
        });
    }

    function load() {
        return fetch(CONFIG.api + '&entity=games&id=' + encodeURIComponent(CONFIG.gameId),
            { credentials: 'same-origin' })
            .then(readJson)
            .then(function (p) {
                state.payload = p;
                var t = p.teams || {};
                var ids = [t.hometeam, t.visitorteam].filter(Boolean)
                    .map(function (x) { return x.team_id; }).filter(Boolean);
                return Promise.all(ids.map(function (id) {
                    return fetch(CONFIG.api + '&entity=teams&id=' + encodeURIComponent(id),
                        { credentials: 'same-origin' })
                        .then(readJson)
                        .then(function (team) { state.teams[id] = team; })
                        .catch(function () { /* roster is optional */ });
                }));
            });
    }

    /** Per-game goals and assists, straight from the goal list. */
    function gameStats(teamIsHome) {
        var out = {};
        (state.payload.goals || []).forEach(function (g) {
            if ((Number(g.ishomegoal) === 1) !== teamIsHome) { return; }
            var add = function (id, key) {
                if (!id) { return; }
                out[id] = out[id] || { goals: 0, assists: 0 };
                out[id][key] += 1;
            };
            add(g.scorer, 'goals');
            add(g.assist, 'assists');
        });
        return out;
    }

    function sides() {
        var t = state.payload.teams || {};
        return [
            { key: 'home', team: t.hometeam || {}, isHome: true },
            { key: 'away', team: t.visitorteam || {}, isHome: false }
        ];
    }

    /**
     * How the roster is ordered.
     *
     * Number is the default and stays the default: a commentator sees a shirt
     * and needs the name, which is a number lookup. The others answer a
     * different question — "who is doing the damage" — and are for prep and for
     * between points, not for identifying somebody mid-play.
     *
     * "Blocks" is conditional rather than fixed — see blocksTracked(). It ranks by
     * the tournament total because there is no per-game block list to derive a
     * this-game figure from, unlike goals and assists.
     */
    function sortOptions() {
        var opts = [
            { key: 'num', label: '#' },
            { key: 'goals', label: 'Goals' },
            { key: 'assists', label: 'Assists' },
            { key: 'points', label: 'Points' },
            { key: 'tour', label: 'Tournament' }
        ];
        if (blocksTracked()) { opts.push({ key: 'blocks', label: 'Blocks' }); }
        return opts;
    }
    var sortKey = 'num';

    function sortRoster(list) {
        var byName = function (a, b) { return a.name.localeCompare(b.name); };
        var desc = function (get) {
            return function (a, b) { return get(b) - get(a) || byName(a, b); };
        };
        if (sortKey === 'goals') { return list.sort(desc(function (p) { return p.gGoals; })); }
        if (sortKey === 'assists') { return list.sort(desc(function (p) { return p.gAssists; })); }
        if (sortKey === 'points') { return list.sort(desc(function (p) { return p.gTotal; })); }
        if (sortKey === 'tour') { return list.sort(desc(function (p) { return p.tTotal; })); }
        if (sortKey === 'blocks') { return list.sort(desc(function (p) { return p.tBlocks; })); }
        return list.sort(function (a, b) {
            var an = a.num === null || a.num === undefined ? 9999 : Number(a.num);
            var bn = b.num === null || b.num === undefined ? 9999 : Number(b.num);
            return an - bn || byName(a, b);
        });
    }

    /** Roster sorted by jersey number — the lookup a commentator performs most. */
    function roster(side) {
        var team = state.teams[side.team.team_id];
        var players = (team && team.players) || [];
        var per = gameStats(side.isHome);
        return players.map(function (p) {
            var g = per[p.player_id] || { goals: 0, assists: 0 };
            return {
                id: p.player_id,
                num: p.num,
                name: ((p.firstname || '') + ' ' + (p.lastname || '')).trim() || '—',
                gGoals: g.goals, gAssists: g.assists, gTotal: g.goals + g.assists,
                tGoals: Number(p.done) || 0,
                tAssists: Number(p.fedin) || 0,
                tTotal: Number(p.total) || 0,
                // null means "this installation does not track blocks", 0 means
                // "tracked, none yet" — see blocksTracked().
                tBlocks: (p.deftotal === undefined || p.deftotal === null)
                    ? null : (Number(p.deftotal) || 0),
                tCallahan: Number(p.callahan) || 0,
                games: Number(p.games) || 0,
                // Derived rather than read: the roster row carries totalavg only
                // in the variant Live! serves with defence stats switched off.
                avg: Number(p.games) > 0 ? (Number(p.total) || 0) / Number(p.games) : 0,
                team: side.team,
                division: p.seriesname || ''
            };
        });
    }

    /**
     * Whether this installation records blocks at all.
     *
     * Blocks reach us as `deftotal` on the roster row, and only when Live! has
     * `ShowDefenseStats` on — otherwise TeamManager serves a roster variant
     * without the field. So the test is for the field being THERE, not for it
     * being non-zero: absent means "this installation does not track blocks" and
     * the column should not exist, while a present 0 means "tracked, nobody has
     * one yet" and is worth saying out loud. docs/STUDIO.md 3.3.
     *
     * The count shares the tournament columns' rule: completed games only, so a
     * block made in the game currently on the clock is not in it yet.
     */
    function blocksTracked() {
        return sides().some(function (s) {
            var team = state.teams[s.team.team_id];
            return ((team && team.players) || []).some(function (p) {
                return p.deftotal !== undefined && p.deftotal !== null;
            });
        });
    }

    /** Number order regardless of the display sort — the picker is a lookup. */
    function rosterByNumber(side) {
        var list = roster(side);
        return list.slice().sort(function (a, b) {
            var an = a.num === null || a.num === undefined ? 9999 : Number(a.num);
            var bn = b.num === null || b.num === undefined ? 9999 : Number(b.num);
            return an - bn || a.name.localeCompare(b.name);
        });
    }

    /* ---------------------------------------------------------------
       Header
       --------------------------------------------------------------- */

    function renderTop() {
        var p = state.payload, r = p.game_result || {}, i = p.game_info || {};
        var s = sides();

        // Each name sits over the column its panels occupy, so nothing below
        // needs to name the team again.
        [['headHome', s[0]], ['headAway', s[1]]].forEach(function (pair) {
            var host = document.getElementById(pair[0]);
            var side = pair[1];
            var parts = [el('span', 'nm', side.team.name || '—')];
            if (side.team.seed) { parts.push(el('span', 'seed', 'seed ' + side.team.seed)); }
            if (side.team.team_id) {
                parts.push(link(CONFIG.base + '/index.php?view=teamcard&team=' + side.team.team_id,
                    'team ↗'));
            }
            // Same DOM order both sides — name, seed, link — so it is read in that
            // order. The away side is flipped visually with row-reverse, not by
            // reversing the nodes, which would make a screen reader announce the
            // heading backwards.
            host.replaceChildren.apply(host, parts);
        });

        document.getElementById('fixture').textContent =
            (s[0].team.name || '?') + ' versus ' + (s[1].team.name || '?');
        document.getElementById('score').textContent =
            (Number(r.homescore) || 0) + ' – ' + (Number(r.visitorscore) || 0);

        var ctx = document.getElementById('ctx');
        ctx.replaceChildren();
        var live = Number(r.isongoing) === 1;
        var cls = live ? 'live' : (r.status === 'completed' ? 'done' : 'soon');
        ctx.append(el('span', 'badge ' + cls, live ? 'Live' : (r.status === 'completed' ? 'Final' : 'Scheduled')));
        var bits = [i.poolname, i.gamename,
            i.fieldname ? 'Field ' + i.fieldname : null, i.placename].filter(Boolean);
        ctx.append(document.createTextNode(' ' + bits.join(' · ')));
    }

    /* ---------------------------------------------------------------
       Team stats — the same block under both modes
       --------------------------------------------------------------- */

    function teamStatsPanel(side) {
        var t = side.team;
        var full = state.teams[t.team_id] || {};
        var i = state.payload.game_info || {};
        // No visible heading: the sticky header above names this column's team,
        // and the team page link lives up there with the name. The association is
        // carried for assistive technology by pointing at that heading.
        var panel = el('div', 'panel');
        panel.setAttribute('role', 'region');
        panel.setAttribute('aria-labelledby', side.isHome ? 'headHome' : 'headAway');

        var row = function (label, value) {
            var d = el('div', 'stat');
            d.append(el('span', 'muted', label), el('b', null, value));
            panel.append(d);
        };
        var st = full.stats || {};
        var pts = full.points || {};
        row('Record (W–L)', (t.wins !== undefined ? t.wins : (st.wins || 0))
            + '–' + (t.losses !== undefined ? t.losses : (st.losses || 0)));
        row('Games played', st.games !== undefined ? st.games : (t.games || 0));
        row('Points for / against',
            (pts.scores !== undefined ? pts.scores : (t['for'] || 0))
            + ' / ' + (pts.against !== undefined ? pts.against : (t.against || 0)));
        if (t.diff !== undefined) { row('Difference', (t.diff > 0 ? '+' : '') + t.diff); }
        if (full.poolname) { row('Pool', full.poolname); }
        if (t.final_standing) { row('Standing', t.final_standing); }

        var links = el('div', 'stat');
        links.append(el('span', 'muted', 'Tournament'));
        var wrap = el('span');
        if (i.pool) {
            wrap.append(link(CONFIG.base + '/index.php?view=poolstatus&pool=' + i.pool, 'pool ↗'));
            wrap.append(document.createTextNode('  '));
        }
        if (i.series) {
            wrap.append(link(CONFIG.base + '/index.php?view=seriesstatus&series=' + i.series, 'division ↗'));
        }
        links.append(wrap);
        panel.append(links);

        return panel;
    }

    function teamStatsRow() {
        var cols = el('div', 'cols');
        sides().forEach(function (s) { cols.append(teamStatsPanel(s)); });
        return cols;
    }

    /* ---------------------------------------------------------------
       Prep mode
       --------------------------------------------------------------- */

    function rosterPanel(side) {
        var panel = el('div', 'panel');
        panel.setAttribute('role', 'region');
        panel.setAttribute('aria-labelledby', side.isHome ? 'headHome' : 'headAway');
        var list = sortRoster(roster(side));
        if (!list.length) {
            panel.append(el('p', 'muted', 'No roster available for this team.'));
            return panel;
        }

        var blocks = blocksTracked();
        var table = el('table', 'roster');
        table.setAttribute('aria-label', (side.team.name || 'Team') + ' roster');
        var thead = el('thead'), hr = el('tr');
        var cols = [['n', '#'], ['who', 'Player'], ['', 'G'], ['', 'A'], ['', 'Pts'], ['', 'Tot']];
        if (blocks) { cols.push(['', 'Blk']); }
        cols.forEach(function (c) { hr.append(el('th', c[0], c[1])); });
        thead.append(hr);
        table.append(thead);

        var tb = el('tbody');
        list.forEach(function (p) {
            var tr = el('tr', p.gTotal ? '' : 'quiet');
            tr.append(el('td', 'n', p.num === null || p.num === undefined ? '' : p.num));

            var who = el('td', 'who');
            var btn = el('button', null, p.name);
            btn.type = 'button';
            btn.title = 'Details';
            btn.addEventListener('click', function () { openSheet(p, side); });
            who.append(btn);
            tr.append(who);

            tr.append(el('td', null, p.gGoals || '·'));
            tr.append(el('td', null, p.gAssists || '·'));
            tr.append(el('td', null, p.gTotal || '·'));
            tr.append(el('td', null, p.tTotal || '·'));
            if (blocks) { tr.append(el('td', null, p.tBlocks || '·')); }
            tb.append(tr);
        });
        table.append(tb);
        // A 28-player squad does not fit a screen beside a second one, so the
        // list scrolls inside its panel and the header stays put.
        var scroll = el('div', 'scroll');
        scroll.append(table);
        panel.append(scroll);
        panel.append(el('p', 'muted',
            list.length + ' players · G / A / Pts are this game, Tot is the tournament'
            + (blocks ? ', Blk is tournament blocks from completed games' : '')));
        return panel;
    }

    function renderPrep() {
        body.replaceChildren();

        var bar = el('div', 'pickhead');
        bar.append(el('span', 'muted', 'Sort by'));
        sortOptions().forEach(function (s) {
            var b = el('button', 'chip' + (sortKey === s.key ? ' on' : ''), s.label);
            b.type = 'button';
            b.setAttribute('aria-pressed', sortKey === s.key ? 'true' : 'false');
            b.addEventListener('click', function () { sortKey = s.key; renderPrep(); });
            bar.append(b);
        });
        // Team stats first, player lists below.
        //
        // The squads are 28 a side and scroll; the team block is short and
        // fixed. Putting the short thing on top keeps it permanently in view and
        // means the only content that ever leaves the screen is the tail of a
        // roster — which is the part a commentator is least likely to want in a
        // hurry, since the players who matter are near the top of any sort.
        body.append(teamStatsRow());
        body.append(bar);

        var cols = el('div', 'cols');
        sides().forEach(function (s) { cols.append(rosterPanel(s)); });
        body.append(cols);
    }

    /* ---------------------------------------------------------------
       Player detail
       --------------------------------------------------------------- */

    var sheet = document.getElementById('sheet');

    /**
     * Per-player scoring history, cached for the life of the page.
     *
     * `entity=playerevents` is the same public feed behind Live!'s own player
     * page, so nothing here is a data point a spectator could not already look
     * up. It is fetched when a sheet opens rather than with the rosters: 56
     * players' histories are a lot of requests to make for the handful anyone
     * clicks.
     */
    var eventsCache = {};

    function loadEvents(playerId) {
        if (eventsCache[playerId]) { return Promise.resolve(eventsCache[playerId]); }
        return fetch(CONFIG.api + '&entity=playerevents&id=' + encodeURIComponent(playerId),
            { credentials: 'same-origin' })
            .then(readJson)
            .then(function (b) {
                eventsCache[playerId] = b.playerevents || {};
                return eventsCache[playerId];
            })
            .catch(function () { return null; });
    }

    /** What this player did in one game, plus who they did it with. */
    function summariseGame(g, playerId) {
        var goals = 0, assists = 0, callahans = 0, partners = {};
        (g.events || []).forEach(function (e) {
            var scored = Number(e.scorer_id) === Number(playerId);
            var fed = Number(e.assist_id) === Number(playerId);
            if (scored) {
                goals += 1;
                if (Number(e.iscallahan) === 1) { callahans += 1; }
                if (e.assist_name) { partners[e.assist_name] = (partners[e.assist_name] || 0) + 1; }
            }
            if (fed) {
                assists += 1;
                if (e.scorer_name) { partners[e.scorer_name] = (partners[e.scorer_name] || 0) + 1; }
            }
        });
        return { goals: goals, assists: assists, callahans: callahans, partners: partners };
    }

    function openSheet(p, side) {
        var card = document.getElementById('sheetCard');
        card.replaceChildren();
        var name = el('h3', null, (p.num !== null && p.num !== undefined ? '#' + p.num + '  ' : '') + p.name);
        name.id = 'sheetName';
        card.append(name);
        card.append(el('div', 'sub',
            [side.team.name, p.division].filter(Boolean).join(' · ')));

        // Blocks earn a column here on the same condition as the roster, so the
        // sheet and the list never disagree about whether the number exists.
        var blocks = blocksTracked();
        var head = ['', 'G', 'A', 'Pts'];
        var rows = [
            ['This game', p.gGoals, p.gAssists, p.gTotal],
            ['Tournament', p.tGoals, p.tAssists, p.tTotal]
        ];
        if (blocks) {
            head.push('Blk');
            rows[0].push('—');            // no per-game block list exists to split it
            rows[1].push(p.tBlocks);
        }
        var grid = el('div', 'grid');
        grid.style.gridTemplateColumns = '1fr' + ' auto'.repeat(head.length - 1);
        [head].concat(rows).forEach(function (row, ri) {
            row.forEach(function (cell, ci) {
                grid.append(el('div', ri === 0 || ci === 0 ? 'h' : 'v',
                    ri === 0 && ci === 0 ? '' : cell));
            });
        });
        card.append(grid);

        var facts = el('div', 'facts');
        var fact = function (k, v) {
            facts.append(el('div', 'k', k), el('div', 'v', v));
        };
        fact('Games played', p.games || 0);
        if (p.games) { fact('Points per game', p.avg.toFixed(1)); }
        if (p.tCallahan) { fact('Callahans', p.tCallahan); }
        if (blocks) { fact('Blocks', p.tBlocks); }
        if (p.num !== null && p.num !== undefined) { fact('Jersey', '#' + p.num); }
        card.append(facts);
        card.append(el('p', 'muted',
            'Tournament figures cover completed games, so this game is not in them yet.'));

        var histHead = el('h4', null, 'Game by game');
        var histBox = el('div');
        histBox.append(el('p', 'muted', 'Loading…'));
        card.append(histHead, histBox);

        var foot = el('footer');
        foot.append(link(CONFIG.base + '/index.php?view=playercard&player=' + p.id,
            'Full player page ↗'));
        var close = el('button', 'barbtn', 'Close');
        close.type = 'button';
        close.addEventListener('click', closeSheet);
        foot.append(close);
        card.append(foot);

        sheet.classList.add('open');
        // Move focus into the dialog and remember where it came from, so keyboard
        // and screen-reader users are not left behind it with no way back.
        lastFocus = document.activeElement;
        close.focus();

        var openedFor = p.id;
        loadEvents(p.id).then(function (data) {
            // The reader may have closed this sheet and opened another one while
            // the request was in flight.
            if (!sheet.classList.contains('open') || openedFor !== p.id) { return; }
            histBox.replaceChildren();

            var games = (data && data.games) || [];
            if (!games.length) {
                histBox.append(el('p', 'muted', 'No scoring recorded yet this tournament.'));
                return;
            }

            var partners = {};
            games.slice().reverse().forEach(function (g) {
                var s = summariseGame(g, p.id);
                Object.keys(s.partners).forEach(function (name) {
                    partners[name] = (partners[name] || 0) + s.partners[name];
                });

                var isHome = Number(g.hometeam) === Number(side.team.team_id);
                var own = Number(isHome ? g.homescore : g.visitorscore) || 0;
                var opp = Number(isHome ? g.visitorscore : g.homescore) || 0;
                var oppName = (isHome ? g.visitorteamname : g.hometeamname) || 'Opponent';

                var row = el('div', 'hist');
                row.append(el('span', 'opp', 'v ' + oppName));
                row.append(el('span', 'res ' + (own > opp ? 'w' : (own < opp ? 'l' : '')),
                    own + '–' + opp));
                var line = s.goals + 'G ' + s.assists + 'A'
                    + (s.callahans ? ' ·' + s.callahans + 'C' : '');
                row.append(el('span', 'ga', line));
                histBox.append(row);
            });

            // The connection worth mentioning on air: who this player most often
            // scores with, in either direction.
            var top = Object.keys(partners).sort(function (a, b) {
                return partners[b] - partners[a];
            })[0];
            if (top && partners[top] > 1) {
                histBox.append(el('p', 'muted',
                    'Most frequent connection: ' + top + ' (' + partners[top] + ')'));
            }
        });
    }

    var lastFocus = null;

    function closeSheet() {
        if (!sheet.classList.contains('open')) { return; }
        sheet.classList.remove('open');
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        lastFocus = null;
    }

    // Keep Tab inside the dialog while it is open. Without this, tabbing walks
    // out into the roster behind it, which is still visible but not reachable.
    sheet.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') { return; }
        var focusable = sheet.querySelectorAll('a[href], button:not([disabled])');
        if (!focusable.length) { return; }
        var first = focusable[0], last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    sheet.addEventListener('click', function (e) { if (e.target === sheet) { closeSheet(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeSheet(); }
    });

    /* ---------------------------------------------------------------
       Play-by-play mode
       --------------------------------------------------------------- */

    function lineFor(side) {
        var id = String(side.team.team_id);
        state.line[id] = state.line[id] || [];
        return state.line[id];
    }

    function toggleNum(side, playerId) {
        var line = lineFor(side);
        var at = line.indexOf(playerId);
        if (at === -1) { line.push(playerId); } else { line.splice(at, 1); }
        pushLine(side.team.team_id, line);
        renderPlay();
    }

    /* ---------------------------------------------------------------
       Sharing the line between two commentators

       The room is a GAME plus a CODE. The code is a namespace, not a
       credential — see docs/COMMENTATOR.md section 6 for why that is the right gate
       here and would be the wrong one for anything that reaches air.
       --------------------------------------------------------------- */

    /* ---------------------------------------------------------------
       Gender ratio (WFDF prescribed ratio, "ABBA")
       --------------------------------------------------------------- */

    /**
     * Which points repeat the first point's ratio.
     *
     * WFDF's prescribed ratio runs A B B A · A B B A over successive points, so
     * points 1, 4, 5, 8, 9, 12 … carry whatever ratio was set for point 1 and
     * the rest carry the other. Taken from UltiOrganizer's own WFDF scoresheet
     * (`cust/wfdf/pdfscoresheet.php:1254`), which marks exactly those points
     * with an asterisk — the same rule, so a commentator reading this and a
     * scorekeeper reading the printed sheet cannot disagree.
     */
    function abbaSlot(pointNumber) {
        var i = Number(pointNumber) || 1;
        return (i % 4 === 0 || (i - 1) % 4 === 0) ? 'A' : 'B';
    }

    /**
     * The two ratios in play, by season type.
     *
     * Indoor and beach are five a side, outdoor seven — same source as the
     * scoresheet (`pdfscoresheet.php:461-471`).
     */
    function ratioPair() {
        var type = ((state.payload && state.payload.seasoninfo
            && state.payload.seasoninfo.type) || 'outdoor').toLowerCase();
        return (type === 'indoor' || type === 'beach')
            ? ['3MMP/2FMP', '2MMP/3FMP']
            : ['4MMP/3FMP', '3MMP/4FMP'];
    }

    /** Mixed is decided by the division name, exactly as the scoresheet decides it. */
    function isMixedDivision() {
        var name = (state.payload && state.payload.game_info
            && state.payload.game_info.seriesname) || '';
        return name.toLowerCase().indexOf('mixed') !== -1;
    }

    /**
     * Which of the two ratios was chosen for point 1.
     *
     * **UltiOrganizer does not record this.** It is circled by hand on the paper
     * scoresheet and nothing sends it back, so the A-B-B-A pattern is derivable
     * but its labels are not — the same shape of gap as possession, and
     * collected the same way.
     *
     * Kept with the GAME rather than in this browser, so the commentator panel
     * and the stage's progression card read one value. It was per-browser at
     * first, which meant the person calling the game and the graphic going out
     * could quietly disagree about which ratio a point was played at.
     *
     * Writable by whoever holds the room code, for the same reason possession
     * is: the commentary desk has the scoresheet in front of them.
     */
    function firstRatioValue() { return possession.ratio1 || null; }

    function setFirstRatio(v) {
        if (!possession.canTrack) { return; }
        fetch(CONFIG.possessionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ game: CONFIG.gameId, code: syncCode, ratio1: v || '' })
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (state) { if (state) { possession = state; render(); } })
            .catch(function () { /* transient */ });
    }

    /** The point being played now: goals scored plus one. */
    function currentPointNumber() {
        return ((state.payload && state.payload.goals) || []).length + 1;
    }

    /**
     * Who is winning which ratio.
     *
     * "They have taken five of six on 4M/3F and are level on the other" is a
     * real line about how a mixed game is actually being won, and it is
     * derivable from the goal list alone — the ABBA slot of each point falls out
     * of its number.
     *
     * **This game only, and that is a limit rather than an omission.** A
     * tournament-wide version cannot be built: the first point's ratio is
     * decided per game and circled on paper, so "A" in one game and "A" in the
     * next are not necessarily the same ratio and the two cannot be added
     * together. See docs/COMMENTATOR.md section 6b.
     */
    function ratioSplit() {
        var goals = ((state.payload && state.payload.goals) || []).slice()
            .sort(function (a, b) { return a.num - b.num; });
        var out = { A: { home: 0, away: 0 }, B: { home: 0, away: 0 } };
        goals.forEach(function (g, i) {
            var slot = abbaSlot(i + 1);
            if (Number(g.ishomegoal) === 1) { out[slot].home += 1; } else { out[slot].away += 1; }
        });
        return out;
    }

    /* ---------------------------------------------------------------
       Possession — tracked here, shown on air
       --------------------------------------------------------------- */

    /**
     * The commentary desk is the right place to track possession.
     *
     * The Studio can do it, but the operator is choosing and timing graphics
     * while they do; the person calling the game is already watching the disc,
     * so pressing O and D rides along with their job instead of competing with
     * it (docs/STUDIO.md 3.5).
     *
     * It is gated, because unlike a line selection this reaches the broadcast.
     * The operator types one room code into the Studio, and only that code may
     * write. Nothing here can turn the mode on, nominate a code, or change the
     * game — those stay with the operator, so holding the code never becomes a
     * way to grant yourself more.
     */
    var possession = { enabled: false, events: [], canTrack: false, hasCode: false, rev: -1 };

    /**
     * A per-browser id, so the Studio can say how many commentators are on.
     *
     * Random and local: it identifies a tab across reloads and nothing else. Not
     * an IP, which would answer the question worse — two commentators on one
     * tournament wifi are a single address — while storing personal data to do
     * it.
     */
    var CLIENT_KEY = 'uo-commentator-client';
    var clientId;
    try {
        clientId = window.localStorage.getItem(CLIENT_KEY) || '';
    } catch (e) { clientId = ''; }
    if (!clientId) {
        clientId = Math.random().toString(36).slice(2, 12).replace(/[^a-z0-9]/g, '') + '0';
        try { window.localStorage.setItem(CLIENT_KEY, clientId); } catch (e) { /* private mode */ }
    }

    /**
     * A display name, sent with the poll so the operator can tell desks apart.
     *
     * The Studio otherwise shows "2 commentators connected", which answers the
     * wrong question — the operator needs to know whether the RIGHT desk is on
     * the code, not how many are. Typed here, kept in this browser, shown only
     * to the operator, and gone as soon as this page stops polling.
     */
    var NAME_KEY = 'uo-commentator-name';
    var myName = '';
    try { myName = window.localStorage.getItem(NAME_KEY) || ''; } catch (e) { myName = ''; }

    function setMyName(v) {
        myName = String(v || '').slice(0, 24);
        try { window.localStorage.setItem(NAME_KEY, myName); } catch (e) { /* private mode */ }
        pollPossession();
    }

    function possessionUrl(extra) {
        return CONFIG.possessionUrl
            + '&game=' + encodeURIComponent(CONFIG.gameId)
            + '&code=' + encodeURIComponent(syncCode)
            + '&client=' + encodeURIComponent(clientId)
            + (myName ? '&name=' + encodeURIComponent(myName) : '')
            + (extra || '');
    }

    function pollPossession() {
        fetch(possessionUrl(), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (state) {
                if (!state || state.rev === possession.rev) { return; }
                possession = state;
                if (state.mode !== 'prep') { render(); }
            })
            .catch(function () { /* transient */ });
    }

    /**
     * The possession log for the point being played.
     *
     * Reading it is the point, not correcting it. "Four changes, and three of
     * them inside eight seconds" is a sentence about how scrappy a point is, and
     * the gaps carry that better than the count does — so each row is spaced
     * from the one above it in proportion to the time between them, and a long
     * settled possession looks long.
     *
     * Corrections live here rather than beside the keys, and every deletion is
     * confirmed. The keys are pressed repeatedly without looking; nothing that
     * removes data belongs next to them.
     */
    var LOG_MIN_GAP = 6;      // px, so consecutive changes still read as rows
    var LOG_PX_PER_SEC = 4;   // a 15s possession is 60px of air
    var LOG_MAX_GAP = 120;

    function pointStartedAt() {
        // Approximate, and only offered when there is a clock. The point began
        // at the previous goal, whose time is on the GAME clock, so it has to be
        // put back on the wall clock to compare with a possession timestamp.
        var r = (state.payload && state.payload.game_result) || {};
        var goals = ((state.payload && state.payload.goals) || []).slice()
            .sort(function (a, b) { return a.num - b.num; });
        var start = Number(r.timer_start);
        if (!start || !goals.length) { return null; }
        var lastGoal = goals[goals.length - 1];
        return start + (Number(lastGoal.time) || 0) + (Number(r.timer_paused_duration) || 0);
    }

    /**
     * The point's start, but only when it can be believed.
     *
     * Derived from the last goal's position on the game clock, which assumes the
     * clock is at or past that goal. It usually is — but a clock corrected
     * backwards, or a goal entered late, puts the computed start after events
     * that have already been recorded, and the honest response is to stop
     * claiming a point-relative time rather than to print a negative one.
     */
    function usablePointStart(rows) {
        var began = pointStartedAt();
        if (!began || !rows.length) { return null; }
        return began <= rows[0].t ? began : null;
    }

    function openLog() {
        var sc = liveScore();
        if (!sc || !window.Possession) { return; }
        var rows = window.Possession.eventsFor(possession.events, sc.key);

        var card = document.getElementById('sheetCard');
        card.replaceChildren();
        card.append(el('h3', null, 'Possession \u00b7 point at ' + sc.key));

        var began = usablePointStart(rows);
        card.append(el('div', 'sub', rows.length + (rows.length === 1 ? ' change' : ' changes')
            + (began ? ' \u00b7 timed from the last goal' : ' \u00b7 timed from the first change')));

        if (!rows.length) {
            card.append(el('p', 'muted', 'Nothing recorded for this point.'));
        }

        var base = began || (rows.length ? rows[0].t : 0);
        var list = el('div', 'plog');
        rows.forEach(function (e, i) {
            var prev = i === 0 ? base : rows[i - 1].t;
            var gap = Math.max(0, e.t - prev);

            var row = el('div', 'plogrow' + (e.d ? ' d' : ''));
            // Spacing IS the data: how far apart these happened, to scale.
            row.style.marginTop = i === 0 ? '0'
                : Math.min(LOG_MAX_GAP, LOG_MIN_GAP + gap * LOG_PX_PER_SEC) + 'px';

            row.append(el('b', null, e.d ? 'Defence' : 'Offence'));
            // Sub-second gaps are the interesting ones -- a scramble reads as
            // "+0.4s" rather than collapsing to "+0s" as it did at whole-second
            // resolution. Longer gaps do not need the decimal.
            var showGap = gap < 10 ? (Math.round(gap * 10) / 10) : Math.round(gap);
            row.append(el('span', 'gap', i === 0 && !began ? 'first change' : '+' + showGap + 's'));
            row.append(el('span', 'abs', began
                ? Math.round(e.t - began) + 's into the point' : ''));

            var del = el('button', 'chip danger', 'Delete');
            del.type = 'button';
            del.addEventListener('click', function () { confirmDelete(e, sc); });
            row.append(del);
            list.append(row);
        });
        card.append(list);

        if (rows.length > 1) {
            var clr = el('button', 'barbtn', 'Clear the whole point');
            clr.type = 'button';
            clr.addEventListener('click', function () { confirmClear(sc, rows.length); });
            card.append(clr);
        }

        var foot = el('footer');
        var close = el('button', 'barbtn', 'Close');
        close.type = 'button';
        close.addEventListener('click', closeSheet);
        foot.append(close);
        card.append(foot);
        sheet.classList.add('open');
    }

    /**
     * Nothing is removed without being confirmed.
     *
     * These numbers are on air the moment they change, and the person deleting
     * is doing it mid-game with a hand on the keyboard.
     */
    function confirmDelete(e, sc) {
        var what = (e.d ? 'Defence' : 'Offence') + ' in possession';
        if (!window.confirm('Delete this change?\n\n' + what
            + '\n\nThe turnover count for this point changes immediately.')) { return; }
        correct({ at: e.t }, sc);
    }

    function confirmClear(sc, n) {
        if (!window.confirm('Clear all ' + n + ' changes for point ' + sc.key + '?\n\n'
            + 'The point reads as offence throughout afterwards.')) { return; }
        correct({ clearPoint: true }, sc);
    }

    /** Who this desk is, for the operator's roster. */
    function nameField() {
        var wrap = el('div', 'namefield');
        var label = el('label', null, 'Your name');
        var input = document.createElement('input');
        input.type = 'text';
        input.maxLength = 24;
        input.value = myName;
        input.placeholder = 'shown to the operator';
        input.id = 'commentatorName';
        label.htmlFor = input.id;
        input.addEventListener('change', function () { setMyName(input.value); });
        wrap.append(label, input);
        return wrap;
    }

    /** Is a declared stoppage running for the point on the clock? */
    function stoppageOn() {
        var sc = liveScore();
        return Boolean(sc && possession.stoppage && possession.stoppage.score === sc.key);
    }

    /**
     * Flag or clear an injury stoppage.
     *
     * Nothing in UltiOrganizer records these, so it is declared like possession
     * and the gender ratio — three separate facts behind one link, which is why
     * the link is no longer tied to break-chance mode.
     */
    function toggleStoppage() {
        if (!possession.canTrack) { return; }
        var sc = liveScore();
        if (!sc) { return; }
        var on = stoppageOn();
        fetch(CONFIG.possessionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ game: CONFIG.gameId, code: syncCode,
                                   score: sc.key, stoppage: !on })
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (state) { if (state) { possession = state; render(); } })
            .catch(function () { /* transient */ });
    }

    /** Apply a correction, then redraw the log so the result is visible. */
    function correct(what, forScore) {
        if (!possession.canTrack) { return; }
        var sc = forScore || liveScore();
        if (!sc) { return; }
        var body = { game: CONFIG.gameId, code: syncCode, score: sc.key };
        Object.keys(what).forEach(function (k) { body[k] = what[k]; });
        fetch(CONFIG.possessionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (state) {
                if (!state) { return; }
                possession = state;
                render();
                // Redraw the log underneath, so the correction is seen landing
                // rather than leaving the reader to reopen and check.
                if (sheet.classList.contains('open')) { openLog(); }
            })
            .catch(function () { /* transient */ });
    }

    function setDefence(hasDisc) {
        if (!possession.canTrack) { return; }
        var sc = liveScore();
        if (!sc) { return; }
        fetch(CONFIG.possessionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ game: CONFIG.gameId, code: syncCode,
                                   score: sc.key, defence: hasDisc })
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (state) { if (state) { possession = state; render(); } })
            .catch(function () { /* transient */ });
    }

    /** The score as the store keys it, plus the point before it. */
    function liveScore() {
        var r = (state.payload && state.payload.game_result) || {};
        if (r.homescore === undefined || r.homescore === null) { return null; }
        var h = Number(r.homescore) || 0, v = Number(r.visitorscore) || 0;
        return { home: h, visitor: v, key: h + '-' + v };
    }

    /** The score at which the previous point was played, or null at 0-0. */
    function previousScore() {
        var goals = (state.payload && state.payload.goals) || [];
        if (!goals.length) { return null; }
        var ordered = goals.slice().sort(function (a, b) { return a.num - b.num; });
        var last = ordered[ordered.length - 1];
        var sc = liveScore();
        if (!sc) { return null; }
        return Number(last.ishomegoal) === 1
            ? { home: sc.home - 1, visitor: sc.visitor }
            : { home: sc.home, visitor: sc.visitor - 1 };
    }

    /**
     * O and D on the keyboard, for the same reason the Studio has them:
     * possession changes several times in a scrappy point and a commentator is
     * watching the field, not this screen.
     */
    document.addEventListener('keydown', function (e) {
        if (e.metaKey || e.ctrlKey || e.altKey) { return; }
        var tag = (e.target && e.target.tagName) || '';
        if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || e.target.isContentEditable) { return; }
        var key = (e.key || '').toLowerCase();
        if (key !== 'o' && key !== 'd' && key !== 'u' && key !== 'i') { return; }
        if (state.mode !== 'play' || !possession.canTrack) { return; }
        e.preventDefault();
        if (key === 'i') { toggleStoppage(); return; }
        // U opens the log rather than deleting outright: one key still reaches
        // the fix at the speed the mistake was made, but nothing goes without
        // being seen and confirmed first.
        if (key === 'u') { openLog(); return; }
        setDefence(key === 'd');
    });

    var CODE_KEY = 'uo-lines-code-' + CONFIG.gameId;
    var lastLocalWrite = 0;

    function storedCode() {
        try { return window.localStorage.getItem(CODE_KEY) || ''; } catch (e) { return ''; }
    }

    function rememberCode(code) {
        try { window.localStorage.setItem(CODE_KEY, code); } catch (e) { /* private mode */ }
    }

    /**
     * The code persists per game, and is only generated once.
     *
     * Regenerating on every load would be worse than useless: a commentator who
     * reloaded mid-game would silently leave the room and stop seeing their
     * partner's changes, with nothing on screen to say so.
     */
    var syncCode = storedCode();
    if (!syncCode) {
        syncCode = CONFIG.suggestedCode;
        rememberCode(syncCode);
    }

    function pushLine(teamId, players) {
        if (!teamId) { return; }
        lastLocalWrite = Date.now();
        fetch(CONFIG.linesUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                game: CONFIG.gameId, code: syncCode,
                team: teamId, players: players
            })
        }).catch(function () { /* sharing is best-effort; the local pick still stands */ });
    }

    function pullLines() {
        if (!CONFIG.gameId || !syncCode) { return; }
        // A local edit wins for a moment, so a poll in flight cannot undo the
        // tap that was just made.
        if (Date.now() - lastLocalWrite < 1500) { return; }

        fetch(CONFIG.linesUrl + '&game=' + encodeURIComponent(CONFIG.gameId)
            + '&code=' + encodeURIComponent(syncCode), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (b) {
                if (!b || !b.teams) { return; }
                var changed = false;
                Object.keys(b.teams).forEach(function (teamId) {
                    var incoming = b.teams[teamId] || [];
                    var mine = state.line[teamId] || [];
                    if (incoming.join(',') !== mine.join(',')) {
                        state.line[teamId] = incoming.slice();
                        changed = true;
                    }
                });
                if (changed && state.mode === 'play') { renderPlay(); }
            });
    }

    function renderSync() {
        var box = document.getElementById('sync');
        box.replaceChildren();
        box.append(el('span', 'muted', 'Sync'));

        var input = document.createElement('input');
        input.className = 'code';
        input.value = syncCode;
        input.size = CONFIG.codeLength;
        input.maxLength = CONFIG.codeLength;
        input.spellcheck = false;
        input.autocapitalize = 'characters';
        input.title = 'Share this code with the other commentator, or type theirs. '
            + 'The room is this code plus this game.';
        input.addEventListener('change', function () {
            var v = input.value.trim().toUpperCase();
            if (v.length !== CONFIG.codeLength) { input.value = syncCode; return; }
            syncCode = v;
            rememberCode(v);
            input.value = v;
            state.line = {};       // a different room is a different selection
            pullLines();
            render();
        });
        box.append(input);
    }

    function pickPanel(side) {
        var panel = el('div', 'panel');
        var list = rosterByNumber(side);
        var line = lineFor(side);

        var head = el('div', 'pickhead');
        head.append(el('strong', null, side.team.name || '—'));
        var count = el('span', 'count' + (line.length === 7 ? ' ok' : (line.length > 7 ? ' over' : '')),
            line.length + ' / 7');
        head.append(count);
        panel.append(head);

        if (!list.length) {
            panel.append(el('p', 'muted', 'No roster available.'));
            return panel;
        }

        var nums = el('div', 'nums');
        list.forEach(function (p) {
            var b = el('button', line.indexOf(p.id) !== -1 ? 'on' : '');
            b.type = 'button';
            b.append(document.createTextNode(
                p.num === null || p.num === undefined ? '–' : String(p.num)));
            // Surname under the number: the number is what is on the shirt, the
            // name is what gets said.
            b.append(el('small', null, p.name.split(' ').slice(-1)[0]));
            b.addEventListener('click', function () { toggleNum(side, p.id); });
            nums.append(b);
        });
        panel.append(nums);
        return panel;
    }

    function onFieldPanel(side, cls) {
        var wrap = el('div', 'side' + (cls ? ' ' + cls : ''));
        wrap.append(el('h3', null, side.team.name || '—'));
        var line = lineFor(side);
        var byId = {};
        rosterByNumber(side).forEach(function (p) { byId[p.id] = p; });

        if (!line.length) {
            wrap.append(el('div', 'empty', 'Nobody selected — pick a line first.'));
            return wrap;
        }
        var row = el('div', 'line');
        line.map(function (id) { return byId[id]; }).filter(Boolean)
            .sort(function (a, b) { return (a.num || 0) - (b.num || 0); })
            .forEach(function (p) {
                var d = el('div', 'p');
                if (p.num !== null && p.num !== undefined) { d.append(el('span', null, p.num)); }
                d.append(document.createTextNode(p.name));
                row.append(d);
            });
        wrap.append(row);
        return wrap;
    }

    /**
     * Possession, and how scrappy the point is.
     *
     * The turnover counts are the part a commentator actually reaches for. "Four
     * turnovers in this point already" is a line you can say on air, and it is
     * derivable here and nowhere else — UltiOrganizer records no possession
     * changes at all, so this log is the only place the number exists.
     *
     * The previous point's count is shown beside it because the interesting
     * comparison is almost always to the point just gone.
     */
    function possessionPanel() {
        var box = el('div', 'possbox');

        if (!possession.enabled) {
            box.className = 'possbox quiet';
            box.append(el('span', 'muted',
                'Possession tracking is off. The Studio operator turns it on.'));
            return box;
        }

        if (!possession.canTrack) {
            box.className = 'possbox quiet';
            // Your name goes here even before you are linked: it is what the
            // operator reads when deciding whose code to enter.
            box.append(nameField());
            var ask = el('span', 'muted');
            ask.append(document.createTextNode(
                possession.hasCode
                    ? 'Another code is tracking possession for this game. To take it over, ask the operator to enter '
                    : 'To track possession, ask the Studio operator to enter your code '));
            ask.append(el('b', 'code', syncCode));
            ask.append(document.createTextNode(
                possession.hasCode ? ' in the Studio instead.' : ' in the Studio.'));
            box.append(ask);
            return box;
        }

        var sc = liveScore();
        var P = window.Possession;
        var d = sc && P ? P.defenceHasDisc(possession.events, sc.home, sc.visitor) : false;

        box.append(nameField());

        // Injury stoppage sits with possession because it is the same kind of
        // thing: a fact about the game that nothing records, declared by whoever
        // is watching. It is not part of break-chance mode and does not need it.
        var stop = el('button', 'chip' + (stoppageOn() ? ' on' : ''),
            stoppageOn() ? '\u25a0 End injury stoppage' : '\u2691 Injury stoppage');
        stop.type = 'button';
        stop.title = 'Keyboard: I';
        stop.addEventListener('click', toggleStoppage);
        box.append(stop);

        var btns = el('div', 'possbtns');
        [['O', 'Offence', false], ['D', 'Defence', true]].forEach(function (spec) {
            var active = (spec[2] === d);
            var b = el('button', 'possbig' + (active ? ' on' : '') + (spec[2] ? ' d' : ''));
            b.type = 'button';
            b.disabled = !sc;
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
            b.append(el('span', 'k', spec[0]));
            b.append(el('span', 'w', spec[1]));
            b.addEventListener('click', function () { setDefence(spec[2]); });
            btns.append(b);
        });
        box.append(btns);

        // One way in to the log, which is also the only place anything is
        // deleted. Corrections do not sit beside the live keys: a button that
        // removes data should not be adjacent to two that are being hit
        // repeatedly without looking.
        var pressed = sc && P ? P.eventsFor(possession.events, sc.key).length : 0;
        var fixes = el('div', 'possfix');
        var open = el('button', 'chip', '\u2637 Possession log');
        open.type = 'button';
        open.disabled = !pressed;
        open.title = pressed ? 'Keyboard: U' : 'Nothing recorded this point yet';
        open.addEventListener('click', openLog);
        fixes.append(open);
        box.append(fixes);

        var counts = el('div', 'posscounts');
        var now = (sc && P) ? P.turnovers(possession.events, sc.home, sc.visitor) : 0;
        var prev = previousScore();
        var was = (prev && P) ? P.turnovers(possession.events, prev.home, prev.visitor) : null;

        var stat = function (label, value, cls) {
            var d2 = el('div', 'posscount' + (cls ? ' ' + cls : ''));
            d2.append(el('b', null, String(value)));
            d2.append(el('span', null, label));
            counts.append(d2);
        };
        stat('turnovers this point', now, now >= 3 ? 'hot' : '');
        if (was !== null) {
            stat('in the previous point', was, '');
        }
        box.append(counts);

        box.append(el('p', 'muted',
            'Press O and D as the disc changes hands, U to undo. Resets to offence on every goal. '
            + 'This drives BREAK CHANCE on the scoreboard.'));
        return box;
    }

    /**
     * The ratio for this point and the next few.
     *
     * Mixed only, and silent otherwise — an Open or Women's game has no ratio to
     * show and a panel saying so would be clutter. Shows the run ahead rather
     * than just the current point, because the useful call is "this one and the
     * next are 4M/3F, then it flips".
     */
    function ratioPanel() {
        if (!isMixedDivision()) { return null; }

        var box = el('div', 'abba');
        var pair = ratioPair();
        var now = currentPointNumber();

        var head = el('div', 'abbahead');
        head.append(el('span', 'muted', 'Gender ratio'));
        box.append(head);

        var firstRatio = firstRatioValue();

        if (!firstRatio) {
            // Ask once, plainly. Nothing in the payload can answer it.
            var ask = el('div', 'abbaask');
            ask.append(el('span', 'muted', 'Ratio on point 1:'));
            if (possession.canTrack) {
                pair.forEach(function (r) {
                    var b = el('button', 'chip', r);
                    b.type = 'button';
                    b.addEventListener('click', function () { setFirstRatio(r); });
                    ask.append(b);
                });
            } else {
                ask.append(el('span', 'muted', 'set by the operator, or by a code holder'));
            }
            box.append(ask);
            box.append(el('p', 'muted',
                'Not recorded anywhere \u2014 it is circled on the paper scoresheet. '
                + 'Set it once and the rest of the game follows the ABBA pattern, '
                + 'here and on the progression card.'));
            return box;
        }

        var other = pair[0] === firstRatio ? pair[1] : pair[0];
        var ratioFor = function (n) { return abbaSlot(n) === 'A' ? firstRatio : other; };

        var run = el('div', 'abbarun');
        for (var n = now; n < now + 4; n += 1) {
            var cell = el('div', 'abbapt' + (n === now ? ' now' : ''));
            cell.append(el('b', null, ratioFor(n)));
            cell.append(el('span', null, n === now ? 'this point' : 'pt ' + n));
            run.append(cell);
        }
        box.append(run);

        // Which ratio each side is actually winning. Only once there is enough
        // played to mean anything -- a split over two points is a coin toss with
        // a label on it.
        var split = ratioSplit();
        if ((split.A.home + split.A.away + split.B.home + split.B.away) >= 4) {
            var s = sides();
            var tbl = el('div', 'abbasplit');
            [['A', firstRatio], ['B', other]].forEach(function (pairv) {
                var slot = split[pairv[0]];
                var line = el('div', 'abbasplitrow');
                line.append(el('b', null, pairv[1]));
                line.append(el('span', 'sc', slot.home + ' \u2013 ' + slot.away));
                line.append(el('span', 'who',
                    (s[0].team.name || 'Home') + ' v ' + (s[1].team.name || 'Away')));
                tbl.append(line);
            });
            box.append(tbl);
        }

        var reset = el('button', 'chip', 'point 1 was ' + firstRatio);
        reset.type = 'button';
        reset.disabled = !possession.canTrack;
        reset.title = possession.canTrack
            ? 'Change what was set for the first point'
            : 'Only a code holder can change this';
        reset.addEventListener('click', function () { setFirstRatio(null); });
        box.append(reset);
        return box;
    }

    function renderPlay() {
        body.replaceChildren();
        var s = sides();
        body.append(teamStatsRow());
        // Above the line picker as well as the on-field view: in step 1 it is
        // how a commentator learns they are not authorised yet, which is worth
        // finding out before the pull rather than during the point.
        var ratio = ratioPanel();
        if (ratio) { body.append(ratio); }
        body.append(possessionPanel());

        if (state.step === 1) {
            var bar = el('div', 'pickhead');
            bar.append(el('strong', null, 'Who is on for this point?'));
            var go = el('button', 'barbtn primary', 'Start point →');
            go.type = 'button';
            go.addEventListener('click', function () { state.step = 2; renderPlay(); });
            bar.append(go);
            var clear = el('button', 'barbtn', 'Clear both');
            clear.type = 'button';
            clear.addEventListener('click', function () { state.line = {}; renderPlay(); });
            bar.append(clear);
            body.append(bar);

            var cols = el('div', 'cols');
            s.forEach(function (side) { cols.append(pickPanel(side)); });
            body.append(cols);
        } else {
            var top = el('div', 'pickhead');
            top.append(el('strong', null, 'On the field'));
            var edit = el('button', 'barbtn', '← Change line');
            edit.type = 'button';
            edit.addEventListener('click', function () { state.step = 1; renderPlay(); });
            top.append(edit);
            body.append(top);

            var field = el('div', 'onfield');
            field.append(onFieldPanel(s[0]));
            field.append(onFieldPanel(s[1], 'away'));
            body.append(field);
        }
    }

    /* ---------------------------------------------------------------
       Modes and refresh
       --------------------------------------------------------------- */

    function render() {
        renderTop();
        document.getElementById('tabPrep').setAttribute('aria-pressed', state.mode === 'prep' ? 'true' : 'false');
        document.getElementById('tabPlay').setAttribute('aria-pressed', state.mode === 'play' ? 'true' : 'false');
        document.getElementById('tabPrep').className = state.mode === 'prep' ? 'on' : '';
        document.getElementById('tabPlay').className = state.mode === 'play' ? 'on' : '';
        renderSync();
        if (state.mode === 'play') { renderPlay(); } else { renderPrep(); }
    }

    function setMode(m) {
        state.mode = m;
        var u = new URL(window.location.href);
        u.searchParams.set('mode', m);
        window.history.replaceState({}, '', u);
        render();
    }

    document.getElementById('tabPrep').addEventListener('click', function () { setMode('prep'); });
    document.getElementById('tabPlay').addEventListener('click', function () { setMode('play'); });

    /**
     * A score means the point is over, so the line is stale.
     *
     * Returning to the picker rather than wiping the selection: the next line
     * usually shares players with the last one, so the previous picks stay
     * selected and re-picking is a few taps rather than seven. That also makes
     * the automatic reset safe — nothing a commentator typed is destroyed, only
     * the mode changes.
     */
    function watchScore() {
        var r = state.payload.game_result || {};
        var key = (r.homescore || 0) + ':' + (r.visitorscore || 0);
        if (state.lastScore !== null && key !== state.lastScore && state.mode === 'play') {
            state.step = 1;
        }
        state.lastScore = key;
    }

    function fail(message) {
        body.replaceChildren();
        var box = el('div', 'err');
        box.append(el('strong', null, 'Could not load the game.'), el('p', null, message));
        body.append(box);
    }

    function refresh() {
        return load().then(function () { watchScore(); render(); });
    }

    /**
     * With no game, this is the landing page: pick one.
     *
     * Live games first, because that is what a commentator arriving at a field
     * is almost always after.
     */
    function renderPicker() {
        document.getElementById('title').textContent = 'Commentator';
        document.querySelector('.tabs').style.display = 'none';

        fetch(CONFIG.api + '&entity=games', { credentials: 'same-origin' })
            .then(readJson)
            .then(function (b) {
                var games = (b.games || []).slice().sort(function (x, y) {
                    var rank = function (g) {
                        return Number(g.isongoing) === 1 ? 0
                            : (g.status === 'completed' ? 2 : 1);
                    };
                    return rank(x) - rank(y)
                        || String(x.time || '').localeCompare(String(y.time || ''));
                });

                body.replaceChildren();
                if (!games.length) {
                    body.append(el('p', 'muted', 'No games in this event yet.'));
                    return;
                }

                var panel = el('div', 'panel');
                panel.append(el('h2', null, 'Choose a game'));
                var table = el('table', 'roster');
                var tb = el('tbody');
                games.forEach(function (g) {
                    var tr = el('tr');
                    var live = Number(g.isongoing) === 1;

                    var who = el('td', 'who');
                    who.append(link(CONFIG.base + '/c/' + g.game_id,
                        g.gamename || ('Game ' + g.game_id)));
                    tr.append(who);

                    var st = el('td');
                    st.style.textAlign = 'left';
                    st.append(el('span', 'badge ' + (live ? 'live' : (g.status === 'completed' ? 'done' : 'soon')),
                        live ? 'Live' : (g.status === 'completed' ? 'Final' : 'Scheduled')));
                    tr.append(st);

                    var sc = el('td', null,
                        g.homescore === null || g.homescore === undefined
                            ? '·' : g.homescore + '–' + g.visitorscore);
                    tr.append(sc);

                    var when = el('td');
                    when.style.textAlign = 'right';
                    when.className = 'muted';
                    when.textContent = String(g.time || '').slice(0, 16).replace('T', ' ');
                    tr.append(when);

                    tb.append(tr);
                });
                table.append(tb);
                panel.append(table);
                body.append(panel);
            })
            .catch(function (e) { fail(e.message); });
    }

    if (!CONFIG.gameId) {
        renderPicker();
    } else {
        refresh().catch(function (e) { fail(e.message); });
        // The partner's picks, and a lighter cadence than the game data because
        // a line changes far more often than a score.
        pullLines();
        setInterval(pullLines, 2000);
        // Possession is the fastest-moving thing on this page and the only one
        // that reaches air, so it gets its own tick rather than riding the 10s
        // game refresh.
        pollPossession();
        setInterval(pollPossession, 2000);
        // A commentator can be a few seconds behind; no need for the overlay's
        // tighter cadence.
        setInterval(function () { refresh().catch(function () {}); }, 10000);
    }
}());
</script>
</body>
</html>
