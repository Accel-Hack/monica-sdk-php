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

        $key = rawurldecode((string) $parts['user']);
        // A public key authenticates with X-Monica-Key, a header no server SDK
        // sends. As a Bearer token it is a guaranteed 401, and the events would
        // disappear with nothing to look at, so the DSN is refused where it is
        // configured instead of once per request at runtime.
        if (strncasecmp($key, 'mpk_', 4) === 0) {
            throw new InvalidArgumentException(
                'dsn carries a public key (mpk_), which only browser and mobile SDKs may use. '
                . 'The PHP SDK needs a secret key (msk_)'
            );
        }

        return [
            'endpoint' => $scheme . '://' . $authority . '/v1/envelope',
            'key' => $key,
        ];
    }
}
