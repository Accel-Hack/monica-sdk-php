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
 * oversized file to describe it. `retry_after` sets the header of that name,
 * and `max_items` answers 413 for anything bigger.
 *
 * Every request is appended to `MONICA_STUB_LOG` as one JSON line, so a test
 * can assert on what actually arrived -- that the body is gzipped, and which
 * items were in it. Without that, a transport that posts nothing at all looks
 * exactly like one that works.
 */

$control = getenv('MONICA_STUB_FILE');
$raw = is_string($control) && is_file($control) ? (string) file_get_contents($control) : '';
$directive = json_decode($raw, true);
if (!is_array($directive)) {
    $directive = [];
}

$requestBody = (string) file_get_contents('php://input');
$decompressed = $requestBody === '' ? false : @gzdecode($requestBody);
$envelope = $decompressed === false ? null : json_decode($decompressed, true);

$log = getenv('MONICA_STUB_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents(
        $log,
        json_encode([
            'gzip_bytes' => strlen($requestBody),
            'gzipped' => $decompressed !== false,
            'content_encoding' => $_SERVER['HTTP_CONTENT_ENCODING'] ?? '',
            'items' => is_array($envelope) && isset($envelope['items']) && is_array($envelope['items'])
                ? count($envelope['items'])
                : -1,
            'messages' => is_array($envelope) && isset($envelope['items']) && is_array($envelope['items'])
                ? array_map(
                    static function ($item) {
                        return is_array($item) && isset($item['message']) ? $item['message'] : null;
                    },
                    $envelope['items']
                )
                : [],
            'discarded' => is_array($envelope) && isset($envelope['discarded']) ? $envelope['discarded'] : null,
        ]) . "\n",
        FILE_APPEND
    );
}

$status = isset($directive['status']) ? (int) $directive['status'] : 202;
$body = isset($directive['body']) ? (string) $directive['body'] : '';
$repeat = isset($directive['repeat']) ? max(1, (int) $directive['repeat']) : 1;

// `max_items` makes the stub answer 413 for anything bigger, which is how a
// split can be driven without building a megabyte of items: the size MONICA
// refuses is a server-side decision either way.
if (isset($directive['max_items']) && is_array($envelope) && isset($envelope['items'])) {
    if (count($envelope['items']) > (int) $directive['max_items']) {
        $status = 413;
        $body = '';
        $repeat = 1;
    }
}

http_response_code($status);
header('Content-Type: application/json');
if (isset($directive['retry_after'])) {
    header('Retry-After: ' . (string) $directive['retry_after']);
}
echo str_repeat($body, $repeat);
