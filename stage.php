<?php
/**
 * Live! by BULA video overlays — stage.
 *
 *   /index.php?view=live/overlays/stage
 *   /s/stage                                (short form)
 *
 * One full-frame transparent surface for the whole broadcast. The switcher points
 * at this once and never changes; what appears on it is decided by the show state
 * (conf/show.json), which the Studio UI writes.
 *
 * Two independent polls, and the split is the point:
 *
 *   show state   conf/show.json, static, ~1s   — an operator click must feel instant
 *   game data    ?view=live/api, cached        — the numbers, 10-30s behind at worst
 *
 * The show-state read deliberately does NOT go through UltiOrganizer's front
 * controller. At one second that would be a database connection and a session per
 * second, per stage; conf/ is web-reachable so the file is read directly.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../conf/LocalConfig.php';
require_once __DIR__ . '/shared/show.php';
require_once __DIR__ . '/shared/logos.php';

use Overlays\Show;

$prefix = defined('UO_URL_PREFIX') ? UO_URL_PREFIX : '/';
$base = rtrim($prefix, '/');
$apiBase = $base . '/index.php?view=live/api';
$assetBase = $base . '/live/overlays';

/** Whitelisted so a URL parameter can never reach a class attribute verbatim. */
$backgrounds = ['green' => '#00B140', 'blue' => '#0047BB', 'magenta' => '#FF00FF', 'black' => '#000000'];
$bgParam = strtolower((string) filter_input(INPUT_GET, 'bg'));
$hex = (string) filter_input(INPUT_GET, 'bg');
$background = $backgrounds[$bgParam]
    ?? (preg_match('/^#?[0-9A-Fa-f]{6}$/', $hex) ? '#' . strtoupper(ltrim($hex, '#')) : 'transparent');

// A game id on the URL overrides the show state's, so one stage can be pinned to
// a field while another follows the operator.
$pinnedGame = filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$showPoll = filter_input(INPUT_GET, 'showpoll', FILTER_VALIDATE_INT, [
    'options' => ['default' => 1000, 'min_range' => 250, 'max_range' => 60000],
]);

$store = new Show();

$assetUrl = static function (string $relative) use ($assetBase): string {
    $relative = ltrim($relative, '/');
    $path = __DIR__ . '/' . $relative;
    $version = is_file($path) ? (string) filemtime($path) : '0';
    return $assetBase . '/' . $relative . '?v=' . $version;
};

header('Cache-Control: no-store, must-revalidate');

$json = static fn ($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Overlay stage</title>
<link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('shared/overlay-base.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('shared/stage.css'), ENT_QUOTES) ?>">
<style>body { background-color: <?= htmlspecialchars($background, ENT_QUOTES) ?>; }</style>
</head>
<body class="stage-body">

<div class="stage-canvas" id="canvas">
    <div class="stage" id="stage">
        <div class="slot" data-slot="upper-left"></div>
        <div class="slot" data-slot="upper-center"></div>
        <div class="slot" data-slot="upper-right"></div>
        <div class="slot" data-slot="center"></div>
        <div class="slot" data-slot="lower-left"></div>
        <div class="slot" data-slot="lower-center"></div>
        <div class="slot" data-slot="lower-right"></div>
    </div>
    <div class="slot with-scoreboard" data-slot="with-scoreboard"></div>
    <div class="slot fullscreen" data-slot="fullscreen"></div>
    <div class="tourney-logo" id="tourneyLogo"></div>
</div>

<script src="<?= htmlspecialchars($assetUrl('shared/overlay-client.js'), ENT_QUOTES) ?>"></script>
<script>
(function () {
    'use strict';

    var CONFIG = {
        apiBase: <?= $json($apiBase) ?>,
        assetBase: <?= $json($assetBase) ?>,
        showUrl: <?= $json($store->publicUrl($assetBase)) ?>,
        showPoll: <?= (int) $showPoll ?>,
        pinnedGame: <?= $json($pinnedGame ?: null) ?>,
        bg: <?= $json($bgParam) ?>,
        fallback: <?= $json(Show::defaults()) ?>,
        // Team id -> logo URL, same store the scoreboard uses. Live!'s own team
        // photos are the fallback when a team has no logo here.
        teamLogos: <?= $json((object) (new \Overlays\Logos(null, $assetBase . '/logos'))->all()) ?>
    };

    /**
     * Resolve a team's logo and wait for it to DECODE.
     *
     * Part of arming (see docs/STUDIO.md 2.6): a card must not appear while its
     * artwork is still being fetched, or it pops in half-drawn on air. `decode()`
     * rather than `onload` because onload resolves before the bitmap is ready and
     * the first paint can still stall.
     *
     * A missing or slow image resolves to null rather than rejecting — a card
     * without a crest is a much smaller failure than a card that never appears.
     */
    function teamLogo(team) {
        if (!team) { return Promise.resolve(null); }
        var src = CONFIG.teamLogos[team.team_id] || null;
        if (!src) {
            var photo = Array.isArray(team.photos) && team.photos.length ? team.photos[0] : null;
            src = typeof photo === 'string' ? photo : (photo && photo.url) || null;
        }
        if (!src) { return Promise.resolve(null); }

        return new Promise(function (resolve) {
            var img = new Image();
            img.onload = function () {
                (img.decode ? img.decode() : Promise.resolve())
                    .then(function () { resolve(src); })
                    .catch(function () { resolve(src); });
            };
            img.onerror = function () { resolve(null); };
            img.src = src;
        });
    }

    /** A crest beside a team name, or nothing when there is no artwork. */
    function logoNode(src) {
        if (!src) { return null; }
        var img = document.createElement('img');
        img.className = 'teamlogo';
        img.alt = '';
        img.src = src;
        return img;
    }

    var slots = {};
    Array.prototype.forEach.call(document.querySelectorAll('.slot'), function (node) {
        slots[node.dataset.slot] = node;
    });

    /**
     * Scale the 1920x1080 canvas to whatever viewport it is being shown in.
     *
     * The stage is authored at a fixed 1920x1080 because that is what a switcher
     * feeds it, and it must not reflow — a graphic that moves when the source
     * resolution changes is useless for broadcast. But that made it unusable to
     * *preview*: opened in an ordinary browser window, everything anchored to the
     * bottom sat below the fold, and the canvas has `overflow: hidden` so there
     * was nothing to scroll to. The page looked simply blank.
     *
     * Scaling keeps the layout exact — same proportions, same relative
     * positions — while letting the whole frame fit any window. On a switcher
     * running at native 1920x1080 the factor is 1 and nothing changes at all.
     *
     * A useful side effect: a transformed ancestor becomes the containing block
     * for `position: fixed` descendants, so the framed slots, the fullscreen
     * takeover and the companion strip all resolve against the canvas rather
     * than the window, and keep working unchanged.
     */
    var canvas = document.getElementById('canvas');

    function fitCanvas() {
        var scale = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
        canvas.style.transform = 'scale(' + scale + ')';
    }

    fitCanvas();
    window.addEventListener('resize', fitCanvas);

    /* ---------------------------------------------------------------------
       Cards

       Each card declares how it mounts and how it updates. Two kinds exist:

       `frame`  an iframe onto an existing overlay page. The scoreboard is
                already a complete, self-contained overlay — re-implementing it
                inside the stage would duplicate several hundred lines and give
                the two copies room to drift. It polls the API itself.

       `inline` rendered by the stage from the game payload it already polls.
                Cheaper for small cards, and the only option for anything that
                has to coordinate with another card.
       --------------------------------------------------------------------- */

    /**
     * Build a one-line "who did the last one" card.
     *
     * Three exist — scorer alone, assist alone, and both together — because an
     * operator wants different amounts of it at different moments: the pair
     * right after a point, one of them while something else occupies the frame.
     * They differ only in which names they read, so one definition generates
     * all three.
     *
     * `roles` is a list of 'scorer' / 'assist'. Both are named on every goal in
     * the payload, with jersey numbers, so none of these needs an extra request.
     */
    function lastPlayCard(roles) {
        var LABELS = { scorer: 'Goal', assist: 'Assist' };

        function nameOf(goal, role) {
            return ((goal[role + 'firstname'] || '') + ' '
                + (goal[role + 'lastname'] || '')).trim();
        }

        return {
            kind: 'inline',
            arm: function (payload) {
                var goals = Array.isArray(payload.goals) ? payload.goals.slice() : [];
                goals.sort(function (a, b) { return a.num - b.num; });

                // Walk BACKWARDS to the most recent goal that names the leading
                // role. A scorekeeper under pressure often records the score
                // first and attributes it later, and an unassisted goal names no
                // thrower at all — in both cases the useful answer is the last
                // one we do know, not silence.
                for (var i = goals.length - 1; i >= 0; i -= 1) {
                    var g = goals[i];
                    if (!nameOf(g, roles[0])) { continue; }

                    // Secondary roles are optional: a genuinely unassisted goal
                    // drops the assist rather than inventing one.
                    var parts = roles.map(function (role) {
                        var name = nameOf(g, role);
                        return name ? { tag: LABELS[role], name: name, num: g[role + 'num'] } : null;
                    }).filter(Boolean);

                    var teams = payload.teams || {};
                    var side = Number(g.ishomegoal) === 1 ? teams.hometeam : teams.visitorteam;
                    // The crest is armed with the card, so the strip never
                    // appears and then acquires a logo a moment later.
                    return teamLogo(side).then(function (logo) {
                        return {
                            team: (side && (side.name || '')) || '',
                            logo: logo,
                            parts: parts
                        };
                    });
                }
                return null;
            },
            render: function (node, d) {
                node.replaceChildren();
                var card = document.createElement('div');
                card.className = 'card lastplay';

                d.parts.forEach(function (part) {
                    var group = document.createElement('span');
                    group.className = 'lastplay-part';

                    var tag = document.createElement('span');
                    tag.className = 'lastplay-tag';
                    tag.textContent = part.tag;
                    group.appendChild(tag);

                    if (part.num !== undefined && part.num !== null && part.num !== '') {
                        var n = document.createElement('span');
                        n.className = 'lastplay-num';
                        n.textContent = part.num;
                        group.appendChild(n);
                    }

                    var who = document.createElement('span');
                    who.className = 'lastplay-name';
                    who.textContent = part.name;
                    group.appendChild(who);

                    card.appendChild(group);
                });

                var team = document.createElement('span');
                team.className = 'lastplay-team';
                var crest = logoNode(d.logo);
                if (crest) { team.appendChild(crest); }
                var who = document.createElement('span');
                who.textContent = d.team;
                team.appendChild(who);
                card.appendChild(team);

                node.appendChild(card);
            }
        };
    }

    /**
     * A player's gender matching, in the sport's own terms.
     *
     * Mixed ultimate is played to a gender-matching ratio, so FMP and MMP are
     * the RULES' vocabulary — a competition designation, not a description of a
     * person. That distinction is the whole point of the terminology, and it is
     * why this must not be derived from a personal-identity field.
     *
     * **Nothing supplies this today, and the obvious candidate is not it.**
     * UltiOrganizer models gender ratio only for printed scoresheets
     * (`cust/wfdf/pdfscoresheet.php`, which detects a mixed division by matching
     * the word "mixed" in its name and prints "4M/3F" style rules); it never
     * records which players are which. `uo_player_profile.gender` exists, but it
     * is a self-declared F/M/**O** profile field behind a privacy opt-in
     * (`user/playerprofile.php:247-269`) — so it cannot answer for a player who
     * selected Other, may be withheld entirely, and is personal data rather than
     * a roster designation. Reading it as a matching would be both a category
     * error and unreliable. No Live! entity exposes it either.
     *
     * So `matching` arrives only if some future roster field supplies it, and
     * until then this returns null for everyone and the balanced split never
     * engages — the same adapt-at-runtime rule the BLOCKS column follows.
     *
     * Only `FMP` and `MMP` are accepted. Not F/M/O: those are the values of a
     * personal-identity field, and mapping them onto a matching is the category
     * error this whole comment exists to prevent — "O" in particular has no
     * matching at all, so there is nothing to map it to. A source that wants to
     * supply matchings must say FMP or MMP.
     *
     * Callers must also only ask in a MIXED division. Elsewhere every player
     * shares one matching, so a label carries no information.
     */
    function matchingOf(player) {
        var g = String(player.matching || '').trim().toUpperCase();
        if (g === 'FMP') { return 'FMP'; }
        if (g === 'MMP') { return 'MMP'; }
        return null;
    }

    /**
     * Top `n`, balanced across gender matchings when that is knowable.
     *
     * With matchings present, an even split — top two of each for n=4 — so a
     * mixed team's card cannot end up showing four MMPs and erasing the FMP
     * contribution, which is a well-known failing of ultimate coverage.
     *
     * Without them, or in a single-gender division where one group is empty,
     * this is a plain ranking. Better an honest top four than a split invented
     * from data we do not have.
     */
    function topBalanced(players, n) {
        var groups = { FMP: [], MMP: [] };
        players.forEach(function (p) {
            if (p.matching && groups[p.matching]) { groups[p.matching].push(p); }
        });
        if (!groups.FMP.length || !groups.MMP.length) {
            return players.slice(0, n);
        }

        var each = Math.max(1, Math.floor(n / 2));
        var picked = groups.FMP.slice(0, each).concat(groups.MMP.slice(0, each));

        // Any shortfall (a team with only one FMP, say) is filled from the
        // overall ranking rather than left as a gap.
        players.forEach(function (p) {
            if (picked.length < n && picked.indexOf(p) === -1) { picked.push(p); }
        });
        picked.sort(function (a, b) { return b.total - a.total; });
        return picked.slice(0, n);
    }

    var CARDS = {
        scoreboard: {
            kind: 'frame',
            /**
             * The scoreboard positions itself inside its own full-frame viewport,
             * so the slot cannot place it — the slot has to be TOLD to it.
             *
             * This was hard-coded to bottom-left, which meant moving the card in
             * the Studio changed nothing on screen: the operator picked a
             * position, the graphic stayed where it was, and anything already
             * bottom-left ended up underneath it.
             */
            src: function (game, slot) {
                var POSITIONS = {
                    'upper-left': 'top-left',
                    'upper-center': 'top-center',
                    'upper-right': 'top-right',
                    'lower-left': 'bottom-left',
                    'lower-center': 'bottom-center',
                    'lower-right': 'bottom-right'
                };
                var url = CONFIG.apiBase.replace('view=live/api', 'view=live/overlays/scoreboard');
                url += '&game=' + encodeURIComponent(game);
                url += '&position=' + (POSITIONS[slot] || 'bottom-left');
                // No &bg=: the stage already paints the chroma background, and a
                // card painting its own would punch an opaque rectangle through it.
                url += '&ribbon=1';
                return url;
            }
        },
        lastgoal: lastPlayCard(['scorer']),
        lastassist: lastPlayCard(['assist']),
        lastplay: lastPlayCard(['scorer', 'assist']),
        topplayers: {
            kind: 'inline',
            /**
             * Top scorers, N from EACH team rather than N overall.
             *
             * An overall ranking is the wrong shape here: one strong team can
             * take every place and the card stops being a comparison, which is
             * the only reason to show it during a game. Per team it always
             * answers "who is doing the damage on either side".
             *
             * `total` is served pre-summed, so this is a sort rather than a
             * computation. Tournament figures come from entity=teams, which is
             * what makes it useful *before* a game as well as during one.
             */
            arm: function (payload, params) {
                var teams = payload.teams || {};
                var ids = [teams.hometeam, teams.visitorteam]
                    .filter(Boolean)
                    .map(function (t) { return t.team_id; })
                    .filter(Boolean);
                if (!ids.length) { return Promise.resolve(null); }

                // Four rather than three, so a gender-balanced division can show
                // the top two of each matching and still be one glanceable card.
                var perTeam = Math.max(1, Math.min(8, Number(params.count) || 4));

                return Promise.all(ids.map(function (id) {
                    return fetch(CONFIG.apiBase + '&entity=teams&id=' + encodeURIComponent(id),
                        { credentials: 'same-origin' })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .catch(function () { return null; });
                })).then(function (results) {
                    var teams = results.filter(Boolean);

                    return Promise.all(teams.map(function (t) { return teamLogo(t); }))
                        .then(function (logos) {
                            var groups = teams.map(function (team, i) {
                                // Gender matching means something only in mixed.
                                // In open and women's divisions every player has
                                // the same one, so a label says nothing and a
                                // balanced split has nothing to balance — both
                                // stay off rather than rendering noise.
                                var mixed = String(team.type || '').toLowerCase() === 'mixed';

                                var players = (team.players || []).map(function (p) {
                                    return {
                                        name: ((p.firstname || '') + ' ' + (p.lastname || '')).trim(),
                                        num: p.num,
                                        matching: mixed ? matchingOf(p) : null,
                                        goals: Number(p.done) || 0,
                                        assists: Number(p.fedin) || 0,
                                        total: Number(p.total) || 0
                                    };
                                });
                                players.sort(function (a, b) { return b.total - a.total; });

                                return {
                                    team: team.name || '',
                                    logo: logos[i],
                                    players: topBalanced(players, perTeam)
                                };
                            }).filter(function (g) { return g.players.length; });

                            return groups.length ? groups : null;
                        });
                });
            },
            render: function (node, groups) {
                node.replaceChildren();
                var card = document.createElement('div');
                card.className = 'card statcard';

                var head = document.createElement('div');
                head.className = 'statcard-head';
                head.textContent = 'Top scorers';
                card.appendChild(head);

                // Side by side, one column per team, so the card reads as a
                // comparison rather than a single ranked list.
                var cols = document.createElement('div');
                cols.className = 'statcols';

                groups.forEach(function (group) {
                    var col = document.createElement('div');
                    col.className = 'statcol';

                    var title = document.createElement('div');
                    title.className = 'statcol-team';
                    var crest = logoNode(group.logo);
                    if (crest) { title.appendChild(crest); }
                    var label = document.createElement('span');
                    label.className = 'statcol-name';
                    label.textContent = group.team;
                    title.appendChild(label);
                    col.appendChild(title);

                    group.players.forEach(function (p) {
                        var row = document.createElement('div');
                        row.className = 'statrow';

                        var rank = document.createElement('span');
                        rank.className = 'statrow-num';
                        rank.textContent = p.num !== undefined && p.num !== null ? p.num : '';
                        row.appendChild(rank);

                        var name = document.createElement('span');
                        name.className = 'statrow-name';
                        name.textContent = p.name || '—';
                        row.appendChild(name);

                        // Only when the split is actually in play; an unlabelled
                        // row is better than one labelled from a guess.
                        if (p.matching) {
                            var m = document.createElement('span');
                            m.className = 'statrow-matching';
                            m.textContent = p.matching;
                            row.appendChild(m);
                        }

                        // Goals and assists alongside the total, so the number
                        // is explained rather than asserted.
                        [[p.goals, 'G'], [p.assists, 'A'], [p.total, 'PTS']].forEach(function (pair, i) {
                            var v = document.createElement('span');
                            v.className = 'statrow-val' + (i === 2 ? ' strong' : '');
                            v.textContent = pair[0];
                            v.dataset.label = pair[1];
                            row.appendChild(v);
                        });

                        col.appendChild(row);
                    });

                    cols.appendChild(col);
                });

                card.appendChild(cols);
                node.appendChild(card);
            }
        }
    };

    /* ---------------------------------------------------------------------
       Mounting

       A card is armed before it is shown: data fetched, assets decoded, layout
       done offscreen. Only then does it become visible. A card that appears
       mid-load pops in half-drawn, on air.
       --------------------------------------------------------------------- */

    /**
     * Mounted cards, keyed by slot AND card id.
     *
     * Not keyed by slot alone: `with-scoreboard` stacks several cards, so a slot
     * is not a unique mount point. Each card gets its own container inside the
     * slot, which is also what lets one be visible while another is merely
     * armed.
     */
    var mounted = {};
    var payload = null;
    var currentGame = null;

    /* ---------------------------------------------------------------------
       Auto mode: a card that shows itself when there is something to say
       --------------------------------------------------------------------- */

    /**
     * How long a last-goal card stays up after a goal.
     *
     * Long enough to read two names off a screen while still watching the
     * game, short enough to be gone before the next pull. Fifteen seconds is
     * roughly the gap between a goal and the restart.
     */
    var AUTO_MS = 15000;

    var autoUntil = 0;
    var lastGoalCount = null;
    var autoTimer = null;

    /**
     * Notice a goal, and open the auto window.
     *
     * Only a goal being ADDED counts. A scorekeeper correcting an attribution
     * rewrites an existing entry, and re-showing the card for that would put a
     * graphic on air for an edit nobody watching can see the reason for.
     *
     * `lastGoalCount` starts null so the first payload only establishes the
     * baseline. Without that, every stage would flash the last goal of the
     * match the moment it loaded — the same first-paint trap the scoreboard's
     * score animation already had to be taught to avoid.
     */
    function noteGoals(body) {
        var count = Array.isArray(body && body.goals) ? body.goals.length : 0;
        var was = lastGoalCount;
        lastGoalCount = count;
        if (was === null || count <= was) { return false; }

        autoUntil = Date.now() + AUTO_MS;
        // Re-apply when the window shuts. The show poll only calls applyShow on
        // a rev change and the game poll is far slower than 15s, so without this
        // the card would simply stay up.
        if (autoTimer) { clearTimeout(autoTimer); }
        autoTimer = setTimeout(function () {
            autoTimer = null;
            applyShow(lastShow);
        }, AUTO_MS + 50);
        return true;
    }

    /**
     * Whether a card wants to be on screen right now.
     *
     * Auto is a mode of being ON AIR, not an alternative to it: the card still
     * has to be switched on, which is what reserves the slot and keeps the
     * store's one-visible-per-slot rule meaningful. Auto only decides whether an
     * on-air card is currently painting. So "on air" keeps meaning "this card
     * owns this position", and auto adds "and it speaks only when there is
     * something to say".
     */
    function wantsVisible(entry) {
        if (!entry.visible) { return false; }
        if (!entry.params || !entry.params.auto) { return true; }
        return Date.now() < autoUntil;
    }

    function keyOf(slot, cardId) { return slot + '::' + cardId; }

    function unmount(key) {
        var m = mounted[key];
        if (!m) { return; }
        if (m.host && m.host.parentNode) { m.host.parentNode.removeChild(m.host); }
        delete mounted[key];
        refreshSlot(m.slot);
    }

    /** A slot is shown when any card in it is. */
    function refreshSlot(slot) {
        var node = slots[slot];
        if (!node) { return; }
        var any = Object.keys(mounted).some(function (k) {
            var m = mounted[k];
            return m.slot === slot && m.visible && m.ready;
        });
        node.classList.toggle('shown', any);
    }

    /** The container a single card lives in, created on first mount. */
    function hostFor(slot, cardId) {
        var key = keyOf(slot, cardId);
        if (mounted[key] && mounted[key].host) { return mounted[key].host; }
        var host = document.createElement('div');
        host.className = 'mount';
        host.dataset.card = cardId;
        slots[slot].appendChild(host);
        return host;
    }

    /**
     * A slot is revealed only when its card is both mounted and wanted visible.
     *
     * Placement and visibility are separate states on purpose. A card that is
     * placed but switched off is still MOUNTED — iframe loaded, data fetched,
     * layout done — it is simply not revealed. That is what makes switching it
     * on instant and free of any pop-in: by the time an operator presses the
     * button there is nothing left to load.
     */
    function applyVisibility(key) {
        var m = mounted[key];
        if (!m) { return; }
        m.host.classList.toggle('shown', Boolean(m.visible && m.ready));
        refreshSlot(m.slot);
    }

    function mountFrame(slot, card, game, visible) {
        var key = keyOf(slot, card.id);
        var existing = mounted[key];
        var src = card.src(game, slot);

        if (existing && existing.src === src) {
            existing.visible = visible;   // already mounted; only the switch moved
            applyVisibility(key);
            return;
        }

        var host = hostFor(slot, card.id);
        host.replaceChildren();

        var frame = document.createElement('iframe');
        frame.className = 'card-frame';
        frame.setAttribute('scrolling', 'no');
        frame.setAttribute('tabindex', '-1');
        frame.addEventListener('load', function () {
            if (mounted[key]) { mounted[key].ready = true; }
            applyVisibility(key);
            requestAnimationFrame(function () { measureBug(); placeLogo(); });
            watchFrame(frame);
        });
        frame.src = src;
        host.appendChild(frame);

        mounted[key] = { id: card.id, slot: slot, host: host, src: src,
                         visible: visible, ready: false };
        applyVisibility(key);
    }

    function mountInline(slot, card, params, visible) {
        if (!payload) { return; }
        var key = keyOf(slot, card.id);

        Promise.resolve(card.arm(payload, params || {}))
            .then(function (data) {
                if (!data) {
                    // Nothing to show is not an error: a card with no data stays
                    // dark rather than rendering an empty box.
                    unmount(key);
                    return;
                }
                var host = hostFor(slot, card.id);
                card.render(host, data);
                mounted[key] = { id: card.id, slot: slot, host: host,
                                 visible: visible, ready: true };
                applyVisibility(key);
            })
            .catch(function () { unmount(key); });
    }

    /**
     * Point the `with-scoreboard` slot at wherever the scoreboard currently is.
     *
     * The companion takes the scoreboard's horizontal alignment and sits on the
     * far side of it vertically: a bug in the lower band gets its companion
     * ABOVE, an upper bug gets it BELOW. That way the pair never overlaps and
     * never runs off the frame, whichever corner the operator chose — and moving
     * the scoreboard moves the companion with it, with nothing to keep in sync.
     *
     * Falls back to the lower-left pairing when the scoreboard is not placed at
     * all, so a companion switched on by itself still lands somewhere sensible.
     */
    /**
     * How much vertical room the scoreboard actually occupies.
     *
     * This was a hard-coded guess (138px) and it was wrong — the real block is
     * 160px, because the callout row above the bar adds height that was not in
     * the arithmetic. The result was a companion strip sitting 22px on top of the
     * scoreboard.
     *
     * So it is measured instead. The scoreboard is same-origin in its iframe, so
     * its rendered height is readable directly, and the value adapts to the
     * ribbon being switched off or a callout appearing without anyone
     * maintaining a constant.
     *
     * Only ever grows. A HOLD/BREAK callout appears for under a second, and a
     * companion that dropped every time one vanished would twitch continuously;
     * reserving the tallest seen costs a few idle pixels and holds still.
     */
    var bugBlock = 0;
    var frameWatch = null;

    /**
     * Re-measure whenever the scoreboard inside the frame changes size.
     *
     * Its dimensions arrive asynchronously and repeatedly: the element starts
     * `display: none` and has a zero box until the overlay's own first payload
     * lands, then grows again when a ribbon or a callout row appears. Measuring
     * only at load and once afterwards left a window — sometimes a long one, if
     * that first payload was slow — in which the logo had been placed against a
     * scoreboard of zero height and so sat on top of it.
     *
     * A ResizeObserver closes the window instead of guessing at a delay. The
     * frame is same-origin, so it can observe the element directly.
     */
    function watchFrame(frame) {
        if (frameWatch) { frameWatch.disconnect(); frameWatch = null; }
        if (typeof ResizeObserver !== 'function') { return; }
        try {
            var doc = frame.contentDocument;
            var board = doc && doc.getElementById('scoreboard');
            if (!board) { return; }
            frameWatch = new ResizeObserver(function () {
                measureBug();
                placeLogo();
            });
            frameWatch.observe(board);
        } catch (e) {
            // Cross-origin, or no observer: the periodic re-measure still runs.
        }
    }

    function measureBug() {
        var frame = document.querySelector('.card-frame');
        if (!frame) { return; }
        try {
            var doc = frame.contentDocument;
            var board = doc && doc.getElementById('scoreboard');
            if (!board) { return; }
            var h = Math.round(board.getBoundingClientRect().height);
            if (h > bugBlock) {
                bugBlock = h;
                var node = slots['with-scoreboard'];
                // 14px of air, so the two read as separate graphics rather than
                // one block with a seam.
                if (node) { node.style.setProperty('--bug-block', (h + 14) + 'px'); }
                // The grid reserves the same band, so a tall centre card cannot
                // grow down into the scoreboard either.
                document.getElementById('stage').style
                    .setProperty('--band-bottom', (h + 14 + 60) + 'px');
            }
        } catch (e) {
            // Cross-origin would land here; same-origin today, but a measurement
            // failure must not take the stage down — the CSS default stands.
        }
    }

    /* ---------------------------------------------------------------------
       Tournament logo

       Not a card: it is persistent branding rather than something an operator
       shows and hides, and — unlike a card — it must COEXIST with whatever
       occupies its corner rather than taking the position from it. So it lives
       on its own layer and steps out of the way instead of competing.
       --------------------------------------------------------------------- */

    var logoEl = document.getElementById('tourneyLogo');
    var logoReady = false;
    var logoCorner = '';
    var logoFloor = 0;      // high-water offset; see placeLogo

    function loadTourneyLogo() {
        if (!logoEl || logoReady) { return; }
        logoReady = true;   // one attempt; a missing logo is not worth retrying
        fetch(CONFIG.apiBase + '&entity=config', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (body) {
                var cfg = (body && (body.config || body)) || {};
                var src = cfg.TV_SCREEN_LOGO_PATH;
                if (!src) { return; }
                var img = new Image();
                img.onload = function () {
                    // Decoded before it is inserted, like every other asset here.
                    (img.decode ? img.decode() : Promise.resolve())
                        .catch(function () {})
                        .then(function () {
                            logoEl.replaceChildren(img);
                            placeLogo();
                        });
                };
                img.onerror = function () { /* configured but missing: stay dark */ };
                img.alt = '';
                img.src = src;
            });
    }

    /**
     * Keep the logo clear of anything sharing its corner.
     *
     * The rule is deliberately simple and general: take every visible card that
     * overlaps the logo horizontally, and push the logo away from its corner
     * past the furthest of them. That covers the scoreboard, the companion
     * strip, and any future card, without the logo needing to know what any of
     * them are — and without an operator having to keep two positions in sync.
     *
     * Measured rather than reasoned about, because the things it has to avoid
     * change height at runtime: a callout row appears, a ribbon is switched off.
     */
    /** Every visible card's drawn box, in canvas coordinates. */
    function contentBoxes() {
        var boxes = [];
        Object.keys(mounted).forEach(function (key) {
            var m = mounted[key];
            if (!m.visible || !m.ready) { return; }
            var content = m.host.querySelector('.card') || m.host.querySelector('iframe');
            if (!content) { return; }

            // A framed card fills the frame, so measure what it actually draws
            // rather than the iframe, which is always the whole 1920x1080.
            if (content.tagName === 'IFRAME') {
                try {
                    var inner = content.contentDocument
                        && content.contentDocument.getElementById('scoreboard');
                    if (inner) { boxes.push(inner.getBoundingClientRect()); }
                } catch (e) { /* cross-origin: skip rather than fail */ }
            } else {
                boxes.push(content.getBoundingClientRect());
            }
        });
        return boxes;
    }

    /**
     * Apply the corner from the show state.
     *
     * Set once for an event and then left alone, so it is a stored setting
     * rather than a URL option — a switcher should not have to carry it and an
     * operator should not have to remember it.
     */
    function setLogoCorner(corner) {
        corner = corner || '';
        if (corner === logoCorner) { return; }
        logoCorner = corner;
        logoFloor = 0;      // a new corner has its own obstacles
        logoEl.className = 'tourney-logo' + (corner ? ' ' + corner : '')
            + (logoEl.firstChild && corner ? ' shown' : '');
        // A corner change moves it, so clear the old offsets before replacing.
        logoEl.style.top = '';
        logoEl.style.bottom = '';
        placeLogo();
    }

    function placeLogo() {
        if (!logoEl || !logoCorner || !logoEl.firstChild) { return; }
        logoEl.classList.add('shown');

        var fromTop = logoCorner.indexOf('top') === 0;
        var EDGE = 54;          // title-safe, matching the grid
        var GAP = 18;
        var side = fromTop ? 'top' : 'bottom';

        var boxes = contentBoxes();
        var offset = Math.max(EDGE, logoFloor);

        // Resolve collisions by stepping, not by taking the furthest reach in
        // the column. An earlier version pushed past anything sharing the
        // logo's column, which sent a top-corner logo below a bottom-anchored
        // scoreboard — clear of it, and completely wrong. Only something the
        // logo would actually touch should move it.
        for (var pass = 0; pass < 6; pass += 1) {
            logoEl.style[side] = Math.round(offset) + 'px';
            var mine = logoEl.getBoundingClientRect();

            var hit = null;
            for (var i = 0; i < boxes.length; i += 1) {
                var b = boxes[i];
                if (b.right > mine.left && b.left < mine.right
                    && b.bottom > mine.top && b.top < mine.bottom) {
                    hit = b;
                    break;
                }
            }
            if (!hit) { break; }

            offset = (fromTop ? hit.bottom : (1080 - hit.top)) + GAP;
        }

        // Remember the furthest it has had to go, and never come back.
        //
        // Without this the logo would slide toward its corner whenever a card
        // went off air and slide out again when one returned — a branding mark
        // twitching around the frame every time the operator touches anything.
        // Holding the high-water mark costs a few idle pixels and keeps it
        // still, which is what a logo is for.
        logoFloor = Math.max(logoFloor, offset);
        logoEl.style[side] = Math.round(logoFloor) + 'px';
    }

    function anchorCompanion(state) {
        var node = slots['with-scoreboard'];
        if (!node) { return; }

        var board = null;
        (state.cards || []).forEach(function (c) {
            if (c && c.id === 'scoreboard') { board = c.slot; }
        });
        board = board || 'lower-left';

        var parts = board.split('-');            // e.g. ['lower','left']
        var band = parts[0] === 'upper' ? 'upper' : 'lower';
        var side = parts[1] || 'left';

        node.className = node.className.replace(/\banchor-\S+/g, '').trim();
        node.classList.add('anchor-' + band, 'anchor-' + side);
    }

    function applyShow(state) {
        var game = CONFIG.pinnedGame || state.game || currentGame;
        var wanted = {};

        anchorCompanion(state);

        // The layout is about to change, so the logo's high-water offset is no
        // longer meaningful — recompute it from scratch. It is held only to stop
        // a transient callout row from making the logo twitch WITHIN a
        // configuration; across configurations it would just leave the logo
        // drifted permanently outward after a card moved away.
        logoFloor = 0;

        // Every PLACED card is taken, whether or not it is switched on. Skipping
        // the hidden ones would mean each one loaded at the moment it was
        // revealed, which is exactly the pop-in the arm/show split exists to
        // avoid.
        (state.cards || []).forEach(function (entry) {
            if (!entry) { return; }
            var card = CARDS[entry.id];
            if (!card || !slots[entry.slot] || !game) { return; }
            card.id = entry.id;
            wanted[keyOf(entry.slot, entry.id)] = entry;
        });

        // Drop anything no longer wanted before mounting, so a card moving from
        // one slot to another never appears in both at once.
        Object.keys(mounted).forEach(function (key) {
            if (!wanted[key]) { unmount(key); }
        });

        Object.keys(wanted).forEach(function (key) {
            var entry = wanted[key];
            var card = CARDS[entry.id];
            var visible = wantsVisible(entry);
            if (card.kind === 'frame') {
                mountFrame(entry.slot, card, game, visible);
            } else {
                mountInline(entry.slot, card, entry.params, visible);
            }
        });

        // Whatever just changed, the logo may need to move out of its way.
        placeLogo();
    }

    /* ---------------------------------------------------------------------
       Polls
       --------------------------------------------------------------------- */

    var lastRev = -1;
    var lastShow = CONFIG.fallback;

    function pollShow() {
        fetch(CONFIG.showUrl + '?_=' + Date.now(), { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (state) {
                // A missing or unreadable file is auto mode, not a failure: a
                // tournament with no operator must still get a working stage.
                if (!state || !Array.isArray(state.cards)) { state = CONFIG.fallback; }
                lastShow = state;
                setLogoCorner(state.logo);

                var game = CONFIG.pinnedGame || state.game;
                var gameChanged = game && game !== currentGame;
                if (gameChanged) {
                    currentGame = game;
                    startGamePoll(game);
                }
                if (state.rev !== lastRev || gameChanged) {
                    lastRev = state.rev;
                    applyShow(state);
                }
            })
            .then(function () { setTimeout(pollShow, CONFIG.showPoll); });
    }

    var client = null;

    function startGamePoll(game) {
        if (client) { client.stop(); }
        client = new OverlayDataClient({ gameId: game, apiBase: CONFIG.apiBase })
            .onData(function (body) {
                payload = body;
                noteGoals(body);
                // Cheap, and catches the scoreboard growing a ribbon or a
                // callout row after its own poll.
                measureBug();
                // Re-apply the whole show state rather than only re-rendering
                // slots that already mounted. An inline card cannot mount until
                // the first payload arrives, and the show poll always wins that
                // race — so iterating the mounted set would skip exactly the
                // cards that are still waiting to appear.
                //
                // Safe to repeat: mountFrame short-circuits when the frame it
                // would build is the one already there, so a framed card is not
                // torn down and reloaded on every poll.
                applyShow(lastShow);
            })
            .onError(function () { /* the stage keeps the last good frame on screen */ });
        client.start();
    }

    loadTourneyLogo();
    pollShow();
    window.addEventListener('beforeunload', function () { if (client) { client.stop(); } });
}());
</script>
</body>
</html>
