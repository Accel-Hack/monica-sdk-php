<?php

declare(strict_types=1);

namespace Monica\Transport;

use RuntimeException;
use Throwable;

final class CurlTransport implements TransportInterface
{
    /** @var array{endpoint: string, key: string} */
    private array $dsn;

    public function __construct(string $dsn)
    {
        $this->dsn = Dsn::parse($dsn);
    }

    public function send(array $envelope, int $timeoutMilliseconds): bool
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is required when no PSR-18 client is supplied');
        }

        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $body = gzencode($json, 6);
        if ($body === false) {
            throw new RuntimeException('Unable to gzip the MONICA envelope');
        }

        $handle = curl_init($this->dsn['endpoint']);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->dsn['key'],
                    'Content-Type: application/json',
                    'Content-Encoding: gzip',
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_CONNECTTIMEOUT_MS => $timeoutMilliseconds,
                CURLOPT_TIMEOUT_MS => $timeoutMilliseconds,
                CURLOPT_NOSIGNAL => true,
            ]);
            $result = curl_exec($handle);
            if ($result === false) {
                return false;
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            return $status >= 200 && $status < 300;
        } catch (Throwable $ignored) {
            return false;
        } finally {
            curl_close($handle);
        }
    }
}
