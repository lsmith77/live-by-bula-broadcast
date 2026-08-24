<?php
/**
 * Show state — what the stage is currently displaying.
 *
 * The entire contract between the Studio control UI and the stage:
 *
 *   {
 *     "rev": 42,
 *     "game": 702,
 *     "cards": [
 *       { "id": "scoreboard", "slot": "lower-left", "visible": true },
 *       { "id": "topplayers", "slot": "upper-right", "visible": true }
 *     ]
 *   }
 *
 * Written here through temp-file-and-rename, and read by the stage straight off
 * disk as a static file — `live/overlays/conf/` is web-reachable, so a stage
 * polling once a second costs no PHP and no database connection at all. That
 * matters: a routed endpoint at 1s would be a full UltiOrganizer bootstrap every
 * second per stage.
 *
 * `rev` is not decoration. Two people on the control page — a laptop and a phone
 * is a normal enough pairing — would otherwise silently overwrite each other,
 * and the loser would never know. A writer sends the rev it last read and the
 * store refuses if it has moved.
 *
 * Stored as JSON rather than in the database: the plan forbids schema changes,
 * and a file under live/overlays/ survives a Live! upgrade.
 */

namespace Overlays;

final class Show
{
    /**
     * Fixed positions; no drag-and-drop by design.
     *
     * `with-scoreboard` is the odd one out and deliberately so: it is not a
     * place in the frame but a place *relative to another card*. A companion
     * strip that belongs to the scoreboard has to follow it — pinning it to a
     * corner would leave it stranded the moment the scoreboard moved, and no
     * operator should have to keep two positions in sync by hand. The stage
     * resolves it at render time and flips it above or below so it never
     * overlaps the bug and never leaves the frame.
     */
    public const SLOTS = [
        'upper-left', 'upper-center', 'upper-right',
        'center',
        'lower-left', 'lower-center', 'lower-right',
        'with-scoreboard',
        'fullscreen',
    ];

    /**
     * Cards the stage knows how to mount, and where each one physically fits.
     *
     * A show state naming an unknown card, or putting a card somewhere it does
     * not fit, is rejected rather than passed through: the id reaches a class
     * attribute and a mount lookup, and a card that overflows its slot is a
     * broken graphic on air.
     *
     * The constraint is real, not stylistic. A corner slot is roughly a third of
     * the frame wide; a six-row roster comparison is not going to live there. So
     * the card declares what it needs and the operator is offered only the
     * positions that work, rather than being trusted to remember.
     */
    public const CARDS = [
        // A bug: belongs against an edge, never mid-frame, never a takeover.
        'scoreboard' => [
            'upper-left', 'upper-center', 'upper-right',
            'lower-left', 'lower-center', 'lower-right',
        ],
        // One-line strips. They ride with the scoreboard by default, and are
        // small enough for a corner too — which is how two of them can be on air
        // at once, since a slot shows only one.
        'lastgoal' => ['with-scoreboard', 'upper-left', 'upper-center', 'upper-right'],
        'lastassist' => ['with-scoreboard', 'upper-left', 'upper-center', 'upper-right'],
        // The pair on one line, so it needs more width than the single strips.
        'lastplay' => ['with-scoreboard', 'upper-center'],

        // Everything below here wants the middle of the frame or the whole of
        // it, and appears for a few seconds at a time. Order is not decoration:
        // this list IS the order of the control page, so the cards that share
        // the frame with each other — the bug and the strips that ride with it —
        // are grouped where an operator coordinating positions is looking, and
        // the ones that take the frame on their own sit below, where they need
        // no coordinating at all.

        // A grid as tall as the losing score and as wide as the winning one.
        // Wants the middle of the frame like the other analysis cards.
        'progression' => ['center', 'fullscreen'],
        // Six rows across two teams. Needs the width of the whole frame.
        'topplayers' => ['center', 'fullscreen'],
        // Team-versus-team summaries for the three moments that are not a goal.
        // A takeover is exactly right for two of them: before the pull and after
        // the final point there is nothing else worth covering.
        // One card that reads the moment off the game. The three below are the
        // same card pinned to one moment, kept so saved states keep working.
        'summary' => ['center', 'fullscreen'],
        'pregame' => ['center', 'fullscreen'],
        'halftime' => ['center', 'fullscreen'],
        'postgame' => ['center', 'fullscreen'],
    ];

    /**
     * Where the tournament logo sits, or '' for none.
     *
     * A setting rather than a per-URL option: a logo's corner is decided once
     * for an event and then left alone, so putting it on the URL would mean
     * every switcher had to carry it and every operator had to remember it.
     */
    /**
     * Slots the tournament logo takes out of use, by corner.
     *
     * The logo owns its corner and does not move. Anything that would collide
     * with it is simply not placeable there — which is a rule an operator can
     * hold in their head, unlike a graphic that repositions itself.
     *
     * **Only the matching corner, and the centre stays available.** That is not
     * free: the scoreboard bug is `width: max-content` capped at 1520px, so at a
     * centre position it leaves 200px each side in the worst case and 146px once
     * the title-safe inset is taken. The logo is capped to that width
     * (shared/stage.css) so it can sit flush against the widest bug there will
     * ever be. It costs the logo about a quarter of its width and buys back both
     * centre positions, which is the better trade — the alternative was blocking
     * the two most useful scoreboard positions on every edge the logo sits on.
     *
     * `with-scoreboard` is not listed: those cards ride with the scoreboard and
     * inherit whichever slot it is allowed to occupy.
     */
    public const LOGO_BLOCKS = [
        'top-left' => ['upper-left'],
        'top-right' => ['upper-right'],
        'bottom-left' => ['lower-left'],
        'bottom-right' => ['lower-right'],
    ];

    public const LOGO_CORNERS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];

    /** @return string[] */
    public static function slotsFor(string $cardId): array
    {
        return self::CARDS[$cardId] ?? [];
    }

    /** @return string[] */
    public static function cardIds(): array
    {
        return array_keys(self::CARDS);
    }

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? __DIR__ . '/../conf/show.json';
    }

    public function storePath(): string
    {
        return $this->path;
    }

    /** Where a stage reads it from, relative to the site root. */
    public function publicUrl(string $assetBase): string
    {
        return $assetBase . '/conf/show.json';
    }

    /**
     * Current state, or the auto-mode default when nothing has been saved.
     *
     * A missing file is not an error: it is how a tournament with no operator
     * starts, and it must produce a usable stage rather than an empty one.
     *
     * @return array{rev:int, game:?int, cards:array<int,array{id:string,slot:string,visible:bool,params:array}>}
     */
    public function load(): array
    {
        if (!is_readable($this->path)) {
            return self::defaults();
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($decoded)) {
            return self::defaults();
        }

        // Resolved first: which slots exist for cards depends on it.
        $logo = self::cleanLogo($decoded['logo'] ?? null);

        return [
            'rev' => max(0, (int) ($decoded['rev'] ?? 0)),
            'game' => self::cleanGame($decoded['game'] ?? null),
            'logo' => $logo,
            'cards' => self::cleanCards($decoded['cards'] ?? [], $logo),
        ];
    }

    /**
     * The state a stage runs with when nobody has configured one.
     *
     * Scoreboard only, bottom-left. Easy mode is not a separate code path — it
     * is this document, unwritten.
     */
    public static function defaults(): array
    {
        return [
            'rev' => 0,
            'game' => null,
            'logo' => '',
            'cards' => [
                ['id' => 'scoreboard', 'slot' => 'lower-left', 'visible' => true, 'params' => []],
            ],
        ];
    }

    /**
     * Replace the state.
     *
     * @param  int|null $expectedRev Reject if the stored rev has moved past this.
     *                               Null skips the check, for callers that have
     *                               no prior read to compare against.
     * @return array{ok:bool, error:?string, state:array}
     */
    /**
     * Serialise the read-modify-write, as the other stores do.
     *
     * The `rev` check below narrows the window but does not close it: two
     * writers can both read rev 5, both find it current, and both write rev 6.
     * The temp-file-and-rename gives readers atomicity and nothing else — each
     * writer locks its own private temp file, which excludes nobody.
     */
    private function withLock(callable $body): mixed
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $body();
        }
        $handle = @fopen($this->path . '.lock', 'c');
        if ($handle === false) {
            return $body();
        }
        try {
            @flock($handle, LOCK_EX);
            return $body();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public function save(array $incoming, ?int $expectedRev = null): array
    {
        return $this->withLock(function () use ($incoming, $expectedRev): array {
            return $this->saveLocked($incoming, $expectedRev);
        });
    }

    private function saveLocked(array $incoming, ?int $expectedRev = null): array
    {
        $current = $this->load();

        if ($expectedRev !== null && $expectedRev !== $current['rev']) {
            return [
                'ok' => false,
                // Reported so the UI can reload and reapply rather than guessing.
                'error' => 'Show state changed elsewhere (rev ' . $current['rev']
                    . ', expected ' . $expectedRev . ').',
                'state' => $current,
            ];
        }

        // Absent means unchanged: a card write must not silently clear a setting
        // that belongs to the whole event. Resolved before the cards, because
        // moving the logo can take a slot out of use under them.
        $logo = self::cleanLogo(
            array_key_exists('logo', $incoming) ? $incoming['logo'] : $current['logo']
        );

        $next = [
            'rev' => $current['rev'] + 1,
            'game' => self::cleanGame($incoming['game'] ?? $current['game']),
            'logo' => $logo,
            'cards' => self::cleanCards($incoming['cards'] ?? [], $logo),
        ];

        if (!$this->write($next)) {
            return ['ok' => false, 'error' => 'Could not write ' . $this->path, 'state' => $current];
        }
        return ['ok' => true, 'error' => null, 'state' => $next];
    }

    public function isWritable(): bool
    {
        return is_writable($this->path)
            || (!file_exists($this->path) && is_writable(dirname($this->path)));
    }

    // -- internals ----------------------------------------------------------

    private static function cleanGame(mixed $raw): ?int
    {
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private static function cleanLogo(mixed $raw): string
    {
        $value = is_string($raw) ? $raw : '';
        return in_array($value, self::LOGO_CORNERS, true) ? $value : '';
    }

    /**
     * Drop anything the stage could not mount, and enforce the layout rules here
     * rather than in the UI — a rule enforced only in the browser is a rule that
     * a hand-edited file can break.
     *
     * The central distinction: **placement is not exclusive, visibility is.**
     * Several cards may be configured into one space — that is how an operator
     * prepares alternatives and flips between them — but only one of them can be
     * on air there at a time, because they would otherwise draw on top of each
     * other. So a slot holds many, and shows one.
     *
     *   - a card appears once overall
     *   - at most one VISIBLE card per slot
     *   - a visible fullscreen card is alone
     *
     * @return array<int,array{id:string,slot:string,visible:bool,params:array}>
     */
    /** @return string[] */
    public static function blockedSlots(?string $logoCorner): array
    {
        return self::LOGO_BLOCKS[(string) $logoCorner] ?? [];
    }

    private static function cleanCards(mixed $raw, ?string $logoCorner = null): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $blocked = self::blockedSlots($logoCorner);

        $clean = [];
        $placed = [];
        $onAir = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
            $slot = is_string($entry['slot'] ?? null) ? $entry['slot'] : '';

            // Slot must exist *and* the card must fit in it.
            if (!in_array($slot, self::slotsFor($id), true)) {
                continue;
            }
            // The tournament logo owns its corner; nothing is placed into it.
            // Enforced here rather than only in the control page, so a stale tab
            // or a direct POST cannot put a card under the logo.
            if (in_array($slot, $blocked, true)) {
                continue;
            }
            // One home per card: a card in two places at once is a UI bug, and
            // silently honouring it would put the same graphic on screen twice.
            if (isset($placed[$id])) {
                continue;
            }
            $placed[$id] = true;

            // Only one card per slot reaches air. A later claimant is kept —
            // still placed, still armed — but switched off.
            $visible = (bool) ($entry['visible'] ?? false);
            if ($visible && isset($onAir[$slot])) {
                $visible = false;
            }
            if ($visible) {
                $onAir[$slot] = true;
            }

            $clean[] = [
                'id' => $id,
                'slot' => $slot,
                'visible' => $visible,
                'params' => is_array($entry['params'] ?? null) ? $entry['params'] : [],
            ];
        }

        // A visible fullscreen card is a takeover: everything else goes dark
        // rather than being composited underneath it.
        foreach ($clean as $card) {
            if ($card['slot'] === 'fullscreen' && $card['visible']) {
                return array_values(array_filter($clean, static function (array $c): bool {
                    return $c['slot'] === 'fullscreen';
                }));
            }
        }

        return $clean;
    }

    private function write(array $state): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        // Temp-file-and-rename in the same directory, so a stage polling through
        // a save never reads half a document.
        $tmp = $this->path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
