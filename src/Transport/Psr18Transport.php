<?php

declare(strict_types=1);

namespace Monica\Transport;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;

final class Psr18Transport implements TransportInterface
{
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    /** @var array{endpoint: string, key: string} */
    private array $dsn;

    public function __construct(
        string $dsn,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory
    ) {
        $this->dsn = Dsn::parse($dsn);
        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    public function send(array $envelope, int $timeoutMilliseconds): bool
    {
        unset($timeoutMilliseconds);

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

            return $status >= 200 && $status < 300;
        } catch (Throwable $ignored) {
            // Transport failures never escape into application handlers. Apart
            // from protecting the host, this prevents recursive MONICA events.
            return false;
        }
    }
}
