<?php
// tools/fetch_lyff_polygons.php (PHP 7.4, CLI only) — OSM polygon fetch for LYFF.
//
// Queries OpenStreetMap Nominatim by LYFF name and writes a static GeoJSON
// FeatureCollection (new/data/lyff.geojson) keyed by LYFF-<nr>, plus a misses log
// (new/data/lyff_misses.txt). Run once, offline — the app never calls OSM at runtime.
//
// Honours the Nominatim usage policy: <=1 request/second, descriptive User-Agent,
// results cached to disk. Output carries ODbL attribution. Resumable.
//
//   Usage:
//     php tools/fetch_lyff_polygons.php [--limit=N] [--force]   # initial pass (full names)
//     php tools/fetch_lyff_polygons.php --retry-misses          # retry ONLY misses, simplified
//
//     --limit=N        fetch at most N new objects (smoke testing)
//     --force          ignore existing output and refetch everything
//     --retry-misses   re-query the objects currently in lyff_misses.txt using a
//                      simplified name (drop "(...)" notes and descriptor words such
//                      as kraštovaizdžio / hidrografinis / telmologinis / pedologinis
//                      / pralaužos / senslėnio …), keeping found hits.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

const NOMINATIM   = 'https://nominatim.openstreetmap.org/search';
const USER_AGENT  = 'qso-lyff-fetch/1.0 (+https://qrz.lt; one-off map data tooling)';
const RATE_SLEEP  = 1; // seconds between requests (Nominatim policy: max 1/s)
const ATTRIBUTION = 'Data © OpenStreetMap contributors, ODbL 1.0 (https://osm.org/copyright)';

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
$srcFile  = $root . '/lyff.json';
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
    'timeout'       => 30,
    'ignore_errors' => true,
]]);

/** LYFF-<nr> code from a raw nr. */
function lyff_code_of(int $nr): string
{
    return 'LYFF-' . str_pad((string) $nr, 4, '0', STR_PAD_LEFT);
}

/** Query Nominatim for a polygon; returns the hit (with geojson) or null. */
function nominatim_polygon(string $query, $ctx): ?array
{
    $url = NOMINATIM . '?' . http_build_query([
        'format'          => 'jsonv2',
        'polygon_geojson' => 1,
        'limit'           => 1,
        'countrycodes'    => 'lt',
        'q'               => $query,
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        return null;
    }
    $data = json_decode($res, true);
    $hit  = (is_array($data) && isset($data[0])) ? $data[0] : null;
    return ($hit !== null && isset($hit['geojson'])) ? $hit : null;
}

/** Build a GeoJSON Feature from a Nominatim hit. */
function build_feature(string $code, int $nr, string $name, array $hit, array $extra = []): array
{
    return [
        'type'       => 'Feature',
        'properties' => array_merge([
            'code'     => $code,
            'name'     => $name,
            'nr'       => $nr,
            'osm_type' => $hit['osm_type'] ?? null,
            'osm_id'   => $hit['osm_id'] ?? null,
        ], $extra),
        'geometry'   => $hit['geojson'],
    ];
}

/**
 * Simplify a missed name: drop "(...)" notes and descriptor words (NOISE_WORDS),
 * keeping the place name + type noun. e.g.
 *   "Rūdninkų krastovaizdžio draustinis"          -> "Rūdninkų draustinis"
 *   "Minijos senslėnio kraštovaizdžio draustinis" -> "Minijos draustinis"
 */
function simplify_name(string $name): string
{
    $noise = array_flip(NOISE_WORDS);
    $s     = preg_replace('/\([^)]*\)/u', ' ', $name);       // strip parentheticals
    $tokens = preg_split('/\s+/u', trim((string) $s)) ?: [];

    $keep = [];
    foreach ($tokens as $t) {
        if ($t === '') {
            continue;
        }
        if (isset($noise[strtolower($t)])) {                 // descriptor word -> drop
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
//  RETRY-MISSES PASS — re-query only current misses with simplified names
// =========================================================================
if ($retryMisses) {
    // existing hits, keyed by code
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

    // name lookup by code
    $nameOf = [];
    foreach ($rows as $o) {
        $nameOf[lyff_code_of((int) $o['nr'])] = (string) $o['name'];
    }

    // miss set (still-missing codes)
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

    fwrite(STDERR, sprintf("Retrying %d misses with simplified names.\n", count($missSet)));
    $found = 0;
    $tried = 0;
    foreach (array_keys($missSet) as $code) {
        $orig = $nameOf[$code] ?? null;
        if ($orig === null) {
            continue; // unknown code, leave in misses
        }
        $simpl = simplify_name($orig);
        // nothing gained if the simplification is empty or unchanged
        if ($simpl === '' || strcasecmp($simpl, $orig) === 0) {
            fwrite(STDOUT, sprintf("skip   %s  (no simpler form)  %s\n", $code, $orig));
            continue;
        }

        $tried++;
        $hit = nominatim_polygon($simpl, $ctx);
        if ($hit !== null) {
            $nr = (int) substr($code, strlen('LYFF-'));
            $features[$code] = build_feature($code, $nr, $orig, $hit, [
                'matched_via' => 'simplified',
                'query'       => $simpl,
            ]);
            unset($missSet[$code]);
            $found++;
            fwrite(STDOUT, sprintf("found  %s  %-14s '%s'\n", $code, $hit['geojson']['type'] ?? '?', $simpl));
        } else {
            fwrite(STDOUT, sprintf("miss   %s  '%s'\n", $code, $simpl));
        }

        flush_state($outFile, $missFile, $features, array_keys($missSet));
        sleep(RATE_SLEEP);
    }

    flush_state($outFile, $missFile, $features, array_keys($missSet));
    fwrite(STDERR, sprintf(
        "Done (retry). %d simplified queries, %d newly found, %d still missing.\n",
        $tried, $found, count($missSet)
    ));
    exit(0);
}

// =========================================================================
//  INITIAL PASS — query each object by its full name
// =========================================================================

// resume: load already-attempted features (unless --force)
$features = [];   // code => Feature
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

    $hit = nominatim_polygon($name, $ctx);
    if ($hit !== null) {
        $features[$code] = build_feature($code, $nr, $name, $hit);
        $hits++;
        fwrite(STDOUT, sprintf("ok    %s  %-14s %s\n", $code, $hit['geojson']['type'] ?? '?', $name));
    } else {
        $features[$code] = null; // record the gap (so resume doesn't retry it)
        $missed++;
        fwrite(STDOUT, sprintf("miss  %s  %s\n", $code, $name));
    }

    $missCodes = array_keys(array_filter($features, static function ($f) {
        return $f === null;
    }));
    flush_state($outFile, $missFile, $features, $missCodes);
    sleep(RATE_SLEEP);
}

$missCodes = array_keys(array_filter($features, static function ($f) {
    return $f === null;
}));
flush_state($outFile, $missFile, $features, $missCodes);
fwrite(STDERR, sprintf(
    "Done. %d processed this run (%d hits, %d misses). Output: %s\n",
    $fetched, $hits, $missed, $outFile
));
