<?php

declare(strict_types=1);

namespace Monica;

use LogicException;
use Throwable;

final class Monica
{
    private static ?Client $client = null;

    /**
     * @param array<string, mixed> $options
     */
    public static function init(array $options): Client
    {
        self::$client = new Client($options);

        return self::$client;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function captureException(Throwable $exception, array $context = []): ?string
    {
        return self::client()->captureException($exception, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function captureMessage(
        string $message,
        string $level = 'info',
        array $context = []
    ): ?string {
        return self::client()->captureMessage($message, $level, $context);
    }

    public static function flush(int $timeoutMilliseconds = 2000): bool
    {
        return self::client()->flush($timeoutMilliseconds);
    }

    public static function client(): Client
    {
        if (self::$client === null) {
            throw new LogicException('Monica::init() must be called first');
        }

        return self::$client;
    }
}
