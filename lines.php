<?php
/**
 * Shared line-selection endpoint for the commentary position.
 *
 *   GET  ?view=live/overlays/lines&game=702&code=K7QM4
 *        -> {"teams":{"300":[...]},"touched":N,"writable":bool}
 *
 *   POST {"game":702,"code":"K7QM4","team":300,"players":[3,7,12]}
 *        -> replace one team's line
 *
 * **Unauthenticated on purpose, and the only such write in this project.** The
 * code is a namespace rather than a credential; requiring the Live! admin
 * session here would hand broadcast control to someone who only wants to sync a
 * private reference panel with a colleague. Nothing this endpoint stores reaches
 * a viewer. See docs/COMMENTATOR.md section 6 and shared/lines.php for the guards
 * that come with that decision.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/shared/lines.php';

use Overlays\Lines;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, must-revalidate');

$store = new Lines();

/** @return never */
function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$payload = [];

if ($isPost) {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        fail(400, 'Expected a JSON object.');
    }
    $gameId = (int) ($payload['game'] ?? 0);
    $code = strtoupper(trim((string) ($payload['code'] ?? '')));
} else {
    $gameId = (int) filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT);
    $code = strtoupper(trim((string) filter_input(INPUT_GET, 'code')));
}

if ($gameId <= 0) {
    fail(400, 'Missing or invalid game.');
}
if (!Lines::isCode($code)) {
    fail(400, 'A room code is ' . Lines::CODE_LENGTH . ' characters from ' . Lines::ALPHABET . '.');
}

if (!$isPost) {
    $state = $store->load($gameId, $code);
    echo json_encode([
        'teams' => (object) $state['teams'],
        'touched' => $state['touched'],
        'writable' => $store->isWritable(),
    ]);
    exit;
}

$teamId = (int) ($payload['team'] ?? 0);
if ($teamId <= 0) {
    fail(400, 'Missing team.');
}
if (!is_array($payload['players'] ?? null)) {
    fail(400, 'Expected {"players": [...]}.');
}

$result = $store->saveTeam($gameId, $code, $teamId, $payload['players']);
if (!$result['ok']) {
    fail(500, (string) $result['error']);
}

echo json_encode([
    'teams' => (object) $result['state']['teams'],
    'touched' => $result['state']['touched'],
    'writable' => true,
]);
