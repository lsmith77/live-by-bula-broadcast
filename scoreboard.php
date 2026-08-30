<?php
/**
 * Live! by BULA video overlays — scoreboard
 *
 * A broadcast lower-third bug for a video switcher browser source (OBS, Magewell
 * Director Mini, Yolobox).
 *
 *   /index.php?view=live/overlays/scoreboard&game=700
 *
 * Reached through UltiOrganizer's front controller, which has already opened
 * the database, started the session, and applied Live!'s event-publication
 * boundary before this file runs.
 */

// Live! v3 serves nothing under live/ directly; the front controller defines
// this. Refuse the direct path rather than rendering a broken page.
if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

// Live!'s config, which defines UO_URL_PREFIX. Optional: standalone has no
// Live! to configure, and every reader below already falls back when the
// constant is undefined — see docs/STANDALONE.md.
if (is_file(__DIR__ . '/../conf/LocalConfig.php')) {
    require_once __DIR__ . '/../conf/LocalConfig.php';
}
require_once __DIR__ . '/shared/mode.php';
require_once __DIR__ . '/shared/colors.php';
require_once __DIR__ . '/shared/logos.php';

$gameId = filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

// Or follow a FIELD, and let the game change under the overlay as the day runs.
// One camera covers one field while the games on it turn over every ninety
// minutes, so this is the URL a switcher is actually set up with.
$fieldName = trim((string) (filter_input(INPUT_GET, 'field') ?: ''));
if (strlen($fieldName) > 40) {
    $fieldName = '';
}

if (!$gameId && $fieldName === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing or invalid ?game=<id> (or ?field=<name>).';
    exit;
}

/** Whitelisted so a URL parameter can never reach a class attribute verbatim. */
function overlay_choice(string $param, array $allowed, string $default): string
{
    $value = (string) filter_input(INPUT_GET, $param);
    return in_array($value, $allowed, true) ? $value : $default;
}

/** A bug belongs in a corner, so the default is no longer the card's top-centre. */
$position = overlay_choice('position', [
    'top-left', 'top-center', 'top-right',
    'bottom-left', 'bottom-center', 'bottom-right',
], 'bottom-left');

// Full team names by default: a scoreboard that only fits an abbreviation
// assumes every team has a usable four-letter one, and most do not. `compact`
// is the deliberate exception — a small bug for replays and busy shots, where
// abbreviations are what fit.
$size = overlay_choice('size', ['full', 'compact'], 'full');

// Most supplied logos are dark monochrome on transparency, so `auto` measures
// each one and plates it on its own; the rest cover art that needs overriding.
$plate = overlay_choice('logoplate', ['auto', 'light', 'dark', 'none'], 'auto');

// The context ribbon is detachable: event and round are worth carrying most of
// the time, but a busy shot is better off without them.
$ribbon = filter_input(INPUT_GET, 'ribbon') !== '0';

$interval = filter_input(INPUT_GET, 'interval', FILTER_VALIDATE_INT, [
    'options' => ['default' => 5000, 'min_range' => 1000, 'max_range' => 300000],
]);

/**
 * Whole-page reload interval, in seconds. Off by default.
 *
 * The overlay updates itself by polling, which needs the browser source to keep
 * running JavaScript. Some hardware sources do not: they fetch a page once and
 * rasterise a still, so nothing script-driven ever changes on air. A device like
 * that usually still honours a meta refresh, because that is a navigation rather
 * than a timer.
 *
 * It is a fallback, not a default: a reload flashes the overlay and restarts the
 * clock animation, so it is only worth it on a source that cannot do better.
 * Run ?view=live/overlays/tests/selftest on the device to find out which kind it is
 * before reaching for this.
 */
$reload = filter_input(INPUT_GET, 'reload', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 2, 'max_range' => 3600],
]);

/**
 * Scripted walk-through of every scoreboard state.
 *
 * One real payload is fetched for its shape, then the demo replays scripted
 * mutations over it — scheduled, live, hold, break, timeout, halftime, paused
 * clock, final. It is the only way to see a running clock locally, because the
 * fixture's timer_start is null.
 *
 * Development and design review only; never point a switcher at it.
 */
/**
 * Deterministic render mode, for post-production.
 *
 *   ?at=<clock seconds>&goals=<count>&phase=pre|live|final
 *
 * The overlay is handed the state to draw instead of deriving it from the
 * clock: how many goals have been scored, what the clock reads, and which phase
 * the game is in. It fetches the payload once, renders one frame, and stops --
 * no polling, no timers, nothing that would make two runs differ.
 *
 * Deliberately dumb. It does NOT work out which goals have happened by the time
 * given, because in post-production that question is not the overlay's to
 * answer: a recording is aligned to the game by anchors the operator supplies,
 * and plenty of tournaments record no goal times at all
 * (`hide_time_on_scoresheet`). Keeping the alignment entirely in the tool means
 * the overlay renders identically whether the answer came from UltiOrganizer's
 * timestamps or from somebody scrubbing a video.
 */
$atParam = filter_input(INPUT_GET, 'at', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 86400],
]);
$offline = $atParam !== false && $atParam !== null;
$atGoals = filter_input(INPUT_GET, 'goals', FILTER_VALIDATE_INT, [
    'options' => ['default' => 0, 'min_range' => 0, 'max_range' => 999],
]);
$atPhase = in_array(filter_input(INPUT_GET, 'phase'), ['pre', 'live', 'final'], true)
    ? filter_input(INPUT_GET, 'phase')
    : 'live';

$demo = filter_input(INPUT_GET, 'demo') === '1';
$demoStep = filter_input(INPUT_GET, 'step', FILTER_VALIDATE_INT, [
    'options' => ['default' => 3200, 'min_range' => 600, 'max_range' => 30000],
]);

// Hex colours only; anything else falls back to the pool colour from the API.
$colourParam = static function (string $param): ?string {
    $value = (string) filter_input(INPUT_GET, $param);
    return preg_match('/^#?[0-9A-Fa-f]{6}$/', $value) ? strtoupper(ltrim($value, '#')) : null;
};
$homeColour = $colourParam('homecolor');
$awayColour = $colourParam('awaycolor');

// Page background. Transparent by default, which is what OBS and any source
// that composites alpha wants. A switcher that cannot key on alpha needs a
// solid colour to chroma-key instead: ?bg=green (or any hex).
$backgrounds = ['green' => '#00B140', 'blue' => '#0047BB', 'magenta' => '#FF00FF', 'black' => '#000000'];
$bgParam = strtolower((string) filter_input(INPUT_GET, 'bg'));
$background = $backgrounds[$bgParam] ?? ($colourParam('bg') !== null ? '#' . $colourParam('bg') : 'transparent');

$prefix = defined('UO_URL_PREFIX') ? UO_URL_PREFIX : '/';
$apiBase = rtrim($prefix, '/') . '/index.php?view=live/api';
$assetBase = \Overlays\Mode::assetBase(rtrim($prefix, '/'));

$colorStore = new \Overlays\Colors();

/**
 * Asset URL stamped with the file's modification time.
 *
 * The stylesheet and client are served with no Cache-Control header, so a
 * browser caches them heuristically from Last-Modified and can hold a stale
 * copy indefinitely. On a laptop that is a confusing hard-refresh; on a switcher
 * mid-broadcast it is an overlay that silently reverts to an old layout after an
 * update. The stamp changes the URL whenever the file changes, so a cached copy
 * can never be the one in use.
 */
$assetUrl = static function (string $relative) use ($assetBase): string {
    $relative = ltrim($relative, '/');
    $path = __DIR__ . '/' . $relative;
    $version = is_file($path) ? (string) filemtime($path) : '0';
    return $assetBase . '/' . $relative . '?v=' . $version;
};

// The overlay page itself is generated per request and must never be cached:
// a stale shell would pin old markup against a fresh stylesheet.
header('Cache-Control: no-store, must-revalidate');

$json = static fn ($value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<?php if ($reload) : ?>
<meta http-equiv="refresh" content="<?= (int) $reload ?>">
<?php endif; ?>
<title>Scoreboard — game <?= (int) $gameId ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('shared/overlay-base.css'), ENT_QUOTES) ?>">
<style>body { background-color: <?= htmlspecialchars($background, ENT_QUOTES) ?>; }</style>
</head>
<body>

<div class="connection-status" id="connectionStatus">
    <div class="connection-indicator" id="connectionIndicator"></div>
    <span id="connectionText">Connecting…</span>
</div>

<div class="overlay-container <?= htmlspecialchars($position, ENT_QUOTES) ?>">
    <div class="loading" id="loadingState">Loading game data</div>

    <div class="scoreboard <?= htmlspecialchars($size, ENT_QUOTES) ?> plate-<?= htmlspecialchars($plate, ENT_QUOTES) ?>" id="scoreboard" style="display: none;">
        <div class="callout-row hide" id="calloutRow">
            <div class="callout-cell home"><span class="callout hide" id="homeCallout">Break chance</span></div>
            <div class="callout-cell away"><span class="callout hide" id="awayCallout">Break chance</span></div>
        </div>

        <div class="bug">
            <div class="side home">
                <img class="team-logo" id="homeLogo" alt="" style="display: none;">
                <div class="team-logo placeholder" id="homeLogoPlaceholder">H</div>
                <div class="team-block" id="homeBlock">
                    <span class="seed hide" id="homeSeed"></span>
                    <div class="team-ident">
                        <span class="team-name" id="homeName"></span>
                        <span class="timeouts hide" id="homeTimeouts"></span>
                    </div>
                </div>
                <div class="score" id="homeScore">0</div>
            </div>

            <div class="centre" id="centre">
                <div class="clock hide" id="clock"></div>
                <div class="segment" id="segment"></div>
            </div>

            <div class="side away">
                <img class="team-logo" id="awayLogo" alt="" style="display: none;">
                <div class="team-logo placeholder" id="awayLogoPlaceholder">A</div>
                <div class="team-block" id="awayBlock">
                    <span class="seed hide" id="awaySeed"></span>
                    <div class="team-ident">
                        <span class="team-name" id="awayName"></span>
                        <span class="timeouts hide" id="awayTimeouts"></span>
                    </div>
                </div>
                <div class="score" id="awayScore">0</div>
            </div>
        </div>

        <div class="ribbon<?= $ribbon ? '' : ' hide' ?>" id="ribbon"></div>
    </div>

    <div class="error-display" id="errorDisplay" style="display: none;">
        <h3>No game data</h3>
        <p id="errorMessage"></p>
    </div>
</div>

<div class="demo-label hide" id="demoLabel"></div>

<script src="<?= htmlspecialchars($assetUrl('shared/possession.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($assetUrl('shared/field.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($assetUrl('shared/stoppage.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($assetUrl('shared/timeouts.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($assetUrl('shared/provider.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($assetUrl('shared/overlay-client.js'), ENT_QUOTES) ?>"></script>
<?php if ($demo) : ?>
<script src="<?= htmlspecialchars($assetUrl('shared/demo.js'), ENT_QUOTES) ?>"></script>
<?php endif; ?>
<script>
(function () {
    'use strict';

    var CONFIG = {
        gameId: <?= (int) $gameId ?>,
        field: <?= $json($fieldName !== '' ? $fieldName : null) ?>,
        fieldPoll: 30000,
        apiBase: <?= $json($apiBase) ?>,
        interval: <?= (int) $interval ?>,
        homeColour: <?= $json($homeColour) ?>,
        awayColour: <?= $json($awayColour) ?>,
        size: <?= $json($size) ?>,
        plate: <?= $json($plate) ?>,
        ribbon: <?= $json($ribbon) ?>,
        demo: <?= $json($demo) ?>,
        demoStep: <?= (int) $demoStep ?>,
        // Declared possession, read as a static file so it can move at the pace
        // a break chance needs rather than the pace the game payload allows.
        possessionBase: <?= $json($assetBase) ?>,
        possessionPoll: 1000,
        offline: <?= $json($offline) ?>,
        at: <?= (int) ($offline ? $atParam : 0) ?>,
        atGoals: <?= (int) $atGoals ?>,
        atPhase: <?= $json($atPhase) ?>,
        // What each side is wearing for this game, entered in the Studio
        // minutes before the pull by someone looking at the jerseys.
        kit: <?= $json($colorStore->gameChoice((int) $gameId)) ?>,
        // Team id -> logo URL from live/overlays/logos/. Live!'s own team
        // photos are the fallback when a team has no logo here.
        teamLogos: <?= $json((object) (new \Overlays\Logos(null, $assetBase . '/logos'))->all()) ?>
    };

    var el = {};
    ['connectionStatus', 'connectionIndicator', 'connectionText', 'loadingState',
     'scoreboard', 'errorDisplay', 'errorMessage', 'ribbon', 'centre', 'clock', 'segment',
     'calloutRow', 'homeCallout', 'awayCallout', 'demoLabel',
     'homeName', 'homeScore', 'homeLogo', 'homeLogoPlaceholder', 'homeSeed',
     'homeBlock', 'homeTimeouts',
     'awayName', 'awayScore', 'awayLogo', 'awayLogoPlaceholder', 'awaySeed',
     'awayBlock', 'awayTimeouts'].forEach(function (id) {
        el[id] = document.getElementById(id);
    });

    // Shown until an operator has entered both kits. Neutral on purpose, and
    // separated by lightness so the two sides still read apart.
    var NEUTRAL_HOME = '2A3340';
    var NEUTRAL_AWAY = '55606E';

    /** The API returns pool colours as a bare 6-digit number, which drops any leading zero. */
    function normaliseColour(value) {
        if (value === null || value === undefined || value === '') return null;
        var hex = String(value).replace(/^#/, '');
        return /^[0-9A-Fa-f]{1,6}$/.test(hex) ? hex.padStart(6, '0').toUpperCase() : null;
    }

    /** Rec. 601 luma. Good enough for every light-or-dark decision here. */
    function luma(hex) {
        var n = parseInt(hex, 16);
        return 0.299 * ((n >> 16) & 255) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255);
    }

    /**
     * Text colour for a kit fill.
     *
     * A kit can be anything from white to near-black, and the fill is now the
     * name's background rather than an 8px bar, so the foreground has to be
     * decided from it rather than assumed white.
     */
    function contrastOn(hex) {
        return luma(hex) > 150 ? '#111820' : '#FFFFFF';
    }

    /**
     * Shrink a team name until it fits on its one line.
     *
     * The condensed stack cannot be relied on: measured on macOS, the only
     * broadly portable condensed face is Arial Narrow, and even that renders
     * "Mosquitos Klosterneuburg" at 468px against a 420px box. A switcher's own
     * font set is unknown and probably narrower still, so rather than trusting
     * any particular face, the name is measured after layout and stepped down
     * until it fits.
     *
     * Ellipsis remains the backstop below the floor — past that the type is too
     * small to read off a compressed video feed, and a clipped tail beats an
     * illegible full name.
     */
    function fitName(node) {
        var FLOOR = 24;
        node.style.fontSize = '';
        var size = parseFloat(window.getComputedStyle(node).fontSize);
        while (node.scrollWidth > node.clientWidth && size > FLOOR) {
            size -= 1;
            node.style.fontSize = size + 'px';
        }
    }

    var painted = false;

    /**
     * How long a goal is announced for — the score's green flash and the
     * HOLD/BREAK tab both use it, so the two read as one event rather than two
     * with different lifetimes.
     */
    var FLASH_MS = 900;

    /** @returns {boolean} true when this changed while the overlay was on air. */
    function setScore(node, value) {
        var next = String(value);
        if (node.textContent === next) return false;
        node.textContent = next;
        // Only a score that changes while the overlay is on air should flash.
        // Without this every overlay flashes the moment it loads, which on a
        // switcher reads as a goal being scored.
        return painted;
    }

    /**
     * Flash a score cell for the goal just scored.
     *
     * `kind` colours it to match the callout tab for the same point, so the two
     * read as one event. A goal whose outcome cannot be classified still
     * flashes, just neutrally — the score changed either way.
     */
    function flashScore(node, kind) {
        node.classList.add('flash');
        if (kind) { node.classList.add(kind); }
        setTimeout(function () {
            node.classList.remove('flash', 'hold', 'brk');
        }, FLASH_MS);
    }

    /**
     * Pick a backing plate from the logo's own pixels.
     *
     * Ultimate logos are overwhelmingly monochrome marks on transparency, and
     * they come in both polarities — a navy crest and a white wordmark are
     * equally common. One global plate setting therefore cannot be right for
     * both teams at once, so each logo is measured and plated on its own.
     *
     * Only pixels with meaningful alpha count: averaging in the transparent
     * background would drag every logo toward the same value and defeat the
     * measurement. A logo that is entirely transparent, or too small to sample,
     * keeps the neutral default.
     */
    function applyAutoPlate(img) {
        var BOX = 32;
        try {
            var canvas = document.createElement('canvas');
            canvas.width = BOX;
            canvas.height = BOX;
            var ctx = canvas.getContext('2d', { willReadFrequently: true });
            // SVGs without intrinsic dimensions draw as nothing unless given an
            // explicit destination rectangle.
            ctx.drawImage(img, 0, 0, BOX, BOX);

            var data = ctx.getImageData(0, 0, BOX, BOX).data;
            var total = 0;
            var counted = 0;

            for (var i = 0; i < data.length; i += 4) {
                if (data[i + 3] < 32) continue;
                total += 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
                counted += 1;
            }

            if (counted === 0) return;
            img.classList.add((total / counted) < 140 ? 'plate-light' : 'plate-dark');
        } catch (e) {
            // Cross-origin artwork taints the canvas. The neutral default still
            // renders; it is just not tuned to this logo.
        }
    }

    /**
     * A dedicated logo from live/overlays/logos/ wins; otherwise fall back to
     * Live!'s own team photos, which the API resolves for us so the overlay
     * never has to guess a filename from the team name.
     */
    function setLogo(side, team) {
        var img = el[side + 'Logo'];
        var placeholder = el[side + 'LogoPlaceholder'];
        var src = CONFIG.teamLogos[team.team_id] || null;

        if (!src) {
            var photo = Array.isArray(team.photos) && team.photos.length ? team.photos[0] : null;
            src = typeof photo === 'string' ? photo : (photo && photo.url) || null;
        }

        placeholder.textContent = (team.name || '?').charAt(0).toUpperCase();
        img.classList.remove('plate-light', 'plate-dark');

        if (!src) {
            img.style.display = 'none';
            placeholder.style.display = 'flex';
            return;
        }
        img.onload = function () {
            img.style.display = 'block';
            placeholder.style.display = 'none';
            if (CONFIG.plate === 'auto') {
                applyAutoPlate(img);
            }
        };
        img.onerror = function () {
            img.style.display = 'none';
            placeholder.style.display = 'flex';
        };
        img.src = src;
    }

    /**
     * Timeouts remaining, as ticks.
     *
     * The count itself is `shared/timeouts.js` — the commentary desk and the
     * Studio show the same number, and a derivation used by three surfaces is
     * one that must not be written three times. A null means this pool gives no
     * timeouts at all, which hides the row rather than drawing an empty one:
     * absent is not zero.
     */
    function setTimeouts(node, isHome, pool, gameEvents) {
        var t = window.Timeouts.remaining(pool, gameEvents, isHome);
        if (!t) {
            node.className = 'timeouts hide';
            return;
        }
        node.className = 'timeouts';
        node.textContent = '';
        for (var i = 0; i < t.allowance; i += 1) {
            var dot = document.createElement('span');
            dot.className = 'timeout-dot' + (i < t.remaining ? ' available' : '');
            node.appendChild(dot);
        }
    }

    /* ---------------------------------------------------------------------
       Callouts

       One tab above the bar. Two things compete for it, and the outcome of a
       point wins for a few seconds because it is the news:

         HOLD / BREAK   briefly, over the team that just scored
         BREAK CHANCE   otherwise, over the side that would break by scoring

       The standing tab is deliberately NOT called "break chance". A break chance
       is an event — the defence wins the disc and can now score against the
       pull. UltiOrganizer records goals and blocks but never turnovers, so an
       overlay cannot know when possession changes, and a tab claiming a break
       chance would be asserting something unknown.

       What we do know is who is on defence for this point, which holds for the
       whole point. Labelling it that way also removes a sequence that looked
       like a bug: "break chance" followed immediately by "hold" implies the
       defence had the disc and then somehow did not, whereas "on defence"
       followed by "hold" is simply a point being held.

       Hold and break themselves need no possession data: whoever concedes
       receives the next pull, so a goal by the receiving team is a hold and a
       goal by the pulling team is a break.

       (WFDF rules assumed. If UPA/USAU handling is ever needed, this is one of
       the places it would branch.)
       --------------------------------------------------------------------- */

    var outcome = null;
    var outcomeTimer = null;
    var possession = { live: false, breaking: null };

    /**
     * Operator- or commentator-declared possession, on the fast channel.
     *
     * UltiOrganizer records no possession changes, so a break chance cannot be
     * derived — only declared (docs/STUDIO.md 3.5). This is that declaration,
     * read straight off a static file about once a second because a break
     * chance is worthless ten seconds late and the game payload is up to 30s
     * behind.
     *
     * Absent, unreadable or switched off, the board falls back to the standing
     * ON DEFENCE tag, which is always true. The card adapts to what is available
     * rather than being configured for it.
     */
    var declared = { enabled: false, events: [] };

    /** The score the board is currently showing, as the possession store keys it. */
    var shownScore = null;

    /**
     * The possession document for the game being shown.
     *
     * One document per game: a shared one let a second field's data reach this
     * overlay whenever the two happened to be on the same score. See
     * shared/possession.php.
     */
    function possessionFileUrl() {
        return CONFIG.possessionBase + '/conf/possession-' + (CONFIG.gameId) + '.json';
    }

    function pollDeclared() {
        fetch(possessionFileUrl() + '?_=' + Date.now(), { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (state) {
                var next = (state && Array.isArray(state.events)) ? state : { enabled: false, events: [] };
                var changed = next.enabled !== declared.enabled || next.rev !== declared.rev;
                declared = next;
                if (changed) { paintCallouts(); paintRibbon(); }
            })
            .then(function () { setTimeout(pollDeclared, CONFIG.possessionPoll); });
    }

    /**
     * The ribbon, repainted from either channel.
     *
     * Its fixed half — event, pool, round — arrives on the 30s game poll, but
     * the turnover count moves on the 1s possession channel. Painting it only
     * from render() would leave the count up to thirty seconds stale while the
     * tab above it was current, so the text is composed here and both callers
     * use it.
     */
    var ribbonContext = '';

    function paintRibbon() {
        if (!CONFIG.ribbon) { return; }
        var text = ribbonContext;
        var churn = turnoversThisPoint();
        if (churn >= 2) {
            text += (text ? ' · ' : '') + churn + ' turnovers this point';
        }
        el.ribbon.textContent = text;
        el.ribbon.className = text ? 'ribbon' : 'ribbon hide';
    }

    /**
     * How often the disc has changed hands in the point being played.
     *
     * Zero unless the mode is on and somebody is pressing O and D, which is the
     * point: an untracked game reports nothing rather than reporting zero, and
     * the ribbon simply does not mention it.
     */
    function turnoversThisPoint() {
        if (!declared.enabled || !possession.live || !window.Possession || !shownScore) { return 0; }
        return window.Possession.turnovers(declared.events, shownScore.home, shownScore.visitor);
    }

    /** Is the defence holding the disc right now, according to the log? */
    function breakChanceNow() {
        if (!declared.enabled || !possession.live || !window.Possession || !shownScore) { return false; }
        return window.Possession.defenceHasDisc(declared.events, shownScore.home, shownScore.visitor);
    }

    /**
     * Was the point that just ended a CLEAN hold — the defence never touching it?
     *
     * Asked of the score BEFORE the goal, which is the point being classified.
     * Only claimable while the log was actually running: an untracked point is
     * unknown, not clean, and "Clean hold" is an assertion that over-claims if
     * it is wrong. So it upgrades a hold only on positive evidence.
     */
    function wasCleanHold(latest, goals) {
        if (!declared.enabled || !latest || latest.kind !== 'hold' || !window.Possession) { return false; }
        var ordered = (goals || []).slice().sort(function (a, b) { return a.num - b.num; });
        var home = 0, visitor = 0;
        for (var i = 0; i < ordered.length; i += 1) {
            if (ordered[i].num === latest.num) { break; }
            if (Number(ordered[i].ishomegoal) === 1) { home += 1; } else { visitor += 1; }
        }
        var seen = window.Possession.eventsFor(declared.events,
            window.Possession.scoreKey(home, visitor)).length > 0;
        return seen && !window.Possession.defenceTouched(declared.events, home, visitor);
    }
    var lastGoalNum = null;

    /** Restart a CSS animation that is already on the element. */
    function retrigger(node, cls) {
        node.classList.remove(cls);
        // Forcing layout is what makes the removal take effect before the re-add.
        void node.offsetWidth;
        node.classList.add(cls);
    }

    /**
     * Classify the most recent goal as a hold or a break.
     *
     * Mirrors classifyPoints() in overlay-client.js: walk the goals in order,
     * tracking who receives, and report the last one. Returns null when it
     * cannot be known — no recorded starting offence, or a gap in the sequence.
     */
    function lastGoalOutcome(goals, gameEvents) {
        var ordered = Array.isArray(goals) ? goals.slice().sort(function (a, b) {
            return a.num - b.num;
        }) : [];
        if (ordered.length === 0) { return null; }

        var receiving = startingOffence(gameEvents);
        var expected = ordered[0].num;
        var result = null;

        for (var i = 0; i < ordered.length; i += 1) {
            var g = ordered[i];
            var scoredBy = Number(g.ishomegoal) === 1 ? 'home' : 'visitor';

            result = (receiving === null || g.num !== expected)
                ? null
                : { side: scoredBy, kind: scoredBy === receiving ? 'hold' : 'brk', num: g.num };

            receiving = scoredBy === 'home' ? 'visitor' : 'home';
            expected = g.num + 1;
        }
        return result;
    }

    function paintCallouts() {
        var showing;
        if (outcome) {
            showing = {
                side: outcome.side,
                text: outcome.kind === 'brk' ? 'Break' : (outcome.clean ? 'Clean hold' : 'Hold'),
                cls: outcome.kind
            };
        } else if (declaredStoppage()) {
            // Above a timeout: if both are somehow live, the injury is the more
            // important thing on screen and the one a viewer needs explained.
            showing = { side: null, text: 'Injury stoppage', cls: 'timeout' };
        } else if (activeTimeout()) {
            // Above break chance on purpose: during a timeout nobody has the
            // disc, so claiming a break chance would be asserting something that
            // is not happening. It is also the more useful thing to say -- it
            // explains why the picture has stopped moving.
            showing = { side: activeTimeout().side, text: 'Timeout', cls: 'timeout' };
        } else if (breakChanceNow() && possession.breaking) {
            // A declared, transient event — the real thing, not the standing tag.
            showing = { side: possession.breaking, text: 'Break chance', cls: 'brk chance' };
        } else if (possession.live && possession.breaking) {
            showing = { side: possession.breaking, text: 'On defence', cls: 'standing' };
        } else {
            showing = null;
        }

        [['home', el.homeCallout], ['away', el.awayCallout]].forEach(function (pair) {
            var key = pair[0] === 'home' ? 'home' : 'visitor';
            var node = pair[1];
            // A sideless callout paints in the home cell, centred by CSS.
            var active = showing && (showing.side === key
                || (showing.side === null && key === 'home'));

            var next = 'callout' + (active ? (showing.cls ? ' ' + showing.cls : '') : ' hide');
            var changed = node.className.replace(' enter', '') !== next;

            node.className = next;
            if (active) {
                node.textContent = showing.text;
                // Only animate a tab that just appeared or just changed meaning,
                // so a steady BREAK CHANCE does not re-pop on every poll.
                if (changed) { retrigger(node, 'enter'); }
            }
        });

        // A stoppage belongs to neither team, so its row is centred rather than
        // sitting over one side. Set here with the rest of the class, not before
        // it: this assignment replaces the whole attribute.
        el.calloutRow.className = 'callout-row'
            + (showing ? '' : ' hide')
            + (showing && showing.side === null ? ' centred' : '');
    }

    function setPossession(result, goals, gameEvents) {
        var live = Number(result.isongoing) === 1;
        shownScore = (result.homescore === undefined || result.homescore === null)
            ? null
            : { home: Number(result.homescore) || 0, visitor: Number(result.visitorscore) || 0 };
        var offence = live ? currentOffence(goals, gameEvents) : null;
        possession = {
            live: live,
            // The defending side is the one that would break by scoring.
            breaking: offence === 'home' ? 'visitor' : (offence === 'visitor' ? 'home' : null)
        };

        // A goal that lands while the overlay is on air gets the outcome tab.
        // On first paint the whole game history arrives at once, so the last
        // goal is old news and must not be announced.
        var latest = lastGoalOutcome(goals, gameEvents);
        var num = latest ? latest.num : null;

        if (painted && live && latest && num !== lastGoalNum) {
            outcome = latest;
            outcome.clean = wasCleanHold(latest, goals);
            if (outcomeTimer) { clearTimeout(outcomeTimer); }
            outcomeTimer = setTimeout(function () {
                outcome = null;
                paintCallouts();
            }, FLASH_MS);
        }
        lastGoalNum = num;

        if (!live) {
            outcome = null;
            if (outcomeTimer) { clearTimeout(outcomeTimer); outcomeTimer = null; }
        }

        paintCallouts();
    }

    /* ---------------------------------------------------------------------
       Clock

       UltiOrganizer stores an absolute start instant, so the clock is derived
       locally and ticks every second rather than waiting on the 30s poll. The
       arithmetic mirrors GameClockState() in lib/game.functions.php:1042:

           elapsed = now - timer_start - timer_paused_duration
           if paused: elapsed -= now - timer_pause_start

       `timer_start` is unix SECONDS (written by time() at
       lib/game.functions.php:2436), not milliseconds.
       --------------------------------------------------------------------- */

    // The elapsed time is measured against the server's clock, so a client whose
    // clock is off by minutes would show a wrong game time. Every payload
    // carries when the server generated it; the difference is the correction.
    var serverSkew = 0;
    var timer = null;

    /**
     * Enough of the live game to answer "is a timeout running right now".
     *
     * Set on every payload and read by the callout painter, which runs on the
     * clock tick rather than only on the 30s poll -- a timeout that ended has to
     * come off the air within a second or two of ending, not whenever the next
     * fetch happens to land.
     */
    var live = { events: null, clockState: null, timeoutLen: 0 };

    /**
     * A declared stoppage — an injury, or anything else halting play.
     *
     * Nothing in UltiOrganizer records these, so somebody flags it: the operator
     * or a commentator holding the code, with the I key. Keyed by score, so it
     * ends itself at the next goal rather than relying on anyone to clear it.
     */
    function declaredStoppage() {
        if (!window.Stoppage || !possession.live || !shownScore) { return null; }
        return window.Stoppage.declared(declared.stoppage, shownScore.home, shownScore.visitor);
    }

    /** The timeout in progress, if any. Window logic lives in shared/stoppage.js. */
    function activeTimeout() {
        if (!window.Stoppage || !live.clockState) { return null; }
        return window.Stoppage.active(live.events, elapsedSeconds(live.clockState), live.timeoutLen);
    }

    function serverNow() {
        return Math.floor(Date.now() / 1000) + serverSkew;
    }

    function elapsedSeconds(state) {
        var elapsed = serverNow() - state.start - state.pausedDuration;
        if (state.pauseStart) {
            elapsed -= serverNow() - state.pauseStart;
        }
        return Math.max(0, elapsed);
    }

    function formatClock(seconds) {
        var mm = Math.floor(seconds / 60);
        var ss = seconds % 60;
        return mm + ':' + (ss < 10 ? '0' : '') + ss;
    }

    /**
     * Status word for the centre column when no clock is running.
     *
     * "Live" is deliberately absent: a running clock is the liveness signal, and
     * no professional board labels itself live. It only appears as a fallback
     * for an ongoing game whose scorekeeper never started the clock.
     */
    function statusText(result) {
        if (Number(result.forfeit) === 1) return 'Forfeit';
        // v3: "completed" means started and no longer ongoing, so a 0-0 game is
        // completed too. hasstarted is what separates played from scheduled.
        if (result.status === 'completed' && Number(result.hasstarted) === 1) return 'Final';
        if (Number(result.isongoing) === 1) return 'Live';
        if (result.time) return result.time.slice(0, 16).replace('T', ' ');
        return 'Scheduled';
    }

    /**
     * The cap that is currently in force, if one has been called.
     *
     * UltiOrganizer models exactly two, `GameCapEventTypes()` in
     * lib/game.functions.php:712 — `half_cap` ("Halftime cap") and `time_cap`
     * ("Time cap"). Each is a game event carrying the time it was called and,
     * in `info`, the NEW POINT CAP: the score the game now plays to. UO's own
     * wording is "Time cap 6.45 - new point cap 4".
     *
     * There is deliberately no soft/hard distinction here, because UO has none.
     * Every cap sets a new target and play continues to it, which is soft-cap
     * behaviour; a hard cap is only representable as a target equal to the
     * current score. (`poolinfo.timeoutstimecap: "soft"` is a different thing —
     * whether timeouts may be taken once time cap is reached.)
     */
    function activeCap(gameEvents) {
        if (!Array.isArray(gameEvents)) { return null; }
        var found = null;
        gameEvents.forEach(function (e) {
            if (!e || (e.type !== 'half_cap' && e.type !== 'time_cap')) { return; }
            // A time cap supersedes a halftime cap; otherwise the later one wins.
            if (!found
                || (e.type === 'time_cap' && found.type !== 'time_cap')
                || (e.type === found.type && Number(e.time) > Number(found.time))) {
                found = e;
            }
        });
        return found;
    }

    function setClock(result, pool, gameEvents) {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }

        var cap = activeCap(gameEvents);
        var capClass = cap ? (cap.type === 'time_cap' ? ' cap-time' : ' cap-half') : '';

        var start = Number(result.timer_start);
        var running = Number(result.isongoing) === 1 && Number.isFinite(start) && start > 0;

        if (!running) {
            // Nothing is in progress, so nothing can be a timeout either.
            live.clockState = null;
            // No clock to show: the centre carries the status on its own. The
            // cap tint is dropped here on purpose — a cap is a live constraint,
            // and a red centre behind the word "Final" reads as an alarm rather
            // than as a record of how the game ended.
            el.clock.className = 'clock hide';
            el.segment.className = 'segment';
            el.segment.textContent = statusText(result);
            el.centre.className = 'centre status-only';
            return;
        }

        var state = {
            start: start,
            pausedDuration: Number(result.timer_paused_duration) || 0,
            pauseStart: Number(result.timer_pause_start) || 0
        };
        live.clockState = state;

        // `poolinfo.timecap` is the game's time limit in MINUTES — the admin
        // form labels the field that way (admin/addseasonpools.php:445). With
        // one set the clock counts down to it, which is what a broadcast wants;
        // without one there is nothing to count down to, so it counts up.
        //
        // UO itself has no countdown anywhere: GameClockState() returns elapsed,
        // and Timekeeper's game clock is explicitly a count-up. The countdown is
        // derived here, not read.
        var limit = Number(pool && pool.timecap);
        var counting = Number.isFinite(limit) && limit > 0 ? limit * 60 : null;

        el.centre.className = 'centre' + capClass;
        el.clock.className = 'clock' + (state.pauseStart ? ' paused' : '');
        el.segment.className = 'segment';

        if (cap) {
            // The new point cap is the useful number once a cap is called.
            var target = Number(cap.info);
            var name = cap.type === 'time_cap' ? 'Time cap' : 'Half cap';
            el.segment.textContent = Number.isFinite(target) && target > 0
                ? name + ' · to ' + target
                : name;
        } else {
            el.segment.textContent = state.pauseStart ? 'Paused' : 'Live';
        }

        var tick = function () {
            var elapsed = elapsedSeconds(state);
            // Clamped at zero: a negative clock past the cap is meaningless, and
            // the cap badge is what carries the state from then on.
            el.clock.textContent = formatClock(
                counting === null ? elapsed : Math.max(0, counting - elapsed)
            );
            // The timeout window closes on the clock, not on the poll.
            paintCallouts();
        };
        tick();
        // A paused clock does not advance, so there is nothing to tick.
        if (!state.pauseStart) {
            timer = setInterval(tick, 1000);
        }
    }

    function render(payload) {
        var result = payload.game_result || {};
        var info = payload.game_info || {};
        var pool = payload.poolinfo || {};
        var season = payload.seasoninfo || {};
        var teams = payload.teams || {};
        // The API names the sides hometeam/visitorteam; the overlay layout calls
        // the right-hand side "away".
        var home = teams.hometeam || {};
        var away = teams.visitorteam || {};

        var generated = Number(payload.meta && payload.meta.generated_timestamp);
        if (Number.isFinite(generated)) {
            serverSkew = generated - Math.floor(Date.now() / 1000);
        }

        // Full names carry the broadcast; the abbreviation is only used where
        // there genuinely is not room, and even then falls back to the full name
        // for the many teams that have no sensible short form.
        var compact = CONFIG.size === 'compact';
        el.homeName.textContent = (compact ? info.hometeamshortname : null)
            || info.hometeamname || home.name || '';
        el.awayName.textContent = (compact ? info.visitorteamshortname : null)
            || info.visitorteamname || away.name || '';

        // Classified once and shared by the score flash and the callout tab, so
        // the two can never disagree about whether a point was a hold or a break.
        var scored = lastGoalOutcome(payload.goals, payload.gameevents);

        if (setScore(el.homeScore, Number(result.homescore) || 0)) {
            flashScore(el.homeScore, scored && scored.side === 'home' ? scored.kind : null);
        }
        if (setScore(el.awayScore, Number(result.visitorscore) || 0)) {
            flashScore(el.awayScore, scored && scored.side === 'visitor' ? scored.kind : null);
        }

        /* Kit colours are all-or-nothing.
         *
         * An operator enters both sides minutes before the pull, reading them
         * off the actual jerseys. Until both are in, the board shows neutral
         * blocks rather than mixing one real kit with a placeholder — a single
         * real colour beside a fallback reads as *that team's* colour, which is
         * a wrong statement rather than a missing one.
         *
         * The pool colour is deliberately not used as the fallback: both teams
         * are in the same pool, so it would paint the two sides identically.
         * The neutrals differ in lightness instead, which keeps the sides
         * distinguishable without claiming either is a kit.
         *
         * A URL parameter still wins, side by side, as a one-off override. */
        var kit = CONFIG.kit || {};
        var kitHome = normaliseColour(kit.home);
        var kitAway = normaliseColour(kit.visitor);
        var bothKnown = Boolean(kitHome && kitAway);

        var homeColour = CONFIG.homeColour || (bothKnown ? kitHome : NEUTRAL_HOME);
        var awayColour = CONFIG.awayColour || (bothKnown ? kitAway : NEUTRAL_AWAY);

        el.homeBlock.style.setProperty('--kit', '#' + homeColour);
        el.homeBlock.style.setProperty('--kit-fg', contrastOn(homeColour));
        el.awayBlock.style.setProperty('--kit', '#' + awayColour);
        el.awayBlock.style.setProperty('--kit-fg', contrastOn(awayColour));

        setLogo('home', home);
        setLogo('away', away);

        // Seed rather than a win-loss record: a record of 1-0 on day one carries
        // almost no information, and a seed is meaningful all week. Teams
        // without a seed lose the slot rather than falling back to the record.
        [['home', home], ['away', away]].forEach(function (pair) {
            var node = el[pair[0] + 'Seed'];
            var seed = Number(pair[1].seed);
            var show = Number.isFinite(seed) && seed > 0 && !compact;
            node.className = 'seed' + (show ? '' : ' hide');
            node.textContent = show ? String(seed) : '';
        });

        setTimeouts(el.homeTimeouts, true, pool, payload.gameevents);
        setTimeouts(el.awayTimeouts, false, pool, payload.gameevents);

        // Recorded before the clock is set up, because setClock's first tick
        // paints the callouts and needs both.
        live.events = payload.gameevents || [];
        live.timeoutLen = Number(pool && pool.timeoutlen) || 0;

        setClock(result, pool, payload.gameevents);
        setPossession(result, payload.goals, payload.gameevents);

        // Context moves out of the centre column, where it competed with the
        // score, onto its own strip.
        ribbonContext = [season.name, pool.name || info.poolname, info.gamename]
            .filter(Boolean).join(' · ');
        paintRibbon();

        el.loadingState.style.display = 'none';
        el.errorDisplay.style.display = 'none';
        el.scoreboard.style.display = 'flex';

        // After the board is visible, never before: a hidden element reports
        // scrollWidth and clientWidth as 0, so measuring it here would silently
        // decide every name already fits.
        fitName(el.homeName);
        fitName(el.awayName);

        painted = true;
    }

    function showError(error) {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        el.loadingState.style.display = 'none';
        el.scoreboard.style.display = 'none';
        el.errorDisplay.style.display = 'block';
        el.errorMessage.textContent = error.message;
    }

    // Declared possession runs on its own clock, independent of the game poll:
    // it is a different channel at a different pace, and it must keep working
    // when the game payload is stale.
    if (!CONFIG.demo) { pollDeclared(); }

    /**
     * Everything the board remembers about the game it is showing.
     *
     * Cleared when a field-following board moves to the next game. Without this
     * the new game's first goal would be compared against the old game's score
     * and announced as if it had just been scored, and `painted` would still be
     * true so the flash would fire on the very first payload.
     */
    function resetForNewGame() {
        painted = false;
        shownScore = null;
        lastGoalNum = null;
        outcome = null;
        if (outcomeTimer) { clearTimeout(outcomeTimer); outcomeTimer = null; }
        possession = { live: false, breaking: null };
        ribbonContext = '';
    }

    function buildClient() {
        return new OverlayDataClient(CONFIG)
        .onData(render)
        .onStatus(function (status) {
            el.connectionIndicator.classList.toggle('connected', status.connected);
            el.connectionText.textContent = status.message;
            el.connectionStatus.classList.toggle('hide', status.connected);
        })
        .onError(function (error) {
            // Keep the last good scoreboard on screen through a transient blip;
            // only replace it once retrying has been abandoned.
            if (error.fatal || error.consecutiveErrors >= 5) showError(error);
        });
    }

    var client = buildClient();

    /**
     * Build the payload as it stood at one instant, and draw it once.
     *
     * Everything downstream -- score, hold/break tab, clock, status word -- is
     * already a pure function of the payload, so the whole of offline mode is
     * "hand render() a truncated payload". No second renderer, and therefore no
     * way for a post-produced overlay to drift from the live one.
     */
    function renderAt(base) {
        var payload = JSON.parse(JSON.stringify(base));
        var goals = (payload.goals || []).slice().sort(function (a, b) { return a.num - b.num; });
        var upTo = goals.slice(0, Math.min(CONFIG.atGoals, goals.length));

        payload.goals = upTo;
        var home = 0, away = 0;
        upTo.forEach(function (g) {
            if (Number(g.ishomegoal) === 1) { home += 1; } else { away += 1; }
        });

        var r = payload.game_result || (payload.game_result = {});
        r.homescore = home;
        r.visitorscore = away;
        r.isongoing = CONFIG.atPhase === 'live' ? 1 : 0;
        r.hasstarted = CONFIG.atPhase === 'pre' ? 0 : 1;
        r.status = CONFIG.atPhase === 'final' ? 'completed' : r.status;

        // The clock is stated, not derived: `at` IS the reading. Anchoring it to
        // a synthetic timer_start lets the existing clock code run unchanged,
        // including the countdown and the cap tints.
        //
        // The payload's own generated_timestamp has to go, or render() will set
        // a server skew from it and shift the reading by however stale the cache
        // was -- three seconds, in the first run of this. Offline there is no
        // "now" to be skewed against.
        if (payload.meta) { delete payload.meta.generated_timestamp; }
        if (CONFIG.atPhase === 'live') {
            serverSkew = 0;
            r.timer_start = Math.floor(Date.now() / 1000) - CONFIG.at;
            r.timer_pause_start = null;
            r.timer_paused_duration = 0;
        } else {
            r.timer_start = null;
        }

        // Cap events only count once the goal they were called at has happened.
        payload.gameevents = (payload.gameevents || []).filter(function (e) {
            return !e || e.time === null || e.time === undefined || Number(e.time) <= CONFIG.at;
        });

        // A frame is a still: the score flash and the hold/break tab are
        // transitions, and a burnt-in overlay must not show a tab that would
        // otherwise have expired.
        painted = true;
        render(payload);
        if (timer) { clearInterval(timer); timer = null; }
        document.documentElement.setAttribute('data-rendered', '1');
    }

    if (CONFIG.offline) {
        client.fetchGame()
            .then(renderAt)
            .catch(function (e) { showError({ message: e.message }); });
    } else if (CONFIG.demo) {
        // One real fetch for the payload shape, then the script drives it. The
        // poll loop never starts, so nothing overwrites a demo frame.
        el.demoLabel.classList.remove('hide');
        client.fetchGame()
            .then(function (base) {
                var demo = runOverlayDemo(base, function (payload, label) {
                    el.demoLabel.textContent = label;
                    render(payload);
                }, { stepMs: CONFIG.demoStep });
                window.addEventListener('beforeunload', function () { demo.stop(); });
            })
            .catch(function (e) {
                showError({ message: 'Demo needs one real payload first: ' + e.message });
            });
    } else if (CONFIG.field) {
        /**
         * Field-following: resolve first, then start, then keep checking.
         *
         * The client is rebuilt rather than mutated when the game changes,
         * because everything downstream of a game id — the goal history the
         * hold/break derivation walks, the score the flash compares against —
         * is per game. Carrying any of it across would announce the new game's
         * first goal as if it had just been scored.
         */
        var following = null;

        var follow = function () {
            window.FieldResolver.resolveFieldGame(CONFIG.apiBase, CONFIG.field)
                .then(function (id) {
                    if (!id) {
                        if (!following) {
                            showError({ message: 'No game found on field ' + CONFIG.field + '.' });
                        }
                        return;
                    }
                    if (id === following) { return; }
                    following = id;
                    if (client) { client.stop(); }
                    resetForNewGame();
                    CONFIG.gameId = id;
                    client = buildClient();
                    client.start();
                })
                .catch(function () { /* transient; the next tick retries */ });
        };

        follow();
        setInterval(follow, CONFIG.fieldPoll);
        window.addEventListener('beforeunload', function () { if (client) { client.stop(); } });
    } else {
        client.start();
        window.addEventListener('beforeunload', function () { client.stop(); });
    }
}());
</script>
</body>
</html>
