<?php
// src/api.php (PHP 7.4) — read-only API handlers (Phase 3).
// Each handler returns a plain array; the front controller encodes + caches it.
// Object-key columns (c1/c2) come ONLY from the programme whitelist (programmes.php);
// every user-supplied value is bound via a prepared statement.

declare(strict_types=1);

// --- helpers -------------------------------------------------------------

/** Datetime threshold = now minus $days, as 'Y-m-d H:i:s' (lexically comparable). */
function recent_threshold(int $days): string
{
    $dt = new DateTime('now');
    $dt->modify('-' . $days . ' days');
    return $dt->format('Y-m-d H:i:s');
}

/** Format a (c1, c2) pair into the display code for the mode. */
function format_code(string $mode, string $c1, int $c2): string
{
    switch ($mode) {
        case 'wal':  return wal_code($c1, $c2);
        case 'lhfa': return lhfa_code($c1, $c2);
        case 'lyff': return lyff_code($c2);
    }
    return $c1 . '-' . $c2;
}

/** Split a GROUP_CONCAT string into a list (empty list for null). */
function split_list(?string $s): array
{
    return ($s === null || $s === '') ? [] : explode(',', $s);
}

/** Normalize a callsign: uppercase, drop any "/suffix". */
function normalize_call(string $s): string
{
    $s = strtoupper(trim($s));
    $slash = strpos($s, '/');
    return $slash === false ? $s : substr($s, 0, $slash);
}

/**
 * Resolve a callsign to its canonical primary and the full set of calls that
 * fold to it (via pri_sec_call) — so an activator is matched regardless of which
 * variant was logged.
 *
 * @return array{canonical:string, calls:string[]}
 */
function resolve_calls(mysqli $db, string $input): array
{
    $norm = normalize_call($input);
    if ($norm === '') {
        return ['canonical' => '', 'calls' => []];
    }

    $canonical = $norm;
    if ($stmt = mysqli_prepare($db, 'SELECT primary_call FROM pri_sec_call WHERE secondary_call = ? LIMIT 1')) {
        mysqli_stmt_bind_param($stmt, 's', $norm);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['primary_call'])) {
            $canonical = strtoupper($row['primary_call']);
        }
    }

    $calls = [$norm => true, $canonical => true];
    if ($stmt = mysqli_prepare($db, 'SELECT secondary_call FROM pri_sec_call WHERE primary_call = ?')) {
        mysqli_stmt_bind_param($stmt, 's', $canonical);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            if (!empty($row['secondary_call'])) {
                $calls[strtoupper($row['secondary_call'])] = true;
            }
        }
    }

    return ['canonical' => $canonical, 'calls' => array_keys($calls)];
}

/** Per-mode reference geometry features. */
function mode_features(mysqli $db, string $mode, string $dataDir): array
{
    switch ($mode) {
        case 'wal':  return wal_features($db);
        case 'lhfa': return lhfa_features($db);
        case 'lyff': return lyff_features($dataDir . '/lyff.min.geojson');
    }
    return [];
}

/** Join key (c1|c2) for a geometry feature, matching the qso aggregate keys. */
function feature_key(string $mode, array $feature): ?string
{
    $p = $feature['properties'] ?? [];
    switch ($mode) {
        case 'wal':
            $k = parse_object_code('wal', (string) ($p['code'] ?? ''));
            return $k ? object_key($k[0], $k[1]) : null;
        case 'lhfa':
            return isset($p['state'], $p['nr']) ? object_key((string) $p['state'], (int) $p['nr']) : null;
        case 'lyff':
            return isset($p['nr']) ? object_key('LYFF', (int) $p['nr']) : null;
    }
    return null;
}

function search_empty_groups(): array
{
    return ['callsigns' => [], 'wal' => [], 'lhfa' => [], 'lyff' => []];
}

function search_rank(string $query, string $code, string $name): ?int
{
    $q = strtoupper($query);
    $c = strtoupper($code);
    $n = strtoupper($name);

    if ($c === $q) {
        return 0;
    }
    if (strpos($c, $q) === 0) {
        return 1;
    }
    if (strpos($c, $q) !== false) {
        return 2;
    }
    if (strpos($n, $q) === 0) {
        return 3;
    }
    if (strpos($n, $q) !== false) {
        return 4;
    }
    return null;
}

function search_ranked_objects(array $rows, string $mode, string $query, int $limit): array
{
    $ranked = [];
    foreach ($rows as $row) {
        $code = format_code($mode, (string) $row['k1'], (int) $row['k2']);
        $name = (string) $row['name'];
        $rank = search_rank($query, $code, $name);
        if ($rank === null) {
            continue;
        }
        $ranked[] = [
            'rank' => $rank,
            'sort' => $code,
            'item' => [
                'type' => 'object',
                'mode' => $mode,
                'code' => $code,
                'label' => $code,
                'subtitle' => $name,
            ],
        ];
    }

    usort($ranked, static function (array $a, array $b): int {
        if ($a['rank'] !== $b['rank']) {
            return $a['rank'] <=> $b['rank'];
        }
        return strcmp($a['sort'], $b['sort']);
    });

    return array_map(static fn(array $r): array => $r['item'], array_slice($ranked, 0, $limit));
}

function api_search(mysqli $db, string $query, int $limit): array
{
    $groups = search_empty_groups();
    $q = strtoupper(trim($query));

    // Callsigns: grouped by normalized activator, prefix matches first, then contains.
    $like = '%' . $q . '%';
    $prefix = $q . '%';
    $sql = "SELECT caller_simple AS callsign,
                   COUNT(DISTINCT CASE WHEN wal1 <> '' THEN CONCAT('wal:', wal1, '-', wal2) END)
                 + COUNT(DISTINCT CASE WHEN lhfa1 <> '' THEN CONCAT('lhfa:', lhfa1, '-', lhfa2) END)
                 + COUNT(DISTINCT CASE WHEN lyff1 <> '' THEN CONCAT('lyff:', lyff1, '-', lyff2) END) AS objects,
                   MAX(datetime) AS last_at
            FROM qso
            WHERE caller_simple <> '' AND caller_simple LIKE ?
            GROUP BY caller_simple
            ORDER BY CASE
                       WHEN caller_simple = ? THEN 0
                       WHEN caller_simple LIKE ? THEN 1
                       ELSE 2
                     END,
                     last_at DESC,
                     caller_simple ASC
            LIMIT ?";
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, 'sssi', $like, $q, $prefix, $limit);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $groups['callsigns'][] = [
                'type' => 'callsign',
                'label' => (string) $row['callsign'],
                'value' => (string) $row['callsign'],
                'subtitle' => (int) $row['objects'] . ' objects activated',
            ];
        }
    }

    $rows = [];
    if ($res = mysqli_query($db, 'SELECT `row` AS k1, `column` AS k2, `name` FROM `wal`')) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    $groups['wal'] = search_ranked_objects($rows, 'wal', $q, $limit);

    $rows = [];
    if ($res = mysqli_query($db, 'SELECT `state` AS k1, `nr` AS k2, `name` FROM `lhfa`')) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    $groups['lhfa'] = search_ranked_objects($rows, 'lhfa', $q, $limit);

    $rows = [];
    if ($res = mysqli_query($db, "SELECT 'LYFF' AS k1, `nr` AS k2, `name` FROM `lyff`")) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    $groups['lyff'] = search_ranked_objects($rows, 'lyff', $q, $limit);

    return ['query' => $query, 'limit' => $limit, 'groups' => $groups];
}

// --- 3.1  /api/{mode}/objects --------------------------------------------

function api_objects(mysqli $db, array $prog, string $mode, string $dataDir): array
{
    $c1 = $prog['c1'];
    $c2 = $prog['c2'];

    // Live aggregates per object (columns whitelisted; no user input).
    $agg = [];
    $sql = "SELECT `$c1` AS k1, `$c2` AS k2,
                   COUNT(*) AS qsos,
                   COUNT(DISTINCT caller_simple) AS activators,
                   MAX(datetime) AS last_at
            FROM qso WHERE `$c1` <> '' GROUP BY `$c1`, `$c2`";
    if ($res = mysqli_query($db, $sql)) {
        while ($row = mysqli_fetch_assoc($res)) {
            $agg[object_key((string) $row['k1'], (int) $row['k2'])] = $row;
        }
    }

    $threshold = recent_threshold(30);
    $features  = mode_features($db, $mode, $dataDir);

    foreach ($features as &$f) {
        $key = feature_key($mode, $f);
        $a   = ($key !== null && isset($agg[$key])) ? $agg[$key] : null;

        $f['properties']['qsos']       = $a ? (int) $a['qsos'] : 0;
        $f['properties']['activators'] = $a ? (int) $a['activators'] : 0;
        $f['properties']['last_at']    = $a ? $a['last_at'] : null;
        // Stable unix timestamp of the last activation — the client derives the
        // RAG recency colour from it (green ≤1y … red ≥30y). Absent = never activated.
        if ($a && !empty($a['last_at'])) {
            $f['properties']['last_ts'] = strtotime($a['last_at']);
        }
        $f['properties']['status']     = $a
            ? (($a['last_at'] >= $threshold) ? 'recent' : 'activated')
            : 'never';
    }
    unset($f);

    return feature_collection($features);
}

// --- 3.2  /api/{mode}/recent (last N unique activator callsigns) ----------

function api_recent(mysqli $db, array $prog, string $mode, array $q): array
{
    $c1    = $prog['c1'];
    $c2    = $prog['c2'];
    $limit = isset($q['limit']) ? max(1, min(50, (int) $q['limit'])) : 10;

    // All (caller, object, day) activations of the N most-recently-active callsigns,
    // newest first; we then keep each callsign's most recent one (PHP dedupe). This
    // keeps the query ONLY_FULL_GROUP_BY-safe and deterministic.
    $sql = "SELECT caller_simple AS activator, `$c1` AS k1, `$c2` AS k2,
                   DATE(datetime) AS day, COUNT(*) AS qsos,
                   GROUP_CONCAT(DISTINCT band ORDER BY band) AS bands,
                   GROUP_CONCAT(DISTINCT mode ORDER BY mode) AS modes,
                   MAX(datetime) AS last_qso
            FROM qso
            WHERE `$c1` <> '' AND caller_simple IN (
                SELECT caller_simple FROM (
                    SELECT caller_simple, MAX(datetime) AS mx
                    FROM qso WHERE `$c1` <> ''
                    GROUP BY caller_simple
                    ORDER BY mx DESC
                    LIMIT ?
                ) z )
            GROUP BY caller_simple, `$c1`, `$c2`, DATE(datetime)
            ORDER BY last_qso DESC";

    $rows = [];
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, 'i', $limit);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $rows[] = $row;
        }
    }

    $seen = [];
    $out  = [];
    foreach ($rows as $r) {
        if (isset($seen[$r['activator']])) {
            continue;                       // keep only each callsign's most recent activation
        }
        $seen[$r['activator']] = true;
        $out[] = [
            'code'      => format_code($mode, (string) $r['k1'], (int) $r['k2']),
            'activator' => $r['activator'],
            'date'      => $r['day'],
            'qsos'      => (int) $r['qsos'],
            'bands'     => split_list($r['bands']),
            'modes'     => split_list($r['modes']),
            'last_qso'  => $r['last_qso'],
        ];
    }

    return [
        'mode'        => $mode,
        'limit'       => $limit,
        'count'       => count($out),
        'activations' => $out,
    ];
}

// --- 3.3  /api/{mode}/stats ----------------------------------------------

function api_stats(mysqli $db, array $prog, string $mode): array
{
    $c1 = $prog['c1'];
    $c2 = $prog['c2'];

    $row = mysqli_fetch_assoc(mysqli_query(
        $db,
        "SELECT COUNT(DISTINCT caller_simple) AS activators,
                COUNT(DISTINCT CONCAT(`$c1`,'-',`$c2`)) AS objects_activated,
                COUNT(*) AS qsos,
                COUNT(DISTINCT CASE WHEN call_simple LIKE 'LY%' THEN call_simple END) AS ly_hunters,
                COUNT(DISTINCT CASE WHEN call_simple NOT LIKE 'LY%' THEN call_simple END) AS dx_hunters
         FROM qso WHERE `$c1` <> ''"
    )) ?: [];

    $total = $prog['total'];
    if ($total === null) {
        // LHFA/LYFF: denominator = COUNT(*) of the reference table (named like the mode).
        $t = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS c FROM `$mode`"));
        $total = $t ? (int) $t['c'] : 0;
    }

    $byMode = mysqli_fetch_assoc(mysqli_query(
        $db,
        "SELECT SUM(mode IN ('SSB','LSB','USB','FM','AM')) AS phone,
                SUM(mode = 'CW') AS cw,
                SUM(mode IN ('DIGI','PSK31','PSK63','RTTY','SSTV','MFSK','FT8')) AS digi
         FROM qso WHERE `$c1` <> ''"
    )) ?: [];

    // QSO counts per band, emitted in canonical band order (HF → VHF → UHF → SHF).
    $bandCount = [];
    if ($res = mysqli_query($db, "SELECT band, COUNT(*) AS n FROM qso WHERE `$c1` <> '' AND band <> '' GROUP BY band")) {
        while ($r = mysqli_fetch_assoc($res)) {
            $bandCount[$r['band']] = (int) $r['n'];
        }
    }
    $byBand = [];
    foreach (['160m','80m','60m','40m','30m','20m','17m','15m','12m','10m','6m','4m','2m','70cm','23cm','13cm','9cm','6cm','3cm'] as $b) {
        if (isset($bandCount[$b])) {
            $byBand[] = ['band' => $b, 'qsos' => $bandCount[$b]];
            unset($bandCount[$b]);
        }
    }
    foreach ($bandCount as $b => $n) {           // any non-canonical bands, appended as-is
        $byBand[] = ['band' => $b, 'qsos' => $n];
    }

    $objActivated = (int) ($row['objects_activated'] ?? 0);

    return [
        'mode'              => $mode,
        'activators'        => (int) ($row['activators'] ?? 0),
        'objects_activated' => $objActivated,
        'objects_total'     => (int) $total,
        'coverage'          => $total > 0 ? round($objActivated / $total, 4) : null,
        'qsos'              => (int) ($row['qsos'] ?? 0),
        'hunters'           => [
            'ly' => (int) ($row['ly_hunters'] ?? 0),
            'dx' => (int) ($row['dx_hunters'] ?? 0),
        ],
        'by_mode'           => [
            'phone' => (int) ($byMode['phone'] ?? 0),
            'cw'    => (int) ($byMode['cw'] ?? 0),
            'digi'  => (int) ($byMode['digi'] ?? 0),
        ],
        'by_band'           => $byBand,
        'last_update'       => data_version($db),
    ];
}

// --- 3.4  /api/{mode}/object/{code} --------------------------------------

function api_object(mysqli $db, array $prog, string $mode, string $c1val, int $c2val): array
{
    $c1 = $prog['c1'];
    $c2 = $prog['c2'];

    // Per activator/day activation rows.
    $acts = [];
    $sql = "SELECT caller_simple AS activator, DATE(datetime) AS day, COUNT(*) AS qsos,
                   GROUP_CONCAT(DISTINCT band ORDER BY band) AS bands,
                   GROUP_CONCAT(DISTINCT mode ORDER BY mode) AS modes,
                   MAX(datetime) AS last_qso
            FROM qso WHERE `$c1` = ? AND `$c2` = ?
            GROUP BY caller_simple, DATE(datetime)
            ORDER BY last_qso DESC";
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, 'si', $c1val, $c2val);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $acts[] = [
                'activator' => $row['activator'],
                'date'      => $row['day'],
                'qsos'      => (int) $row['qsos'],
                'bands'     => split_list($row['bands']),
                'modes'     => split_list($row['modes']),
                'last_qso'  => $row['last_qso'],
            ];
        }
    }

    // Summary.
    $sum = [];
    $sql = "SELECT COUNT(DISTINCT caller_simple) AS activators, COUNT(*) AS qsos,
                   MIN(datetime) AS first_at, MAX(datetime) AS last_at
            FROM qso WHERE `$c1` = ? AND `$c2` = ?";
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, 'si', $c1val, $c2val);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $sum = ($res ? mysqli_fetch_assoc($res) : []) ?: [];
    }

    return [
        'mode'        => $mode,
        'code'        => format_code($mode, $c1val, $c2val),
        'activators'  => (int) ($sum['activators'] ?? 0),
        'qsos'        => (int) ($sum['qsos'] ?? 0),
        'first'       => $sum['first_at'] ?? null,
        'last'        => $sum['last_at'] ?? null,
        'activations' => $acts,
    ];
}

// --- 3.5  /api/{mode}/activator/{call} -----------------------------------

function api_activator(mysqli $db, array $prog, string $mode, string $input): array
{
    $c1 = $prog['c1'];
    $c2 = $prog['c2'];

    $resolved = resolve_calls($db, $input);
    $calls    = $resolved['calls'];
    if (!$calls) {
        return ['mode' => $mode, 'callsign' => normalize_call($input), 'count' => 0, 'objects' => []];
    }

    $placeholders = implode(',', array_fill(0, count($calls), '?'));
    $types        = str_repeat('s', count($calls));
    $sql = "SELECT `$c1` AS k1, `$c2` AS k2, COUNT(*) AS qsos,
                   MIN(datetime) AS first_at, MAX(datetime) AS last_at,
                   GROUP_CONCAT(DISTINCT band ORDER BY band) AS bands,
                   GROUP_CONCAT(DISTINCT mode ORDER BY mode) AS modes
            FROM qso
            WHERE `$c1` <> '' AND caller_simple IN ($placeholders)
            GROUP BY `$c1`, `$c2`
            ORDER BY last_at DESC";

    $objects = [];
    if ($stmt = mysqli_prepare($db, $sql)) {
        $refs = [];
        foreach ($calls as $k => $v) {
            $refs[$k] = &$calls[$k];               // bind_param needs references
        }
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $objects[] = [
                'code'  => format_code($mode, (string) $row['k1'], (int) $row['k2']),
                'qsos'  => (int) $row['qsos'],
                'first' => $row['first_at'],
                'last'  => $row['last_at'],
                'bands' => split_list($row['bands']),
                'modes' => split_list($row['modes']),
            ];
        }
    }

    return [
        'mode'     => $mode,
        'callsign' => $resolved['canonical'],
        'count'    => count($objects),
        'objects'  => $objects,
    ];
}

// --- 3.6  /api/meta/last-update ------------------------------------------

function api_meta_last_update(mysqli $db): array
{
    return ['last_update' => data_version($db)];
}

// --- 3.7  /api/nearest?lat=&lng=  (nearest LHFA + LYFF object to a point) -

/** Great-circle distance in km. */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Bounding-box centre [lng, lat] of any GeoJSON geometry, or null. */
function geometry_centroid(array $geom): ?array
{
    if (!isset($geom['coordinates'])) {
        return null;
    }
    $minX = INF; $minY = INF; $maxX = -INF; $maxY = -INF;
    $stack = [$geom['coordinates']];
    while ($stack) {
        $c = array_pop($stack);
        if (isset($c[0], $c[1]) && is_numeric($c[0]) && is_numeric($c[1])) {
            $minX = min($minX, $c[0]); $maxX = max($maxX, $c[0]);
            $minY = min($minY, $c[1]); $maxY = max($maxY, $c[1]);
        } elseif (is_array($c)) {
            foreach ($c as $cc) {
                $stack[] = $cc;
            }
        }
    }
    return $minX === INF ? null : [($minX + $maxX) / 2, ($minY + $maxY) / 2];
}

function api_nearest(mysqli $db, string $dataDir, float $lat, float $lng): array
{
    $out = ['lat' => $lat, 'lng' => $lng, 'lhfa' => null, 'lyff' => null];

    // Nearest LHFA — planar approximation (longitude scaled by cos lat) for ordering.
    $sql = "SELECT state, nr, name, coordsN, coordsE,
                   (POW(coordsN - ?, 2) + POW((coordsE - ?) * COS(RADIANS(?)), 2)) AS d2
            FROM lhfa ORDER BY d2 ASC LIMIT 1";
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, 'ddd', $lat, $lng, $lat);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && ($r = mysqli_fetch_assoc($res))) {
            $out['lhfa'] = [
                'code' => lhfa_code((string) $r['state'], (int) $r['nr']),
                'name' => $r['name'],
                'km'   => round(haversine_km($lat, $lng, (float) $r['coordsN'], (float) $r['coordsE']), 1),
            ];
        }
    }

    // Nearest LYFF — from the fetched geojson (bbox centroids).
    $fc = json_decode((string) @file_get_contents($dataDir . '/lyff.geojson'), true);
    $features = is_array($fc) && isset($fc['features']) && is_array($fc['features']) ? $fc['features'] : [];
    $bestD = INF; $best = null;
    foreach ($features as $f) {
        $c = isset($f['geometry']) && is_array($f['geometry']) ? geometry_centroid($f['geometry']) : null;
        if ($c === null) {
            continue;
        }
        $dx = ($c[0] - $lng) * cos(deg2rad($lat));
        $dy = $c[1] - $lat;
        $d  = $dx * $dx + $dy * $dy;
        if ($d < $bestD) {
            $bestD = $d;
            $best  = ['p' => $f['properties'] ?? [], 'c' => $c];
        }
    }
    if ($best !== null) {
        $out['lyff'] = [
            'code' => $best['p']['code'] ?? null,
            'name' => $best['p']['name'] ?? null,
            'km'   => round(haversine_km($lat, $lng, $best['c'][1], $best['c'][0]), 1),
        ];
    }

    return $out;
}
