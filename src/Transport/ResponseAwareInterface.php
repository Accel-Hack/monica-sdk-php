<?php

declare(strict_types=1);

namespace Monica\Transport;

/**
 * A transport that reports what MONICA answered, not just how the answer is
 * classified. The rungs below it stay usable and stay the entry points for
 * callers that do not care: `TransportInterface::send()` answers yes or no,
 * `OutcomeAwareInterface::sendEnvelope()` answers with an Outcome.
 */
interface ResponseAwareInterface extends OutcomeAwareInterface
{
    /**
     * @param array<string, mixed> $envelope
     */
    public function sendEnvelopeResponse(array $envelope, int $timeoutMilliseconds): Response;
}
