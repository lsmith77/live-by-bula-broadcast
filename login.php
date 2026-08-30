<?php

/**
 * Standalone administrator login.
 *
 *   app.php?view=login
 *
 * Hosted, this page does not exist as far as anyone is concerned: Live! owns
 * the administrator session and `?view=live/admin` is where you log in. Here
 * there is no Live!, so this is the one door — and what it grants is exactly
 * what `Overlays\Auth::isAdmin()` answers: permission to change what is on air.
 *
 * It grants nothing else. A commentator still needs a room code, which is a
 * separate capability on purpose (`possession.php`, "two doors").
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/shared/auth.php';

use Overlays\Auth;

// Hosted installations have a real login elsewhere; this page must not offer a
// second, weaker one beside it.
if (Auth::isHosted()) {
    http_response_code(404);
    exit;
}

$error = null;
$done = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (isset($_POST['logout'])) {
        Auth::logout();
        $done = 'Logged out.';
    } elseif (!Auth::isConfigured()) {
        // Said plainly rather than as a failed login, because it is not one and
        // the fix is completely different.
        $error = 'No administrator password is set for this installation.';
    } elseif (Auth::attempt((string) ($_POST['password'] ?? ''))) {
        $done = 'Logged in.';
    } else {
        // One message for a wrong password, deliberately saying nothing about
        // which part was wrong.
        $error = 'That password was not accepted.';
    }
}

$isAdmin = Auth::isAdmin();
$e = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
// A login form must not be restored from cache after a logout.
header('Cache-Control: no-store, must-revalidate');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — overlays</title>
<style>
    :root { color-scheme: light dark; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center;
        font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
        background: #f6f7f9; color: #16202e; }
    @media (prefers-color-scheme: dark) {
        body { background: #0f1a30; color: #e8eef7; }
        .card { background: #16233f; border-color: #2a3a5c; }
        input { background: #0f1a30; color: inherit; border-color: #2a3a5c; }
    }
    .card { background: #fff; border: 1px solid #d7dee8; border-radius: 10px;
        padding: 1.4rem 1.5rem; width: min(22rem, calc(100vw - 2rem)); }
    h1 { font-size: 1.1rem; margin: 0 0 .2rem; }
    p.sub { margin: 0 0 1rem; color: #5a6a80; font-size: .85rem; }
    label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem; }
    input { width: 100%; box-sizing: border-box; padding: .55rem .6rem; font: inherit;
        border: 1px solid #c3ccd9; border-radius: 6px; }
    button { margin-top: .8rem; width: 100%; padding: .55rem; font: inherit;
        font-weight: 600; border: 0; border-radius: 6px; background: #2f6fdb;
        color: #fff; cursor: pointer; }
    .msg { margin: 0 0 .9rem; padding: .5rem .65rem; border-radius: 6px; font-size: .85rem; }
    .bad { background: #fdeaea; color: #8c1d1d; }
    .good { background: #e8f5ec; color: #14532d; }
    @media (prefers-color-scheme: dark) {
        .bad { background: #3b1a1a; color: #f6c8c8; }
        .good { background: #16341f; color: #c7ebd3; }
    }
    a { color: #2f6fdb; font-size: .82rem; }
</style>
</head>
<body>
<main class="card">
    <h1>Overlays</h1>
    <p class="sub">Signing in lets this browser change what is on air.</p>

    <?php if ($error !== null) : ?>
        <p class="msg bad"><?= $e($error) ?></p>
    <?php elseif ($done !== null) : ?>
        <p class="msg good"><?= $e($done) ?></p>
    <?php endif; ?>

    <?php if ($isAdmin) : ?>
        <form method="post">
            <button type="submit" name="logout" value="1">Sign out</button>
        </form>
        <p style="margin:.9rem 0 0"><a href="app.php?view=index">Back to the Studio</a></p>
    <?php else : ?>
        <?php if (!Auth::isConfigured()) : ?>
            <p class="msg bad">
                No administrator password is set. Put a bcrypt hash in
                <code>conf/local-config.php</code> as <code>admin_hash</code>.
            </p>
        <?php endif; ?>
        <form method="post">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password"
                autofocus required>
            <button type="submit">Sign in</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
