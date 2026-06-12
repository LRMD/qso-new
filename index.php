<?php
// index.php (PHP 7.4) — front controller for the /new map-first app.
// Wires: config -> router -> programme whitelist -> (db, cache ready) -> handler.
// Phase 3 (API handlers) and Phase 4 (SPA shell) plug into the marked branches.

declare(strict_types=1);

require __DIR__ . '/src/http.php';
require __DIR__ . '/src/config.php';
require __DIR__ . '/src/router.php';
require __DIR__ . '/src/programmes.php';
require __DIR__ . '/src/cache.php';
require __DIR__ . '/src/db.php';
require __DIR__ . '/src/geometry.php';
require __DIR__ . '/src/api.php';
require __DIR__ . '/src/view.php';

// Bump on every deploy that changes API/geometry output — it is part of the cache
// key, so old cached responses are invalidated even if no new QSO has arrived.
const APP_BUILD = 'p8.4-anchors-6';

// Built-in server (php -S): serve real static files directly (Apache does this
// via .htaccess in production). No-op under php-fpm/mod_php.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

$cfg      = load_config(__DIR__ . '/.env.ini');
$segments = route($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');

// ---- API namespace: /api/... ----
if ($segments[0] === 'api') {
    $db      = db_connect($cfg);
    $version = data_version($db) . '|' . APP_BUILD;   // bumps on new QSOs OR code deploys
    $cached  = $cfg['cache_dir'];
    $dataDir = __DIR__ . '/data';

    // /api/meta/last-update
    if (($segments[1] ?? null) === 'meta') {
        if (($segments[2] ?? null) === 'last-update') {
            send_json(cached($cached, 'meta/last-update', $version,
                static fn() => json_encode_api(api_meta_last_update($db))));
        }
        fail(404, 'Unknown resource');
    }

    // /api/{mode}/{resource}[/{arg}]
    $mode = $segments[1] ?? '';
    $prog = programme($mode);
    if ($prog === null) {
        fail(404, 'Unknown mode');
    }

    switch ($segments[2] ?? null) {
        case 'objects':
            send_json(cached($cached, "$mode/objects", $version,
                static fn() => json_encode_api(api_objects($db, $prog, $mode, $dataDir))));

        case 'stats':
            send_json(cached($cached, "$mode/stats", $version,
                static fn() => json_encode_api(api_stats($db, $prog, $mode))));

        case 'recent':
            $limit  = isset($_GET['limit'])  ? (string) $_GET['limit']  : '';
            $before = isset($_GET['before']) ? (string) $_GET['before'] : '';
            send_json(cached($cached, "$mode/recent?l=$limit&b=$before", $version,
                static fn() => json_encode_api(api_recent($db, $prog, $mode, $_GET))));

        case 'object':
            $code = rawurldecode($segments[3] ?? '');
            $key  = parse_object_code($mode, $code);
            if ($key === null) {
                fail(404, 'Invalid object code');
            }
            send_json(cached($cached, "$mode/object/$code", $version,
                static fn() => json_encode_api(api_object($db, $prog, $mode, $key[0], $key[1]))));

        case 'activator':
            $call = rawurldecode($segments[3] ?? '');
            if ($call === '') {
                fail(400, 'Missing callsign');
            }
            send_json(cached($cached, "$mode/activator/$call", $version,
                static fn() => json_encode_api(api_activator($db, $prog, $mode, $call))));

        default:
            fail(404, 'Unknown resource');
    }
}

// ---- SPA shell: root and every non-API route (client-side router takes over) ----
header('Content-Type: text/html; charset=utf-8');
echo shell_html(app_base_href(), 'lt');
exit;
