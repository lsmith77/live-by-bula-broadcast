<?php
/**
 * Operator-declared possession, for break chance and clean holds.
 *
 * UltiOrganizer records no possession changes at all — no turnover table, no
 * block timestamps usable for it (docs/STUDIO.md section 3.4). So a break chance
 * cannot be derived; it can only be declared. This is the store behind that
 * declaration, and docs/STUDIO.md section 3.5 is the argument for why it is a
 * bridge rather than the destination.
 *
 * **An append-only log keyed by score, not a mutable flag.** That choice is the
 * whole design, and it is what makes the thing correct rather than merely
 * working:
 *
 *   { "rev": 7, "enabled": true, "game": 702,
 *     "events": [ {"score":"9-6","d":1,"t":1787420000} ] }
 *
 * Each event carries the score at which it was made. A reader filters by the
 * score it is currently displaying, which gives three properties for free:
 *
 *   - **A point boundary resets possession without anyone resetting it.** A goal
 *     changes the score, no event matches the new score, and possession reads as
 *     offence again. There is no "clear on score" message to lose, and no window
 *     in which a stale BREAK CHANCE tab can survive into the next point — the
 *     single most likely way this feature could put something untrue on air.
 *   - **Clean holds become answerable after the fact.** "Did the defence ever
 *     have the disc during the point that just ended?" is a question about the
 *     PREVIOUS score, and the log still holds it. A mutable flag would have been
 *     overwritten by then.
 *   - **No races between the two pollers.** The Studio writes and the scoreboard
 *     reads on independent timers; with a log there is no read-modify-write to
 *     interleave, and a reader that misses a poll misses nothing.
 *
 * Written by the Studio through possession.php (admin-gated) and read by the
 * scoreboard straight off disk as a static file, on the ~1s channel — a break
 * chance is worthless ten seconds late.
 */

namespace Overlays;

require_once __DIR__ . '/lines.php';

final class Possession
{
    /**
     * Events kept.
     *
     * A busy point has a handful; a whole game a few dozen. This is a bound on
     * the file the scoreboard fetches every second, not a functional limit.
     */
    private const MAX_EVENTS = 400;

    /**
     * A commentator silent for longer than this has gone.
     *
     * Comfortably more than the 2s poll, so a slow network or a backgrounded tab
     * does not make somebody vanish and reappear in the operator's count.
     */
    private const PRESENCE_SECONDS = 15;

    /** One presence write per client per this many seconds. */
    private const HEARTBEAT_WRITE = 5;

    /** Events older than this are dropped on write — a game day, generously. */
    private const MAX_AGE_SECONDS = 43200;

    private string $path;

    /**
     * The nominated code lives in a SEPARATE file, and that is not tidiness.
     *
     * possession.json is served straight off disk by Apache so the scoreboard
     * can poll it every second without PHP — which means everything in it is
     * public. The code is what authorises a commentator to write, so publishing
     * it beside the data it protects would hand the door key to anyone who
     * fetched the file. It goes in a sibling that the .htaccess allowlist does
     * not open.
     */
    private string $codePath;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? __DIR__ . '/../conf/possession.json';
        $this->codePath = dirname($this->path) . '/possession-code.json';
    }

    public static function defaults(): array
    {
        return ['rev' => 0, 'enabled' => false, 'game' => null, 'code' => null,
                'ratio1' => null, 'events' => [], 'stoppage' => null, 'touched' => 0];
    }

    /**
     * A gender ratio, as MMP/FMP.
     *
     * Always MMP and FMP -- matching male player and matching female player --
     * never M and F. The categories are about who a player matches up against,
     * not about who they are, and using the shorter form quietly turns a
     * matchup rule into a statement about people. UltiOrganizer's printed
     * scoresheet still says "4M/3F"; this is the same rule stated properly.
     */
    public static function isRatio(string $value): bool
    {
        return (bool) preg_match('/^[1-9]MMP\/[1-9]FMP$/', $value);
    }

    /**
     * An event timestamp, to the millisecond.
     *
     * Whole seconds were not enough to tell two events apart: three presses in
     * one second all carried the same `t`, and since the log screen deletes the
     * row somebody is looking at BY its timestamp, deleting the third change
     * removed the first. Milliseconds also make the gaps on that screen honest —
     * two changes a third of a second apart are a scramble, and at one-second
     * resolution they were both "+0s".
     */
    private static function now(): float
    {
        return round(microtime(true), 3);
    }

    /** A score key. Both readers and writers must agree on this exact shape. */
    public static function scoreKey(int $home, int $visitor): string
    {
        return $home . '-' . $visitor;
    }

    public function load(): array
    {
        if (!is_readable($this->path)) {
            return self::defaults();
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($decoded)) {
            return self::defaults();
        }
        return [
            'rev' => (int) ($decoded['rev'] ?? 0),
            'enabled' => (bool) ($decoded['enabled'] ?? false),
            'game' => isset($decoded['game']) ? (int) $decoded['game'] : null,
            'code' => $this->loadCode(),
            'ratio1' => isset($decoded['ratio1']) && is_string($decoded['ratio1'])
                && self::isRatio($decoded['ratio1']) ? $decoded['ratio1'] : null,
            'events' => self::cleanEvents($decoded['events'] ?? []),
            'stoppage' => self::cleanStoppage($decoded['stoppage'] ?? null),
            'touched' => (int) ($decoded['touched'] ?? 0),
        ];
    }

    /**
     * Record one possession change, or flip the mode.
     *
     * Switching games clears the log rather than carrying it: a score key is
     * only meaningful within one game, and "9-6" in the next game is a different
     * point entirely.
     *
     * @param  array{enabled?:bool, game?:?int, score?:string, defence?:bool} $change
     * @return array{ok:bool, error:?string, state:array}
     */
    /**
     * Serialise a read-modify-write against a lock file.
     *
     * The temp-file-and-rename below gives READERS atomicity — a scoreboard
     * polling through a save never sees half a document — and that is all it
     * gives. It does nothing for two writers: each holds `LOCK_EX` on its own
     * private temp file, which no other process ever opens, so the lock excludes
     * nobody. Measured, twelve concurrent writes to this store landed five
     * events and lost seven.
     *
     * This is the one store with two intended writers — the operator and a
     * commentator both press O and D — so it is the one where that matters.
     */
    private function withLock(callable $body): mixed
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $body();
        }
        $handle = @fopen($this->path . '.lock', 'c');
        if ($handle === false) {
            // Better to risk the race than to refuse the write: a dropped O/D
            // press is invisible to the person who made it.
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

    public function apply(array $change): array
    {
        return $this->withLock(function () use ($change) {
            return $this->applyLocked($change);
        });
    }

    private function applyLocked(array $change): array
    {
        $state = $this->load();

        if (array_key_exists('game', $change)) {
            $game = $change['game'] === null ? null : (int) $change['game'];
            if ($game !== $state['game']) {
                $state['game'] = $game;
                $state['events'] = [];
            }
        }


        if (array_key_exists('enabled', $change)) {
            // `enabled` now means one thing only: is POSSESSION being tracked.
            // It used to double as the master switch for the commentator link,
            // which meant a desk could not be handed the gender ratio or a
            // stoppage without also turning on break chances. The code below is
            // independent of it for that reason.
            $state['enabled'] = (bool) $change['enabled'];
            if (!$state['enabled']) {
                // Leaving the mode drops the possession log — and only that.
                // Coming back later with stale events would let a tab reappear
                // for a point that ended long ago. The code, the ratio and any
                // stoppage are not possession data and survive.
                $state['events'] = [];
            }
        }

        /**
         * An injury stoppage: play has stopped for something the clock does not
         * know about.
         *
         * Keyed by score like everything else here, so it clears itself at the
         * next goal without anyone remembering to end it — the failure that
         * matters is a stoppage tab still on air two points later.
         */
        if (array_key_exists('stoppage', $change)) {
            $raw = $change['stoppage'];
            if (empty($raw)) {
                $state['stoppage'] = null;
            } else {
                $score = trim((string) ($change['score'] ?? ''));
                if (!preg_match('/^\d{1,3}-\d{1,3}$/', $score)) {
                    return ['ok' => false, 'error' => 'A score key looks like "9-6".', 'state' => $state];
                }
                $state['stoppage'] = ['score' => $score, 'since' => self::now()];
            }
        }

        if (array_key_exists('defence', $change)) {
            // Possession is the one thing that needs the mode on: without it the
            // log is cleared anyway, so recording into it would be writing to a
            // bucket with a hole in it.
            if (!$state['enabled']) {
                return ['ok' => false, 'error' => 'Possession tracking is off.', 'state' => $state];
            }
            $score = trim((string) ($change['score'] ?? ''));
            if (!preg_match('/^\d{1,3}-\d{1,3}$/', $score)) {
                return ['ok' => false, 'error' => 'A score key looks like "9-6".', 'state' => $state];
            }
            $d = $change['defence'] ? 1 : 0;

            // A press that does not change anything is not recorded.
            //
            // The disc cannot pass from the defence to the defence, so a second
            // D during the same possession is somebody confirming what is
            // already true — most often a second person tracking the same play.
            // Every reader already counts changes rather than entries, so
            // keeping it would not corrupt anything; dropping it keeps the log
            // to the length of the game rather than the length of the audience,
            // and makes two people tracking cost the same as one.
            $last = null;
            foreach ($state['events'] as $event) {
                if ($event['score'] === $score) {
                    $last = $event;
                }
            }

            if ($last === null || $last['d'] !== $d) {
                $state['events'][] = ['score' => $score, 'd' => $d, 't' => self::now()];
                $state['events'] = self::cleanEvents($state['events']);
            }
        }

        /**
         * The ratio the first point was played at.
         *
         * Nowhere in UltiOrganizer: it is circled by hand on the paper
         * scoresheet and never sent back, and the whole A-B-B-A pattern hangs
         * off it. It lives HERE rather than on the progression card because it
         * is a fact about the game, not about one graphic -- the commentator
         * panel and the card both need it, and asking two people to enter the
         * same thing is how they end up disagreeing on air.
         *
         * Deliberately outside the enabled/disabled cycle that clears events: a
         * ratio is still true when nobody is tracking possession.
         */
        if (array_key_exists('ratio1', $change)) {
            $raw = trim((string) ($change['ratio1'] ?? ''));
            if ($raw === '') {
                $state['ratio1'] = null;
            } elseif (self::isRatio($raw)) {
                $state['ratio1'] = $raw;
            } else {
                return ['ok' => false, 'error' => 'A ratio looks like "4MMP/3FMP".', 'state' => $state];
            }
        }

        /**
         * Undo the last press, or wipe the point and start it again.
         *
         * Both are scoped to ONE point, named by its score, and that is the
         * safety property rather than a limitation. A correction to the point
         * being played changes a number nobody has read out yet; a correction to
         * an earlier point silently rewrites a turnover count that was already
         * on air and a conversion figure a commentator may have quoted. If an
         * older point really is wrong, that is worth a deliberate decision, not
         * a button next to the live ones.
         *
         * Available to whoever can set possession, because the person who
         * mis-pressed is the person who needs to fix it, immediately.
         */
        if (!empty($change['undo']) || !empty($change['clearPoint']) || isset($change['at'])) {
            $score = trim((string) ($change['score'] ?? ''));
            if (!preg_match('/^\d{1,3}-\d{1,3}$/', $score)) {
                return ['ok' => false, 'error' => 'A score key looks like "9-6".', 'state' => $state];
            }

            if (isset($change['at'])) {
                // Delete one named entry rather than "the last one". The log is
                // read on screen before anything is removed, so the thing being
                // removed is the row somebody is looking at -- identified by
                // when it happened, which does not shift under a concurrent
                // write the way an index would.
                $at = (float) $change['at'];
                foreach ($state['events'] as $i => $event) {
                    if ($event['score'] === $score && abs((float) $event['t'] - $at) < 0.0005) {
                        array_splice($state['events'], $i, 1);
                        break;
                    }
                }
            } elseif (!empty($change['clearPoint'])) {
                $state['events'] = array_values(array_filter(
                    $state['events'],
                    static fn (array $e): bool => $e['score'] !== $score,
                ));
            } else {
                // Drop only the last press of that point, so a mis-key costs one
                // press to make and one to unmake.
                for ($i = count($state['events']) - 1; $i >= 0; $i--) {
                    if ($state['events'][$i]['score'] === $score) {
                        array_splice($state['events'], $i, 1);
                        break;
                    }
                }
            }
        }

        if (array_key_exists('code', $change)) {
            // "new" asks for one rather than supplying it. Left to invent a code
            // on the spot people reach for "12345", and two desks at the same
            // tournament then collide.
            $wanted = $change['code'] === 'new'
                ? (new Lines())->generate()
                : self::cleanCode($change['code']);
            $state['code'] = $wanted;
            $this->writeCode($wanted);
        }

        $state['rev'] += 1;
        $state['touched'] = time();

        if (!$this->write($state)) {
            return ['ok' => false, 'error' => 'Could not write the possession store.', 'state' => $state];
        }
        return ['ok' => true, 'error' => null, 'state' => $state];
    }

    public function isWritable(): bool
    {
        $dir = dirname($this->path);
        return is_file($this->path) ? is_writable($this->path) : is_writable($dir);
    }

    public function publicUrl(string $assetBase): string
    {
        return rtrim($assetBase, '/') . '/conf/possession.json';
    }

    /**
     * May this room code write possession for this game?
     *
     * Three conditions, all of them the operator's to grant: the mode is on, a
     * code has been nominated in the Studio, and it is this one. So the code is
     * not a credential someone can present — it is a single-entry allowlist an
     * admin filled in, and the operator revokes it by clearing the field or
     * switching the mode off, either of which is one action.
     *
     * That is a deliberately narrower door than lines.php, and it has to be:
     * a line selection is private to a commentary desk, whereas this reaches
     * the broadcast. See docs/COMMENTATOR.md section 6.
     */
    /**
     * Is this the code the operator nominated for this game?
     *
     * Deliberately says nothing about WHAT the holder may then do. It used to
     * require possession tracking to be switched on, which meant a commentary
     * desk could not be handed the gender ratio or an injury stoppage without
     * also turning on break chances — three unrelated things behind one switch.
     * The code is the link; each thing it unlocks decides its own conditions.
     */
    public function allowsCode(?string $code, ?int $game): bool
    {
        $state = $this->load();
        if ($state['code'] === null) {
            return false;
        }
        if ($state['game'] !== null && $game !== null && $state['game'] !== $game) {
            return false;
        }
        return self::cleanCode($code) === $state['code'];
    }

    // -- internals ----------------------------------------------------------

    private function loadPrivate(): array
    {
        if (!is_readable($this->codePath)) {
            return ['code' => null, 'seen' => []];
        }
        $decoded = json_decode((string) file_get_contents($this->codePath), true);
        if (!is_array($decoded)) {
            return ['code' => null, 'seen' => []];
        }
        $seen = [];
        foreach ((array) ($decoded['seen'] ?? []) as $id => $row) {
            if (!is_string($id) || !preg_match('/^[a-z0-9]{4,32}$/', $id)) {
                continue;
            }
            // Older files stored a bare timestamp; read both shapes.
            $seen[$id] = is_array($row)
                ? ['t' => (int) ($row['t'] ?? 0), 'name' => self::cleanName($row['name'] ?? null)]
                : ['t' => (int) $row, 'name' => null];
        }
        return ['code' => self::cleanCode($decoded['code'] ?? null), 'seen' => $seen];
    }

    private function loadCode(): ?string
    {
        return $this->loadPrivate()['code'];
    }

    private function writePrivate(array $private): void
    {
        if ($private['code'] === null && empty($private['seen'])) {
            @unlink($this->codePath);
            return;
        }
        @file_put_contents($this->codePath, json_encode($private) . "\n", LOCK_EX);
    }

    private function writeCode(?string $code): void
    {
        $private = $this->loadPrivate();
        $private['code'] = $code;
        // A new code means a new audience; the old code's clients are not it.
        $private['seen'] = [];
        $this->writePrivate($private);
    }

    /**
     * Note that a commentator is listening.
     *
     * Keyed by a random id the browser makes for itself, not by IP address. The
     * question the Studio asks is "how many people are on this", which a client
     * id answers exactly; an IP answers it worse (two commentators behind one
     * tournament wifi are one address) and stores personal data to do it.
     *
     * Rate-limited to one write per client per HEARTBEAT_WRITE seconds, because
     * this is called from a poll that runs every couple of seconds per client.
     */
    public function touchClient(string $clientId, ?string $name = null): void
    {
        if (!preg_match('/^[a-z0-9]{4,32}$/', $clientId)) {
            return;
        }
        $private = $this->loadPrivate();
        $now = time();
        $known = $private['seen'][$clientId] ?? null;
        $clean = self::cleanName($name);

        // Rate-limited, but a NAME change is written straight through: somebody
        // typing their name wants to see it appear on the operator's screen, not
        // in five seconds' time.
        if ($known !== null && ($now - $known['t']) < self::HEARTBEAT_WRITE
            && $clean === ($known['name'] ?? null)) {
            return;
        }

        $private['seen'] = self::freshOnly($private['seen'], $now);
        $private['seen'][$clientId] = ['t' => $now, 'name' => $clean];
        $this->writePrivate($private);
    }

    /**
     * Who is listening, most recently heard from first.
     *
     * Names are typed by the commentators themselves and shown only to the
     * operator, so they exist to answer "is the right desk on this code" and
     * nothing else. They live in the private file with the code, are capped
     * short, and disappear with the client when it stops polling — there is no
     * reason for a name to outlive the session that supplied it.
     *
     * @return array<int, array{name: ?string, ago: int}>
     */
    public function connected(): array
    {
        $now = time();
        $fresh = self::freshOnly($this->loadPrivate()['seen'], $now);
        uasort($fresh, static fn (array $a, array $b): int => $b['t'] <=> $a['t']);

        $out = [];
        foreach ($fresh as $row) {
            $out[] = ['name' => $row['name'] ?? null, 'ago' => $now - $row['t']];
        }
        return $out;
    }

    public function connectedCount(): int
    {
        return count(self::freshOnly($this->loadPrivate()['seen'], time()));
    }

    /** A short, plain display name. Never rendered as markup by any caller. */
    private static function cleanName(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        // Control characters out, whitespace collapsed, and capped: this is a
        // label beside a code, not a free-text field.
        $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', '', $raw)) ?? '');
        if ($name === '') {
            return null;
        }
        return mb_substr($name, 0, 24);
    }

    /** @param array<string,array{t:int,name:?string}> $seen */
    private static function freshOnly(array $seen, int $now): array
    {
        $out = [];
        foreach ($seen as $id => $row) {
            if (($now - (int) ($row['t'] ?? 0)) <= self::PRESENCE_SECONDS) {
                $out[$id] = $row;
            }
        }
        return $out;
    }

    /** @return array{score:string, since:float}|null */
    private static function cleanStoppage(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $score = (string) ($raw['score'] ?? '');
        if (!preg_match('/^\d{1,3}-\d{1,3}$/', $score)) {
            return null;
        }
        return ['score' => $score, 'since' => (float) ($raw['since'] ?? 0)];
    }

    private static function cleanCode(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $code = strtoupper(trim($raw));
        return Lines::isCode($code) ? $code : null;
    }

    /** @return array<int,array{score:string,d:int,t:int}> */
    private static function cleanEvents(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $cutoff = time() - self::MAX_AGE_SECONDS;
        $clean = [];
        foreach ($raw as $event) {
            if (!is_array($event)) {
                continue;
            }
            $score = (string) ($event['score'] ?? '');
            $when = (float) ($event['t'] ?? 0);
            if (!preg_match('/^\d{1,3}-\d{1,3}$/', $score) || $when < $cutoff) {
                continue;
            }
            $clean[] = ['score' => $score, 'd' => empty($event['d']) ? 0 : 1, 't' => $when];
        }
        return count($clean) > self::MAX_EVENTS
            ? array_slice($clean, -self::MAX_EVENTS)
            : $clean;
    }

    private function write(array $state): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        // Strip the code before it reaches the world-readable file.
        $public = $state;
        unset($public['code']);
        $public['hasCode'] = $state['code'] !== null;

        $json = json_encode($public, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        // Same temp-file-and-rename as the other stores: a scoreboard polling
        // through a save must never read half a document.
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
