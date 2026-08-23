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

$store = new Possession();
$config = (new ConfigManager())->getConfig()['config'] ?? [];
$isAdmin = SeasonAccess::isLiveAdminAuthenticated($config);

/**
 * @return never
 *
 * The nominated code goes out only to an admin. A commentator does not need to
 * be told what it is — they already hold a code and only need to know whether it
 * is the one that was nominated, which `canTrack` answers for the code they
 * actually presented. Returning the code to everyone would publish the thing
 * that authorises writing it.
 */
function respond(Possession $store, bool $isAdmin, ?string $askedCode = null, ?int $askedGame = null): void
{
    $state = $store->load();
    $body = [
        'rev' => $state['rev'],
        'enabled' => $state['enabled'],
        'game' => $state['game'],
        'events' => $state['events'],
        'hasCode' => $state['code'] !== null,
        'canTrack' => $askedCode !== null && $store->allowsCode($askedCode, $askedGame),
        'connected' => $store->connectedCount(),
        'writable' => $store->isWritable(),
        'admin' => $isAdmin,
    ];
    if ($isAdmin) {
        $body['code'] = $state['code'];
    }
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $askedCode = filter_input(INPUT_GET, 'code') ?: null;
    $askedGame = filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT) ?: null;

    // A commentator polling with the nominated code is a commentator present.
    // Only the nominated one counts: anyone can poll this endpoint, and a count
    // that included them would tell the operator nothing.
    $client = (string) (filter_input(INPUT_GET, 'client') ?: '');
    if ($client !== '' && $askedCode !== null && $store->allowsCode($askedCode, $askedGame)) {
        $store->touchClient($client);
    }

    respond($store, $isAdmin, $askedCode, $askedGame);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Expected a JSON object.']);
    exit;
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
$gameGiven = isset($payload['game']) ? (int) $payload['game'] : null;
$byCode = !$isAdmin && $codeGiven !== null && $store->allowsCode($codeGiven, $gameGiven);

if (!$isAdmin && !$byCode) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Possession is set by the Studio operator, or by a commentator '
            . 'holding the room code the operator entered there.',
    ]);
    exit;
}

$allowed = $isAdmin
    ? ['enabled', 'game', 'code', 'score', 'defence']
    : ['score', 'defence'];

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

respond($store, $isAdmin, $codeGiven, $gameGiven);
