<?php
// src/db.php (PHP 7.4) — single read-only mysqli connection.

declare(strict_types=1);

/**
 * Opens the read-only connection to the qso database.
 *
 * @param array{db: array<string,string>} $cfg
 */
function db_connect(array $cfg): mysqli
{
    $d = $cfg['db'];

    // Pin the error mode: stay identical regardless of host PHP defaults.
    mysqli_report(MYSQLI_REPORT_OFF);

    $db = @mysqli_connect($d['hostname'], $d['username'], $d['password'], $d['database']);
    if (!$db) {
        fail(503, 'Database unavailable');
    }

    // Decode text as UTF-8 (schema is utf8mb3_lithuanian_ci). Use the universal
    // client alias 'utf8' — some client libs don't recognise 'utf8mb3' and would
    // silently fall back (mangling LT names into invalid UTF-8 → json_encode fails).
    if (!mysqli_set_charset($db, 'utf8')) {
        mysqli_query($db, "SET NAMES 'utf8'");
    }

    return $db;
}
