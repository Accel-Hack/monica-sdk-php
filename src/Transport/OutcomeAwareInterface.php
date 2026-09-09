<?php

declare(strict_types=1);

namespace Monica\Transport;

/**
 * A transport that reports which of transport.json's behaviours applies, rather
 * than only whether the envelope was accepted. TransportInterface::send() stays
 * the entry point for callers that do not care.
 */
interface OutcomeAwareInterface
{
    /**
     * @param array<string, mixed> $envelope
     *
     * @return string one of the Outcome constants
     */
    public function sendEnvelope(array $envelope, int $timeoutMilliseconds): string;
}
