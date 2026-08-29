<?php
/**
 * Prepared talking points about a player, for the commentary position.
 *
 *   { "players": { "1234": { "text": "...", "pronouns": "she/her", "by": "Sam", "at": 1787420000 } },
 *     "touched": 1787420000 }
 *
 * Besides the free-text note, an entry may carry three STRUCTURED fields —
 * `nickname`, `pronouns`, `pronunciation` (see FIELDS) — the answers a
 * commentator looks up at speed rather than reads out, shown beside the name
 * instead of inside the note. They arrive through the bio round trip, where the
 * player fills in their own row (which is what makes pronouns acceptable to hold
 * at all: self-declared or absent, never guessed — docs/COMMENTATOR.md section
 * 5), or are typed at the desk. Non-empty values only; an entry exists while any
 * of its four channels has content.
 *
 * WHY THIS EXISTS. `docs/COMMENTATOR.md` section 5 argues that the right home for
 * what a commentator says about a player is `uo_player_profile`, self-declared and
 * published through the existing `public` opt-in. That remains true and this does
 * not replace it. But at a smaller tournament the commentary position exists only
 * for the finals, and the way material actually reaches it is that somebody asks
 * the two teams for something to say and is handed it an hour before the pull.
 * There is no UltiOrganizer surface for that, and there will not be one before the
 * game starts. So the commentator types it here.
 *
 * KEYED BY CODE, NOT BY GAME, which is the one place this store's shape differs
 * from `Lines` and the difference is the point. A line selection is worthless five
 * minutes after the point ends; a note about a player is worth exactly as much in
 * the final as it was in the quarter. Keying by game would make a commentary desk
 * retype everything each round, which is the surest way to have them stop doing
 * it. So the room here is the code alone, and a desk that keeps its code keeps its
 * notes for the tournament.
 *
 * That also removes the trap `Lines::prune()` fell into. There, rooms are bounded
 * per game AND overall, because bounding per game alone bounds nothing: any
 * positive integer is an acceptable game id, so a caller walking `game=1, 2, 3 …`
 * collects a fresh allowance every time. Here there is no game in the key, so
 * there is one flat directory and one bound that actually holds.
 *
 * UNAUTHENTICATED, like `lines.php` and for the same reason: the code is a
 * namespace, not a credential, and nothing stored here reaches a viewer. The cost
 * of that decision is everything below — a length cap on the text, a cap on
 * players per room, a cap on rooms, and expiry.
 *
 * THIS IS PERSONAL DATA ABOUT NAMED PEOPLE, WRITTEN BY SOMEBODY ELSE, which is
 * what separates it from every other store here and from the self-declared profile
 * fields section 5 asks for upstream. Three consequences are load-bearing rather
 * than decorative: `conf/` is default-closed in the overlays' `.htaccess` so these
 * files are not servable, the directory is gitignored so nothing reaches a
 * repository, and notes EXPIRE — see STALE_SECONDS. A note is scaffolding for one
 * broadcast, not a record anybody is keeping.
 *
 * See docs/COMMENTATOR.md section 5a.
 */

namespace Overlays;

require_once __DIR__ . '/lines.php';

final class Notes
{
    /**
     * Length of one player's note.
     *
     * About 150 words, which is far more than anybody reads aloud between points
     * and comfortably more than a team sends over. The cap exists to bound the
     * document rather than to shape the writing.
     */
    public const MAX_TEXT = 1000;

    /** Matches the commentator page's own name field. */
    public const MAX_BY = 24;

    /**
     * The structured fields an entry may carry beside the note.
     *
     * Single-line and short: each is something said in a breath — a nickname, a
     * pronoun set, how to say a name — not a place for prose.
     *
     * @var list<string>
     */
    public const FIELDS = ['nickname', 'pronouns', 'pronunciation'];

    /** Length of one structured field. */
    public const MAX_FIELD = 60;

    /**
     * Players carrying a note in one room.
     *
     * Two 28-player squads is 56, and a desk that keeps one code across a whole
     * tournament will touch several games — so this is generous rather than
     * tight. With MAX_TEXT and the structured fields it also fixes the document's
     * ceiling at ~120 KB, which is the number that actually matters for an
     * unauthenticated write.
     */
    private const MAX_PLAYERS_PER_ROOM = 100;

    /** Rooms kept before the least recently touched are dropped. */
    private const MAX_ROOMS_TOTAL = 200;

    /**
     * A room untouched for this long is deleted.
     *
     * These are notes about named people, gathered for one broadcast, and the
     * shortest defensible retention is the best one. A week is measured from the
     * last WRITE, which makes it long enough to cover preparation in the days
     * before an event plus the event itself, and short enough that a tournament's
     * notes are gone the following week rather than sitting on a disk for a year.
     *
     * Reading does NOT extend it. That is deliberate: a desk leaving the page
     * open must not keep somebody's personal data alive indefinitely, and expiry
     * that any passer-by can renew is not expiry.
     */
    public const STALE_SECONDS = 604800;   // 7 days

    /**
     * How often expiry is actually checked.
     *
     * Pruning has to happen on READ as well as on write. Pruning only on write —
     * which is what this store did first — means a tournament that finishes and
     * is never written to again keeps its notes forever, which is precisely the
     * case the retention limit exists for. But a commentator page polls this
     * endpoint every fifteen seconds per desk, so an unconditional directory scan
     * per read is wasteful. Once an hour is far more often than a 7-day window
     * needs and costs nothing.
     */
    private const PRUNE_INTERVAL = 3600;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? __DIR__ . '/../conf/notes';
    }

    /** The same alphabet and length as a line room: one code runs the whole desk. */
    public static function isCode(string $code): bool
    {
        return Lines::isCode($code);
    }

    /**
     * @return array{players: array<string,array<string,mixed>>, touched: int}
     */
    public function load(string $code): array
    {
        // Expiry is enforced here rather than only on write, so a tournament that
        // ends and is never written to again still forgets. The next desk to open
        // any room clears out everything that has aged past the limit.
        $this->maybePrune();

        $empty = ['players' => [], 'touched' => 0];
        $path = $this->pathFor($code);
        if ($path === null || !is_readable($path)) {
            return $empty;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return $empty;
        }
        return [
            'players' => self::cleanPlayers($decoded['players'] ?? []),
            'touched' => (int) ($decoded['touched'] ?? 0),
        ];
    }

    /**
     * Write one player's whole entry, or clear it.
     *
     * Per player rather than per room, for the reason `Lines::saveTeam()` is per
     * team: two people preparing a broadcast split the squads, so their writes
     * touch disjoint keys and cannot conflict. Where they do overlap the last
     * write wins, and the page shows who wrote it.
     *
     * `$fields` is the FULL desired state of the structured fields, not a delta:
     * a key that is absent or empty clears that field. The sheet edits all four
     * channels in one box and flushes them together, so "what the box shows is
     * what is stored" is the only contract a caller can reason about. Everything
     * empty deletes the entry rather than storing a blank, so clearing what a
     * commentator no longer wants actually removes it instead of leaving an
     * entry that counts against the room's cap and expires on its own schedule.
     *
     * @param  array<string,string> $fields
     * @return array{ok: bool, error: ?string, state: array}
     */
    public function save(string $code, int $playerId, string $text, string $by = '', array $fields = []): array
    {
        $path = $this->pathFor($code);
        if ($path === null || $playerId <= 0) {
            return ['ok' => false, 'error' => 'Bad room.', 'state' => $this->load($code)];
        }

        return $this->withLock($path, function () use ($path, $code, $playerId, $text, $by, $fields) {
            $state = $this->load($code);
            $players = $state['players'];
            $clean = self::cleanText($text);
            $cleanFields = self::cleanFields($fields);

            // Skip a write that would change nothing, rather than one that
            // arrives too soon.
            //
            // This started as a time-based throttle -- refuse anything landing
            // within 0.2s of the last write and report success, on the reasoning
            // that the next poll carries the same state. It does not: the state
            // was never stored, so the next poll REVERTS the edit, and the caller
            // was told it saved. `filemtime()` also has one-second granularity,
            // which made the real window unpredictable up to a second rather than
            // the 0.2s it appeared to be.
            //
            // Comparing content instead is strictly better. It drops exactly the
            // writes worth dropping -- a debounced save firing twice with the
            // same text -- and never loses one that would have changed anything.
            $existing = $players[(string) $playerId] ?? null;
            $emptied = $clean === '' && $cleanFields === [];
            $unchanged = $emptied
                ? $existing === null
                : ($existing !== null
                    && $existing['text'] === $clean
                    && self::fieldsOf($existing) === $cleanFields);
            if ($unchanged) {
                return ['ok' => true, 'error' => null, 'state' => $state];
            }

            if ($emptied) {
                unset($players[(string) $playerId]);
            } else {
                if (
                    !isset($players[(string) $playerId])
                    && count($players) >= self::MAX_PLAYERS_PER_ROOM
                ) {
                    return [
                        'ok' => false,
                        'error' => 'This room is full (' . self::MAX_PLAYERS_PER_ROOM . ' players).',
                        'state' => $state,
                    ];
                }
                $players[(string) $playerId] = ['text' => $clean]
                    + $cleanFields
                    + ['by' => self::cleanBy($by), 'at' => time()];
            }

            $next = ['players' => $players, 'touched' => time()];

            // Unconditional here, unlike the throttled sweep on read, and the
            // difference is not an oversight. On read, pruning enforces RETENTION
            // and once an hour is ample for a 7-day window. On write it also
            // enforces the room-count BOUND, and a bound that only applies once an
            // hour is not a bound: an unauthenticated caller would simply create
            // rooms inside the gap.
            $this->prune();
            if (!$this->write($path, $next)) {
                return ['ok' => false, 'error' => 'Could not write the room.', 'state' => $state];
            }
            return ['ok' => true, 'error' => null, 'state' => $next];
        });
    }

    /**
     * Write a whole team's notes in one go, for a CSV import.
     *
     * **Not a loop over save(), and it should not become one.** Twenty-eight
     * separate requests means twenty-eight lock/read/write cycles, each one a
     * chance for a partner's concurrent edit to interleave, and a failure halfway
     * through leaves an import half-applied with no way to say which half.
     *
     * One request, one lock, one file write. It is the only shape in which
     * "apply this import" is a single decision rather than a partial one.
     *
     * `$ifAbsent` keeps the promise the preview makes, PER CHANNEL: the note and
     * each structured field are filled only where empty, independently, so a row
     * whose note was typed at the desk can still bring the pronouns nobody had.
     * The page already filters, but it decided that from a poll that may be
     * seconds old; enforcing it here means a partner writing during the preview
     * cannot have their note overwritten by an import that never saw it.
     *
     * @param  array<array{player:int,text?:string,nickname?:string,pronouns?:string,pronunciation?:string}> $entries
     * @return array{ok: bool, error: ?string, state: array, written: int, kept: int}
     */
    public function saveMany(string $code, array $entries, string $by = '', bool $ifAbsent = true): array
    {
        $path = $this->pathFor($code);
        if ($path === null) {
            return [
                'ok' => false, 'error' => 'Bad room.',
                'state' => $this->load($code), 'written' => 0, 'kept' => 0,
            ];
        }
        if (count($entries) > self::MAX_PLAYERS_PER_ROOM) {
            $entries = array_slice($entries, 0, self::MAX_PLAYERS_PER_ROOM);
        }

        return $this->withLock($path, function () use ($path, $code, $entries, $by, $ifAbsent) {
            $state = $this->load($code);
            $players = $state['players'];
            $written = 0;
            $kept = 0;

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $id = (int) ($entry['player'] ?? 0);
                $text = self::cleanText((string) ($entry['text'] ?? ''));
                $fields = self::cleanFields($entry);
                if ($id <= 0 || ($text === '' && $fields === [])) {
                    continue;
                }
                $key = (string) $id;
                $current = $players[$key] ?? null;

                // Fill what is empty; keep what was written. Per channel.
                $next = ['text' => $current['text'] ?? ''] + self::fieldsOf($current ?? []);
                $wrote = false;
                $keptAny = false;
                if ($text !== '') {
                    if ($ifAbsent && trim($next['text']) !== '') {
                        $keptAny = true;
                    } else {
                        $next['text'] = $text;
                        $wrote = true;
                    }
                }
                foreach ($fields as $field => $value) {
                    if ($ifAbsent && trim($next[$field] ?? '') !== '') {
                        $keptAny = true;
                    } else {
                        $next[$field] = $value;
                        $wrote = true;
                    }
                }
                if (!$wrote) {
                    if ($keptAny) {
                        $kept++;
                    }
                    continue;
                }
                if ($current === null && count($players) >= self::MAX_PLAYERS_PER_ROOM) {
                    // Full. Stop rather than dropping the tail in silence -- the
                    // caller reports what was written and what was not.
                    break;
                }
                $players[$key] = array_filter(
                    $next,
                    static fn ($v, $k) => $k === 'text' || $v !== '',
                    ARRAY_FILTER_USE_BOTH
                ) + ['by' => self::cleanBy($by), 'at' => time()];
                $written++;
            }

            $next = ['players' => $players, 'touched' => time()];
            $this->prune();
            if (!$this->write($path, $next)) {
                return [
                    'ok' => false, 'error' => 'Could not write the room.',
                    'state' => $state, 'written' => 0, 'kept' => 0,
                ];
            }
            return [
                'ok' => true, 'error' => null,
                'state' => $next, 'written' => $written, 'kept' => $kept,
            ];
        });
    }

    public function isWritable(): bool
    {
        return is_dir($this->dir) ? is_writable($this->dir) : is_writable(dirname($this->dir));
    }

    // -- internals ----------------------------------------------------------

    private function pathFor(string $code): ?string
    {
        if (!self::isCode($code)) {
            return null;
        }
        // The code is validated against a fixed alphabet above, so the name
        // cannot escape the directory whatever arrives on the wire.
        return $this->dir . '/' . $code . '.json';
    }

    /**
     * Strip control characters and hold the length.
     *
     * Tabs and newlines survive because a team's hand-over arrives as a list.
     * Everything else in the C0 range is removed rather than escaped: none of it
     * can be typed on purpose here, and a stray control character in a note is a
     * rendering problem waiting for whichever surface displays it next.
     */
    private static function cleanText(string $raw): string
    {
        $out = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw);
        if (!is_string($out)) {
            // A non-UTF-8 body fails the /u match and returns null. Refuse it
            // rather than storing bytes that will not survive json_encode.
            return '';
        }
        $out = trim($out);
        return mb_substr($out, 0, self::MAX_TEXT);
    }

    private static function cleanBy(string $raw): string
    {
        $out = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw);
        return is_string($out) ? mb_substr(trim($out), 0, self::MAX_BY) : '';
    }

    /**
     * The structured fields present in an arbitrary array, cleaned.
     *
     * Single-line — a newline in a pronoun set is nothing but an accident — and
     * held to MAX_FIELD. Empty values are omitted rather than kept as ''.
     *
     * @return array<string,string>
     */
    private static function cleanFields(array $raw): array
    {
        $clean = [];
        foreach (self::FIELDS as $field) {
            $out = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($raw[$field] ?? ''));
            $out = is_string($out) ? trim(preg_replace('/\s+/', ' ', $out) ?? '') : '';
            $out = mb_substr($out, 0, self::MAX_FIELD);
            if ($out !== '') {
                $clean[$field] = $out;
            }
        }
        return $clean;
    }

    /** The structured fields already on a stored entry, empties omitted. */
    private static function fieldsOf(array $entry): array
    {
        return self::cleanFields($entry);
    }

    /** @return array<string,array<string,mixed>> */
    private static function cleanPlayers(mixed $raw): array
    {
        if (!is_array($raw) && !is_object($raw)) {
            return [];
        }
        $clean = [];
        foreach ((array) $raw as $playerId => $entry) {
            $id = (int) $playerId;
            if ($id <= 0 || !is_array($entry)) {
                continue;
            }
            $text = self::cleanText((string) ($entry['text'] ?? ''));
            $fields = self::cleanFields($entry);
            if ($text === '' && $fields === []) {
                continue;
            }
            $clean[(string) $id] = ['text' => $text]
                + $fields
                + [
                    'by' => self::cleanBy((string) ($entry['by'] ?? '')),
                    'at' => (int) ($entry['at'] ?? 0),
                ];
            if (count($clean) >= self::MAX_PLAYERS_PER_ROOM) {
                break;
            }
        }
        return $clean;
    }

    /**
     * Run prune() at most once an hour.
     *
     * The stamp file carries the time of the last sweep in its mtime and holds no
     * contents. It is not a lock: two requests racing here both prune, which is
     * harmless because deleting an already-deleted file is a no-op.
     *
     * If the stamp cannot be written — a read-only conf/, say — this degrades to
     * pruning on every call rather than to never pruning. That is the right way
     * round: the expensive failure is cheap, and the cheap failure would be
     * keeping personal data past its limit.
     */
    private function maybePrune(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $stamp = $this->dir . '/.pruned';
        if (is_file($stamp) && (time() - (int) filemtime($stamp)) < self::PRUNE_INTERVAL) {
            return;
        }
        @touch($stamp);
        $this->prune();
    }

    /**
     * Keep the directory bounded, and enforce the retention limit.
     *
     * Anyone can create a room by naming a code, so without this it grows with
     * every typo and every passer-by. Expired rooms go first, then the least
     * recently touched. One glob over one flat directory is the whole bound here,
     * because the code is the entire key — see the note at the top of this file
     * about why `Lines` needs two.
     */
    private function prune(): void
    {
        $files = glob($this->dir . '/*.json') ?: [];
        $now = time();
        foreach ($files as $i => $file) {
            if (($now - (int) filemtime($file)) > self::STALE_SECONDS) {
                @unlink($file);
                @unlink($file . '.lock');
                unset($files[$i]);
            }
        }

        if (count($files) <= self::MAX_ROOMS_TOTAL) {
            return;
        }
        usort($files, static fn ($a, $b) => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, count($files) - self::MAX_ROOMS_TOTAL) as $old) {
            @unlink($old);
            @unlink($old . '.lock');
        }
    }

    /**
     * Serialise a read-modify-write, as shared/colors.php and shared/possession.php do.
     *
     * The temp-file-and-rename in write() gives READERS atomicity and nothing
     * else: each writer holds LOCK_EX on its own private temp file, which no
     * other process opens, so it excludes nobody. save() reads the room, changes
     * one key and writes it back, so two commentators saving notes in the same
     * moment could otherwise lose one of them.
     */
    private function withLock(string $path, callable $body): mixed
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return $body();
        }
        $handle = @fopen($path . '.lock', 'c');
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

    private function write(string $path, array $state): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return false;
        }
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        // Same temp-file-and-rename as the other stores: a partner polling
        // through a save must never read half a document.
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
