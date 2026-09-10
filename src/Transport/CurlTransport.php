<?php

declare(strict_types=1);

namespace Monica\Transport;

use RuntimeException;
use Throwable;

final class CurlTransport implements
    TransportInterface,
    OutcomeAwareInterface,
    ResponseAwareInterface,
    StoppableInterface
{
    /** @var array{endpoint: string, key: string} */
    private array $dsn;
    private Diagnostics $diagnostics;
    private bool $stopped = false;

    public function __construct(string $dsn, ?Diagnostics $diagnostics = null)
    {
        $this->dsn = Dsn::parse($dsn);
        $this->diagnostics = $diagnostics ?? new Diagnostics();
    }

    public function send(array $envelope, int $timeoutMilliseconds): bool
    {
        return $this->sendEnvelope($envelope, $timeoutMilliseconds) === Outcome::ACCEPTED;
    }

    public function sendEnvelope(array $envelope, int $timeoutMilliseconds): string
    {
        return $this->sendEnvelopeResponse($envelope, $timeoutMilliseconds)->outcome();
    }

    /**
     * Whether a 401 has stopped this transport. transport.json's
     * `drop_and_stop` is about the key, not the envelope, so once MONICA
     * refuses it there is nothing to gain from posting again.
     */
    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function sendEnvelopeResponse(array $envelope, int $timeoutMilliseconds): Response
    {
        if ($this->stopped) {
            // The same answer MONICA gave, without asking again. Not reported:
            // the 401 that stopped this transport was already reported once,
            // and repeating it per dropped envelope would bury it.
            return Response::forStatus(401);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is required when no PSR-18 client is supplied');
        }

        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $body = gzencode($json, 6);
        if ($body === false) {
            throw new RuntimeException('Unable to gzip the MONICA envelope');
        }

        $handle = curl_init($this->dsn['endpoint']);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        // A rejection body is read through a write callback rather than
        // collected by cURL, so a MONICA that answers with megabytes cannot
        // make the SDK hold them: anything past the cap is dropped as it
        // arrives. The callback keeps returning the full chunk length, because
        // returning less aborts the transfer and would lose the status too.
        $body = '';
        $oversized = false;
        $collect = static function ($handle, string $chunk) use (&$body, &$oversized): int {
            unset($handle);
            $length = strlen($chunk);
            if (strlen($body) + $length > Response::MAX_BODY_BYTES) {
                $oversized = true;
            } else {
                $body .= $chunk;
            }

            return $length;
        };

        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_WRITEFUNCTION => $collect,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->dsn['key'],
                    'Content-Type: application/json',
                    'Content-Encoding: gzip',
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_CONNECTTIMEOUT_MS => $timeoutMilliseconds,
                CURLOPT_TIMEOUT_MS => $timeoutMilliseconds,
                CURLOPT_NOSIGNAL => true,
            ]);
            $result = curl_exec($handle);
            if ($result === false) {
                // A network failure, which transport.json says to retry.
                return Response::forNetworkFailure();
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $response = Response::forStatus(
                $status,
                $oversized || !Response::carriesDiagnostics($status) ? null : $body
            );
            if ($response->outcome() === Outcome::REJECTED_STOP) {
                $this->stopped = true;
            }
            // Reporting cannot throw, so this does not fall through to the
            // network failure below.
            $this->diagnostics->report($response);

            return $response;
        } catch (Throwable $ignored) {
            return Response::forNetworkFailure();
        } finally {
            curl_close($handle);
        }
    }
}
