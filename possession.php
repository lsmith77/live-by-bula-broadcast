<?php
/**
 * Operator-declared possession — control endpoint.
 *
 *   GET  ?view=live/overlays/possession
 *        -> {"rev":N,"enabled":bool,"game":702,"events":[...],"writable":bool,"admin":bool}
 *
 *   POST {"enabled":true,"game":702}                 turn the mode on or off
 *   POST {"game":702,"score":"9-6","defence":true}   the defence has the disc
 *
 * Writes require the Live! admin session, like show.php and for the same reason:
 * this changes what is on air. The scoreboard does not use this endpoint at all
 * — it reads conf/possession.json as a static file on the ~1s channel. See
 * shared/possession.php for why the store is an append-only log.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/shared/possession.php';

use Api\ConfigManager;
use Api\SeasonAccess;
use Overlays\Possession;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, must-revalidate');

$config = (new ConfigManager())->getConfig()['config'] ?? [];
$isAdmin = SeasonAccess::isLiveAdminAuthenticated($config);

/**
 * Every request names its game, because every game has its own document.
 *
 * There is no "current" game here any more. A tournament runs several fields at
 * once, and one shared document meant a second field's desk wiped the first's
 * and put its data on the wrong scoreboard — see shared/possession.php.
 */
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$payload = [];
if ($isPost) {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Expected a JSON object.']);
        exit;
    }
    $gameId = (int) ($payload['game'] ?? 0);
} else {
    $gameId = (int) (filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT) ?: 0);
}

if ($gameId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid game.']);
    exit;
}

$store = new Possession($gameId);

/**
 * @return never
 *
 * The nominated code goes out only to an admin. A commentator does not need to
 * be told what it is — they already hold a code and only need to know whether it
 * is the one that was nominated, which `canTrack` answers for the code they
 * actually presented. Returning the code to everyone would publish the thing
 * that authorises writing it.
 */
function respond(Possession $store, bool $isAdmin, ?string $askedCode = null): void
{
    $state = $store->load();
    $body = [
        'rev' => $state['rev'],
        'enabled' => $state['enabled'],
        'game' => $store->game(),
        'events' => $state['events'],
        'ratio1' => $state['ratio1'],
        'hasCode' => $state['code'] !== null,
        'canTrack' => $askedCode !== null && $store->allowsCode($askedCode),
        'connected' => $store->connectedCount(),
        'stoppage' => $state['stoppage'],
        'writable' => $store->isWritable(),
        'admin' => $isAdmin,
    ];
    if ($isAdmin) {
        $body['code'] = $state['code'];
        // Only the operator needs the roster: it exists to confirm the right
        // desk is on the code, which is their question, not a commentator's.
        $body['clients'] = $store->connected();
    }
    echo json_encode($body);
    exit;
}

if (!$isPost) {
    $askedCode = filter_input(INPUT_GET, 'code') ?: null;

    // A commentator polling with the nominated code is a commentator present.
    // Only the nominated one counts: anyone can poll this endpoint, and a count
    // that included them would tell the operator nothing.
    $client = (string) (filter_input(INPUT_GET, 'client') ?: '');
    if ($client !== '' && $askedCode !== null && $store->allowsCode($askedCode)) {
        $store->touchClient($client, filter_input(INPUT_GET, 'name') ?: null);
    }

    respond($store, $isAdmin, $askedCode);
}

/**
 * Two doors, and they open onto different rooms.
 *
 * An admin may do anything here. A commentator holding the room code the
 * operator nominated in the Studio may do exactly one thing: say who has the
 * disc. They cannot turn the mode on, cannot nominate a code, and cannot change
 * the game — all three of those are the operator's controls, and letting the
 * code holder touch them would turn a single-entry allowlist into a way to
 * grant yourself entry.
 *
 * The commentator is often better placed to do this than the operator: their
 * job is already watching the play, whereas the operator is choosing and timing
 * graphics. See docs/STUDIO.md section 3.5 on whose attention is spare.
 */
$codeGiven = isset($payload['code']) ? (string) $payload['code'] : null;
$byCode = !$isAdmin && $codeGiven !== null && $store->allowsCode($codeGiven);

if (!$isAdmin && !$byCode) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Possession is set by the Studio operator, or by a commentator '
            . 'holding the room code the operator entered there.',
    ]);
    exit;
}

// A code holder may set the ratio as well as possession: they are the one
// watching the game with the scoresheet in front of them, and it is the same
// kind of fact -- something true about the game that nothing records.
// Corrections sit with the presses: whoever can record possession can fix what
// they recorded, and needs to be able to do it in the next second.
$allowed = $isAdmin
    ? ['enabled', 'game', 'code', 'score', 'defence', 'ratio1', 'undo', 'clearPoint', 'at', 'stoppage']
    : ['score', 'defence', 'ratio1', 'undo', 'clearPoint', 'at', 'stoppage'];

$change = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $payload)) {
        $change[$key] = $payload[$key];
    }
}

$result = $store->apply($change);
if (!$result['ok']) {
    http_response_code(400);
    echo json_encode(['error' => $result['error']]);
    exit;
}

respond($store, $isAdmin, $codeGiven);
