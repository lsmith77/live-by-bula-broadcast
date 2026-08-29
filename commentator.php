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
require_once __DIR__ . '/shared/notes.php';

use Overlays\Lines;
use Overlays\Notes;

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

        /* Matching tints: teal and violet, deliberately neither of the
           stereotyped pair. The term carries the meaning; colour only lets a
           line be scanned. */
        --fmp-bg: #ccfbf1;  --fmp-ink: #115e59;
        --mmp-bg: #ede9fe;  --mmp-ink: #5b21b6;

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

        --fmp-bg: #134e4a;  --fmp-ink: #99f6e4;
        --mmp-bg: #312e81;  --mmp-ink: #ddd6fe;

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
    /* The header and the toolbar stick as ONE block: the controls in the
       toolbar are pressed every few seconds during a point, and sticking only
       the header above them would have let them scroll away exactly when they
       are in use. */
    .stickyhead { position: sticky; top: 0; z-index: 20; background: var(--bg);
                  padding-bottom: .4rem; }
    .top { background: var(--bg);
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
    /* Live controls, beside the code that authorises them. Smaller than the
       body buttons they replace, but always on screen and never scrolled past —
       which matters more for something pressed without looking. */
    .tracking { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
    .tracking .tbtn { background: var(--panel-alt); border: 1px solid var(--line);
                      color: var(--ink); font: inherit; font-weight: 700; font-size: .82rem;
                      padding: .35rem .7rem; border-radius: 5px; cursor: pointer;
                      white-space: nowrap; }
    .tracking .tbtn.on { background: var(--accent); border-color: var(--accent);
                         color: var(--accent-ink); }
    .tracking .tbtn.d.on { background: var(--bad); border-color: var(--bad); color: #fff; }
    .tracking .tbtn:disabled { opacity: .4; cursor: not-allowed; }
    .tracking .note { font-size: .78rem; color: var(--ink-mute); }
    .tracking .tsel { background: var(--panel-alt); border: 1px solid var(--line);
                      color: var(--ink); font: inherit; font-size: .8rem;
                      border-radius: 5px; padding: .3rem .4rem; }
    .tracking .tsel:disabled { opacity: .5; }
    .tracking .tbtn.primary { background: var(--accent); border-color: var(--accent);
                              color: var(--accent-ink); }
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
    .roster td.who .say { margin-left: .5rem; color: var(--ink-mute); font-size: .82rem;
        white-space: nowrap; }
    .roster td.who .say .hi, #sheetCard .sub.say .hi { color: var(--ink); font-weight: 700; }
    .mt { font-size: .68rem; font-weight: 700; letter-spacing: .04em;
        color: var(--ink-mute); border: 1px solid var(--line); border-radius: 3px;
        padding: 0 .25rem; }
    .mt.fmp { background: var(--fmp-bg); color: var(--fmp-ink); border-color: transparent; }
    .mt.mmp { background: var(--mmp-bg); color: var(--mmp-ink); border-color: transparent; }
    .nums button .mt { display: block; margin: .1rem auto 0; width: max-content; }
    .onfield .p .mt { margin-left: .4rem; vertical-align: .2em; }
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
    .abbarun { display: flex; gap: .5rem; flex-wrap: wrap; }
    .abbapt { display: flex; align-items: baseline; gap: .4rem;
              padding: .3rem .6rem; border-radius: 5px;
              background: var(--panel-alt); border: 1px solid var(--line); }
    .abbapt b { font-size: 1rem; font-weight: 800; font-variant-numeric: tabular-nums; }
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
    .linkline { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
    .nameinput { width: 8.5rem; background: var(--panel-alt); border: 1px solid var(--line);
                 color: var(--ink); font: inherit; font-size: .8rem; border-radius: 4px;
                 padding: .3rem .45rem; }
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
    .possbox.slim { padding: .5rem .9rem; }
    .posscounts { display: flex; gap: 1.5rem; }
    .posscount b { font-size: 1.3rem; font-weight: 800; font-variant-numeric: tabular-nums;
                   margin-right: .35rem; }
    .posscount span { font-size: .85rem; color: var(--ink-mute); }
    .posscount.hot b { color: var(--bad); }

    /* ---- play mode ---- */
    .step { margin-top: 1rem; }
    /* The sort chips sat flush against the team-stats panel above them —
       measured at a 0px gap, so they read as part of it rather than as controls
       for the rosters below. They belong to what follows, so the space goes
       above them. */
    .pickhead { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
                margin: 1.1rem 0 .5rem; }
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
    .pickhead .gcount { font-size: .8rem; color: var(--ink-mute); white-space: nowrap; }
    .nums button.fmp { background: var(--fmp-bg); color: var(--fmp-ink); }
    .nums button.fmp small { color: var(--fmp-ink); }
    .nums button.mmp { background: var(--mmp-bg); color: var(--mmp-ink); }
    .nums button.mmp small { color: var(--mmp-ink); }
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
    .onfield .p .pr { color: var(--ink-mute); font-size: .95rem; font-weight: 600;
        margin-left: .4rem; margin-right: 0; }
    .onfield .p .pr.hi { color: var(--ink); font-weight: 700; }
    /* font-family only: the `font` shorthand would reset the size and weight
       .p sets, shrinking every name on the field. */
    .onfield button.p { font-family: inherit; background: none; border: 0; padding: 0;
        color: inherit; cursor: pointer; text-align: left; }
    .onfield button.p:hover { color: var(--link); }
    #quickbuf { font-weight: 700; color: var(--ink); min-width: 2rem; display: inline-block; }
    .prcheck { margin-top: .6rem; }
    .prcheck h4 { margin: 0 0 .15rem; font-size: .85rem; }
    .prcheck .muted { margin: 0 0 .35rem; font-size: .8rem; }
    .prcheck .row { display: flex; align-items: center; gap: .6rem; padding: .15rem 0;
        flex-wrap: wrap; }
    .prcheck .row .who { font-weight: 600; }
    .prcheck .row input { font: inherit; font-size: .85rem; font-weight: 700;
        color: var(--ink); background: var(--bg); border: 1px solid var(--line);
        border-radius: 4px; padding: .2rem .45rem; width: 8rem; }
    .prcheck .row input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
    .keygrid { display: grid; grid-template-columns: auto 1fr; gap: .35rem .8rem;
        align-items: baseline; margin: .3rem 0 .8rem; }
    .keygrid kbd { font-family: inherit; font-size: .85rem; font-weight: 700;
        border: 1px solid var(--line); border-radius: 4px; padding: .05rem .45rem;
        justify-self: start; background: var(--panel); }
    .quickcards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .75rem; margin-top: .75rem; }
    .qcard { background: var(--panel); border: 1px solid var(--line); border-radius: 6px;
        padding: .6rem .9rem; }
    .qcard h4 { margin: 0; font-size: 1.15rem; }
    .qcard .muted { font-size: .8rem; }
    .qcard .say { color: var(--ink-mute); font-size: .85rem; margin-top: .1rem; }
    .qcard .say .hi { color: var(--ink); font-weight: 700; }
    .qcard .qline { font-size: .9rem; margin-top: .35rem; white-space: nowrap; }
    .qcard .qnote { margin: .45rem 0 0; white-space: pre-wrap; font-size: .95rem; }
    .qcard .qpoints { display: flex; flex-wrap: wrap; gap: 2px; margin-top: .45rem; }
    .qcard .qp { width: .95rem; height: .95rem; border-radius: 2px; background: var(--sunk);
        font-size: .62rem; font-weight: 700; line-height: .95rem; text-align: center;
        color: transparent; }
    .qcard .qp.won { background: var(--accent-soft); }
    .qcard .qp.me { background: var(--accent); color: var(--accent-ink); }
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

    /* ---- prepared notes ----
       A talking-points box, filled in before the game from whatever the teams
       hand over. Monospace would be wrong: this is prose to be read aloud, not
       a code. It gets real height because a two-line box invites two lines. */
    .note .fields { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem;
        margin-bottom: .5rem; }
    /* min-width: 0 on the grid item, or a long placeholder sets the column's
       minimum and pushes the row out of the card. */
    .note .fields .field { display: flex; flex-direction: column; gap: .2rem; min-width: 0; }
    .note .fields .field span { font-size: .72rem; color: var(--ink-mute);
        text-transform: uppercase; letter-spacing: .04em; }
    .note .fields .field input { font: inherit; font-size: .9rem; padding: .35rem .5rem;
        border: 1px solid var(--line); border-radius: 4px; background: var(--bg);
        color: var(--ink); min-width: 0; }
    .note .fields .field input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
    #sheetCard .sub.say { color: var(--ink); font-weight: 600; }
    .note.teamnote { margin: .6rem 0 .2rem; }
    .note.teamnote textarea { min-height: 3.2rem; }
    .note textarea { width: 100%; min-height: 6.5rem; resize: vertical; font: inherit;
                     font-size: .92rem; line-height: 1.45; padding: .5rem .6rem;
                     background: var(--bg); color: var(--ink); border: 1px solid var(--line);
                     border-radius: 5px; }
    .note textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
    .note .meta { display: flex; gap: .6rem; align-items: baseline; margin-top: .3rem;
                  font-size: .76rem; color: var(--ink-mute); }
    .note .meta .state { margin-left: auto; }
    .note .meta .state.saved { color: var(--ok); }
    .note .meta .state.failed { color: var(--bad); }
    /* ---- bio export / import ----
       Prep-time actions, so they sit under the roster rather than in the
       toolbar: nobody does this during a point. */
    .flashline { display: none; }
    .flashline.on { display: block; margin: .6rem 0 0; padding: .5rem .8rem;
                    background: var(--accent-soft); border-left: 3px solid var(--accent);
                    border-radius: 4px; font-size: .88rem; font-weight: 600; }
    .biobar { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap;
              margin-top: .5rem; font-size: .8rem; }
    .biobar .barbtn { font-size: .76rem; padding: .3rem .6rem; }
    .biobar input[type="file"] { position: absolute; width: 1px; height: 1px;
                                 padding: 0; margin: -1px; overflow: hidden;
                                 clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
    /* The import preview. Every row the file did not apply is listed: a silent
       import is how the wrong biography ends up being read out. */
    .tally { display: grid; grid-template-columns: auto 1fr; gap: .2rem .7rem;
             font-size: .9rem; font-variant-numeric: tabular-nums; margin: .6rem 0; }
    .tally .n { font-weight: 700; text-align: right; }
    .tally .n.no { color: var(--bad); }
    .tally .n.yes { color: var(--ok); }
    .rejects { max-height: 30vh; overflow-y: auto; margin-top: .4rem;
               border-top: 1px solid var(--sunk); }
    .rejects div { padding: .3rem 0; border-bottom: 1px solid var(--sunk);
                   font-size: .84rem; display: flex; gap: .6rem; }
    .rejects .ln { color: var(--ink-mute); font-variant-numeric: tabular-nums;
                   min-width: 3.4rem; }
    .rejects .why { color: var(--bad); }

    /* The roster marker for "this player has a note". A dot, not a word: the
       column is 28 rows tall and the name is what the eye is scanning for.

       --link, not --accent. Accent is a FILL colour — it is the background of a
       pressed button with white text on it — so in the night theme it is a dark
       blue that measured 2.59:1 against the panel behind this dot. That fails the
       3:1 floor for a meaningful graphic, and this dot is the only visual signal
       that a player has anything written about them. --link is the token that is
       legible against the page in both themes, which is exactly what is wanted. */
    .roster .who .dot { color: var(--link); font-weight: 700; margin-left: .3rem; }

    /* Room code: shown, not asked for. A random one is generated on first load
       so two commentary pairs at one tournament cannot both pick "12345". */
    .sync { display: flex; align-items: center; gap: .4rem; font-size: .8rem; }
    .sync .code { background: var(--bg); border: 1px solid var(--line); color: var(--link);
                  border-radius: 4px; padding: .3rem .45rem; font-weight: 700;
                  letter-spacing: .12em; text-transform: uppercase;
                  font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    /* Masked by default; this reveals it long enough to read out. Small, because
       it is pressed once a game and sits beside the field it belongs to. */
    .sync .peek { padding: .25rem .5rem; font-size: .72rem; }
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
<div class="stickyhead">
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
    <div class="tracking" id="tracking" role="group" aria-label="Live tracking"></div>
    <div class="tracking" id="steps" role="group" aria-label="Point"></div>
    <div class="tabs" role="group" aria-label="View">
        <button id="tabPrep" type="button" aria-pressed="true">Prep</button>
        <button id="tabPlay" type="button" aria-pressed="false">Play-by-play</button>
    </div>
</div>
</div>

<!--
  Import result. A live region rather than an alert: it confirms something the
  reader just asked for and must not interrupt them to do it.
-->
<div id="importFlash" class="flashline" role="status" aria-live="polite"></div>

<main id="body"><p class="muted">Loading…</p></main>

<div class="sheet" id="sheet" role="dialog" aria-modal="true" aria-labelledby="sheetName">
    <div class="card" id="sheetCard"></div>
</div>

<script src="<?= $base ?>/live/overlays/shared/possession.js"></script>
<script src="<?= $base ?>/live/overlays/shared/ratio.js"></script>
<script src="<?= $base ?>/live/overlays/shared/lineup.js"></script>
<script src="<?= $base ?>/live/overlays/shared/tracking.js"></script>
<script src="<?= $base ?>/live/overlays/shared/secret.js"></script>
<script src="<?= $base ?>/live/overlays/shared/csv.js"></script>
<script src="<?= $base ?>/live/overlays/shared/bios.js"></script>
<script>
(function () {
    'use strict';

    var CONFIG = {
        base: <?= $json($base) ?>,
        api: <?= $json($base . '/index.php?view=live/api') ?>,
        gameId: <?= $json($gameId ?: null) ?>,
        mode: <?= $json($mode) ?>,
        linesUrl: <?= $json($base . '/index.php?view=live/overlays/lines') ?>,
        notesUrl: <?= $json($base . '/index.php?view=live/overlays/notes') ?>,
        possessionUrl: <?= $json($base . '/index.php?view=live/overlays/possession') ?>,
        suggestedCode: <?= $json($suggestedCode) ?>,
        codeLength: <?= (int) Lines::CODE_LENGTH ?>,
        noteMax: <?= (int) Notes::MAX_TEXT ?>,
        fieldMax: <?= (int) Notes::MAX_FIELD ?>,
        noteDays: <?= (int) round(Notes::STALE_SECONDS / 86400) ?>
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


    function load() {
        return fetch(CONFIG.api + '&entity=games&id=' + encodeURIComponent(CONFIG.gameId),
            { credentials: 'same-origin' })
            .then(window.Tracking.readJson)
            .then(function (p) {
                state.payload = p;
                var t = p.teams || {};
                var ids = [t.hometeam, t.visitorteam].filter(Boolean)
                    .map(function (x) { return x.team_id; }).filter(Boolean);
                return Promise.all(ids.map(function (id) {
                    return fetch(CONFIG.api + '&entity=teams&id=' + encodeURIComponent(id),
                        { credentials: 'same-origin' })
                        .then(window.Tracking.readJson)
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
            who.setAttribute('data-mixed', isMixedSide(side) ? '1' : '');
            var btn = el('button', null, p.name);
            btn.type = 'button';
            // Say whether opening this player is worth it. The notes live in the
            // detail overlay, which means they are invisible from the list —
            // and a commentator is not going to open 28 players one at a time to
            // find out which three have anything written about them.
            //
            // Presence only, never a preview: the marker is scanned down a
            // column of 28 rows, and the name is what the eye is there for.
            btn.addEventListener('click', function () { openSheet(p, side); });
            // The marker is stamped by markNote() rather than built here, so the
            // same code runs on first render and on every later change.
            btn.setAttribute('data-player', p.id);
            markNote(btn, noteFor(p.id));
            who.append(btn);
            markSay(who, noteFor(p.id));
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
        panel.append(teamNoteBox(side));
        panel.append(bioBar(side));
        panel.append(pronounCheck(side));
        return panel;
    }

    function teamNoteText(side) {
        var entry = teamNotes[String(side.team.team_id)];
        return entry && entry.text ? entry.text : '';
    }

    function pushTeamNote(side, text, onDone) {
        if (!syncCode) { return; }
        lastNoteWrite = Date.now();
        var key = String(side.team.team_id);
        var trimmed = String(text || '').trim();
        if (trimmed) {
            teamNotes[key] = { text: trimmed, by: myName, at: Math.floor(Date.now() / 1000) };
        } else {
            delete teamNotes[key];
        }
        fetch(CONFIG.notesUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                code: syncCode, team: Number(side.team.team_id),
                text: trimmed, by: myName
            })
        })
            .then(window.Tracking.readJson)
            .then(function (b) {
                if (b && b.teams) { teamNotes = b.teams; }
                if (onDone) { onDone(true); }
            })
            .catch(function () { if (onDone) { onDone(false); } });
    }

    /**
     * The team's own note — history, achievements, the material that belongs
     * to nobody's row. Filled by the TEAM row of the bio round trip, or typed
     * here; the same debounced-save shape as the player note box.
     */
    function teamNoteBox(side) {
        var box = el('div', 'note teamnote');
        box.append(el('h4', null, 'About the team'));

        var area = document.createElement('textarea');
        area.value = teamNoteText(side);
        area.maxLength = CONFIG.noteMax;
        area.placeholder = 'History, achievements — what the team sent about itself. '
            + 'The TEAM row of the exported sheet lands here too.';
        area.setAttribute('aria-label', 'Team note for ' + (side.team.name || 'this team'));
        box.append(area);

        var meta = el('div', 'meta');
        var stateLabel = el('span', 'state');
        meta.append(stateLabel);
        box.append(meta);

        var timer = null;
        var pending = false;
        function flush() {
            if (!pending) { return; }
            pending = false;
            if (timer) { window.clearTimeout(timer); timer = null; }
            stateLabel.className = 'state';
            stateLabel.textContent = 'Saving…';
            pushTeamNote(side, area.value, function (ok) {
                stateLabel.className = 'state ' + (ok ? 'saved' : 'failed');
                stateLabel.textContent = ok ? 'Saved' : 'Not shared — kept on this screen';
            });
        }
        area.addEventListener('input', function () {
            pending = true;
            stateLabel.className = 'state';
            stateLabel.textContent = 'Unsaved';
            if (timer) { window.clearTimeout(timer); }
            timer = window.setTimeout(flush, 900);
        });
        area.addEventListener('blur', flush);
        // Catch up when the room's state arrives after the render — but never
        // under someone's fingers.
        prchecks.push(function () {
            if (pending || document.activeElement === area) { return; }
            var current = teamNoteText(side);
            if (area.value !== current) { area.value = current; }
        });
        return box;
    }

    /**
     * The prep-time pronoun check: every declared set the emphasis rule does
     * not recognise as he/him or she/her, and that nobody has reviewed yet.
     *
     * The CSV deliberately stays free text — a dropdown in a team-visible sheet
     * would make the two common answers a form and everything else a disclosure
     * (§5). The cost is variants: "He", "she/her/hers", another language. Left
     * alone they would all be emphasised, and emphasis that is mostly noise
     * stops protecting the entries it exists for. So the desk reconciles during
     * prep, by hand: a variant that plainly means a common set is mapped onto
     * it, a typo is fixed in the row's own input, and a genuinely declared set
     * — she/they, xe/xem, anything — is kept exactly as written with one click,
     * stays emphasised, and stops being asked about. Nothing is ever mapped
     * automatically, and when in doubt the answer is Keep.
     */
    function pronounCheck(side) {
        var box = el('div', 'prcheck');
        function save(p, entry) {
            pushNote(p.id, entry);
            fill();
        }
        function fill() {
            box.replaceChildren();
            var open = rosterByNumber(side).filter(function (p) {
                var n = noteFor(p.id);
                return !!(n && n.pronouns && emphasizedPronouns(n.pronouns) && !n.pronounsok);
            });
            if (!open.length) { return; }
            box.append(el('h4', null, 'Pronouns to review'));
            box.append(el('p', 'muted',
                'Written differently but plainly one of the two common sets? Map it. '
                + 'Anything genuinely declared: edit if needed, then Keep — it stays '
                + 'exactly as written, emphasised, and is not asked about again.'));
            open.forEach(function (p) {
                var cur = noteFor(p.id);
                var row = el('div', 'row');
                row.append(el('span', 'who', p.name));

                var input = document.createElement('input');
                input.type = 'text';
                input.maxLength = CONFIG.fieldMax;
                input.value = cur.pronouns;
                input.setAttribute('aria-label', 'Pronouns for ' + p.name);
                row.append(input);

                function keep() {
                    save(p, {
                        text: cur.text || '', nickname: cur.nickname || '',
                        pronouns: input.value, pronunciation: cur.pronunciation || '',
                        matching: cur.matching || '', pronounsok: true
                    });
                }
                ['he/him', 'she/her'].forEach(function (target) {
                    var b = el('button', 'chip', '→ ' + target);
                    b.type = 'button';
                    b.addEventListener('click', function () {
                        save(p, {
                            text: cur.text || '', nickname: cur.nickname || '',
                            pronouns: target, pronunciation: cur.pronunciation || '',
                            matching: cur.matching || ''
                        });
                    });
                    row.append(b);
                });
                var b = el('button', 'chip', 'Keep');
                b.type = 'button';
                b.title = 'Keep exactly this text, as the player declared it';
                b.addEventListener('click', keep);
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); keep(); }
                });
                row.append(b);
                box.append(row);
            });
        }
        fill();
        prchecks.push(fill);
        return box;
    }

    function renderPrep() {
        body.replaceChildren();
        prchecks = [];

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
       Prepared notes

       Talking points about a player, typed in before the game and shared with
       whoever holds the room code.

       This is the lowest-hanging thing on the page. `docs/COMMENTATOR.md`
       section 5 asks upstream for structured, self-declared fields on the player
       profile, and that remains the right destination — but at a smaller
       tournament the commentary position exists only for the finals, and the way
       material actually arrives is that somebody asks the two teams for
       something to say and is handed it an hour before the pull. There is no
       UltiOrganizer surface for that and there will not be one before the game
       starts, so the commentator types it here.

       Keyed by PLAYER and by CODE, never by game: a note is worth the same in
       the final as in the quarter, and a desk that had to retype everything each
       round would stop doing it by lunchtime.
       --------------------------------------------------------------- */

    /** player id -> {text, by, at}, as last read from the room. */
    var notes = {};
    /** team id -> {text, by, at}: history, achievements — nobody's row. */
    var teamNotes = {};
    var lastNoteWrite = 0;

    /** Prep-panel refreshers (pronoun checks, team boxes), re-run when the room arrives. */
    var prchecks = [];

    function noteFor(playerId) {
        return notes[String(playerId)] || null;
    }

    /**
     * The identity line: nickname, pronouns, how to say the name.
     *
     * These are the parts of an entry read at a glance rather than opened, so
     * they sit beside the name instead of inside the note. Absent means absent —
     * no placeholder, nothing implying the player was asked — and pronouns in
     * particular are shown only as declared, never derived (§5).
     */
    function sayLine(note) {
        if (!note) { return ''; }
        var bits = [];
        if (note.nickname) { bits.push('“' + note.nickname + '”'); }
        if (note.pronouns) { bits.push(note.pronouns); }
        if (note.pronunciation) { bits.push('say ' + note.pronunciation); }
        return bits.join(' · ');
    }

    /**
     * Whether a declared pronoun set deserves visual emphasis.
     *
     * Most rosters are overwhelmingly he/him and she/her, and a commentator's
     * habit serves those correctly without looking. The cost of a lapse
     * concentrates entirely on everyone else — so those are the entries that
     * must not be skimmed past. Emphasis is keyed on the declared string alone:
     * nothing is inferred, and a player who declared a common set still shows
     * it, un-emphasised, so absence keeps meaning "not declared" (§5).
     *
     * This does single people out, and that is acceptable here and only here:
     * the page is a private working surface behind the room code, never on air,
     * and getting a person's pronouns right outranks a uniform-looking list.
     */
    function emphasizedPronouns(value) {
        var n = String(value || '').toLowerCase().replace(/\s+/g, '');
        return n !== '' && n !== 'he/him' && n !== 'she/her';
    }

    /**
     * Fill a container with the identity line, emphasising what must not be
     * skimmed. `opts.matching` opts the competition designation in — it rides
     * the same line but only where it means something, a mixed division.
     */
    function sayInto(node, note, opts) {
        node.replaceChildren();
        var add = function (text, cls) {
            if (node.childNodes.length) { node.append(document.createTextNode(' · ')); }
            node.append(el('span', cls, text));
        };
        if (opts && opts.matching && note.matching) {
            add(note.matching, 'mt ' + note.matching.toLowerCase());
        }
        if (note.nickname) { add('“' + note.nickname + '”', null); }
        if (note.pronouns) {
            add(note.pronouns, emphasizedPronouns(note.pronouns) ? 'hi' : null);
        }
        if (note.pronunciation) { add('say ' + note.pronunciation, null); }
    }

    /** Is there anything for sayInto() to draw? */
    function hasSayLine(note, opts) {
        return !!(sayLine(note) || (opts && opts.matching && note && note.matching));
    }

    /**
     * Stamp the identity line into a roster cell, in place — same reasoning as
     * markNote(): re-rendering the list to add one label would throw the reader
     * back to the top of a scrolled panel.
     */
    function markSay(cell, note) {
        var existing = cell.querySelector('.say');
        var opts = { matching: cell.getAttribute('data-mixed') === '1' };
        if (note && hasSayLine(note, opts)) {
            if (!existing) {
                existing = el('span', 'say');
                cell.append(existing);
            }
            sayInto(existing, note, opts);
        } else if (existing) {
            existing.remove();
        }
    }

    /**
     * Stamp one roster button with whether that player has a note.
     *
     * Presence only, never a preview: this is scanned down a column of 28 rows
     * and the name is what the eye is there for. The dot is decorative and says
     * so; the meaning is carried in text as well, because colour and shape reach
     * neither a screen reader nor a reader who cannot separate the two hues.
     */
    function markNote(btn, note) {
        // The dot means talking points. An entry holding only the structured
        // fields shows those beside the name instead, so it earns no dot.
        note = note && note.text ? note : null;
        var existing = btn.querySelector('.dot');
        var spoken = btn.querySelector('.sr-only');
        btn.title = note ? 'Details · has talking points' : 'Details';
        if (note && !existing) {
            var dot = el('span', 'dot', '●');
            dot.setAttribute('aria-hidden', 'true');
            btn.append(dot);
            btn.append(el('span', 'sr-only', ' — has talking points'));
        } else if (!note && existing) {
            existing.remove();
            if (spoken) { spoken.remove(); }
        }
    }

    /**
     * Bring every visible marker up to date, in place.
     *
     * Deliberately not a re-render. The roster scrolls inside its panel and a
     * 28-player squad is taller than the panel, so rebuilding it to add one dot
     * would throw the reader back to the top of the list — and the moment this
     * runs is exactly when somebody has just finished writing a note and is
     * looking at the row they wrote it for.
     */
    function syncNoteMarkers() {
        var buttons = document.querySelectorAll('.roster td.who button[data-player]');
        Array.prototype.forEach.call(buttons, function (btn) {
            var note = noteFor(btn.getAttribute('data-player'));
            markNote(btn, note);
            markSay(btn.parentNode, note);
        });
    }

    function pullNotes() {
        if (!syncCode) { return; }
        // A local edit wins for a moment, so a poll in flight cannot pull the
        // text out from under somebody who has just typed it.
        if (Date.now() - lastNoteWrite < 2500) { return; }

        fetch(CONFIG.notesUrl + '&code=' + encodeURIComponent(syncCode),
            { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (b) {
                if (!b || !b.players) { return; }
                notes = b.players;
                teamNotes = b.teams || {};
                syncNoteMarkers();
                prchecks.forEach(function (fill) { fill(); });
            });
    }

    /**
     * Save one player's note.
     *
     * Best-effort, like the line push: sharing failing must not cost the
     * commentator the text in front of them. `onDone` reports which way it went
     * so the sheet can say so rather than leaving someone guessing whether their
     * partner will see it.
     */
    function pushNote(playerId, entry, onDone) {
        if (!syncCode || !playerId) { return; }
        lastNoteWrite = Date.now();
        var key = String(playerId);
        var clean = { text: String(entry.text || '').trim() };
        ['nickname', 'pronouns', 'pronunciation', 'matching'].forEach(function (k) {
            clean[k] = String(entry[k] || '').trim();
        });
        clean.matching = clean.matching.toUpperCase();
        if (clean.matching !== 'FMP' && clean.matching !== 'MMP') { clean.matching = ''; }
        clean.pronounsok = entry.pronounsok === true && clean.pronouns !== '';
        var anything = clean.text || clean.nickname || clean.pronouns
            || clean.pronunciation || clean.matching;

        // Reflect it locally first. The roster marker and a sheet reopened
        // straight away should agree with what was just typed, whatever the
        // network is doing.
        if (anything) {
            notes[key] = {
                text: clean.text, nickname: clean.nickname,
                pronouns: clean.pronouns, pronunciation: clean.pronunciation,
                matching: clean.matching, pronounsok: clean.pronounsok,
                by: myName, at: Math.floor(Date.now() / 1000)
            };
        } else {
            delete notes[key];
        }
        // Immediately, not on the next poll. The marker is the only sign from the
        // roster that a note exists, and a commentator who has just written one
        // and closed the sheet should not have to wonder for fifteen seconds
        // whether it took.
        syncNoteMarkers();

        fetch(CONFIG.notesUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                code: syncCode, player: Number(playerId),
                text: clean.text, nickname: clean.nickname,
                pronouns: clean.pronouns, pronunciation: clean.pronunciation,
                matching: clean.matching, pronounsok: clean.pronounsok,
                by: myName
            })
        })
            .then(window.Tracking.readJson)
            .then(function (b) {
                if (b && b.players) { notes = b.players; teamNotes = b.teams || teamNotes; }
                if (onDone) { onDone(true); }
            })
            .catch(function () { if (onDone) { onDone(false); } });
    }

    /* ---------------------------------------------------------------
       The bio round trip

           export CSV (identifier + name, empty prompt columns)
             -> the team puts it in a shared sheet
             -> each player fills in their own row
             -> export CSV
             -> import here

       The value of the round trip is that the PLAYER writes their own entry:
       self-declared rather than second-hand (§5), and a real chance to adapt or
       remove it before the game. The decisions live in shared/bios.js, tested
       directly, because "which player does this row name" is a question asked
       about a file an entire team could edit.
       --------------------------------------------------------------- */

    function bioTeam(side) {
        return { id: side.team.team_id, name: side.team.name || '' };
    }

    function bioFileName(side) {
        var name = (side.team.name || 'team').toLowerCase()
            .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        // The team id in the name for the same reason it is in the rows: the
        // file gets forwarded around, and its name should say whose it is.
        var id = side.team.team_id !== null && side.team.team_id !== undefined
            ? '-' + side.team.team_id : '';
        return (name || 'team') + id + '-bios.csv';
    }

    function exportBios(side) {
        var rows = window.Bios.exportRows(rosterByNumber(side), bioTeam(side));
        var text = window.Csv.stringify(window.Bios.exportHeaders(), rows);
        // A Blob and a temporary link: the file is built here and never goes near
        // the server, which is also why this works with the network down.
        var blob = new Blob([text], { type: 'text/csv;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = bioFileName(side);
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 0);
    }

    /** What this desk has already written, one full entry per player id. */
    function notesForImport() {
        var out = {};
        Object.keys(notes).forEach(function (k) {
            out[k] = {
                text: notes[k].text || '',
                nickname: notes[k].nickname || '',
                pronouns: notes[k].pronouns || '',
                pronunciation: notes[k].pronunciation || '',
                matching: notes[k].matching || ''
            };
        });
        return out;
    }

    /**
     * Matching is shown only where it means something — a mixed division
     * (§10.5). Both teams share the game's division, and the game payload's
     * team objects carry no `type`, so this is the page's one mixed test
     * (isMixedDivision, §6b) asked per side for the call sites that think in
     * sides.
     */
    function isMixedSide(side) {
        return isMixedDivision();
    }

    function importBios(side, input) {
        var file = input.files && input.files[0];
        if (!file) { return; }
        var reader = new FileReader();
        reader.onerror = function () {
            input.value = '';
            openImportPreview(side, { fatal: 'That file could not be read.' });
        };
        reader.onload = function () {
            // Cleared so that picking the SAME file again fires another change
            // event -- otherwise a reader who fixes their spreadsheet and
            // re-exports it appears to have a dead button.
            input.value = '';
            var parsed = window.Csv.parse(String(reader.result || ''));
            var existing = notesForImport();
            existing[window.Bios.TEAM_ROW_ID] = { text: teamNoteText(side) };
            var report = window.Bios.match(parsed, rosterByNumber(side), existing, bioTeam(side));
            openImportPreview(side, report);
        };
        // UTF-8, which is what every spreadsheet exports and what the BOM in our
        // own export declares.
        reader.readAsText(file);
    }

    /**
     * Show what the file would do, and only then offer to do it.
     *
     * The failure this exists for is a squad list imported against the wrong
     * team: every row would carry a real name and a real-looking biography, and
     * the first sign of trouble would be a commentator reading one out. A tally
     * makes it obvious in the moment.
     */
    function openImportPreview(side, report) {
        var card = document.getElementById('sheetCard');
        card.replaceChildren();

        var title = el('h3', null, 'Import for ' + (side.team.name || 'this team'));
        title.id = 'sheetName';
        card.append(title);

        if (report.fatal) {
            card.append(el('p', 'err', report.fatal));
            // The commonest way to pick the wrong file is to pick the OTHER
            // team's, so when that is what happened, say where it goes.
            if (report.wrongTeam) {
                sides().forEach(function (s) {
                    if (String(s.team.team_id) === String(report.wrongTeam.id)
                        && String(s.team.team_id) !== String(side.team.team_id)) {
                        card.append(el('p', 'muted',
                            'That is the sheet for ' + (s.team.name || 'the other team')
                            + ' — use the Import button on their panel.'));
                    }
                });
            }
        } else {
            var columnsRead = Object.keys(report.fieldHeaders || {}).map(function (k) {
                return report.fieldHeaders[k];
            }).concat(report.contentHeaders);
            card.append(el('div', 'sub',
                report.total + (report.total === 1 ? ' row' : ' rows') + ' read'
                + (columnsRead.length ? ' · ' + columnsRead.join(' · ') : '')));

            var tally = el('div', 'tally');
            var line = function (n, label, cls) {
                tally.append(el('div', 'n' + (cls ? ' ' + cls : ''), String(n)));
                tally.append(el('div', null, label));
            };
            line(report.accepted.length, 'to import', report.accepted.length ? 'yes' : '');
            if (report.kept.length) {
                line(report.kept.length, 'already written here, kept');
            }
            if (report.empty) { line(report.empty, 'blank in the file, nothing to import'); }
            if (report.rejected.length) {
                line(report.rejected.length, 'not imported', 'no');
            }
            card.append(tally);

            if (report.rejected.length) {
                card.append(el('h4', null, 'Not imported'));
                var list = el('div', 'rejects');
                report.rejected.forEach(function (r) {
                    var row = el('div');
                    row.append(el('span', 'ln', 'row ' + r.line));
                    row.append(el('span', null, r.name || '(no name)'));
                    row.append(el('span', 'why', r.why));
                    list.append(row);
                });
                card.append(list);
                card.append(el('p', 'muted',
                    'A row is refused when its identifier is not on this roster, or when '
                    + 'the name beside it does not match. Both mean the row cannot safely '
                    + 'say who it is about.'));
            }
        }

        var foot = el('footer');
        var close = el('button', 'barbtn', report.fatal ? 'Close' : 'Cancel');
        close.type = 'button';
        close.addEventListener('click', closeSheet);

        if (!report.fatal && report.accepted.length) {
            var apply = el('button', 'barbtn primary',
                'Import ' + report.accepted.length);
            apply.type = 'button';
            apply.addEventListener('click', function () {
                apply.disabled = true;
                apply.textContent = 'Importing…';
                applyImport(side, report.accepted, function (ok, written) {
                    if (!ok) {
                        apply.disabled = false;
                        apply.textContent = 'Retry';
                        card.append(el('p', 'err', 'Nothing was imported — the room '
                            + 'could not be written.'));
                        return;
                    }
                    closeSheet();
                    flashCount(written);
                });
            });
            foot.append(apply);
        }
        foot.append(close);
        card.append(foot);

        sheet.classList.add('open');
        lastFocus = document.activeElement;
        close.focus();
    }

    /**
     * One request for the whole import.
     *
     * Not a loop of single saves: the store throttles repeated writes to a room,
     * which is right for debounced typing and would silently discard most of a
     * twenty-eight player import while reporting success.
     */
    function applyImport(side, accepted, done) {
        var teamEntry = null;
        var players = [];
        accepted.forEach(function (a) {
            if (a.player === window.Bios.TEAM_ROW_ID) {
                teamEntry = a;
                return;
            }
            var entry = { player: a.player, text: a.text };
            Object.keys(a.fields || {}).forEach(function (k) {
                entry[k] = a.fields[k];
            });
            players.push(entry);
        });
        fetch(CONFIG.notesUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                code: syncCode,
                by: myName,
                players: players,
                teamnote: teamEntry
                    ? { team: Number(side.team.team_id), text: teamEntry.text }
                    : undefined
            })
        })
            .then(window.Tracking.readJson)
            .then(function (b) {
                if (b && b.players) { notes = b.players; teamNotes = b.teams || teamNotes; }
                syncNoteMarkers();
                prchecks.forEach(function (fill) { fill(); });
                done(true, (b && b.written) || 0);
            })
            .catch(function () { done(false, 0); });
    }

    function flashMessage(text) {
        var box = document.getElementById('importFlash');
        if (!box) { return; }
        box.textContent = text;
        box.className = 'flashline on';
        window.setTimeout(function () { box.className = 'flashline'; }, 6000);
    }

    function flashCount(written) {
        flashMessage(written + (written === 1 ? ' note imported.' : ' notes imported.'));
    }

    /**
     * The same table, straight onto the clipboard for a Google Sheet.
     *
     * The 20% of the export flow that was friction: download a CSV, open Sheets,
     * import the file. This is the 80% shortcut — copy as TSV, open a fresh
     * sheet, paste — with no OAuth, no per-installation Google project, and no
     * third-party script on an operator page. A full Drive integration was
     * considered and declined for exactly those costs, and a fetch-by-URL
     * import was declined because it would push teams toward making a document
     * full of player personal data link-public (`docs/COMMENTATOR.md` §5a).
     */
    function copyBios(side) {
        var rows = window.Bios.exportRows(rosterByNumber(side), bioTeam(side));
        var text = window.Csv.stringify(window.Bios.exportHeaders(), rows,
            { delimiter: '\t', bom: false }).replace(/\r\n$/, '');
        if (!navigator.clipboard || !navigator.clipboard.writeText) {
            flashMessage('This browser cannot copy from a script — use Export CSV.');
            return;
        }
        // Copy FIRST, open the tab from the fulfilment. The other order fails
        // in practice: window.open moves focus to the new tab, and the async
        // clipboard API refuses to write from a document that is no longer
        // focused — a fresh sheet and an empty clipboard. The click's transient
        // activation comfortably survives the write, so the popup is not
        // blocked from the callback.
        navigator.clipboard.writeText(text).then(function () {
            // The create endpoint sheets.new redirects to. A ?title= parameter
            // was tried and is ignored there (measured, not assumed), so the
            // sheet arrives untitled — the pasted Team column says whose it is,
            // and naming it stays with the team. No third window.open argument:
            // ANY features string demotes the tab to a small popup window, and
            // "noopener" there is a feature, not a rel. The opener is cut by
            // hand instead.
            var tab = window.open('https://docs.google.com/spreadsheets/create', '_blank');
            if (tab) { tab.opener = null; }
            flashMessage('Copied. Paste into the new sheet (Ctrl+V or Cmd+V), then share it with the team.');
        }, function () {
            flashMessage('Could not copy — use Export CSV instead.');
        });
    }

    function bioBar(side) {
        var bar = el('div', 'biobar');

        var out = el('button', 'barbtn', 'Export CSV');
        out.type = 'button';
        out.title = 'One row per player, with an identifier and empty columns to fill in. '
            + 'Share it with the team and let the players write their own.';
        out.addEventListener('click', function () { exportBios(side); });
        bar.append(out);

        var copy = el('button', 'barbtn', 'Copy for Sheets');
        copy.type = 'button';
        copy.title = 'Copy the same table and open a fresh Google Sheet — paste it there, '
            + 'no download needed.';
        copy.addEventListener('click', function () { copyBios(side); });
        bar.append(copy);

        // A visually-hidden file input with a label styled as a button: the input
        // stays keyboard-reachable and announced, which a div-plus-click is not.
        var id = 'bioimport-' + side.key;
        var input = document.createElement('input');
        input.type = 'file';
        input.id = id;
        input.accept = '.csv,text/csv';
        input.addEventListener('change', function () { importBios(side, input); });

        var label = el('label', 'barbtn', 'Import CSV');
        label.setAttribute('for', id);
        label.title = 'Read the filled-in export back in. Nothing is written until '
            + 'you have seen what it would do.';

        bar.append(input, label);
        bar.append(el('span', 'muted', 'players fill it in; import fills only what is empty here'));
        return bar;
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
            .then(window.Tracking.readJson)
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

    /**
     * The talking-points box for one player.
     *
     * Placed ABOVE the statistics, which is the opposite of how the sheet was
     * built and deliberate. Before a game the numbers are mostly zeros and this
     * is the only reason to open a player at all; during a game a commentator is
     * reading the roster row, not this dialog. So the thing the sheet is opened
     * for goes at the top.
     *
     * Saved on a pause in typing rather than on a button, because there is no
     * good failure mode for a Save button here: a commentator who has typed a
     * paragraph and closes the sheet has lost it, and they will not find out
     * until the moment they wanted to read it out.
     */
    function noteBox(p, side) {
        var box = el('div', 'note');
        var existing = noteFor(p.id);

        box.append(el('h4', null, 'Talking points'));

        // The structured fields: read at a glance beside the name, so they get
        // their own inputs rather than a line inside the note. Usually they
        // arrive through the CSV round trip — a player's own row is the
        // self-declaration pronouns require — but a wrong entry must be
        // correctable at the desk in seconds, so they are editable here too.
        // Matching only offers itself in a mixed division, where it means
        // something (§10.5).
        var fieldsRow = el('div', 'fields');
        var fieldInputs = {};
        [
            { key: 'nickname', label: 'Nickname', hint: 'as the player uses it' },
            { key: 'pronouns', label: 'Pronouns', hint: 'as the player declared — never guessed' },
            { key: 'pronunciation', label: 'Say it', hint: 'how to say the name' }
        ].concat(isMixedSide(side)
            ? [{ key: 'matching', label: 'Matching', hint: 'FMP or MMP' }]
            : []).forEach(function (f) {
            var wrap = el('label', 'field');
            wrap.append(el('span', null, f.label));
            var input = document.createElement('input');
            input.type = 'text';
            input.maxLength = CONFIG.fieldMax;
            input.placeholder = f.hint;
            input.value = existing && existing[f.key] ? existing[f.key] : '';
            input.setAttribute('aria-label', f.label + ' for ' + p.name);
            wrap.append(input);
            fieldInputs[f.key] = input;
            fieldsRow.append(wrap);
        });
        box.append(fieldsRow);

        var area = document.createElement('textarea');
        area.value = existing ? existing.text : '';
        area.maxLength = CONFIG.noteMax;
        area.placeholder = 'What the team told you about this player — background, '
            + 'other sports, how they came to ultimate, anything worth saying on air.';
        area.setAttribute('aria-label', 'Prepared talking points for ' + p.name);
        box.append(area);

        var meta = el('div', 'meta');
        var who = el('span', 'by');
        var stateLabel = el('span', 'state');
        meta.append(who, stateLabel);
        box.append(meta);

        /**
         * Say who wrote it and when.
         *
         * These are somebody else's words about a named person, and a
         * commentator about to repeat them on air should be able to tell whether
         * they came from their partner an hour ago or from themselves last week.
         * The same provenance argument section 5a makes for the upstream fields,
         * applied to a note that has no verification path whatever.
         */
        function renderMeta() {
            var n = noteFor(p.id);
            if (!n) {
                // Say both things up front. Somebody typing notes about a named
                // player should know who can read them and that they do not last
                // — an expiry nobody was told about is indistinguishable from
                // losing data.
                who.textContent = 'Shared with anyone holding the room code. '
                    + 'Deleted ' + CONFIG.noteDays + ' days after the last edit.';
                return;
            }
            var when = n.at ? new Date(n.at * 1000) : null;
            who.textContent = 'Written by ' + (n.by || 'someone on this code')
                + (when ? ' · ' + when.toLocaleDateString() + ' '
                    + when.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                    : '');
        }
        renderMeta();

        var timer = null;
        var pending = false;

        function flush() {
            if (!pending) { return; }
            pending = false;
            if (timer) { window.clearTimeout(timer); timer = null; }
            stateLabel.className = 'state';
            stateLabel.textContent = 'Saving…';
            // The whole entry in one write, so the box and the store cannot
            // disagree about which channels exist. The review marker survives
            // only while the pronouns it reviewed stand unedited.
            var cur = noteFor(p.id);
            pushNote(p.id, {
                text: area.value,
                nickname: fieldInputs.nickname.value,
                pronouns: fieldInputs.pronouns.value,
                pronunciation: fieldInputs.pronunciation.value,
                // Without an input on this sheet, the stored value rides along
                // unchanged rather than being cleared by a save.
                matching: fieldInputs.matching
                    ? fieldInputs.matching.value
                    : (cur && cur.matching) || '',
                pronounsok: !!(cur && cur.pronounsok
                    && fieldInputs.pronouns.value.trim() === (cur.pronouns || ''))
            }, function (ok) {
                stateLabel.className = 'state ' + (ok ? 'saved' : 'failed');
                stateLabel.textContent = ok ? 'Saved' : 'Not shared — kept on this screen';
                renderMeta();
            });
        }

        function onEdit() {
            pending = true;
            stateLabel.className = 'state';
            stateLabel.textContent = 'Unsaved';
            if (timer) { window.clearTimeout(timer); }
            timer = window.setTimeout(flush, 900);
        }
        area.addEventListener('input', onEdit);
        // Blur and close are both flushes rather than saves in their own right,
        // so a fast typist who closes the sheet mid-pause still keeps the text.
        area.addEventListener('blur', flush);
        Object.keys(fieldInputs).forEach(function (k) {
            fieldInputs[k].addEventListener('input', onEdit);
            fieldInputs[k].addEventListener('blur', flush);
        });
        box.flushNote = flush;
        return box;
    }

    function openSheet(p, side) {
        var card = document.getElementById('sheetCard');
        card.replaceChildren();
        var name = el('h3', null, (p.num !== null && p.num !== undefined ? '#' + p.num + '  ' : '') + p.name);
        name.id = 'sheetName';
        card.append(name);
        card.append(el('div', 'sub',
            [side.team.name, p.division].filter(Boolean).join(' · ')));
        // Nickname, pronouns, pronunciation — and, in mixed, the matching —
        // under the name, where somebody about to say it looks. Rendered only
        // when something is declared.
        var sayNote = noteFor(p.id);
        var sayOpts = { matching: isMixedSide(side) };
        if (sayNote && hasSayLine(sayNote, sayOpts)) {
            var idLine = el('div', 'sub say');
            sayInto(idLine, sayNote, sayOpts);
            card.append(idLine);
        }

        card.append(noteBox(p, side));

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
        // Closing is the last chance to keep what was typed. A commentator who
        // types a paragraph and hits Escape mid-pause must not lose it, and the
        // 900ms debounce means there is very often something still pending.
        var box = sheet.querySelector('.note');
        if (box && box.flushNote) { box.flushNote(); }
        sheet.classList.remove('open');
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        lastFocus = null;
    }

    // Keep Tab inside the dialog while it is open. Without this, tabbing walks
    // out into the roster behind it, which is still visible but not reachable.
    //
    // The textarea has to be in this list, not just in the DOM. It is focusable
    // either way, but the trap sends focus back to `first` on leaving `last`, so
    // anything the selector misses becomes unreachable by keyboard entirely —
    // which for the notes box would mean the one part of this dialog anybody
    // types into could only be reached with a mouse.
    sheet.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') { return; }
        var focusable = sheet.querySelectorAll('a[href], button:not([disabled]), textarea');
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
    function abbaSlot(n) { return window.Ratio.slot(n); }

    /**
     * The two ratios in play, by season type.
     *
     * Indoor and beach are five a side, outdoor seven — same source as the
     * scoresheet (`pdfscoresheet.php:461-471`).
     */
    function ratioPair() {
        return window.Ratio.pair((state.payload && state.payload.seasoninfo
            && state.payload.seasoninfo.type) || 'outdoor');
    }

    /**
     * "4MMP/3FMP" said in one word: "4MMP".
     *
     * The majority side names the ratio on its own — a seven-a-side line with
     * four MMP has three FMP and there is nothing else it could be — so printing
     * both halves spends characters on something already known. Works for the
     * five-a-side ratios too: "3MMP" is 3MMP/2FMP.
     */
    function shortRatio(full) { return window.Ratio.short(full); }

    /** Mixed is decided by the division name, exactly as the scoresheet decides it. */
    function isMixedDivision() {
        return window.Ratio.isMixed(state.payload && state.payload.game_info
            && state.payload.game_info.seriesname);
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
     *
     * BUT an unlinked desk may still declare it — the ratio is read off the
     * paper scoresheet, and a desk that never links should not lose the ABBA
     * panel and the line picker over it. That declaration lives in THIS
     * browser only, clearly labelled so, and the shared value is the
     * authority: the moment one exists it wins, the local one is dropped, and
     * the takeover is said once rather than silently reshuffling the panel.
     * A local value is never pushed into the room by anything but a person's
     * own click — an unshared guess must not reach the stage as a side effect
     * of linking.
     */
    var RATIO_KEY = 'uo-ratio1-' + CONFIG.gameId;
    /** One-shot note after the shared ratio replaced a local one. */
    var ratioNote = null;

    function localRatioValue() {
        try {
            var v = window.localStorage.getItem(RATIO_KEY) || '';
            return ratioPair().indexOf(v) !== -1 ? v : null;
        } catch (e) { return null; }
    }

    function rememberLocalRatio(v) {
        try {
            if (v) { window.localStorage.setItem(RATIO_KEY, v); }
            else { window.localStorage.removeItem(RATIO_KEY); }
        } catch (e) { /* private mode */ }
    }

    /** Shared wins; local only ever fills a gap. Run before every render. */
    function reconcileRatio() {
        var local = localRatioValue();
        if (!local || !possession.ratio1) { return; }
        if (possession.ratio1 !== local) {
            ratioNote = { local: local, shared: possession.ratio1 };
        }
        rememberLocalRatio(null);
    }

    function firstRatioValue() { return possession.ratio1 || localRatioValue() || null; }

    function firstRatioIsLocal() { return !possession.ratio1 && !!localRatioValue(); }

    /** This point's full ratio ('4MMP/3FMP'), or null before anyone declared point 1's. */
    function currentRatioFull() {
        var first = firstRatioValue();
        if (!isMixedDivision() || !first) { return null; }
        var pair = ratioPair();
        var other = pair[0] === first ? pair[1] : pair[0];
        return abbaSlot(currentPointNumber()) === 'A' ? first : other;
    }

    function setFirstRatio(v) {
        if (!possession.canTrack) {
            rememberLocalRatio(v);
            render();
            return;
        }
        track.setRatio(v)
            .then(function (state) {
                possession = state;
                rememberLocalRatio(null);
                render();
            })
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

    // One client for the whole page: the protocol lives in shared/tracking.js.
    var track = window.Tracking.client({
        endpoint: CONFIG.possessionUrl,
        game: CONFIG.gameId,
        code: function () { return syncCode; },
        clientId: clientId,
        name: function () { return myName; }
    });

    function pollPossession() {
        track.read()
            .then(function (state) {
                if (!state || state.rev === possession.rev) { return; }
                possession = state;
                render();
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
        track.setStoppage(!stoppageOn(), sc.key)
            .then(function (state) { possession = state; render(); })
            .catch(function () { /* transient */ });
    }

    /** Apply a correction, then redraw the log so the result is visible. */
    function correct(what, forScore) {
        if (!possession.canTrack) { return; }
        var sc = forScore || liveScore();
        if (!sc) { return; }
        var call = what.clearPoint ? track.clearPoint(sc.key)
            : (what.at !== undefined ? track.deleteAt(sc.key, what.at)
                : track.undoLast(sc.key));
        call.then(function (state) {
            possession = state;
            render();
            // Redraw the log underneath, so the correction is seen landing.
            if (sheet.classList.contains('open')) { openLog(); }
        }).catch(function () { /* transient */ });
    }

    function setDefence(hasDisc) {
        if (!possession.canTrack) { return; }
        var sc = liveScore();
        if (!sc) { return; }
        track.setDefence(hasDisc, sc.key)
            .then(function (state) { possession = state; render(); })
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
     *
     * PER GAME, and a new game gets a NEW random code. An earlier revision had a
     * game with no code of its own inherit the last code this browser used, so
     * that prepared notes — which are keyed by the code alone — would follow a
     * desk from one round to the next. That was the wrong trade, for a reason
     * that has nothing to do with notes: this same code is the possession-write
     * credential (see the Tracking client above), and possession reaches air.
     * Making it sticky across fixtures means a desk nominated to write possession
     * on one game arrives at the next already holding the code, so a convenience
     * for a private reference panel had quietly widened what can change a
     * broadcast. The gate should match the consequence, and here two different
     * consequences ride one value.
     *
     * Carrying notes between games is therefore a deliberate act: type the code
     * you used before. Explicitly re-entering it is the same gesture as sharing
     * it with a partner, and it cannot happen by accident.
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
        // Name first: it identifies this desk, and the code links it. Both are
        // setup, so both live in the header rather than inside a mode — the name
        // was in the play-by-play panel, which is not where anyone looks for it
        // and not visible while preparing.
        var name = document.createElement('input');
        name.className = 'nameinput';
        name.type = 'text';
        name.maxLength = 24;
        name.value = myName;
        name.placeholder = 'your name';
        name.id = 'commentatorName';
        name.title = 'Shown to the Studio operator so they know which desk is on this code.';
        name.setAttribute('aria-label', 'Your name, shown to the operator');
        name.addEventListener('change', function () { setMyName(name.value); });
        box.append(name);

        box.append(el('span', 'muted', 'Sync'));

        var input = document.createElement('input');
        input.className = 'code';
        input.value = syncCode;
        input.size = CONFIG.codeLength;
        input.maxLength = CONFIG.codeLength;
        input.spellcheck = false;
        input.autocapitalize = 'characters';
        input.title = 'Share this code with the other commentator, or type theirs. '
            + 'It is what protects the line selection and the prepared notes.';
        input.setAttribute('aria-label', 'Sync code');
        input.addEventListener('change', function () {
            var v = input.value.trim().toUpperCase();
            if (v.length !== CONFIG.codeLength) { input.value = syncCode; return; }
            syncCode = v;
            rememberCode(v);
            input.value = v;
            state.line = {};       // a different room is a different selection
            notes = {};            // ... and a different set of prepared notes
            teamNotes = {};
            pullLines();
            pullNotes();
            render();
        });
        box.append(input);

        // Masked by default. A commentary booth is one of the most-walked-past
        // screens at a tournament, and this code is what protects the line
        // selection and the prepared notes — which are notes about named
        // players. A five-character code on permanent display is not a namespace
        // nobody can guess; it is a published one.
        var peek = el('button', 'barbtn peek');
        window.Secret.guard(input, peek, { label: 'sync code' });
        box.append(peek);
    }

    function pickPanel(side) {
        var panel = el('div', 'panel');
        var list = rosterByNumber(side);
        var line = lineFor(side);
        var mixed = isMixedSide(side);

        // With matchings and this point's ratio in hand, the header counts
        // each matching against its quota, tighter quota first — "MMP 2 of 3"
        // is the question a mixed line answers seven times.
        var assist = mixed && window.Lineup
            ? window.Lineup.groups(
                list.map(function (p) {
                    var n = noteFor(p.id);
                    return { id: p.id, matching: n && n.matching };
                }),
                window.Ratio.counts(currentRatioFull()),
                line)
            : null;

        var head = el('div', 'pickhead');
        head.append(el('strong', null, side.team.name || '—'));
        var count = el('span', 'count' + (line.length === 7 ? ' ok' : (line.length > 7 ? ' over' : '')),
            line.length + ' / 7');
        head.append(count);
        if (assist) {
            assist.groups.forEach(function (g) {
                var gc = el('span', 'gcount');
                gc.append(el('span', 'mt ' + g.matching.toLowerCase(), g.matching));
                gc.append(document.createTextNode(' ' + g.picked + ' of ' + g.quota));
                head.append(gc);
            });
        }
        panel.append(head);

        if (!list.length) {
            panel.append(el('p', 'muted', 'No roster available.'));
            return panel;
        }

        function chip(p) {
            var n = noteFor(p.id);
            var matching = mixed && n && n.matching ? n.matching : '';
            // The matching is the chip's own tint rather than a label — the
            // picker is clicked, not read, and the grouping order plus the
            // title carry the meaning for anyone the tint does not reach.
            var cls = (line.indexOf(p.id) !== -1 ? 'on' : '')
                + (matching ? ' ' + matching.toLowerCase() : '');
            var b = el('button', cls.trim());
            b.type = 'button';
            b.append(document.createTextNode(
                p.num === null || p.num === undefined ? '–' : String(p.num)));
            // Surname under the number: the number is what is on the shirt, the
            // name is what gets said.
            b.append(el('small', null, p.name.split(' ').slice(-1)[0]));
            b.title = p.name + (matching ? ' — ' + matching : '');
            b.addEventListener('click', function () { toggleNum(side, p.id); });
            return b;
        }

        var nums = el('div', 'nums');
        panel.append(nums);

        // The chips order by matching — the tighter quota's players first, so
        // the short list is clicked first — and a group whose quota is exactly
        // filled hides its unpicked players: they could only make the line
        // illegal, and unpicking from the group brings them back. Players
        // without matching data always list last and are never hidden. The
        // decisions live in shared/lineup.js.
        if (!assist) {
            list.forEach(function (p) { nums.append(chip(p)); });
            return panel;
        }

        var byId = {};
        list.forEach(function (p) { byId[String(p.id)] = p; });
        assist.groups.forEach(function (g) {
            g.players.forEach(function (gp) {
                var p = byId[String(gp.id)];
                if (!p) { return; }
                if (g.full && line.indexOf(p.id) === -1) { return; }
                nums.append(chip(p));
            });
        });
        assist.unknown.forEach(function (gp) {
            var p = byId[String(gp.id)];
            if (p) { nums.append(chip(p)); }
        });
        return panel;
    }

    /** The on-field players of one side, in the order the panel shows them. */
    function fieldOrder(side) {
        var byId = {};
        rosterByNumber(side).forEach(function (p) { byId[p.id] = p; });
        return lineFor(side).map(function (id) { return byId[id]; }).filter(Boolean)
            .sort(function (a, b) { return (a.num || 0) - (b.num || 0); });
    }

    function onFieldPanel(side, cls) {
        var wrap = el('div', 'side' + (cls ? ' ' + cls : ''));
        var rowIdx = cls === 'away' ? 1 : 0;
        var mixed = isMixedSide(side);
        wrap.append(el('h3', null, side.team.name || '—'));

        if (!lineFor(side).length) {
            wrap.append(el('div', 'empty', 'Nobody selected — pick a line first.'));
            return wrap;
        }
        var row = el('div', 'line');
        fieldOrder(side)
            .forEach(function (p, idx) {
                // A button, because a click pins the same quick card that typing
                // the shirt number does. The number on the chip is the key hint.
                var d = el('button', 'p');
                d.type = 'button';
                d.addEventListener('click', function () { toggleQuickPlayer(rowIdx, p); });
                if (p.num !== null && p.num !== undefined) { d.append(el('span', null, p.num)); }
                d.append(document.createTextNode(p.name));
                // Pronouns ride along here because this panel is exactly where a
                // name is about to be said — with the less common sets
                // emphasised, since those are the ones a reading habit gets
                // wrong. The rest of the identity line (nickname, how to say the
                // name) is hover text: prepared before the game, available
                // mid-point without spending any scan width.
                var n = noteFor(p.id);
                if (n && n.pronouns) {
                    d.append(el('span',
                        emphasizedPronouns(n.pronouns) ? 'pr hi' : 'pr', n.pronouns));
                }
                // The matching as a tinted tag — the term is the signal, the
                // tint only makes a line scannable, and the pair is neither of
                // the stereotyped colours.
                if (mixed && n && n.matching) {
                    d.append(el('span', 'mt ' + n.matching.toLowerCase(), n.matching));
                }
                var full = sayLine(n);
                if (mixed && n && n.matching) {
                    full = n.matching + (full ? ' · ' + full : '');
                }
                if (full) { d.title = full; }
                row.append(d);
            });
        wrap.append(row);
        return wrap;
    }

    /* ---------------------------------------------------------------
       Quick cards: type the shirt number

       A commentator mid-point has no roster in view and no time for a dialog,
       so the card is summoned by the thing already in their head: the number
       on the shirt. Typing it pins a compact card (identity line, scoring, the
       prepared note) into a fixed region under the field panels; clicking a
       player's chip does the same for a mouse hand. A second card sits beside
       the first for a matchup, a third replaces the oldest, and Escape clears
       — a pending number first, then the cards.

       An earlier version mapped one key per on-field POSITION (digits for the
       top team, a letter row for the bottom). It was a single blind keypress
       and it was wrong: the keys did not mean jersey numbers, so #5 scoring
       invited pressing 5 and pinning whoever stood fifth in the line, and the
       mapping reshuffled with every line change so no muscle memory could
       form. Number entry types what the shirt says — nothing has to be found
       on the panel first, and practice compounds, because memorising jersey
       numbers is the job anyway. Retiring the letter row also dissolved the
       keyboard-layout question: the digit row is the same physical row
       everywhere, matched on `e.code`.

       A digit commits the moment the typed string cannot be extended into
       another on-field number, so with at most 14 players the double-digit
       pause almost never fires; Enter commits early, Backspace edits, and a
       number nobody on the field wears evaporates. A number BOTH teams field
       pins both players, labelled — a number resolves per team (§5), and the
       two-slot region is exactly the size of that ambiguity, so it is shown
       rather than guessed.
       --------------------------------------------------------------- */

    /** Up to two of {sideIndex, playerId}, oldest first. */
    var quick = [];
    var quickBuffer = '';
    var quickTimer = null;

    function paintQuickBuffer() {
        var box = document.getElementById('quickbuf');
        if (box) { box.textContent = quickBuffer ? '#' + quickBuffer : ''; }
    }

    function clearQuickBuffer() {
        quickBuffer = '';
        if (quickTimer) { window.clearTimeout(quickTimer); quickTimer = null; }
        paintQuickBuffer();
    }

    function toggleQuickPlayer(sideIndex, p) {
        if (!p) { return; }
        var at = -1;
        quick.forEach(function (q, i) {
            if (q.sideIndex === sideIndex && String(q.playerId) === String(p.id)) { at = i; }
        });
        if (at !== -1) {
            quick.splice(at, 1);
        } else {
            quick.push({ sideIndex: sideIndex, playerId: p.id });
            if (quick.length > 2) { quick.shift(); }
        }
        renderQuickCards();
    }

    function commitQuickNumber() {
        var buffer = quickBuffer;
        clearQuickBuffer();
        if (!buffer) { return; }
        var matches = [];
        sides().forEach(function (side, si) {
            fieldOrder(side).forEach(function (p) {
                if (String(p.num) === buffer) { matches.push({ sideIndex: si, player: p }); }
            });
        });
        if (matches.length > 1) {
            // Both teams field this number: show both rather than guess.
            quick = matches.slice(0, 2).map(function (m) {
                return { sideIndex: m.sideIndex, playerId: m.player.id };
            });
            renderQuickCards();
            return;
        }
        if (matches.length === 1) {
            toggleQuickPlayer(matches[0].sideIndex, matches[0].player);
        }
    }

    function pressQuickDigit(digit) {
        if (quickTimer) { window.clearTimeout(quickTimer); quickTimer = null; }
        quickBuffer += digit;
        var exact = false;
        var extendable = false;
        sides().forEach(function (side) {
            fieldOrder(side).forEach(function (p) {
                var num = String(p.num === null || p.num === undefined ? '' : p.num);
                if (num === quickBuffer) { exact = true; }
                else if (num.indexOf(quickBuffer) === 0) { extendable = true; }
            });
        });
        paintQuickBuffer();
        if (!exact && !extendable) { clearQuickBuffer(); return; }
        if (exact && !extendable) { commitQuickNumber(); return; }
        // A longer on-field number is still possible: commit what is typed if
        // nothing follows shortly, or forget a bare prefix.
        quickTimer = window.setTimeout(commitQuickNumber, exact ? 650 : 1500);
    }

    function quickCard(q) {
        var side = sides()[q.sideIndex];
        var p = null;
        rosterByNumber(side).forEach(function (r) {
            if (String(r.id) === String(q.playerId)) { p = r; }
        });
        if (!p) { return null; }

        var card = el('div', 'qcard');
        card.append(el('h4', null,
            (p.num !== null && p.num !== undefined ? '#' + p.num + '  ' : '') + p.name));
        card.append(el('div', 'muted', side.team.name || ''));
        var n = noteFor(p.id);
        var sayOpts = { matching: isMixedSide(side) };
        if (n && hasSayLine(n, sayOpts)) {
            var s = el('div', 'say');
            sayInto(s, n, sayOpts);
            card.append(s);
        }
        var blocks = blocksTracked();
        card.append(el('div', 'qline',
            'This game  ' + (p.gGoals || 0) + ' G · ' + (p.gAssists || 0) + ' A · '
            + (p.gTotal || 0) + ' Pts'));
        card.append(el('div', 'qline',
            'Tournament  ' + (p.tGoals || 0) + ' G · ' + (p.tAssists || 0) + ' A · '
            + (p.tTotal || 0) + ' Pts'
            + (blocks ? ' · ' + (p.tBlocks || 0) + ' Blk' : '')));
        var strip = pointStrip(side, p);
        if (strip) { card.append(strip); }
        // The payoff: what was prepared about this player, at the moment it is
        // worth saying.
        if (n && n.text) { card.append(el('p', 'qnote', n.text)); }
        return card;
    }

    /**
     * The game so far, one cell per point: filled when this side scored it,
     * lettered where this player took the goal (G) or the assist (A) — "what
     * have they done today", readable mid-point without opening anything.
     *
     * Points PLAYED are absent deliberately, not forgotten: no line history
     * exists (a selection is kept only for the current point), and blocks are
     * in no per-game payload (`STUDIO.md` §3.4) — and absence must never read
     * as "did nothing".
     */
    function pointStrip(side, p) {
        var goals = ((state.payload && state.payload.goals) || []).slice()
            .sort(function (a, b) { return (Number(a.num) || 0) - (Number(b.num) || 0); });
        if (!goals.length) { return null; }
        var strip = el('div', 'qpoints');
        goals.forEach(function (g) {
            var won = (Number(g.ishomegoal) === 1) === side.isHome;
            var mark = '';
            if (Number(g.scorer) === Number(p.id)) { mark = 'G'; }
            else if (Number(g.assist) === Number(p.id)) { mark = 'A'; }
            var cell = el('span', 'qp' + (won ? ' won' : '') + (mark ? ' me' : ''), mark);
            cell.title = 'Point ' + g.num + ' — '
                + (won ? (side.team.name || 'this side') + ' scored' : 'conceded')
                + (mark === 'G' ? ', their goal' : (mark === 'A' ? ', their assist' : ''));
            strip.append(cell);
        });
        return strip;
    }

    function renderQuickCards() {
        var box = document.getElementById('quickcards');
        if (!box) { return; }
        box.replaceChildren();
        quick.forEach(function (q) {
            var card = quickCard(q);
            if (card) { box.append(card); }
        });
        box.hidden = !box.childNodes.length;
    }

    document.addEventListener('keydown', function (e) {
        if (e.metaKey || e.ctrlKey || e.altKey) { return; }
        var tag = (e.target && e.target.tagName) || '';
        if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || e.target.isContentEditable) { return; }
        if (state.mode !== 'play' || state.step !== 2) { return; }
        if (sheet.classList.contains('open')) { return; }
        if (e.key === 'Escape') {
            if (quickBuffer) { clearQuickBuffer(); return; }
            if (quick.length) { quick = []; renderQuickCards(); }
            return;
        }
        if (e.key === 'Enter' && quickBuffer) {
            e.preventDefault();
            commitQuickNumber();
            return;
        }
        if (e.key === 'Backspace' && quickBuffer) {
            e.preventDefault();
            if (quickTimer) { window.clearTimeout(quickTimer); quickTimer = null; }
            quickBuffer = quickBuffer.slice(0, -1);
            paintQuickBuffer();
            if (quickBuffer) { quickTimer = window.setTimeout(commitQuickNumber, 1500); }
            return;
        }
        var digit = /^(?:Digit|Numpad)([0-9])$/.exec(e.code || '');
        if (digit) {
            e.preventDefault();
            pressQuickDigit(digit[1]);
        }
    });

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
    /**
     * The link: who this desk is, and whether it is connected.
     *
     * Shown in every mode and whatever else is switched on. It used to live
     * behind possession tracking, which meant the name field — the thing the
     * operator reads when deciding whose code to enter — was invisible until
     * somebody had already turned on a graphic. That is the same coupling the
     * store shed; the panel had kept it.
     */

    /**
     * One section for everything the link unlocks.
     *
     * Injury stoppage and possession were two boxes stacked, which cost a whole
     * band of vertical space on the screen that has least of it — the
     * play-by-play view exists to show fourteen names large. They are one
     * section now: the same link, the same desk, the same moment.
     *
     * The code is not repeated here either. It is in the header, permanently,
     * next to the name; saying it twice was a line spent on something already on
     * screen.
     */
    /**
     * The live controls, rendered into the header beside the code.
     *
     * They were a panel in the body. On the play-by-play screen that is the
     * wrong place twice over: it is the view with the least vertical space to
     * spare — its whole job is fourteen names at a readable size — and these are
     * the controls pressed most often, so they are the last thing that should
     * scroll. In the sticky header they cost no body space and are never
     * scrolled past.
     */
    function renderTracking() {
        var box = document.getElementById('tracking');
        if (!box) { return; }
        box.replaceChildren();

        if (!possession.canTrack) {
            var ask = el('span', 'note');
            ask.textContent = possession.hasCode
                ? 'another code is linked'
                : 'not linked \u2014 give the operator your code';
            box.append(ask);
            return;
        }

        // A stoppage needs no mode: it is its own declared fact.
        var stop = el('button', 'tbtn' + (stoppageOn() ? ' on d' : ''));
        stop.type = 'button';
        stop.textContent = stoppageOn() ? '\u25a0 Injury' : '\u2691 Injury';
        stop.title = 'Injury stoppage. Keyboard: I';
        stop.setAttribute('aria-pressed', stoppageOn() ? 'true' : 'false');
        stop.addEventListener('click', toggleStoppage);
        box.append(stop);

        if (!possession.enabled) {
            box.append(el('span', 'note', 'possession tracking off'));
            return;
        }

        var scNow = liveScore();
        var Pn = window.Possession;
        var dNow = scNow && Pn ? Pn.defenceHasDisc(possession.events, scNow.home, scNow.visitor) : false;
        [['O', false], ['D', true]].forEach(function (spec) {
            var active = (spec[1] === dNow);
            var b = el('button', 'tbtn' + (active ? ' on' : '') + (spec[1] ? ' d' : ''));
            b.type = 'button';
            b.textContent = spec[0];
            b.title = (spec[1] ? 'Defence' : 'Offence') + ' in possession. Keyboard: ' + spec[0];
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
            b.disabled = !scNow;
            b.addEventListener('click', function () { setDefence(spec[1]); });
            box.append(b);
        });

        var logBtn = el('button', 'tbtn', '\u2637');
        logBtn.type = 'button';
        logBtn.title = 'Possession log and corrections. Keyboard: U';
        logBtn.setAttribute('aria-label', 'Possession log');
        logBtn.addEventListener('click', openLog);
        box.append(logBtn);
    }

    /**
     * The first point's ratio, in the toolbar with the other setup.
     *
     * It is answered once per game and then never touched, so it belongs beside
     * the code rather than taking a block in the panel that is read between
     * every point. Mixed divisions only — there is nothing to set otherwise.
     */
    function renderRatioControl() {
        var box = document.getElementById('tracking');
        if (!box || !isMixedDivision()) { return; }

        var pair = ratioPair();
        var current = firstRatioValue();

        var sel = document.createElement('select');
        sel.className = 'tsel';
        sel.title = possession.canTrack
            ? (current
                ? 'Gender ratio on point 1. Everything else follows the ABBA pattern from it.'
                : 'Set the gender ratio on point 1, from the paper scoresheet.')
            : 'Gender ratio on point 1 — kept on this screen only until the desk is '
                + 'linked; a shared value replaces it.';
        sel.setAttribute('aria-label', 'Gender ratio on point 1');

        var opts = [['', 'ratio pt 1']].concat(pair.map(function (r) {
            return [r, shortRatio(r) + ' pt 1'];
        }));
        opts.forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o[0];
            opt.textContent = o[1];
            if (o[0] === (current || '')) { opt.selected = true; }
            sel.append(opt);
        });
        sel.addEventListener('change', function () { setFirstRatio(sel.value || null); });
        box.append(sel);
    }

    /**
     * What the tracking is telling you — not the controls for it.
     *
     * The buttons live in the sticky header now, so this is a single slim line
     * of readings. "Four turnovers in this point already" is a sentence a
     * commentator says; the previous point sits beside it because that is the
     * comparison actually reached for.
     */
    /**
     * Which step of the point, in the toolbar.
     *
     * The heading and its buttons were a full row in the body, and the heading
     * said what the panel underneath already showed — a grid of jersey numbers
     * is self-evidently a line picker. What is worth keeping is the transition
     * between the two steps, which is a control, so it goes where the other
     * controls are.
     */
    /**
     * The keyboard reference, in the same dialog the player sheet and the
     * import preview use. A page grows keys one at a time and each is obvious
     * to whoever added it; the person meeting all of them at once is a
     * commentator five minutes before a pull.
     */
    function openKeysHelp() {
        var card = document.getElementById('sheetCard');
        card.replaceChildren();
        var title = el('h3', null, 'Keyboard');
        title.id = 'sheetName';
        card.append(title);

        [
            {
                name: 'On the field', rows: [
                    ['1\u201399', 'Type a shirt number to pin that player\'s card. A number both teams wear pins both, labelled'],
                    ['Enter', 'Pin the number typed so far, without waiting'],
                    ['Backspace', 'Edit the number typed so far'],
                    ['Esc', 'Forget the typed number; pressed again, clear the cards']
                ]
            },
            {
                name: 'Possession tracking, when it is on', rows: [
                    ['O', 'The offence has the disc'],
                    ['D', 'The defence has the disc \u2014 a break chance'],
                    ['I', 'Toggle a stoppage'],
                    ['U', 'Open the possession log, to undo a wrong entry']
                ]
            }
        ].forEach(function (group) {
            card.append(el('h4', null, group.name));
            var grid = el('div', 'keygrid');
            group.rows.forEach(function (row) {
                grid.append(el('kbd', null, row[0]));
                grid.append(el('div', null, row[1]));
            });
            card.append(grid);
        });
        card.append(el('p', 'muted',
            'Keys do nothing while typing in a field. Clicking a player on the '
            + 'field pins the same card the number does.'));

        var foot = el('footer');
        var close = el('button', 'barbtn', 'Close');
        close.type = 'button';
        close.addEventListener('click', closeSheet);
        foot.append(close);
        card.append(foot);

        sheet.classList.add('open');
        lastFocus = document.activeElement;
        close.focus();
    }

    function keysButton() {
        var b = el('button', 'tbtn', 'Keys');
        b.type = 'button';
        b.title = 'Keyboard shortcuts';
        b.addEventListener('click', openKeysHelp);
        return b;
    }

    function renderSteps() {
        var box = document.getElementById('steps');
        if (!box) { return; }
        box.replaceChildren();
        // The keyboard reference is prep material as much as a play-time aid,
        // so the button is in the toolbar in both modes.
        if (state.mode !== 'play') { box.append(keysButton()); return; }

        if (state.step === 1) {
            var go = el('button', 'tbtn primary', 'Start point \u2192');
            go.type = 'button';
            go.addEventListener('click', function () { state.step = 2; render(); });
            box.append(go);

            var clear = el('button', 'tbtn', 'Clear');
            clear.type = 'button';
            clear.title = 'Clear both lines';
            clear.addEventListener('click', function () { state.line = {}; render(); });
            box.append(clear);
            box.append(keysButton());
        } else {
            var edit = el('button', 'tbtn', '\u2190 Change line');
            edit.type = 'button';
            edit.addEventListener('click', function () { state.step = 1; render(); });
            box.append(edit);
            box.append(keysButton());
            // The shirt number being typed, so a mistype is seen rather than
            // silently pinning the wrong player.
            var buf = el('span', 'qbuf');
            buf.id = 'quickbuf';
            box.append(buf);
        }
    }

    function trackingPanel() {
        if (!possession.canTrack || !possession.enabled) { return null; }
        var sc = liveScore();
        var P = window.Possession;
        if (!sc || !P) { return null; }

        var box = el('div', 'possbox slim');
        var counts = el('div', 'posscounts');
        var now = P.turnovers(possession.events, sc.home, sc.visitor);
        var prev = previousScore();
        var was = prev ? P.turnovers(possession.events, prev.home, prev.visitor) : null;

        var stat = function (label, value, cls) {
            var d = el('div', 'posscount' + (cls ? ' ' + cls : ''));
            d.append(el('b', null, String(value)));
            d.append(el('span', null, label));
            counts.append(d);
        };
        stat('turnovers this point', now, now >= 3 ? 'hot' : '');
        if (was !== null) { stat('in the previous point', was, ''); }
        box.append(counts);
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
            // The selector is in the toolbar now; this only says why the rest of
            // the panel is empty.
            // Do not tell somebody to use a control they cannot reach.
            box.append(el('p', 'muted', possession.canTrack
                ? 'Set the ratio for point 1 in the toolbar. It is not recorded anywhere '
                    + '\u2014 it is circled on the paper scoresheet \u2014 and the rest of '
                    + 'the game follows the ABBA pattern from it, here and on the '
                    + 'progression card.'
                : 'Set the ratio for point 1 in the toolbar \u2014 it is circled on the '
                    + 'paper scoresheet. Until this desk is linked it lives on this screen '
                    + 'only; a value shared by the operator or a linked desk replaces it.'));
            appendRatioNote(box);
            return box;
        }

        var other = pair[0] === firstRatio ? pair[1] : pair[0];
        var ratioFor = function (n) { return abbaSlot(n) === 'A' ? firstRatio : other; };

        // Ratio and which point it is, on one line each: the label is a caption
        // for the number, not a second fact, and stacking them doubled the
        // height of the one panel that is read at a glance between points.
        var run = el('div', 'abbarun');
        for (var n = now; n < now + 4; n += 1) {
            var cell = el('div', 'abbapt' + (n === now ? ' now' : ''));
            cell.append(el('b', null, shortRatio(ratioFor(n))));
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
                line.append(el('b', null, shortRatio(pairv[1])));
                line.append(el('span', 'sc', slot.home + ' \u2013 ' + slot.away));
                line.append(el('span', 'who',
                    (s[0].team.name || 'Home') + ' v ' + (s[1].team.name || 'Away')));
                tbl.append(line);
            });
            box.append(tbl);
        }

        // The local-declaration caveat, and the one-click way out of it once
        // the desk is linked. Nothing local is ever shared without this click.
        if (firstRatioIsLocal()) {
            var caveat = el('p', 'muted',
                'Set on this screen only — the stage and other desks cannot see it. ');
            if (possession.canTrack) {
                var share = el('button', 'chip',
                    'Share ' + shortRatio(firstRatio) + ' with the room');
                share.type = 'button';
                share.addEventListener('click', function () {
                    setFirstRatio(localRatioValue());
                });
                caveat.append(share);
            }
            box.append(caveat);
        }
        appendRatioNote(box);

        return box;
    }

    /** The one-shot "shared replaced local" note, wherever the panel renders. */
    function appendRatioNote(box) {
        if (!ratioNote) { return; }
        var note = el('p', 'muted');
        note.append(document.createTextNode(
            'The shared ratio (' + shortRatio(ratioNote.shared) + ' pt 1) replaced the one '
            + 'set on this screen (' + shortRatio(ratioNote.local) + ' pt 1). '));
        var ok = el('button', 'chip', 'OK');
        ok.type = 'button';
        ok.addEventListener('click', function () { ratioNote = null; render(); });
        note.append(ok);
        box.append(note);
    }

    function renderPlay() {
        body.replaceChildren();
        var s = sides();

        // Ratio and tracking first, then the field, then team stats LAST.
        //
        // The opposite of the prep view, and deliberately: there the squads
        // scroll and the short block belongs on top so it never leaves the
        // screen. Here nothing scrolls and the ordering is about what is worth
        // the top of the screen during a point — the ratio for the next line,
        // the controls being pressed, and the names on the field. Season
        // records do not change while a point is being played, so they go to the
        // bottom where they can be scrolled to between points.
        var ratio = ratioPanel();
        if (ratio) { body.append(ratio); }
        var track = trackingPanel();
        if (track) { body.append(track); }

        if (state.step === 1) {
            var cols = el('div', 'cols');
            s.forEach(function (side) { cols.append(pickPanel(side)); });
            body.append(cols);
        } else {
            var field = el('div', 'onfield');
            field.append(onFieldPanel(s[0]));
            field.append(onFieldPanel(s[1], 'away'));
            body.append(field);
            // The quick-card region sits directly under the lines it is pulled
            // from, above the season numbers that do not change mid-point.
            var qc = el('div', 'quickcards');
            qc.id = 'quickcards';
            qc.hidden = true;
            body.append(qc);
            renderQuickCards();
        }

        body.append(teamStatsRow());
    }

    /* ---------------------------------------------------------------
       Modes and refresh
       --------------------------------------------------------------- */

    function render() {
        reconcileRatio();
        renderTop();
        document.getElementById('tabPrep').setAttribute('aria-pressed', state.mode === 'prep' ? 'true' : 'false');
        document.getElementById('tabPlay').setAttribute('aria-pressed', state.mode === 'play' ? 'true' : 'false');
        document.getElementById('tabPrep').className = state.mode === 'prep' ? 'on' : '';
        document.getElementById('tabPlay').className = state.mode === 'play' ? 'on' : '';
        renderSync();
        renderTracking();
        renderRatioControl();
        renderSteps();
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
            .then(window.Tracking.readJson)
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
        // Prepared notes change while two people split the squads before a game
        // and essentially never once it starts, so this is the slowest poll on
        // the page. Nothing here is time-critical: a note arriving fifteen
        // seconds late costs nobody anything.
        pullNotes();
        setInterval(pullNotes, 15000);
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
