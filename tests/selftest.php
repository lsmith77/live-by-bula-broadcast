<?php
/**
 * Live! by BULA video overlays — switcher self-test
 *
 *   /index.php?view=live/overlays/tests/selftest
 *
 * Point a switcher's browser source at this and look at the PROGRAM OUTPUT, not
 * at a laptop. Every panel below moves on its own; whichever ones are frozen
 * tell you which layer the device is not running.
 *
 * This exists because "the overlay does not update" has at least five distinct
 * causes and they need completely different fixes:
 *
 *   ALL MOVING          The device is fine. The overlay's latency is the cause —
 *                       see the LATENCY panel and lower CACHE_MINUTES_MODULATOR.
 *   CSS moves, JS frozen  The device rasterises CSS animation but suspends JS
 *                       timers. Nothing data-driven can work; needs meta-refresh.
 *   ONLY CSS + RAF move  Timers throttled in a background tab/source. Try making
 *                       the source visible or foregrounded.
 *   NOTHING moves       The device fetched the page once and rasterised a still.
 *                       No browser-source overlay can be live; use a different
 *                       transport.
 *   NET frozen, rest moving  The device renders fine but cannot reach the server.
 *                       Check the LAN address — localhost will never work.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

$prefix = defined('UO_URL_PREFIX') ? UO_URL_PREFIX : '/';
$apiBase = rtrim($prefix, '/') . '/index.php?view=live/api';

// A game id is optional: with one, the NET panel polls the real payload and
// reports the actual cache lifetime, which is the number that matters.
$gameId = filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$backgrounds = ['green' => '#00B140', 'blue' => '#0047BB', 'magenta' => '#FF00FF', 'black' => '#000000'];
$bgParam = strtolower((string) filter_input(INPUT_GET, 'bg'));
$background = $backgrounds[$bgParam] ?? '#101418';

$json = static fn ($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Overlay self-test</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    background: <?= htmlspecialchars($background, ENT_QUOTES) ?>;
    color: #fff;
    font-family: 'Arial Narrow', helvetica, arial, sans-serif;
    overflow: hidden;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 18px;
    padding: 40px;
}

.panel {
    padding: 20px 24px;
    background: rgb(255 255 255 / 8%);
    border-left: 8px solid #4ade80;
    border-radius: 6px;
}

.label {
    color: rgb(255 255 255 / 65%);
    font-size: 20px;
    letter-spacing: 0.16em;
    text-transform: uppercase;
}

.value {
    font-size: 66px;
    font-weight: 800;
    line-height: 1.05;
    font-variant-numeric: tabular-nums;
}

.hint {
    margin-top: 4px;
    color: rgb(255 255 255 / 55%);
    font-size: 17px;
}

/* Pure CSS, no JS involved. If this is the only thing moving, the device is
   compositing but not running script. */
.sweep {
    height: 28px;
    margin-top: 10px;
    overflow: hidden;
    background: rgb(0 0 0 / 35%);
    border-radius: 4px;
}

.sweep i {
    display: block;
    width: 28%;
    height: 100%;
    background: #4ade80;
    border-radius: 4px;
    animation: sweep 2s linear infinite;
}

@keyframes sweep {
    from { transform: translateX(-100%); }
    to { transform: translateX(400%); }
}

.bad { border-left-color: #ef4444; }

.verdict {
    grid-column: 1 / -1;
    padding: 18px 24px;
    background: rgb(0 0 0 / 45%);
    border-radius: 6px;
    font-size: 21px;
    line-height: 1.5;
}

.verdict b { color: #4ade80; }
</style>
</head>
<body>
<div class="grid">

    <div class="panel">
        <div class="label">1 · JS timer (setInterval)</div>
        <div class="value" id="wall">--:--:--</div>
        <div class="hint">Frozen = the device suspends JS timers.</div>
    </div>

    <div class="panel">
        <div class="label">2 · Animation frames (rAF)</div>
        <div class="value" id="raf">0</div>
        <div class="hint">Frozen = no repaint loop.</div>
    </div>

    <div class="panel">
        <div class="label">3 · CSS animation (no JS)</div>
        <div class="value" style="font-size: 34px;" id="cssnote">should slide left to right</div>
        <div class="sweep"><i></i></div>
    </div>

    <div class="panel" id="netPanel">
        <div class="label">4 · Network poll</div>
        <div class="value" id="net">0</div>
        <div class="hint" id="netHint">Successful fetches since load.</div>
    </div>

    <div class="panel" style="grid-column: 1 / -1;">
        <div class="label">5 · Latency budget</div>
        <div class="value" style="font-size: 40px;" id="latency">measuring…</div>
        <div class="hint">
            Server cache lifetime is what limits how fresh a score can be, no
            matter how often the page polls. Lower CACHE_MINUTES_MODULATOR in
            live/conf/local-config.json to shrink it.
        </div>
    </div>

    <div class="verdict">
        Watch the PROGRAM OUTPUT for 30 seconds.
        <b>All four moving</b> = the device is fine and the overlay's own latency
        is the problem. <b>Only panel 3 moving</b> = JS is suspended.
        <b>Nothing moving</b> = the device rasterised a still and no browser-source
        overlay can be live. <b>Only 4 frozen</b> = it cannot reach the server;
        the URL must use the machine's LAN address, never localhost.
    </div>

</div>

<script>
(function () {
    'use strict';

    var CONFIG = {
        apiBase: <?= $json($apiBase) ?>,
        gameId: <?= $json($gameId) ?>
    };

    var t0 = Date.now();

    // 1 — plain timer.
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    setInterval(function () {
        var d = new Date();
        document.getElementById('wall').textContent =
            pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }, 250);

    // 2 — repaint loop, counted in whole frames so a throttled source is obvious.
    var frames = 0;
    (function step() {
        frames += 1;
        document.getElementById('raf').textContent = frames;
        requestAnimationFrame(step);
    }());

    // 4 — network. Cache-busted so a caching proxy in front of the device cannot
    // make a dead connection look alive.
    var ok = 0;
    var fail = 0;

    function poll() {
        var url = CONFIG.gameId
            ? CONFIG.apiBase + '&entity=games&id=' + encodeURIComponent(CONFIG.gameId)
            : CONFIG.apiBase + '&entity=config';
        url += '&_cb=' + Date.now();

        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
            .then(function (body) {
                ok += 1;
                document.getElementById('net').textContent = ok;
                document.getElementById('netPanel').className = 'panel';

                var meta = body && body.meta;
                if (meta && meta.cache_lifetime !== undefined) {
                    var life = Number(meta.cache_lifetime);
                    document.getElementById('latency').textContent =
                        'server cache ' + life + 's  ·  worst-case on-air lag ~' + (life * 2) + 's';
                }
                document.getElementById('netHint').textContent =
                    ok + ' ok / ' + fail + ' failed  ·  age ' +
                    Math.round((Date.now() - t0) / 1000) + 's';
            })
            .catch(function (e) {
                fail += 1;
                document.getElementById('netPanel').className = 'panel bad';
                document.getElementById('netHint').textContent =
                    ok + ' ok / ' + fail + ' failed  ·  last error: ' + e.message;
            });
    }

    poll();
    setInterval(poll, 2000);
}());
</script>
</body>
</html>
