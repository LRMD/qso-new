<?php
// src/view.php (PHP 7.4) — render the SPA HTML shell for non-API routes.
// A <base href> = the app mount point makes every relative asset/API URL
// location-independent (works under /new or /).

declare(strict_types=1);

/** Mount-point base href, always ending in "/" (e.g. "/new/" or "/"). */
function app_base_href(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $dir . '/';
}

/** The single-page-app HTML shell. */
function shell_html(string $base, string $lang): string
{
    $base = htmlspecialchars($base, ENT_QUOTES);
    $lang = htmlspecialchars($lang, ENT_QUOTES);
    $v    = defined('APP_BUILD') ? '?v=' . rawurlencode(APP_BUILD) : '';   // cache-bust on deploy

    return <<<HTML
<!doctype html>
<html lang="$lang">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <base href="$base">
  <title>QSO Awards — WAL · LHFA · LYFF</title>
  <meta name="description" content="Lithuanian amateur-radio award map: WAL squares, LHFA hillforts, LYFF parks &amp; reserves.">
  <link rel="stylesheet" href="assets/vendor/maplibre-gl.css">
  <link rel="stylesheet" href="assets/app.css$v">
</head>
<body>
  <div id="app"></div>
  <script src="assets/vendor/maplibre-gl.js"></script>
  <script src="assets/vendor/vue.global.prod.js"></script>
  <script src="assets/app.js$v"></script>
</body>
</html>
HTML;
}
