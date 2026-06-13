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
const APP_BUILD = 'geo-locate-21';

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

    // /api/search?q=&limit=  (advanced autocomplete foundation)
    if (($segments[1] ?? null) === 'search') {
        if (isset($segments[2])) {
            fail(404, 'Unknown resource');
        }
        $query = trim((string) ($_GET['q'] ?? ''));
        if ($query === '') {
            fail(400, 'Missing q');
        }
        if (strlen($query) > 64) {
            fail(400, 'Invalid q');
        }
        $normalizedQuery = strtoupper($query);
        if (strlen($normalizedQuery) < 2) {
            fail(400, 'Invalid q');
        }

        $limit = 8;
        if (isset($_GET['limit'])) {
            $parsedLimit = filter_var($_GET['limit'], FILTER_VALIDATE_INT);
            if ($parsedLimit === false) {
                fail(400, 'Invalid limit');
            }
            $limit = max(1, min(20, $parsedLimit));
        }

        send_json(cached($cached, 'search?q=' . $normalizedQuery . '&l=' . $limit, $version,
            static fn() => json_encode_api(api_search($db, $query, $limit))));
    }

    // /api/nearest?lat=&lng=  (nearest LHFA + LYFF object)
    if (($segments[1] ?? null) === 'nearest') {
        if (!isset($_GET['lat'], $_GET['lng'])) {
            fail(400, 'Missing lat/lng');
        }
        $lat = filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT);
        $lng = filter_var($_GET['lng'], FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false || !is_finite($lat) || !is_finite($lng)
            || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            fail(400, 'Invalid lat/lng');
        }
        send_json(cached($cached, 'nearest?' . round($lat, 3) . ',' . round($lng, 3), $version,
            static fn() => json_encode_api(api_nearest($db, $dataDir, $lat, $lng))));
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
            $normalizedCall = normalize_call($call);
            if ($normalizedCall === '') {
                fail(400, 'Missing callsign');
            }
            if (strlen($call) > 64 || !preg_match('/^[A-Z0-9]{2,20}$/', $normalizedCall)) {
                fail(400, 'Invalid callsign');
            }
            send_json(cached($cached, "$mode/activator/$normalizedCall", $version,
                static fn() => json_encode_api(api_activator($db, $prog, $mode, $normalizedCall))));

        default:
            fail(404, 'Unknown resource');
    }
}

// ---- SPA shell: root and every non-API route (client-side router takes over) ----
header('Content-Type: text/html; charset=utf-8');
echo shell_html(app_base_href(), 'lt');
exit;
