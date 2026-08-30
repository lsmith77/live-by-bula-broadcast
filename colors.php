<?php
/**
 * Team colour store endpoint.
 *
 *   GET  ?view=live/overlays/colors
 *        -> {"games":{...},"writable":bool,"admin":bool}
 *
 *   POST {"game": 702, "home": "FFFFFF", "visitor": "1B1B1B"}
 *        -> record what each side is wearing for one game. Made minutes before
 *           the pull, by an operator looking at the actual jerseys.
 *
 * Saving requires the Live! admin session — the same one that gates
 * entity=wipe — so anyone who can view an overlay cannot rewrite its colours.
 * Log in at ?view=live/admin first.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/colors.php';

use Overlays\Auth;
use Overlays\Colors;

header('Content-Type: application/json; charset=UTF-8');
// Same as the other three endpoints. Without it a browser caches the kit
// colours heuristically, and a coin-toss change made minutes before the pull
// does not take.
header('Cache-Control: no-store, must-revalidate');

$store = new Colors();
$isAdmin = Auth::isAdmin();

/** @return never */
function respond(Colors $store, bool $isAdmin): void
{
    $state = $store->load();
    echo json_encode([
        'games' => (object) $state['games'],
        'writable' => $store->isWritable(),
        'admin' => $isAdmin,
    ]);
    exit;
}

/** @return never */
function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond($store, $isAdmin);
}

if (!$isAdmin) {
    fail(403, 'Log in at ?view=live/admin to change team colours.');
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    fail(400, 'Expected a JSON object.');
}

$writeFailed = 'Could not write ' . basename($store->storePath())
    . '. Make live/overlays/conf/ writable by the web server.';

if (isset($payload['game'])) {
    $gameId = (int) $payload['game'];
    if ($gameId <= 0) {
        fail(400, 'Invalid game id.');
    }

    // An absent key leaves that side alone; an explicit null clears it.
    $current = $store->gameChoice($gameId) ?? ['home' => null, 'visitor' => null];
    $home = array_key_exists('home', $payload)
        ? ($payload['home'] === null ? null : (string) $payload['home'])
        : $current['home'];
    $visitor = array_key_exists('visitor', $payload)
        ? ($payload['visitor'] === null ? null : (string) $payload['visitor'])
        : $current['visitor'];

    if (!$store->saveGameChoice($gameId, $home, $visitor)) {
        fail(500, $writeFailed);
    }
    respond($store, true);
}

fail(400, 'Expected {"game": <id>, "home": "RRGGBB", "visitor": "RRGGBB"}.');
