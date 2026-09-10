<?php

declare(strict_types=1);

namespace Monica\Transport;

/**
 * What the SDK does with a response, in the vocabulary of the `status` table in
 * MONICA's transport.json. A transport reports one of these; the caller decides
 * whether the envelope goes back on the spool, is moved aside, or is gone.
 */
final class Outcome
{
    /** 2xx: the envelope left the queue. */
    public const ACCEPTED = 'accepted';

    /** A permanent failure. Sending the same envelope again cannot help. */
    public const REJECTED = 'rejected';

    /** 401: as REJECTED, and nothing else should be sent with this key either. */
    public const REJECTED_STOP = 'rejected_stop';

    /** 429, 5xx and network failures: the same bytes may be accepted later. */
    public const RETRYABLE = 'retryable';

    public static function forStatus(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return self::ACCEPTED;
        }
        if ($status === 401) {
            return self::REJECTED_STOP;
        }
        // 413 is `split_and_retry`: the bytes are refused, not the content, so
        // the same items can be accepted once they are spread over more than
        // one envelope. EnvelopeSplitter does that inside the transport, which
        // is why a 413 rarely reaches a caller at all -- but classifying it as
        // permanent would be wrong now that resending is not futile.
        if ($status === 429 || $status === 413 || $status >= 500) {
            return self::RETRYABLE;
        }

        // 400 and 422 are permanent by spec, and an unknown status is treated
        // the same way rather than retried.
        return self::REJECTED;
    }
}
