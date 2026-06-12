<?php
// src/router.php (PHP 7.4) — derive the app mount point from the request, return segments.

declare(strict_types=1);

/**
 * Splits a request URI into path segments, relative to wherever the app is mounted.
 *
 * The mount prefix is auto-derived from the front controller's location
 * (`dirname($scriptName)`), so the app works unchanged under `/new`, `/`, or any
 * other folder — no configured base path.
 *
 * The leading segment (mode/namespace) is only ever used after whitelisting in
 * programmes.php — the raw input is never interpolated into SQL.
 *
 * @param string $scriptName  typically $_SERVER['SCRIPT_NAME'] (e.g. "/new/index.php")
 * @return string[]
 */
function route(string $uri, string $scriptName): array
{
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/'); // "" at the site root

    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }

    return array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
}
