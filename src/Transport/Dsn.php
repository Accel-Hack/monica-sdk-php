<?php

declare(strict_types=1);

namespace Monica\Transport;

use InvalidArgumentException;

final class Dsn
{
    /**
     * @return array{endpoint: string, key: string}
     */
    public static function parse(string $dsn): array
    {
        $parts = parse_url($dsn);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'], $parts['user'])
            || $parts['user'] === ''
        ) {
            throw new InvalidArgumentException('dsn must be a URL containing an API key');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];
        $localHttp = $scheme === 'http' && ($host === 'localhost' || $host === '127.0.0.1');
        if ($scheme !== 'https' && !$localHttp) {
            throw new InvalidArgumentException('dsn must use https except for localhost');
        }

        $authority = $host;
        if (isset($parts['port'])) {
            $authority .= ':' . (int) $parts['port'];
        }

        return [
            'endpoint' => $scheme . '://' . $authority . '/v1/envelope',
            'key' => rawurldecode((string) $parts['user']),
        ];
    }
}
