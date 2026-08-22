<?php
/**
 * Per-team overlay logos.
 *
 * Live! already exposes images from live/teams/ as `teams.*.photos[]` when
 * TEAM_PHOTOS_ENABLED is on, and the scoreboard falls back to those. But those
 * are squad photos shown on Live!'s own team pages — usually a wide group shot,
 * not the square, transparent mark a broadcast overlay wants. Reusing that slot
 * would also mean any logo dropped in for the overlay shows up on the public
 * Live! site.
 *
 * So overlays get their own directory. Drop a file named after the team id:
 *
 *   live/overlays/logos/300.png
 *   live/overlays/logos/301.svg
 *
 * Nothing to configure — the file being there is the configuration.
 */

namespace Overlays;

final class Logos
{
    /** Web formats that make sense for a logo. SVG and PNG carry transparency. */
    private const EXTENSIONS = ['svg', 'png', 'webp', 'jpg', 'jpeg', 'gif'];

    private string $dir;
    private string $urlBase;

    public function __construct(?string $dir = null, string $urlBase = '/live/overlays/logos')
    {
        $this->dir = $dir ?? __DIR__ . '/../logos';
        $this->urlBase = rtrim($urlBase, '/');
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * @return array<string,string> team id => URL
     */
    public function all(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $found = [];
        foreach ((array) scandir($this->dir) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            // Strictly <team id>.<ext>: anything else is ignored rather than
            // guessed at, so a stray file cannot become a team's logo.
            if (!preg_match('/^([0-9]+)\.([A-Za-z0-9]+)$/', $entry, $m)) {
                continue;
            }
            if (!in_array(strtolower($m[2]), self::EXTENSIONS, true)) {
                continue;
            }
            if (!is_file($this->dir . '/' . $entry)) {
                continue;
            }

            // Cache-bust on mtime so replacing a logo shows up without the
            // switcher needing its browser cache cleared mid-event.
            $stamp = @filemtime($this->dir . '/' . $entry) ?: 0;
            $found[$m[1]] = $this->urlBase . '/' . rawurlencode($entry) . '?v=' . $stamp;
        }

        ksort($found, SORT_NUMERIC);
        return $found;
    }

    public function for(int $teamId): ?string
    {
        return $this->all()[(string) $teamId] ?? null;
    }

    /** Extensions accepted, for messages in the picker. */
    public static function extensions(): array
    {
        return self::EXTENSIONS;
    }
}
