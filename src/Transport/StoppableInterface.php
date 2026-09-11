<?php

declare(strict_types=1);

namespace Monica\Transport;

/**
 * A transport that can refuse to send any more.
 *
 * transport.json makes 401 `drop_and_stop`: the envelope is dropped *and*
 * nothing else is sent with that key. The dropping is visible in the Outcome;
 * this is how the stopping becomes visible, so a caller can tell "MONICA is
 * refusing the key" from "MONICA is quiet".
 */
interface StoppableInterface
{
    public function isStopped(): bool;
}
