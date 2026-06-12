<?php
// src/cache.php (PHP 7.4) — native file cache keyed on MAX(datetimenow).

declare(strict_types=1);

/** Current data version = timestamp of the most recently touched qso row. */
function data_version(mysqli $db): string
{
    $r   = mysqli_query($db, 'SELECT MAX(datetimenow) AS v FROM qso');
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return (string) ($row['v'] ?? '0');
}

/**
 * Return cached JSON for ($key, $version) if present, else build it, store, return.
 * Cache invalidates automatically when $version (the data version) changes.
 */
function cached(string $dir, string $key, string $version, callable $build): string
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $file = $dir . '/' . md5($key) . '_' . md5($version) . '.json';
    if (is_readable($file)) {
        $hit = file_get_contents($file);
        if ($hit !== false) {
            return $hit;
        }
    }

    $json = $build();
    @file_put_contents($file, $json, LOCK_EX);
    return $json;
}
