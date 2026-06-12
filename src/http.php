<?php
// src/http.php (PHP 7.4) — tiny JSON response helpers shared by the API.

declare(strict_types=1);

/** Emit an already-encoded JSON string and stop (e.g. served from cache). */
function send_json(string $json, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
    exit;
}

/** Encode $data as JSON (consistent flags across the API). Never returns false:
 *  bad UTF-8 is substituted so a single stray byte can't 500 the whole endpoint. */
function json_encode_api($data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    return $json === false ? '{"error":"encoding_failed"}' : $json;
}

/** Emit a JSON response and stop. */
function json_out($data, int $code = 200): void
{
    send_json(json_encode_api($data), $code);
}

/** Emit a JSON error and stop. */
function fail(int $code, string $message): void
{
    json_out(['error' => $message], $code);
}
