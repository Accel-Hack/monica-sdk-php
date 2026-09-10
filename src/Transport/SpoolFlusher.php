<?php

declare(strict_types=1);

namespace Monica\Transport;

use Throwable;

/**
 * Sends what the spool holds, and decides when a failed envelope may be tried
 * again.
 *
 * The retry state lives in the file name: how many attempts an envelope has
 * had, and the earliest instant it may be sent again. It has to survive the
 * process, because the process that failed to send is usually gone by the time
 * the next attempt is due -- and it has to stay out of the file's contents,
 * which are the envelope itself and go to MONICA verbatim.
 *
 * The wait is not spent sleeping. The envelope is left on disk until its time
 * has come and the next flush picks it up, so a `Retry-After: 60` costs a cron
 * run rather than a minute of a worker.
 */
final class SpoolFlusher
{
    /**
     * The retry state, as it appears in a spool file name:
     * `20260910T…-1234-abcd--try2-at1757500000.json`.
     *
     * It sits after the parts that already carry meaning (the 14-digit
     * creation time that orders the spool, the pid, the random tail) and before
     * `.json`, so every existing name pattern still matches: the flusher's
     * `*.json` glob, the `.sending-` claim, the `.rejected` / `.invalid`
     * retirement, and the age sort in SpoolTransport.
     */
    private const MARKER_PATTERN = '/--try([0-9]+)-at([0-9]+)\.json$/';

    private string $directory;
    private TransportInterface $transport;
    private int $claimTtlSeconds;
    private RetryPolicy $retry;
    private Diagnostics $diagnostics;
    private ?Response $lastResponse = null;

    public function __construct(
        string $directory,
        TransportInterface $transport,
        int $claimTtlSeconds = 300,
        ?RetryPolicy $retry = null,
        ?Diagnostics $diagnostics = null
    ) {
        if ($claimTtlSeconds < 1) {
            throw new \InvalidArgumentException('claimTtlSeconds must be positive');
        }
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $this->transport = $transport;
        $this->claimTtlSeconds = $claimTtlSeconds;
        $this->retry = $retry ?? new RetryPolicy();
        $this->diagnostics = $diagnostics ?? new Diagnostics();
    }

    /**
     * `deferred` counts envelopes whose backoff or `Retry-After` has not
     * elapsed yet. They were not touched, which is the difference between
     * "MONICA is not answering" and "it is not time yet".
     *
     * @return array{sent: int, failed: int, rejected: int, invalid: int, deferred: int}
     */
    public function flush(int $timeoutMilliseconds = 2000): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'rejected' => 0, 'invalid' => 0, 'deferred' => 0];
        $this->recoverStaleClaims();
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files, SORT_STRING);
        $now = time();

        foreach ($files as $file) {
            $state = self::retryStateOf($file);
            if ($state['not_before'] > $now) {
                // Sending now is what MONICA asked this SDK not to do. Later
                // envelopes are still tried: one envelope waiting out a 429 is
                // not a reason to hold the rest of the spool.
                $result['deferred']++;
                continue;
            }
            $claimed = $this->directory . DIRECTORY_SEPARATOR
                . '.sending-' . (getmypid() ?: 0) . '-' . basename($file);
            // Refresh the lease before making the file a claim. If the process
            // dies after rename, another process will recover it after the TTL.
            @touch($file);
            if (!@rename($file, $claimed)) {
                continue;
            }

            try {
                $json = file_get_contents($claimed);
                $envelope = $json === false ? null : json_decode($json, true);
                if (!is_array($envelope)) {
                    $result['invalid']++;
                    @rename($claimed, $claimed . '.invalid');
                    continue;
                }
                $outcome = $this->outcomeOf($envelope, $timeoutMilliseconds);
            } catch (Throwable $ignored) {
                $result['failed']++;
                @rename($claimed, $file);
                break;
            }

            if ($outcome === Outcome::ACCEPTED) {
                $result['sent']++;
                @unlink($claimed);
                continue;
            }
            if ($outcome === Outcome::RETRYABLE) {
                // The same bytes may be accepted later, so the envelope goes
                // back to the spool -- but not before its backoff or the
                // Retry-After MONICA sent has elapsed, and not for ever.
                $attempts = $state['attempts'] + 1;
                if (!$this->retry->mayRetry($attempts)) {
                    // transport.json: the retry count has a limit and the
                    // envelope is dropped when it runs out. Keeping it would
                    // trade a bounded loss for an unbounded spool.
                    $result['rejected']++;
                    $this->diagnostics->warn(self::describeGiveUp($attempts, $claimed));
                    @rename($claimed, $claimed . '.rejected');
                    break;
                }
                $delayMilliseconds = $this->retry->delayMilliseconds($this->lastResponse, $attempts);
                $result['failed']++;
                @rename(
                    $claimed,
                    self::withRetryState($file, $attempts, $now + (int) ceil($delayMilliseconds / 1000))
                );
                // Whatever made this fail -- the network, rate limiting, MONICA
                // being down -- applies to the rest of the run too, so there is
                // nothing to gain from continuing.
                break;
            }

            // A permanent rejection. Leaving it in the spool would make every
            // envelope behind it wait for a request that can never succeed, so
            // it moves aside like an unparseable file does.
            $result['rejected']++;
            @rename($claimed, $claimed . '.rejected');
            if ($outcome === Outcome::REJECTED_STOP) {
                // The key itself is refused, so the rest of the run would be.
                break;
            }
        }

        return $result;
    }

    /**
     * What MONICA answered to the last envelope this flusher tried to send,
     * including a 422's `issues[].path`. Null before the first attempt.
     *
     * The counters `flush()` returns say how the run went; this says why the
     * last attempt in it ended that way. The warning for a rejected envelope is
     * emitted by the transport, so it appears on this path without the caller
     * reading anything.
     */
    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function outcomeOf(array $envelope, int $timeoutMilliseconds): string
    {
        $this->lastResponse = $this->responseOf($envelope, $timeoutMilliseconds);

        return $this->lastResponse->outcome();
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function responseOf(array $envelope, int $timeoutMilliseconds): Response
    {
        if ($this->transport instanceof ResponseAwareInterface) {
            return $this->transport->sendEnvelopeResponse($envelope, $timeoutMilliseconds);
        }
        if ($this->transport instanceof OutcomeAwareInterface) {
            return Response::forOutcome($this->transport->sendEnvelope($envelope, $timeoutMilliseconds));
        }

        // A transport supplied from outside only answers yes or no. Treating a
        // no as retryable keeps the behaviour it had before outcomes existed.
        return Response::forOutcome(
            $this->transport->send($envelope, $timeoutMilliseconds)
                ? Outcome::ACCEPTED
                : Outcome::RETRYABLE
        );
    }

    /**
     * How many attempts a spool file has had, and when it may be sent again.
     *
     * A file without the marker has never failed: envelopes are written by
     * SpoolTransport without one, and so are the files an older version of
     * this SDK left behind.
     *
     * @return array{attempts: int, not_before: int}
     */
    private static function retryStateOf(string $path): array
    {
        if (preg_match(self::MARKER_PATTERN, basename($path), $matches) !== 1) {
            return ['attempts' => 0, 'not_before' => 0];
        }

        return ['attempts' => (int) $matches[1], 'not_before' => (int) $matches[2]];
    }

    /**
     * The same path with the retry state replaced. The original name is kept
     * apart from the marker, so the creation time the spool sorts by survives
     * every retry.
     */
    private static function withRetryState(string $path, int $attempts, int $notBefore): string
    {
        $directory = dirname($path);
        $name = preg_replace(self::MARKER_PATTERN, '.json', basename($path)) ?? basename($path);
        $name = substr($name, 0, -strlen('.json'))
            . '--try' . $attempts . '-at' . $notBefore . '.json';

        return $directory . DIRECTORY_SEPARATOR . $name;
    }

    private static function describeGiveUp(int $attempts, string $claimed): string
    {
        $items = 'an unknown number of';
        $json = @file_get_contents($claimed);
        $envelope = $json === false ? null : json_decode($json, true);
        if (is_array($envelope) && isset($envelope['items']) && is_array($envelope['items'])) {
            $items = (string) count($envelope['items']);
        }

        return 'monica: giving up on a spooled envelope after ' . $attempts
            . ' attempt(s); ' . $items . ' event(s) are lost';
    }

    private function recoverStaleClaims(): void
    {
        $claims = glob($this->directory . DIRECTORY_SEPARATOR . '.sending-*.json') ?: [];
        $staleBefore = time() - $this->claimTtlSeconds;
        foreach ($claims as $claim) {
            $modifiedAt = @filemtime($claim);
            if ($modifiedAt === false || $modifiedAt > $staleBefore) {
                continue;
            }
            $basename = basename($claim);
            if (preg_match('/^\\.sending-[0-9]+-(.+\\.json)$/', $basename, $matches) !== 1) {
                continue;
            }
            $target = $this->directory . DIRECTORY_SEPARATOR . $matches[1];
            if (is_file($target)) {
                $target = $this->directory . DIRECTORY_SEPARATOR
                    . gmdate('YmdHis') . '-recovered-' . bin2hex(random_bytes(8)) . '.json';
            }
            @rename($claim, $target);
        }
    }
}
