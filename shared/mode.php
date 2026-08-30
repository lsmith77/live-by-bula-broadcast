<?php

/**
 * Where this installation's payloads come from.
 *
 * Hosted, the answer is always Live!. Standalone, it can be a **capture** — a
 * directory of recorded API responses written by `tests/capture.mjs` — which is
 * what lets these pages run with no UltiOrganizer, no database and no network
 * at all (`docs/STANDALONE.md` §7a).
 *
 * WHY THIS IS CONFIGURED AND NOT A QUERY PARAMETER
 *
 * A `?capture=` parameter would be far more convenient and would also let
 * anyone who can load an overlay decide what that overlay shows. These pages
 * reach air. What is on air is admin-gated everywhere else in this project, and
 * a switch that silently swaps the whole data source is not the one place to
 * make an exception.
 *
 * So it lives in `conf/local-config.php`, beside the administrator hash, which
 * is gitignored and denied over HTTP.
 */

namespace Overlays;

final class Mode
{
    public const LOCAL_CONFIG = __DIR__ . '/../conf/local-config.php';

    /**
     * The URL prefix this directory is served under.
     *
     * Hosted, the overlays live at `<prefix>/live/overlays/` — a path every
     * page had hard-coded into its own asset helper, which is exactly the
     * assumption that breaks when the directory IS the document root. So it is
     * asked once, here.
     *
     * Standalone is detected from the front controller having defined itself,
     * rather than from `Auth::isHosted()`: this is a question about the URL
     * layout, and a host could in principle be present without serving these
     * pages at that path.
     */
    public static function assetBase(string $base = ''): string
    {
        if (defined('OVERLAYS_STANDALONE')) {
            $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));

            return $dir === '/' ? '' : rtrim($dir, '/');
        }

        return rtrim($base, '/') . '/live/overlays';
    }

    /**
     * The URL of one of this project's own routed endpoints.
     *
     * Hosted, that is UltiOrganizer's front controller with a `live/overlays/`
     * view. Standalone, it is `app.php` with a bare one. Four pages had the
     * hosted spelling written into them — the line, note, possession, colour
     * and show endpoints — which is the same assumption the asset paths made
     * and breaks in the same place.
     */
    public static function viewUrl(string $view, string $base = ''): string
    {
        if (defined('OVERLAYS_STANDALONE')) {
            return self::assetBase($base) . '/app.php?view=' . $view;
        }

        return rtrim($base, '/') . '/index.php?view=live/overlays/' . $view;
    }

    /**
     * The URL a browser should read a capture from, or null for live.
     *
     * Returns a URL rather than a path because the reader is JavaScript: the
     * capture directory has to be reachable over HTTP, which means it must sit
     * where the server will serve it. `conf/` deliberately will not, so a
     * capture belongs somewhere like `fixtures/payloads/<name>`.
     */
    public static function captureBase(string $base = ''): ?string
    {
        if (!is_file(self::LOCAL_CONFIG)) {
            return null;
        }

        $config = require self::LOCAL_CONFIG;
        $capture = is_array($config) ? ($config['capture'] ?? null) : null;
        if (!is_string($capture) || $capture === '') {
            return null;
        }

        // An absolute URL is taken as given; anything else is relative to this
        // installation, so a config written once works under any URL prefix.
        if (preg_match('#^(https?:)?//#', $capture) === 1) {
            return $capture;
        }

        return self::assetBase($base) . '/' . ltrim($capture, '/');
    }
}
