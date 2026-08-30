<?php

/**
 * Standalone front controller — the overlays without UltiOrganizer.
 *
 *   app.php?view=stage
 *   app.php?view=commentator&game=702
 *
 * Ten files in this directory begin by refusing to run unless `UO_ROUTED_VIEW`
 * is defined. That guard is what makes a direct request to `commentator.php`
 * a 404 instead of a page, and hosted mode satisfies it through
 * UltiOrganizer's front controller. This is the other way to satisfy it, for a
 * host that has no UltiOrganizer — see `docs/STANDALONE.md` §3.
 *
 * WHY AN ALLOW-LIST RATHER THAN A RESOLVER
 *
 * UltiOrganizer resolves `?view=` against the filesystem, because it serves
 * hundreds of pages and cannot enumerate them: it rejects `..`, restricts the
 * characters, then confirms the resolved realpath is still inside the base
 * directory. That is the correct design for that problem and it is a design
 * whose safety rests on getting three checks right.
 *
 * This directory serves eleven pages and can name all of them. So it does.
 * A view that is not a key below cannot be reached, whatever it contains —
 * there is no traversal to defend against because no part of the request ever
 * becomes part of a path. It is a smaller thing to get right, and this is the
 * file where getting it wrong exposes the prepared notes, which are notes
 * about named people.
 *
 * WHAT THIS DOES NOT INHERIT, AND THEREFORE MUST DO ITSELF
 *
 * Hosted, these pages sit behind UltiOrganizer's session, its private-event
 * check and its maintenance check. None of that exists here. What this file
 * owes them is the session; `Overlays\Auth` owes them the administrator check;
 * and the deployment owes them `conf/` being unreadable over HTTP, which the
 * shipped `.htaccess` does and which any other server must be made to do.
 * That last one is not optional and is not enforceable from PHP.
 */

/**
 * Built-in server mode.
 *
 * `php -S 0.0.0.0:8080 app.php` is a deployment `docs/STANDALONE.md` §8
 * actively suggests — a laptop at a venue with no uplink — and the built-in
 * server **does not read `.htaccess`**. Everything the shipped rules do for
 * Apache therefore has to be done here as well, or the deployment we recommend
 * is the one that leaks.
 *
 * It is not hypothetical: without this block, `conf/notes/<room>.json` is
 * served on request, and that file is the commentary desk's prepared notes —
 * notes about named people, which the `.gitignore` says "must not reach a
 * repository under any circumstances" and which have no business reaching a
 * browser either.
 *
 * Returning false hands the request back to the server to serve as a static
 * file; returning anything else means this script handled it.
 */
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    // Operator state: default closed, open two files by name. The same rule the
    // .htaccess states, for the same reason — the stage polls show.json and the
    // possession file as static assets about once a second, and routing those
    // through PHP would be a bootstrap per second per stage.
    if (preg_match('#(^|/)conf(/|$)#', $path)) {
        if (!preg_match('#/conf/(show\.json|possession-[0-9]+\.json)$#', $path)) {
            http_response_code(404);
            exit;
        }

        return false;
    }

    // Any other real file is served as-is — the CSS and JS under shared/ must
    // keep working — except a .php, which is only ever reached through the
    // allow-list below. Those already refuse to run unrouted; this makes it
    // two locks rather than one.
    if ($path !== '/app.php' && is_file(__DIR__ . $path)) {
        if (str_ends_with(strtolower($path), '.php')) {
            http_response_code(404);
            exit;
        }

        return false;
    }

    // Anything else that is not this script is not here. Without this, a
    // request for a missing asset fell through to the dispatcher, which
    // defaults to the picker — so a mistyped script URL answered 200 with a
    // page of HTML, and the browser reported it as "Unexpected token '<'".
    if ($path !== '/' && $path !== '/app.php') {
        http_response_code(404);
        exit;
    }
}

// Says which URL layout the pages are being served under — they sit at the
// document root here rather than under /live/overlays/. See Overlays\Mode.
define('OVERLAYS_STANDALONE', true);

// The pages this installation serves. Key is the view, value is the file.
//
// Both the bare name and the hosted spelling are accepted, so the rewrite
// rules in .htaccess need only their target changed rather than rewriting —
// and a URL copied from a hosted installation keeps working.
$views = [
    'index' => 'index.php',
    'scoreboard' => 'scoreboard.php',
    'stage' => 'stage.php',
    'commentator' => 'commentator.php',
    'show' => 'show.php',
    'possession' => 'possession.php',
    'lines' => 'lines.php',
    'notes' => 'notes.php',
    'colors' => 'colors.php',
    'login' => 'login.php',
    'tests/selftest' => 'tests/selftest.php',
];

$requested = (string) ($_GET['view'] ?? 'index');

// A URL copied from a hosted installation says `live/overlays/stage`.
if (str_starts_with($requested, 'live/overlays/')) {
    $requested = substr($requested, strlen('live/overlays/'));
}
$requested = trim($requested, '/');
if ($requested === '') {
    $requested = 'index';
}

if (!isset($views[$requested])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "No such view.\n";
    exit;
}

// Before the page runs: it may want to know whether this request is an admin,
// and Auth reads the session.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// The guard every page checks. Defined only after the view has been resolved
// against the allow-list, so nothing can be included without passing it.
if (!defined('UO_ROUTED_VIEW')) {
    define('UO_ROUTED_VIEW', true);
}

require __DIR__ . '/' . $views[$requested];
