<?php

declare(strict_types=1);

namespace Monica\Transport;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;

final class Psr18Transport implements
    TransportInterface,
    OutcomeAwareInterface,
    ResponseAwareInterface,
    StoppableInterface
{
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    /** @var array{endpoint: string, key: string} */
    private array $dsn;
    private Diagnostics $diagnostics;
    private bool $stopped = false;

    public function __construct(
        string $dsn,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?Diagnostics $diagnostics = null
    ) {
        $this->dsn = Dsn::parse($dsn);
        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
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
        unset($timeoutMilliseconds);

        if ($this->stopped) {
            // The same answer MONICA gave, without asking again. Not reported:
            // the 401 that stopped this transport was already reported once,
            // and repeating it per dropped envelope would bury it.
            return Response::forStatus(401);
        }

        try {
            $json = json_encode(
                $envelope,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $body = gzencode($json, 6);
            if ($body === false) {
                throw new RuntimeException('Unable to gzip the MONICA envelope');
            }
            $request = $this->requestFactory
                ->createRequest('POST', $this->dsn['endpoint'])
                ->withHeader('Authorization', 'Bearer ' . $this->dsn['key'])
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Content-Encoding', 'gzip')
                ->withBody($this->streamFactory->createStream($body));
            $response = $this->client->sendRequest($request);
            $status = $response->getStatusCode();
            $result = Response::forStatus(
                $status,
                Response::carriesDiagnostics($status) ? self::readBody($response) : null
            );
            if ($result->outcome() === Outcome::REJECTED_STOP) {
                $this->stopped = true;
            }
            // Reporting cannot throw, so this does not turn a rejection into
            // the network failure below.
            $this->diagnostics->report($result);

            return $result;
        } catch (Throwable $ignored) {
            // Transport failures never escape into application handlers. Apart
            // from protecting the host, this prevents recursive MONICA events.
            // A PSR-18 client throws on a network failure, which is retryable.
            return Response::forNetworkFailure();
        }
    }

    /**
     * The rejection body, or null if it is unreadable or too big to be worth
     * reading. A PSR-7 body is a stream that may not be seekable or may not be
     * there at all, and none of that is worth failing a send over.
     *
     * When `retry` lands, `Retry-After` is read from $response here and handed
     * to `Response::forStatus()` alongside this.
     */
    private static function readBody(ResponseInterface $response): ?string
    {
        try {
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = '';
            while (!$stream->eof() && strlen($body) <= Response::MAX_BODY_BYTES) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }

            return strlen($body) > Response::MAX_BODY_BYTES ? null : $body;
        } catch (Throwable $ignored) {
            return null;
        }
    }
}
