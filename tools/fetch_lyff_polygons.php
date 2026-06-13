<?php
// tools/fetch_lyff_polygons.php (PHP 7.4, CLI only) — CDDA polygon fetch for LYFF.
//
// Queries the EEA Nationally Designated Areas (NatDA/CDDA) REST API for Lithuania
// and writes a static GeoJSON FeatureCollection (new/data/lyff.geojson) keyed by
// LYFF-<nr>, plus a misses log (new/data/lyff_misses.txt). Run once, offline —
// the app never calls CDDA at runtime.
//
// Data source: EEA CDDA MapServer layer 4 (large-scale polygons)
//   https://bio.discomap.eea.europa.eu/arcgis/rest/services/ProtectedSites/CDDA_Dyna_WM/MapServer/4
// No authentication required. Public ArcGIS REST service.
//
//   Usage:
//     php tools/fetch_lyff_polygons.php [--limit=N] [--force]   # initial pass (full names)
//     php tools/fetch_lyff_polygons.php --retry-misses          # retry ONLY misses
//
//     --limit=N        fetch at most N new objects (smoke testing)
//     --force          ignore existing output and refetch everything
//     --retry-misses   re-query the objects currently in lyff_misses.txt using
//                      a simplified name (drop descriptor words such as
//                      kraštovaizdžio / hidrografinis / telmologinis / pedologinis
//                      / pralaužos / senslėnio …) and, if still not found, a broad
//                      LIKE search. Keeps all previously found hits.

declare(strict_types=1);

// The full LT polygon dataset (480 features, large geometries) can exhaust the
// default 128 MB limit when json_encode-ing the FeatureCollection on each flush.
ini_set('memory_limit', '512M');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

// EEA CDDA MapServer — layer 4 is "large scale viewing" polygon layer for LT
const CDDA_LAYER  = 'https://bio.discomap.eea.europa.eu/arcgis/rest/services/ProtectedSites/CDDA_Dyna_WM/MapServer/4/query';
const USER_AGENT  = 'qso-lyff-fetch/2.0 (+https://qrz.lt; one-off map data tooling)';
const ATTRIBUTION = 'Data © European Environment Agency (EEA), Nationally Designated Areas (NatDA/CDDA). https://www.eea.europa.eu/data-and-maps/data/nationally-designated-areas-national-cdda-18';

// Descriptor / category words removed when simplifying a missed name. The proper
// place name and the type noun (draustinis / parkas / rezervatas) are kept.
// Edit this list to widen/narrow the simplification.
const NOISE_WORDS = [
    'kraštovaizdžio', 'krastovaizdžio',
    'hidrografinis', 'telmologinis', 'pedologinis',
    'geologinis', 'geomorfologinis',
    'ichtiologinis', 'ornitologinis', 'entomologinis', 'herpetologinis',
    'botaninis', 'zoologinis', 'botaninis-zoologinis', 'kompleksinis',
    'pralaužos', 'senslėnio',
];

$root     = dirname(__DIR__);            // new/
$srcFile  = $root . '/data/lyff.json';
$outDir   = $root . '/data';
$outFile  = $outDir . '/lyff.geojson';
$missFile = $outDir . '/lyff_misses.txt';

// --- args ---
$limit       = null;
$force       = false;
$retryMisses = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--retry-misses') {
        $retryMisses = true;
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(2);
    }
}

// --- load source rows from the phpMyAdmin export ---
$raw = json_decode((string) @file_get_contents($srcFile), true);
$rows = [];
foreach ((array) $raw as $block) {
    if (($block['type'] ?? '') === 'table' && isset($block['data'])) {
        $rows = $block['data'];
        break;
    }
}
if (!$rows) {
    fwrite(STDERR, "No LYFF rows found in $srcFile\n");
    exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}

$ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'header'        => 'User-Agent: ' . USER_AGENT . "\r\n",
    'timeout'       => 20,
    'ignore_errors' => true,
]]);

/** LYFF-<nr> code from a raw nr. */
function lyff_code_of(int $nr): string
{
    return 'LYFF-' . str_pad((string) $nr, 4, '0', STR_PAD_LEFT);
}

/**
 * Query EEA CDDA REST API (layer 4, large-scale polygons) for a protected area
 * in Lithuania. Returns the first matching GeoJSON feature array, or null.
 *
 * Strategy:
 *   $useLike = false  →  exact siteName match  (fast, unambiguous)
 *   $useLike = true   →  UPPER(siteName) LIKE UPPER('%<name>%')  (fuzzy fallback;
 *                         returns null if 0 or >1 results to avoid false positives)
 */
function cdda_polygon(string $name, $ctx, bool $useLike = false): ?array
{
    // Escape single quotes for the ArcGIS SQL WHERE clause
    $escaped = str_replace("'", "''", $name);

    $where = $useLike
        ? "cddaCountryCode='LT' AND UPPER(siteName) LIKE UPPER('%" . $escaped . "%')"
        : "cddaCountryCode='LT' AND siteName='" . $escaped . "'";

    $url = CDDA_LAYER . '?' . http_build_query([
        'where'             => $where,
        'outFields'         => 'siteName,cddaId,nationalId,legalFoundationDate',
        'f'                 => 'geojson',
        'returnGeometry'    => 'true',
        'outSR'             => '4326',
        'resultRecordCount' => $useLike ? 2 : 1,  // 2 when fuzzy so we can detect ambiguity
    ]);

    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        return null;
    }

    $data     = json_decode($res, true);
    $features = $data['features'] ?? [];

    // Reject ambiguous LIKE results (0 or more than 1 match)
    if ($useLike && count($features) !== 1) {
        return null;
    }

    $feat = $features[0] ?? null;
    return ($feat !== null && !empty($feat['geometry'])) ? $feat : null;
}

/**
 * Build a GeoJSON Feature from a CDDA GeoJSON feature element.
 */
function build_feature(string $code, int $nr, string $name, array $cddaFeat, array $extra = []): array
{
    return [
        'type'       => 'Feature',
        'properties' => array_merge([
            'code'        => $code,
            'name'        => $name,
            'nr'          => $nr,
            'cdda_id'     => $cddaFeat['properties']['cddaId'] ?? null,
            'national_id' => $cddaFeat['properties']['nationalId'] ?? null,
            'founded'     => $cddaFeat['properties']['legalFoundationDate'] ?? null,
            'source'      => 'cdda',
        ], $extra),
        'geometry'   => $cddaFeat['geometry'],
    ];
}

/**
 * Simplify a missed name: drop "(…)" notes and descriptor words (NOISE_WORDS),
 * keeping the place name + type noun. e.g.
 *   "Rūdninkų krastovaizdžio draustinis"          -> "Rūdninkų draustinis"
 *   "Minijos senslėnio kraštovaizdžio draustinis" -> "Minijos draustinis"
 */
function simplify_name(string $name): string
{
    $noise  = array_flip(NOISE_WORDS);
    $s      = preg_replace('/\([^)]*\)/u', ' ', $name);       // strip parentheticals
    $tokens = preg_split('/\s+/u', trim((string) $s)) ?: [];

    $keep = [];
    foreach ($tokens as $t) {
        if ($t === '') {
            continue;
        }
        if (isset($noise[strtolower($t)])) {                   // descriptor word -> drop
            continue;
        }
        $keep[] = $t;
    }
    return trim(implode(' ', $keep));
}

/** Persist the FeatureCollection + miss list. */
function flush_state(string $outFile, string $missFile, array $features, array $missCodes): void
{
    $real = array_values(array_filter($features, static function ($f) {
        return $f !== null;
    }));
    file_put_contents($outFile, json_encode([
        'type'        => 'FeatureCollection',
        'attribution' => ATTRIBUTION,
        'features'    => $real,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    sort($missCodes);
    file_put_contents($missFile, $missCodes ? implode("\n", $missCodes) . "\n" : '');
}

// =========================================================================
//  RETRY-MISSES PASS — re-query only current misses via CDDA
// =========================================================================
if ($retryMisses) {
    // Load existing hits keyed by code
    $features = [];
    if (is_file($outFile)) {
        $prev = json_decode((string) file_get_contents($outFile), true);
        foreach ($prev['features'] ?? [] as $f) {
            $code = $f['properties']['code'] ?? null;
            if ($code !== null) {
                $features[$code] = $f;
            }
        }
    }

    // Name lookup by code
    $nameOf = [];
    foreach ($rows as $o) {
        $nameOf[lyff_code_of((int) $o['nr'])] = (string) $o['name'];
    }

    // Miss set (still-missing codes)
    $missSet = [];
    if (is_file($missFile)) {
        foreach (preg_split('/\R/', (string) file_get_contents($missFile)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $missSet[$line] = true;
            }
        }
    }
    if (!$missSet) {
        fwrite(STDERR, "No misses to retry ($missFile is empty).\n");
        exit(0);
    }

    fwrite(STDERR, sprintf("Retrying %d misses via CDDA.\n", count($missSet)));
    $found = 0;
    $tried = 0;

    foreach (array_keys($missSet) as $code) {
        $orig = $nameOf[$code] ?? null;
        if ($orig === null) {
            continue; // unknown code, leave in misses
        }

        $tried++;
        $feat       = null;
        $matchedVia = null;

        // 1. Exact match with original full name
        $feat = cdda_polygon($orig, $ctx);
        if ($feat !== null) {
            $matchedVia = 'exact';
        }

        // 2. Exact match with simplified name
        if ($feat === null) {
            $simpl = simplify_name($orig);
            if ($simpl !== '' && strcasecmp($simpl, $orig) !== 0) {
                $feat = cdda_polygon($simpl, $ctx);
                if ($feat !== null) {
                    $matchedVia = 'simplified-exact';
                }
            }
        }

        // 3. LIKE (fuzzy) match with simplified name — only if unambiguous (exactly 1 result)
        if ($feat === null) {
            $simpl = simplify_name($orig);
            if ($simpl !== '' && strcasecmp($simpl, $orig) !== 0) {
                $feat = cdda_polygon($simpl, $ctx, true);
                if ($feat !== null) {
                    $matchedVia = 'simplified-like';
                }
            }
        }

        if ($feat !== null) {
            $nr = (int) substr($code, strlen('LYFF-'));
            $features[$code] = build_feature($code, $nr, $orig, $feat, [
                'matched_via' => $matchedVia,
            ]);
            unset($missSet[$code]);
            $found++;
            $cddaSiteName = $feat['properties']['siteName'] ?? '?';
            fwrite(STDOUT, sprintf("found  %s  [%s]  cdda='%s'\n", $code, $matchedVia, $cddaSiteName));
        } else {
            fwrite(STDOUT, sprintf("miss   %s  '%s'\n", $code, $orig));
        }

        flush_state($outFile, $missFile, $features, array_keys($missSet));
    }

    flush_state($outFile, $missFile, $features, array_keys($missSet));
    fwrite(STDERR, sprintf(
        "Done (retry). %d tried, %d newly found, %d still missing.\n",
        $tried, $found, count($missSet)
    ));
    exit(0);
}

// =========================================================================
//  INITIAL PASS — query each object by its full name
// =========================================================================

// Resume: load already-attempted features (unless --force)
$features = [];   // code => Feature|null
if (!$force && is_file($outFile)) {
    $prev = json_decode((string) file_get_contents($outFile), true);
    foreach ($prev['features'] ?? [] as $f) {
        $code = $f['properties']['code'] ?? null;
        if ($code !== null) {
            $features[$code] = $f;
        }
    }
    fwrite(STDERR, sprintf("Resuming: %d features already present.\n", count($features)));
}

$fetched = 0;
$hits    = 0;
$missed  = 0;
foreach ($rows as $o) {
    if ($limit !== null && $fetched >= $limit) {
        break;
    }
    $nr   = (int) $o['nr'];
    $code = lyff_code_of($nr);
    $name = (string) $o['name'];

    if (array_key_exists($code, $features)) {
        continue; // already attempted in a previous run
    }
    $fetched++;

    $feat = cdda_polygon($name, $ctx);
    if ($feat !== null) {
        $features[$code] = build_feature($code, $nr, $name, $feat);
        $hits++;
        $cddaSiteName = $feat['properties']['siteName'] ?? '?';
        fwrite(STDOUT, sprintf("ok    %s  cdda='%s'\n", $code, $cddaSiteName));
    } else {
        $features[$code] = null; // record the gap (so resume doesn't retry it)
        $missed++;
        fwrite(STDOUT, sprintf("miss  %s  %s\n", $code, $name));
    }

    $missCodes = array_keys(array_filter($features, static function ($f) {
        return $f === null;
    }));
    flush_state($outFile, $missFile, $features, $missCodes);
}

$missCodes = array_keys(array_filter($features, static function ($f) {
    return $f === null;
}));
flush_state($outFile, $missFile, $features, $missCodes);
fwrite(STDERR, sprintf(
    "Done. %d processed this run (%d hits, %d misses). Output: %s\n",
    $fetched, $hits, $missed, $outFile
));
