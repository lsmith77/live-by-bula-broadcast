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
                'ratio1' => null, 'events' => [], 'touched' => 0];
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
            $state['enabled'] = (bool) $change['enabled'];
            // Offer a code rather than asking for one. The operator and the
            // commentary desk settle on it out of band — a voice call, a
            // message — so the useful thing is having one ready to read out,
            // not a handshake. Left to invent one on the spot people reach for
            // "12345", and two desks at the same tournament then collide.
            if ($state['enabled'] && $state['code'] === null) {
                $state['code'] = (new Lines())->generate();
                $this->writeCode($state['code']);
            }
            // Leaving the mode drops the log. Coming back later with stale
            // events would let a tab reappear for a point that ended long ago.
            if (!$state['enabled']) {
                $state['events'] = [];
            }
        }

        if (array_key_exists('defence', $change)) {
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
                $state['events'][] = ['score' => $score, 'd' => $d, 't' => time()];
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

        if (array_key_exists('code', $change)) {
            $state['code'] = self::cleanCode($change['code']);
            $this->writeCode($state['code']);
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
    public function allowsCode(?string $code, ?int $game): bool
    {
        $state = $this->load();
        if (!$state['enabled'] || $state['code'] === null) {
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
        foreach ((array) ($decoded['seen'] ?? []) as $id => $when) {
            if (is_string($id) && preg_match('/^[a-z0-9]{4,32}$/', $id)) {
                $seen[$id] = (int) $when;
            }
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
    public function touchClient(string $clientId): void
    {
        if (!preg_match('/^[a-z0-9]{4,32}$/', $clientId)) {
            return;
        }
        $private = $this->loadPrivate();
        $now = time();
        if (isset($private['seen'][$clientId]) && ($now - $private['seen'][$clientId]) < self::HEARTBEAT_WRITE) {
            return;
        }
        $private['seen'] = self::freshOnly($private['seen'], $now);
        $private['seen'][$clientId] = $now;
        $this->writePrivate($private);
    }

    /** Commentators heard from recently enough to still be there. */
    public function connectedCount(): int
    {
        return count(self::freshOnly($this->loadPrivate()['seen'], time()));
    }

    /** @param array<string,int> $seen */
    private static function freshOnly(array $seen, int $now): array
    {
        $out = [];
        foreach ($seen as $id => $when) {
            if (($now - $when) <= self::PRESENCE_SECONDS) {
                $out[$id] = $when;
            }
        }
        return $out;
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
            $when = (int) ($event['t'] ?? 0);
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
