<?php
// src/geometry.php (PHP 7.4) — reference geometry providers.
//   Phase 2.1: LHFA point markers (from `lhfa`).
//   Phase 2.2: WAL grid polygons (`wal_coords` primary, computed grid fallback).
// These build the shapes + names only; live activation status is joined in Phase 3.
// All coordinates are GeoJSON order: [longitude, latitude].

declare(strict_types=1);

// --- WAL fixed grid (qso.md §6): NW origin, uniform 1/6° cells ---
const WAL_STEP    = 0.16666666667;             // 1/6°, cell size in both axes
const WAL_LAT0    = 56.5;                       // north origin (latitude)
const WAL_LNG0    = 20.83333333333;             // west origin (longitude)
const WAL_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

/**
 * Compute a WAL square's polygon from its row letter + column number.
 * Fallback for squares absent from `wal_coords`.
 *
 * @return array<int, array<int, array<int, float>>>  GeoJSON Polygon coordinates
 *                                                     (one closed [lng,lat] ring)
 */
function wal_square_polygon(string $row, int $col): array
{
    $ri = strpos(WAL_LETTERS, $row);            // 0-based latitude band (A = top)
    if ($ri === false) {
        $ri = 0;
    }
    $latTop = WAL_LAT0 - $ri * WAL_STEP;
    $latBot = $latTop - WAL_STEP;
    $lngL   = WAL_LNG0 + $col * WAL_STEP;
    $lngR   = $lngL + WAL_STEP;

    return [[
        [$lngL, $latTop],
        [$lngR, $latTop],
        [$lngR, $latBot],
        [$lngL, $latBot],
        [$lngL, $latTop],
    ]];
}

/** Format a WAL square code, e.g. ('M', 9) -> "M09", ('M', 19) -> "M19". */
function wal_code(string $row, int $col): string
{
    return $row . str_pad((string) $col, 2, '0', STR_PAD_LEFT);
}

/**
 * Build a GeoJSON Polygon from a `wal_coords` row's four WGS84 corners
 * (x = longitude, y = latitude). The corners are stored in raster-scan order
 * (NW, NE, SW, SE), which is NOT a valid polygon ring — using them as-is yields a
 * self-intersecting bowtie that does not render. WAL cells are axis-aligned, so we
 * build a proper rectangle from the bounding box of the corners (robust to any
 * corner ordering). Returns null if a corner is missing or the cell is degenerate.
 *
 * @param array<string, mixed> $r
 * @return array<int, array<int, array<int, float>>>|null
 */
function wal_coords_polygon(array $r): ?array
{
    $lngs = [];
    $lats = [];
    foreach ([['x1w', 'y1w'], ['x2w', 'y2w'], ['x3w', 'y3w'], ['x4w', 'y4w']] as $p) {
        if (!isset($r[$p[0]], $r[$p[1]]) || $r[$p[0]] === '' || $r[$p[1]] === '' || $r[$p[0]] === null || $r[$p[1]] === null) {
            return null;
        }
        $lngs[] = (float) $r[$p[0]];
        $lats[] = (float) $r[$p[1]];
    }

    $minLng = min($lngs);
    $maxLng = max($lngs);
    $minLat = min($lats);
    $maxLat = max($lats);
    if ($minLng === $maxLng || $minLat === $maxLat) {
        return null; // degenerate
    }

    return [[
        [$minLng, $maxLat],   // NW
        [$maxLng, $maxLat],   // NE
        [$maxLng, $minLat],   // SE
        [$minLng, $minLat],   // SW
        [$minLng, $maxLat],   // close
    ]];
}

/**
 * WAL reference geometry: one GeoJSON Polygon Feature per square.
 *
 * The square list is the UNION of `wal_coords` (which carries the corner geometry)
 * and `wal` (which carries names) — so the grid renders whichever table is the
 * populated one. Geometry comes from `wal_coords` corners when present, otherwise
 * it is computed from the fixed grid (row letter + column).
 *
 * @return array<int, array<string, mixed>>  GeoJSON Feature list
 */
function wal_features(mysqli $db): array
{
    // cells keyed "row|col" => ['row'=>, 'col'=>, 'name'=>?, 'coords'=>?]
    $cells = [];

    $res = mysqli_query(
        $db,
        'SELECT `row`, `column`, x1w, y1w, x2w, y2w, x3w, y3w, x4w, y4w FROM `wal_coords`'
    );
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $row = trim((string) $r['row']);
            $col = (int) $r['column'];
            $cells[$row . '|' . $col] = ['row' => $row, 'col' => $col, 'name' => null, 'coords' => $r];
        }
    }

    $res = mysqli_query($db, 'SELECT `row`, `column`, `name` FROM `wal`');
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $row = trim((string) $r['row']);
            $col = (int) $r['column'];
            $key = $row . '|' . $col;
            if (!isset($cells[$key])) {
                $cells[$key] = ['row' => $row, 'col' => $col, 'name' => null, 'coords' => null];
            }
            $cells[$key]['name'] = (string) $r['name'];
        }
    }

    $features = [];
    foreach ($cells as $c) {
        $poly = $c['coords'] !== null ? wal_coords_polygon($c['coords']) : null;
        if ($poly === null) {
            $poly = wal_square_polygon($c['row'], $c['col']);     // computed fallback
        }
        $code = wal_code($c['row'], $c['col']);
        $features[] = [
            'type'       => 'Feature',
            'properties' => ['code' => $code, 'name' => $c['name'] !== null ? $c['name'] : $code],
            'geometry'   => ['type' => 'Polygon', 'coordinates' => $poly],
        ];
    }

    return $features;
}

/** Format an LHFA object code, e.g. ('AL', 1) -> "AL-01", ('KA', 105) -> "KA-105". */
function lhfa_code(string $state, int $nr): string
{
    return $state . '-' . str_pad((string) $nr, 2, '0', STR_PAD_LEFT);
}

/**
 * LHFA reference geometry: one GeoJSON Point Feature per hillfort
 * ([lng, lat] = [coordsE, coordsN]).
 *
 * @return array<int, array<string, mixed>>  GeoJSON Feature list
 */
function lhfa_features(mysqli $db): array
{
    $features = [];
    $res = mysqli_query(
        $db,
        'SELECT `state`, `nr`, `name`, `coordsN`, `coordsE` FROM `lhfa` ORDER BY `state`, `nr`'
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $features[] = [
                'type'       => 'Feature',
                'properties' => [
                    'code'  => lhfa_code((string) $row['state'], (int) $row['nr']),
                    'name'  => (string) $row['name'],
                    'state' => (string) $row['state'],
                    'nr'    => (int) $row['nr'],
                ],
                'geometry'   => [
                    'type'        => 'Point',
                    'coordinates' => [(float) $row['coordsE'], (float) $row['coordsN']],
                ],
            ];
        }
    }

    return $features;
}

/** Format an LYFF object code, e.g. 1 -> "LYFF-0001". */
function lyff_code(int $nr): string
{
    return 'LYFF-' . str_pad((string) $nr, 4, '0', STR_PAD_LEFT);
}

/**
 * LYFF reference geometry: the polygons fetched from OpenStreetMap
 * (new/data/lyff.geojson, produced by tools/fetch_lyff_polygons.php).
 * Objects with no OSM match are simply absent (list-only — see new.md §5.3).
 *
 * @return array<int, array<string, mixed>>  GeoJSON Feature list (possibly empty)
 */
function lyff_features(string $geojsonFile): array
{
    if (!is_readable($geojsonFile)) {
        return [];
    }
    $fc = json_decode((string) file_get_contents($geojsonFile), true);
    return is_array($fc['features'] ?? null) ? $fc['features'] : [];
}

/**
 * Parse a display object code into its two qso key parts (c1, c2).
 *   wal:  "M19"      -> ["M", 19]
 *   lhfa: "AL-01"    -> ["AL", 1]
 *   lyff: "LYFF-0001"-> ["LYFF", 1]
 *
 * @return array{0:string,1:int}|null  null if the code is malformed
 */
function parse_object_code(string $mode, string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }

    if ($mode === 'wal') {
        if (!preg_match('/^([A-Z])(\d{1,3})$/', $code, $m)) {
            return null;
        }
        return [$m[1], (int) $m[2]];
    }

    // lhfa / lyff: "<prefix>-<number>"
    $parts = explode('-', $code, 2);
    if (count($parts) !== 2 || $parts[0] === '' || !ctype_digit($parts[1])) {
        return null;
    }
    return [$parts[0], (int) $parts[1]];
}

/** Build the join key used to match a (c1,c2) pair to live aggregates. */
function object_key(string $c1, int $c2): string
{
    return $c1 . '|' . $c2;
}

/**
 * Wrap features in a GeoJSON FeatureCollection.
 *
 * @param array<int, array<string, mixed>> $features
 * @return array<string, mixed>
 */
function feature_collection(array $features): array
{
    return ['type' => 'FeatureCollection', 'features' => array_values($features)];
}
