<?php

declare(strict_types=1);

namespace Monica\Transport;

/**
 * When a retryable envelope may be sent again, and when to give up on it.
 *
 * This is the `retry` section of transport.json: `Retry-After` in whole
 * seconds capped at a minute, exponential backoff with jitter for everything
 * else, and a limit on how many times one envelope is tried.
 *
 * Nothing here sleeps. In PHP the process that fails to send is usually a
 * request that has a user waiting on it, so the delay is expressed as "not
 * before this instant" and honoured by the spool: the envelope stays on disk
 * and the next flush picks it up once the time has come. `sleep()` inside a
 * request would spend the user's time on MONICA's outage.
 *
 * The numbers are constants because `spec/` is a development-time copy of the
 * contract and is not shipped in the package, so there is nothing to read at
 * runtime. `tests/spec-contract.php` compares them with transport.json, which
 * is what keeps them from drifting.
 */
final class RetryPolicy
{
    /** `retry_after.max_seconds`: a longer Retry-After is clamped to this. */
    public const RETRY_AFTER_MAX_SECONDS = 60;

    /** `backoff`: min(base * factor^attempt, max) milliseconds. */
    public const BACKOFF_BASE_MS = 1000;
    public const BACKOFF_FACTOR = 2;
    public const BACKOFF_MAX_MS = 30000;

    /** `backoff.jitter_min` / `jitter_max`: the multiplier applied to it. */
    public const JITTER_MIN = 0.5;
    public const JITTER_MAX = 1.0;

    /**
     * How many times one envelope is sent before it is dropped.
     *
     * The contract only says there must be a limit. 5 is what the JS core uses,
     * and with the backoff above it spans roughly a minute of outage before an
     * envelope is given up on.
     */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    private int $maxAttempts;
    /** @var callable(): float */
    private $random;

    /**
     * @param callable(): float|null $random 0..1, injectable so the jitter can be tested
     */
    public function __construct(int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS, ?callable $random = null)
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->random = $random ?? static function (): float {
            return mt_rand() / mt_getrandmax();
        };
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Whether an envelope that has been tried `$attempts` times may be tried
     * again.
     */
    public function mayRetry(int $attempts): bool
    {
        return $attempts < $this->maxAttempts;
    }

    /**
     * How long to hold an envelope that has just failed for the `$attempts`th
     * time, in milliseconds.
     *
     * `Retry-After` wins when MONICA sent one: it is the server saying when it
     * will be ready, which no backoff can guess. Otherwise the delay grows
     * exponentially with jitter, so a fleet of processes that all failed at the
     * same moment does not come back in step.
     */
    public function delayMilliseconds(?Response $response, int $attempts): int
    {
        $retryAfter = $response !== null ? $response->retryAfterSeconds() : null;
        if ($retryAfter !== null) {
            return $retryAfter * 1000;
        }

        $exponent = max(0, $attempts - 1);
        $backoff = self::BACKOFF_BASE_MS * (self::BACKOFF_FACTOR ** $exponent);
        $capped = (int) min($backoff, self::BACKOFF_MAX_MS);
        $jitter = self::JITTER_MIN + ($this->jitterFraction() * (self::JITTER_MAX - self::JITTER_MIN));

        return (int) round($capped * $jitter);
    }

    /**
     * `Retry-After` as a number of seconds, or null when there is nothing
     * usable in it.
     *
     * transport.json asks for whole seconds only. The HTTP-date form is legal
     * HTTP but is not interpreted: parsing it means trusting the client's clock
     * against the server's, and the backoff is a safe answer that needs no
     * clock at all. A value over the cap is clamped rather than dropped -- the
     * server did ask for a wait, just a longer one than the contract allows.
     */
    public static function parseRetryAfter(?string $header): ?int
    {
        if ($header === null) {
            return null;
        }
        $value = trim($header);
        if ($value === '' || preg_match('/^[0-9]+$/', $value) !== 1) {
            // Includes every HTTP-date, and anything else that is not a count
            // of seconds. The caller falls back to the backoff.
            return null;
        }

        return (int) min((int) $value, self::RETRY_AFTER_MAX_SECONDS);
    }

    private function jitterFraction(): float
    {
        $fraction = (float) ($this->random)();

        return max(0.0, min(1.0, $fraction));
    }
}
