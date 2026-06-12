<?php
// src/config.php (PHP 7.4) — load configuration from .env.ini (native parse_ini_file).

declare(strict_types=1);

/**
 * Reads the .env.ini file.
 *
 * Required section [database] with keys: hostname, username, password, database.
 * Optional section [app] with key: cache_dir (default <app-root>/cache).
 * (No base_path: the app mount point is auto-derived from the request, see router.php.)
 *
 * @return array{db: array<string,string>, cache_dir: string}
 */
function load_config(string $file): array
{
    $ini = @parse_ini_file($file, true, INI_SCANNER_TYPED);
    if ($ini === false || !isset($ini['database']) || !is_array($ini['database'])) {
        fail(500, 'Configuration error');
    }

    $db = $ini['database'];
    foreach (['hostname', 'username', 'password', 'database'] as $k) {
        if (!isset($db[$k]) || $db[$k] === '') {
            fail(500, 'Configuration error');
        }
    }

    $app = (isset($ini['app']) && is_array($ini['app'])) ? $ini['app'] : [];

    return [
        'db'        => $db,
        'cache_dir' => (string) ($app['cache_dir'] ?? dirname(__DIR__) . '/cache'),
    ];
}
