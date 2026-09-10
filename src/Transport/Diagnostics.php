<?php

declare(strict_types=1);

namespace Monica\Transport;

use Throwable;

/**
 * Where a rejection gets said out loud.
 *
 * A 422 means ingest refused the envelope's *shape*, and `issues[].path` names
 * the field. That is the one failure the application can actually fix, so it is
 * reported by default: an SDK that swallows it turns a broken adapter into
 * "events stopped arriving" months later. The default destination is
 * `error_log()`, which is where PHP puts things the application did not ask
 * about.
 *
 * A 401 is announced for a different reason: it does not just lose the envelope
 * in hand, it stops the transport from sending anything else. That is worth one
 * line, or the silence looks like MONICA going quiet.
 *
 * Nothing else is announced. Every other status either says nothing the caller
 * can act on (400 without a body, 5xx) or is not a failure yet (429).
 */
final class Diagnostics
{
    /** @var callable|null null disables reporting entirely */
    private $handler;

    /**
     * @param callable|null $handler receives (string $message, Response $response)
     */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler ?? static function (string $message): void {
            error_log($message);
        };
    }

    /** Reports nothing. */
    public static function disabled(): self
    {
        $silent = new self();
        $silent->handler = null;

        return $silent;
    }

    /**
     * Reads the reporting option out of the client options.
     *
     * Absent means the default destination. `false` or `null` means off -- both
     * spellings, because either is what a config file ends up holding when the
     * option is switched off. Anything that is neither a callable nor a
     * disabling value is ignored rather than fatal, as `before_send` does:
     * losing the warning is better than breaking initialization over it.
     *
     * @param array<string, mixed> $options
     */
    public static function fromOptions(array $options, string $key = 'on_diagnostic'): self
    {
        if (!array_key_exists($key, $options)) {
            return new self();
        }
        $value = $options[$key];
        if ($value === false || $value === null) {
            return self::disabled();
        }

        return is_callable($value) ? new self($value) : new self();
    }

    /**
     * Announce a response if it is worth announcing. Called once per envelope,
     * by the transport that sent it.
     */
    public function report(Response $response): void
    {
        if ($this->handler === null || !self::reports($response->status())) {
            return;
        }

        $this->warn(self::describe($response), $response);
    }

    /**
     * Report something the SDK decided by itself -- an envelope it will not
     * even try to send, say -- through the same handler as a rejection.
     */
    public function warn(string $message, ?Response $context = null): void
    {
        if ($this->handler === null) {
            return;
        }

        try {
            call_user_func($this->handler, $message, $context ?? Response::forOutcome(Outcome::REJECTED));
        } catch (Throwable $ignored) {
            // A reporting handler is application code, and this runs while an
            // event is already being lost. Letting it throw would turn a
            // reported rejection into an unreported network failure, or worse,
            // escape into the handler the SDK is installed in.
        }
    }

    /** Whether a status is one this class announces. */
    public static function reports(?int $status): bool
    {
        return $status === 422 || $status === 401;
    }

    /**
     * The wording every MONICA SDK uses for a rejected envelope, so the same
     * failure reads the same way whichever SDK reports it. One line: the
     * destination is a log, where a multi-line entry is a multi-line grep.
     *
     * `error.code` is `unknown` rather than absent when the body did not
     * provide one, so the shape of the line does not depend on the body.
     *
     * Only what MONICA sent back appears here: no envelope, no API key.
     */
    public static function describe(Response $response): string
    {
        $line = 'monica: ingest rejected the envelope with '
            . (string) $response->status()
            . ' (' . ($response->errorCode() ?? 'unknown') . ')';

        if ($response->status() === 401) {
            // The key itself was refused, so this is the last envelope the
            // transport will send. Saying so is the point of the line.
            return $line . '; no further envelopes will be sent';
        }

        $issues = $response->issues();
        $line .= ': ' . count($issues) . ' issue(s)';
        foreach ($issues as $issue) {
            $line .= '; ' . $issue['path'] . ': ' . $issue['message'];
        }

        return $line;
    }
}
