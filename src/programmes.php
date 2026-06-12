<?php
// src/programmes.php (PHP 7.4) — fixed programme → column mapping (SQL-injection guard).

declare(strict_types=1);

/**
 * Maps a URL mode to its FIXED qso column names and reference metadata.
 * Column names come ONLY from here, never from the request — this is what makes
 * the otherwise-dynamic <prog>1/<prog>2 SQL safe.
 *
 * 'total': fixed object count for coverage; null means COUNT(*) of the ref table.
 */
const PROGRAMMES = [
    'wal'  => ['c1' => 'wal1',  'c2' => 'wal2',  'total' => 394],
    'lhfa' => ['c1' => 'lhfa1', 'c2' => 'lhfa2', 'total' => null],
    'lyff' => ['c1' => 'lyff1', 'c2' => 'lyff2', 'total' => null],
];

/**
 * @return array{c1:string, c2:string, total:?int}|null  null if the mode is unknown
 */
function programme(?string $mode): ?array
{
    return ($mode !== null && isset(PROGRAMMES[$mode])) ? PROGRAMMES[$mode] : null;
}
