<?php

declare(strict_types=1);

namespace Monica\Transport;

/**
 * What MONICA answered, as far as the SDK can act on it: the Outcome the status
 * maps to plus the diagnostics `error.json` carries with a rejection.
 *
 * `Outcome::forStatus()` stays the classification of a status on its own. This
 * adds the part that only exists in the response body -- `error.code` and, for
 * a 422, the `issues[].path` that says which field ingest refused -- so callers
 * can read it instead of having a status collapse into "not accepted".
 *
 * Nothing here changes what happens to an envelope. A rejection is still a
 * rejection; it is merely no longer silent.
 *
 * `Retry-After` is the one response header the SDK acts on, so it is carried
 * here as a number of seconds rather than as a header bag: the rest of the
 * headers are MONICA's business, and a bag would invite reading them.
 */
final class Response
{
    /**
     * How much of a rejection body is read.
     *
     * A rejection is diagnostics, not data, so a body that does not fit is a
     * body worth ignoring: it is dropped whole rather than parsed from a
     * truncated prefix, which could only produce misleading issues.
     */
    public const MAX_BODY_BYTES = 65536;

    private string $outcome;
    private ?int $status;
    private ?string $errorCode;
    private ?string $errorMessage;
    /** @var list<array{path: string, message: string}> */
    private array $issues;
    private int $droppedItems = 0;
    /** @var list<int> */
    private array $droppedItemIndexes = [];
    private ?int $retryAfterSeconds;

    /**
     * @param list<array{path: string, message: string}> $issues
     */
    private function __construct(
        string $outcome,
        ?int $status,
        ?string $errorCode,
        ?string $errorMessage,
        array $issues,
        ?int $retryAfterSeconds = null
    ) {
        $this->outcome = $outcome;
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->issues = $issues;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    /**
     * @param string|null $body               the response body, or null when it was not
     *                                        read or exceeded MAX_BODY_BYTES
     * @param int|null    $retryAfterSeconds  `Retry-After` as whole seconds, already
     *                                        clamped by RetryPolicy::parseRetryAfter()
     */
    public static function forStatus(
        int $status,
        ?string $body = null,
        ?int $retryAfterSeconds = null
    ): self {
        $outcome = Outcome::forStatus($status);
        if ($body === null || $body === '' || !self::carriesDiagnostics($status)) {
            return new self($outcome, $status, null, null, [], $retryAfterSeconds);
        }

        $error = self::parse($body);

        return new self(
            $outcome,
            $status,
            $error['code'],
            $error['message'],
            $error['issues'],
            $retryAfterSeconds
        );
    }

    /**
     * A response known only by its Outcome: a transport that predates this
     * class, or a caller reconstructing one.
     */
    public static function forOutcome(string $outcome, ?int $status = null): self
    {
        return new self($outcome, $status, null, null, []);
    }

    /**
     * No response at all. transport.json asks for network failures to be
     * retried, and there is no status to hang that on.
     */
    public static function forNetworkFailure(): self
    {
        return new self(Outcome::RETRYABLE, null, null, null, []);
    }

    /**
     * Whether a status can carry an `error.json` body worth reading.
     *
     * 4xx except 429: a rejection explains itself, while 429 is a wait and says
     * everything it has to say in `Retry-After`. 5xx bodies are the server's
     * problem, not the envelope's.
     *
     * Transports use this to avoid pulling a body they would then throw away.
     */
    public static function carriesDiagnostics(int $status): bool
    {
        return $status >= 400 && $status < 500 && $status !== 429;
    }

    /**
     * The same response, plus the items that were dropped for being too large
     * to fit an envelope on their own (see EnvelopeSplitter).
     *
     * This belongs on the result rather than in a log line only, because the
     * caller has to report the loss in the next envelope's `discarded` -- and,
     * when the send failed part-way, has to take the dropped items out of its
     * queue. Retrying them would drop them again, once per flush, for ever.
     *
     * The indexes are positions in the `items` array of the envelope that was
     * handed to the transport.
     *
     * @param list<int> $indexes
     */
    public function withDroppedItems(int $dropped, array $indexes = []): self
    {
        $copy = clone $this;
        $copy->droppedItems = max(0, $dropped);
        $copy->droppedItemIndexes = array_values(array_map('intval', $indexes));

        return $copy;
    }

    /** How many items were dropped as unsendable while sending this envelope. */
    public function droppedItems(): int
    {
        return $this->droppedItems;
    }

    /**
     * Which items were dropped, as positions in the envelope that was sent.
     * Empty when nothing was dropped, and possibly shorter than
     * `droppedItems()` for a transport that only counted them.
     *
     * @return list<int>
     */
    public function droppedItemIndexes(): array
    {
        return $this->droppedItemIndexes;
    }

    /**
     * `Retry-After` in whole seconds, or null when MONICA did not send a usable
     * one (including the HTTP-date form, which is not interpreted). Only 429
     * and 5xx carry it in practice.
     */
    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /** One of the Outcome constants. */
    public function outcome(): string
    {
        return $this->outcome;
    }

    /** The HTTP status, or null when the request never got an answer. */
    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * `error.code` from the body. Meant for humans: branch on the status, which
     * is what transport.json defines the behaviour against.
     */
    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * The field-level issues from a 422, in the order ingest reported them.
     * Empty for every other status, and empty for a 422 whose body was
     * missing, unreadable or larger than MAX_BODY_BYTES.
     *
     * @return list<array{path: string, message: string}>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * Read a body as `error.json`.
     *
     * Anything that does not fit the schema is dropped rather than reported:
     * this runs while an envelope is already being lost, so a surprising body
     * must not turn into a second failure. Callers see a rejection with no
     * issues, which is what they saw before bodies were read at all.
     *
     * @return array{code: string|null, message: string|null, issues: list<array{path: string, message: string}>}
     */
    private static function parse(string $body): array
    {
        $empty = ['code' => null, 'message' => null, 'issues' => []];
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return $empty;
        }
        $error = $decoded['error'];

        $code = isset($error['code']) && is_string($error['code']) && $error['code'] !== ''
            ? $error['code']
            : null;
        $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : null;

        $issues = [];
        if (isset($error['issues']) && is_array($error['issues'])) {
            foreach ($error['issues'] as $issue) {
                // Both fields are required by error.json. An element missing
                // either one cannot be pointed at, so it is not an issue.
                if (
                    !is_array($issue)
                    || !isset($issue['path'], $issue['message'])
                    || !is_string($issue['path'])
                    || !is_string($issue['message'])
                ) {
                    continue;
                }
                $issues[] = ['path' => $issue['path'], 'message' => $issue['message']];
            }
        }

        return ['code' => $code, 'message' => $message, 'issues' => $issues];
    }
}
