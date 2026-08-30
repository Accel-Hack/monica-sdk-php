<?php

declare(strict_types=1);

namespace Monica\Transport;

interface TransportInterface
{
    /**
     * @param array<string, mixed> $envelope
     */
    public function send(array $envelope, int $timeoutMilliseconds): bool;
}
