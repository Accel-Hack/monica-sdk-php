<?php

declare(strict_types=1);

/**
 * A stand-in for MONICA's ingest, for the one transport that cannot be tested
 * with an injected client: CurlTransport talks to a socket, so it needs a
 * server on the other end.
 *
 * Run as the router of PHP's built-in server. The response it gives is
 * whatever the JSON file named by `MONICA_STUB_FILE` says, so a single server
 * can serve every case in tests/run.php without being restarted between them:
 *
 *     {"status": 422, "body": "{\"error\":{...}}"}
 *
 * `repeat` multiplies the body, so an oversized response does not need an
 * oversized file to describe it.
 */

$control = getenv('MONICA_STUB_FILE');
$raw = is_string($control) && is_file($control) ? (string) file_get_contents($control) : '';
$directive = json_decode($raw, true);
if (!is_array($directive)) {
    $directive = [];
}

$status = isset($directive['status']) ? (int) $directive['status'] : 202;
$body = isset($directive['body']) ? (string) $directive['body'] : '';
$repeat = isset($directive['repeat']) ? max(1, (int) $directive['repeat']) : 1;

http_response_code($status);
header('Content-Type: application/json');
echo str_repeat($body, $repeat);
