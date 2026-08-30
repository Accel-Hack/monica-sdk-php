<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Monica\Client;
use Monica\Transport\SpoolFlusher;
use Monica\Transport\SpoolTransport;
use Monica\Transport\TransportInterface;
use Monica\Transport\Dsn;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RecordingTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $envelopes = [];
    public bool $accepted = true;
    public bool $throw = false;

    public function send(array $envelope, int $timeoutMilliseconds): bool
    {
        expect($timeoutMilliseconds === 2000, 'timeout should default to 2000ms');
        if ($this->throw) {
            throw new RuntimeException('transport failed');
        }
        $this->envelopes[] = $envelope;

        return $this->accepted;
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$invalidDsnRejected = false;
try {
    Dsn::parse('ftp://secret@localhost/1');
} catch (InvalidArgumentException $ignored) {
    $invalidDsnRejected = true;
}
expect($invalidDsnRejected, 'DSNs should allow only HTTPS or local HTTP');

$transport = new RecordingTransport();
$client = new Client([
    'dsn' => 'https://secret@ingest.example.test/1',
    'environment' => 'test',
    'release' => 'abc123',
    'transport_instance' => $transport,
    'auto_capture' => false,
    'random' => static function (): float {
        return 0.0;
    },
    'before_send' => static function (array $event): array {
        unset($event['user']);
        return $event;
    },
]);
$eventId = $client->captureException(new RuntimeException('boom'), [
    'tags' => ['service' => 'api'],
    'user' => ['id' => 'must-not-leave'],
]);
expect(is_string($eventId), 'captureException should return an event id');
expect($client->flush(), 'recording transport should accept the envelope');
expect(count($transport->envelopes) === 1, 'one envelope should be sent');
$item = $transport->envelopes[0]['items'][0];
expect($item['platform'] === 'php', 'event platform should be php');
expect($item['release'] === 'abc123', 'release should be included');
expect($item['tags']['service'] === 'api', 'safe tags should be included');
expect(!isset($item['user']), 'before_send should be able to remove PII');
expect($item['exception']['values'][0]['value'] === 'boom', 'Throwable should be normalized');

$client->handleError(E_USER_NOTICE, 'notice', __FILE__, __LINE__);
expect(count($client->queuedEvents()) === 1, 'notices should be captured');
expect($client->queuedEvents()[0]['level'] === 'warning', 'notices use warning level');
$chained = false;
$chainedErrorArguments = [];
set_exception_handler(static function (Throwable $exception) use (&$chained): void {
    unset($exception);
    $chained = true;
});
set_error_handler(static function (...$arguments) use (&$chainedErrorArguments): bool {
    $chainedErrorArguments = $arguments;

    return true;
});
$automaticTransport = new RecordingTransport();
$automaticClient = new Client([
    'dsn' => 'https://secret@ingest.example.test/1',
    'environment' => 'test',
    'transport_instance' => $automaticTransport,
]);
$automaticClient->handleException(new RuntimeException('unhandled'));
expect(count($automaticClient->queuedEvents()) === 1, 'unhandled exceptions should be captured');
expect(
    $automaticClient->queuedEvents()[0]['exception']['values'][0]['mechanism']['handled'] === false,
    'unhandled exceptions should be marked unhandled'
);
expect($chained, 'the previous exception handler should be chained');
$automaticClient->handleError(
    E_USER_NOTICE,
    'legacy context',
    __FILE__,
    __LINE__,
    ['legacy' => true]
);
expect(count($chainedErrorArguments) === 5, 'legacy error-handler arguments should be chained');
expect(
    $chainedErrorArguments[4] === ['legacy' => true],
    'legacy error-handler context should not be discarded'
);
restore_error_handler();
restore_error_handler();
restore_exception_handler();
restore_exception_handler();

$failing = new RecordingTransport();
$failing->throw = true;
$client = new Client([
    'dsn' => 'https://secret@ingest.example.test/1',
    'environment' => 'test',
    'transport_instance' => $failing,
    'auto_capture' => false,
]);
$client->captureMessage('transport recursion guard');
expect(!$client->flush(), 'transport exceptions should become a failed flush');
expect(count($client->queuedEvents()) === 1, 'failed events should remain queued');

$spoolDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'monica-test-' . bin2hex(random_bytes(5));
$spool = new SpoolTransport($spoolDirectory, 10);
$envelope = [
    'sdk' => ['name' => 'test', 'version' => '1'],
    'sent_at' => '2026-08-30T00:00:00.000Z',
    'discarded' => 0,
    'items' => [],
];
expect($spool->send($envelope, 2000), 'spool transport should write an envelope');
$spooledFiles = glob($spoolDirectory . '/*.json') ?: [];
expect(count($spooledFiles) === 1, 'one spool file should exist');
$staleClaim = $spoolDirectory . DIRECTORY_SEPARATOR . '.sending-999-' . basename($spooledFiles[0]);
expect(rename($spooledFiles[0], $staleClaim), 'the test should simulate a claimed spool file');
expect(touch($staleClaim, time() - 120), 'the test should make the claim stale');
$receiver = new RecordingTransport();
$result = (new SpoolFlusher($spoolDirectory, $receiver, 60))->flush();
expect($result === ['sent' => 1, 'failed' => 0, 'invalid' => 0], 'spool should flush');
expect(count($receiver->envelopes) === 1, 'a stale spool claim should be recovered and sent');
expect(count(glob($spoolDirectory . '/*.json') ?: []) === 0, 'sent spool file should be removed');
@rmdir($spoolDirectory);

if (interface_exists(ClientInterface::class) && class_exists(Psr17Factory::class)) {
    $factory = new Psr17Factory();
    $psrClient = new class($factory) implements ClientInterface {
        /** @var Psr17Factory */
        private $factory;
        /** @var RequestInterface|null */
        public $request;

        public function __construct(Psr17Factory $factory)
        {
            $this->factory = $factory;
            $this->request = null;
        }

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->request = $request;

            return $this->factory->createResponse(202);
        }
    };
    $psrTransportClient = new Client([
        'dsn' => 'https://secret%20key@ingest.example.test/1',
        'environment' => 'test',
        'http_client' => $psrClient,
        'request_factory' => $factory,
        'stream_factory' => $factory,
        'auto_capture' => false,
    ]);
    $psrTransportClient->captureMessage('PSR-18 transport');
    expect($psrTransportClient->flush(), 'PSR-18 transport should accept a 202 response');
    expect($psrClient->request instanceof RequestInterface, 'PSR-18 client should receive a request');
    expect(
        $psrClient->request->getUri()->__toString() === 'https://ingest.example.test/v1/envelope',
        'PSR-18 transport should use the ingest endpoint'
    );
    expect(
        $psrClient->request->getHeaderLine('Authorization') === 'Bearer secret key',
        'PSR-18 transport should decode and send the DSN secret'
    );
    $decodedBody = gzdecode((string) $psrClient->request->getBody());
    $decodedEnvelope = $decodedBody === false ? null : json_decode($decodedBody, true);
    expect(
        is_array($decodedEnvelope) && $decodedEnvelope['items'][0]['message'] === 'PSR-18 transport',
        'PSR-18 transport should send a gzipped MONICA envelope'
    );
}

echo "MONICA PHP SDK tests passed\n";
