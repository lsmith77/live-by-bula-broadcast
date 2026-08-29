<?php
/**
 * Prepared talking points about players, for the commentary position.
 *
 *   GET  ?view=live/overlays/notes&code=K7QM4
 *        -> {"players":{"1234":{"text":"…","pronouns":"she/her","by":"Sam","at":N}},"touched":N,"writable":bool}
 *
 *   POST {"code":"K7QM4","team":304,"text":"…","by":"Sam"}
 *        -> write one TEAM's note — history, achievements, the material that
 *           belongs to nobody's row; an empty text clears it
 *
 *   POST {"code":"K7QM4","player":1234,"text":"…","nickname":"…","pronouns":"…","pronunciation":"…","pronounsok":true,"by":"Sam"}
 *        -> write one player's entry, as a DELTA: a structured-field key that
 *           is present sets the field (empty string clears it), an absent key
 *           leaves the stored value alone — so a stale page can only touch
 *           what it sends. An entry whose every channel ends up empty is
 *           deleted. `pronounsok` marks the declared pronouns as reviewed
 *           (keep-as-written); absent keeps the mark while the pronouns stand
 *           unedited, and it never survives an edited declaration.
 *
 * **Unauthenticated, like lines.php and for the same reason.** The code is a
 * namespace rather than a credential; nothing stored here reaches a viewer, so
 * requiring the Live! admin session would hand broadcast control to somebody who
 * only wants to prepare notes with a colleague. See shared/notes.php for the caps
 * that come with that decision, and docs/COMMENTATOR.md section 5a for why the
 * room is keyed by code alone rather than by game and code.
 *
 * NO GAME ID ANYWHERE. A note about a player is worth the same in the final as in
 * the quarter, so it is not scoped to a fixture. That also means this endpoint has
 * nothing game-shaped to validate, which is deliberate: an unauthenticated
 * endpoint cannot be given a database lookup to do on demand, and lines.php shows
 * what happens when a cap leans on an id nobody checked.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/shared/notes.php';

use Overlays\Lines;
use Overlays\Notes;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, must-revalidate');

$store = new Notes();

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
    $code = strtoupper(trim((string) ($payload['code'] ?? '')));
} else {
    $code = strtoupper(trim((string) filter_input(INPUT_GET, 'code')));
}

if (!Notes::isCode($code)) {
    fail(400, 'A room code is ' . Lines::CODE_LENGTH . ' characters from ' . Lines::ALPHABET . '.');
}

if (!$isPost) {
    $state = $store->load($code);
    echo json_encode([
        'players' => (object) $state['players'],
        'teams' => (object) $state['teams'],
        'touched' => $state['touched'],
        'writable' => $store->isWritable(),
    ]);
    exit;
}

// A bulk write, for a CSV import. Deliberately one request rather than a loop of
// single writes: save() throttles repeat writes to a room, which is right for a
// commentator's debounced typing and would silently discard most of an import.
if (isset($payload['players'])) {
    if (!is_array($payload['players'])) {
        fail(400, 'Expected {"players": [...]}.');
    }
    $result = $store->saveMany(
        $code,
        $payload['players'],
        (string) ($payload['by'] ?? ''),
        // Default closed: an import fills gaps and never overwrites, unless the
        // caller says otherwise. Nothing asks otherwise today.
        ($payload['overwrite'] ?? false) !== true,
        is_array($payload['teamnote'] ?? null) ? $payload['teamnote'] : null,
    );
    if (!$result['ok']) {
        fail(500, (string) $result['error']);
    }
    echo json_encode([
        'players' => (object) $result['state']['players'],
        'teams' => (object) $result['state']['teams'],
        'touched' => $result['state']['touched'],
        'written' => $result['written'],
        'kept' => $result['kept'],
        'writable' => true,
    ]);
    exit;
}

// One team's note, the desk's own edit path.
if (isset($payload['team'])) {
    $teamId = (int) $payload['team'];
    if ($teamId <= 0) {
        fail(400, 'Missing team.');
    }
    if (!array_key_exists('text', $payload) || !is_string($payload['text'])) {
        fail(400, 'Expected {"text": "..."}.');
    }
    $result = $store->saveTeamNote($code, $teamId, $payload['text'], (string) ($payload['by'] ?? ''));
    if (!$result['ok']) {
        fail(500, (string) $result['error']);
    }
    echo json_encode([
        'players' => (object) $result['state']['players'],
        'teams' => (object) $result['state']['teams'],
        'touched' => $result['state']['touched'],
        'writable' => true,
    ]);
    exit;
}

$playerId = (int) ($payload['player'] ?? 0);
if ($playerId <= 0) {
    fail(400, 'Missing player.');
}
if (!array_key_exists('text', $payload) || !is_string($payload['text'])) {
    fail(400, 'Expected {"text": "..."}.');
}

// A DELTA: only the keys the caller actually sent reach the store, so a page
// that predates a field cannot clear it by saving "everything it knows".
$fields = [];
foreach (Notes::FIELDS as $field) {
    if (array_key_exists($field, $payload) && is_string($payload[$field])) {
        $fields[$field] = $payload[$field];
    }
}

$result = $store->save(
    $code,
    $playerId,
    $payload['text'],
    (string) ($payload['by'] ?? ''),
    $fields,
    array_key_exists('pronounsok', $payload) ? $payload['pronounsok'] === true : null,
);
if (!$result['ok']) {
    fail(500, (string) $result['error']);
}

echo json_encode([
    'players' => (object) $result['state']['players'],
    'teams' => (object) $result['state']['teams'],
    'touched' => $result['state']['touched'],
    'writable' => true,
]);
