<?php
/**
 * Kit colours for overlays.
 *
 * UltiOrganizer 4 has no team colour: `uo_pool.color` and `uo_series.color`
 * exist, but nothing on `uo_team`, `uo_team_profile` or `uo_club`. Without a
 * store of our own both sides of a scoreboard show the same pool colour.
 *
 * Deliberately per game, and nothing else:
 *
 *   { "games": { "702": { "home": "FFFFFF", "visitor": "1B1B1B" } } }
 *
 * An earlier design kept prepared per-team palettes to pick from. That was more
 * machinery than the job needs: which kit a team wears is decided at the coin
 * toss, and the operator is standing there looking at the jerseys minutes before
 * the pull. Reading a colour off a shirt and typing it is one step; maintaining
 * a palette all season so it can be clicked is several. Palettes found in an
 * existing file are ignored rather than migrated.
 *
 * Stored as JSON rather than in the database — the plan forbids schema changes,
 * and a file under live/overlays/ survives a Live! upgrade (which unzips over
 * live/ without deleting files it does not ship).
 */

namespace Overlays;

final class Colors
{
    /** Six uppercase hex digits, no leading '#'. */
    private const HEX = '/^[0-9A-F]{6}$/';

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? __DIR__ . '/../conf/team-colors.json';
    }

    public function storePath(): string
    {
        return $this->path;
    }

    /**
     * @return array{games: array<string,array{home:?string,visitor:?string}>}
     */
    public function load(): array
    {
        if (!is_readable($this->path)) {
            return ['games' => []];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($decoded)) {
            return ['games' => []];
        }

        return ['games' => self::cleanGames($decoded['games'] ?? [])];
    }

    /** @return array{home:?string,visitor:?string}|null */
    public function gameChoice(int $gameId): ?array
    {
        return $this->load()['games'][(string) $gameId] ?? null;
    }

    /**
     * Record what each side is wearing for one game. Null clears a side.
     */
    public function saveGameChoice(int $gameId, ?string $home, ?string $visitor): bool
    {
        if ($gameId <= 0) {
            return false;
        }
        return $this->withLock(function () use ($gameId, $home, $visitor): bool {
            return $this->saveGameChoiceLocked($gameId, $home, $visitor);
        });
    }

    private function saveGameChoiceLocked(int $gameId, ?string $home, ?string $visitor): bool
    {
        $state = $this->load();
        $key = (string) $gameId;

        $entry = [
            'home' => $home === null ? null : self::normalise($home),
            'visitor' => $visitor === null ? null : self::normalise($visitor),
        ];

        if ($entry['home'] === null && $entry['visitor'] === null) {
            unset($state['games'][$key]);
        } else {
            $state['games'][$key] = $entry;
        }

        return $this->write($state);
    }

    public function isWritable(): bool
    {
        return is_writable($this->path)
            || (!file_exists($this->path) && is_writable(dirname($this->path)));
    }

    /** Accepts '#abc123' or 'ABC123'; returns 'ABC123' or null. */
    public static function normalise(string $hex): ?string
    {
        $value = strtoupper(ltrim(trim($hex), '#'));
        return preg_match(self::HEX, $value) ? $value : null;
    }

    // -- internals ----------------------------------------------------------

    /** @return array<string,array{home:?string,visitor:?string}> */
    private static function cleanGames(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $gameId => $entry) {
            $id = (int) $gameId;
            if ($id <= 0 || !is_array($entry)) {
                continue;
            }
            $home = isset($entry['home']) && is_string($entry['home'])
                ? self::normalise($entry['home']) : null;
            $visitor = isset($entry['visitor']) && is_string($entry['visitor'])
                ? self::normalise($entry['visitor']) : null;

            if ($home !== null || $visitor !== null) {
                $clean[(string) $id] = ['home' => $home, 'visitor' => $visitor];
            }
        }

        ksort($clean, SORT_NUMERIC);
        return $clean;
    }

    /**
     * Serialise a read-modify-write, as shared/possession.php does.
     *
     * The temp-file-and-rename below gives READERS atomicity and nothing else:
     * each writer holds `LOCK_EX` on its own private temp file, which no other
     * process opens, so it excludes nobody. Two operators setting kit colours in
     * the same second could lose one of them.
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

        // Write via a temporary file in the same directory so a reader never
        // sees a half-written store — an overlay may poll through a save.
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
