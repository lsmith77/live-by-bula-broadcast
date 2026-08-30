<?php
/**
 * Show state endpoint — what the stage is displaying.
 *
 *   GET  ?view=live/overlays/show
 *        -> {"rev":N,"game":702,"cards":[...],"writable":bool,"admin":bool,
 *            "slots":[...],"knownCards":[...]}
 *
 *   POST {"rev": 42, "game": 702, "cards": [{"id":"scoreboard","slot":"lower-left","visible":true}]}
 *        -> replace the state. `rev` must match what the caller last read.
 *
 * Saving requires the Live! admin session — the same one that gates entity=wipe
 * — so anyone who can view an overlay cannot change what is on air. Log in at
 * ?view=live/admin first.
 *
 * NOTE this endpoint is for the control UI, not for the stage. A stage reads
 * `conf/show.json` directly as a static file: at a one-second poll a routed
 * request would be a full UltiOrganizer bootstrap — database connection and
 * session — every second, per stage. See shared/show.php.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/show.php';

use Overlays\Auth;
use Overlays\Show;

header('Content-Type: application/json; charset=UTF-8');
// The control UI must never act on a stale view of what is on air.
header('Cache-Control: no-store, must-revalidate');

$store = new Show();
$isAdmin = Auth::isAdmin();

/** @return never */
function respond(Show $store, bool $isAdmin, ?string $warning = null): void
{
    $state = $store->load();
    echo json_encode([
        'rev' => $state['rev'],
        'game' => $state['game'],
        'logo' => $state['logo'],
        'cards' => $state['cards'],
        'writable' => $store->isWritable(),
        'admin' => $isAdmin,
        // Sent so the UI never hard-codes a list that could drift from the
        // store's — including which positions each card actually fits in, so
        // the picker can offer only those rather than duplicating the rule.
        'slots' => Show::SLOTS,
        'knownCards' => Show::cardIds(),
        'cardSlots' => (object) Show::CARDS,
        'logoCorners' => Show::LOGO_CORNERS,
        'logoBlocks' => (object) Show::LOGO_BLOCKS,
        'warning' => $warning,
    ]);
    exit;
}

/** @return never */
function fail(int $status, string $message, ?array $state = null): void
{
    http_response_code($status);
    echo json_encode(['error' => $message, 'state' => $state]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond($store, $isAdmin);
}

if (!$isAdmin) {
    fail(403, 'Log in at ?view=live/admin to change what is on air.');
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    fail(400, 'Expected a JSON object.');
}
if (!isset($payload['cards']) || !is_array($payload['cards'])) {
    fail(400, 'Expected {"cards": [...]}.');
}

// Absent rev skips the conflict check, which is what a deliberate "force" looks
// like; the UI always sends one.
$expected = array_key_exists('rev', $payload) ? (int) $payload['rev'] : null;

$result = $store->save($payload, $expected);

if (!$result['ok']) {
    // 409 rather than 500 for a conflict: the caller should reload and reapply,
    // not retry the same body.
    $conflict = $expected !== null && $result['state']['rev'] !== $expected;
    fail($conflict ? 409 : 500, (string) $result['error'], $result['state']);
}

// Report silently-dropped entries rather than letting a card vanish without
// explanation — an unknown id or a doubled-up slot is a UI bug worth surfacing.
$warning = count($result['state']['cards']) < count($payload['cards'])
    ? 'Some cards were dropped: unknown id, a position that card does not fit, '
        . 'a slot used twice, a position the tournament logo occupies, '
        . 'or hidden by a fullscreen takeover.'
    : null;

respond($store, true, $warning);
