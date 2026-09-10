<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Monica\Client;
use Monica\Transport\CurlTransport;
use Monica\Transport\Diagnostics;
use Monica\Transport\EnvelopeSplitter;
use Monica\Transport\Outcome;
use Monica\Transport\OutcomeAwareInterface;
use Monica\Transport\Psr18Transport;
use Monica\Transport\Response;
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

/**
 * A transport that answers with the outcome of each call in turn, so the spool
 * flusher can be driven through every branch of transport.json's status table.
 */
final class OutcomeTransport implements TransportInterface, OutcomeAwareInterface
{
    /** @var list<array<string, mixed>> */
    public array $envelopes = [];
    /** @var list<string> */
    private array $outcomes;

    /** @param list<string> $outcomes */
    public function __construct(array $outcomes)
    {
        $this->outcomes = $outcomes;
    }

    public function send(array $envelope, int $timeoutMilliseconds): bool
    {
        return $this->sendEnvelope($envelope, $timeoutMilliseconds) === Outcome::ACCEPTED;
    }

    public function sendEnvelope(array $envelope, int $timeoutMilliseconds): string
    {
        $this->envelopes[] = $envelope;
        $outcome = array_shift($this->outcomes);

        return $outcome === null ? Outcome::ACCEPTED : $outcome;
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

$publicKeyRejected = false;
try {
    Dsn::parse('https://mpk_public@ingest.example.test/1');
} catch (InvalidArgumentException $ignored) {
    $publicKeyRejected = true;
}
expect($publicKeyRejected, 'a public key DSN should be rejected instead of 401ing at runtime');

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
expect(
    $result === ['sent' => 1, 'failed' => 0, 'rejected' => 0, 'invalid' => 0],
    'spool should flush'
);
expect(count($receiver->envelopes) === 1, 'a stale spool claim should be recovered and sent');
expect(count(glob($spoolDirectory . '/*.json') ?: []) === 0, 'sent spool file should be removed');
@rmdir($spoolDirectory);

$spoolWith = static function (int $count) use ($envelope): string {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'monica-test-' . bin2hex(random_bytes(5));
    $transport = new SpoolTransport($directory, 10);
    for ($index = 0; $index < $count; $index++) {
        expect($transport->send($envelope, 2000), 'the test should spool an envelope');
    }

    return $directory;
};
$discardSpool = static function (string $directory): void {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($directory);
};

// A permanent rejection must not hold up the envelopes behind it. Keeping a
// doomed envelope in the spool used to stall every later one until the spool
// filled up and pruned it.
$rejectDirectory = $spoolWith(2);
$rejecting = new OutcomeTransport([Outcome::REJECTED]);
$rejectResult = (new SpoolFlusher($rejectDirectory, $rejecting, 60))->flush();
expect(
    $rejectResult === ['sent' => 1, 'failed' => 0, 'rejected' => 1, 'invalid' => 0],
    'a rejected envelope should be counted and the run should continue: '
    . json_encode($rejectResult)
);
expect(count($rejecting->envelopes) === 2, 'the envelope behind a rejected one should still be sent');
expect(count(glob($rejectDirectory . '/*.json') ?: []) === 0, 'neither envelope should stay in the spool');
expect(
    count(glob($rejectDirectory . '/.sending-*.rejected') ?: []) === 1,
    'a rejected envelope should be kept aside rather than deleted'
);
$discardSpool($rejectDirectory);

// 429, 5xx and network failures are the opposite case: the envelope stays put
// and the run stops, because whatever failed applies to the rest of it too.
$retryDirectory = $spoolWith(2);
$retrying = new OutcomeTransport([Outcome::RETRYABLE]);
$retryResult = (new SpoolFlusher($retryDirectory, $retrying, 60))->flush();
expect(
    $retryResult === ['sent' => 0, 'failed' => 1, 'rejected' => 0, 'invalid' => 0],
    'a retryable failure should be counted as failed: ' . json_encode($retryResult)
);
expect(count($retrying->envelopes) === 1, 'a retryable failure should stop the run');
expect(
    count(glob($retryDirectory . '/*.json') ?: []) === 2,
    'a retryable envelope should stay in the spool for the next run'
);
$discardSpool($retryDirectory);

// A refused key drops the envelope in hand and stops the run, but leaves the
// rest of the spool alone: the key may be fixed before the next one.
$stopDirectory = $spoolWith(2);
$stopping = new OutcomeTransport([Outcome::REJECTED_STOP]);
$stopResult = (new SpoolFlusher($stopDirectory, $stopping, 60))->flush();
expect(
    $stopResult === ['sent' => 0, 'failed' => 0, 'rejected' => 1, 'invalid' => 0],
    'a refused key should be counted as rejected: ' . json_encode($stopResult)
);
expect(count($stopping->envelopes) === 1, 'a refused key should stop the run');
expect(
    count(glob($stopDirectory . '/*.json') ?: []) === 1,
    'the envelope behind a refused key should stay in the spool'
);
$discardSpool($stopDirectory);

// spool_max_files caps the directory, not just the envelopes waiting in it. The
// flusher retires what it cannot deliver by renaming it aside, and those names
// do not match *.json: counting only pending envelopes let the directory grow
// without bound while reporting itself as capped.
$capDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'monica-test-' . bin2hex(random_bytes(5));
$capTransport = new SpoolTransport($capDirectory, 4);
$countFiles = static function (string $directory): int {
    $found = 0;
    foreach (glob($directory . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $path) {
        if (is_file($path)) {
            $found++;
        }
    }

    return $found;
};
for ($round = 0; $round < 3; $round++) {
    for ($index = 0; $index < 4; $index++) {
        expect($capTransport->send($envelope, 2000), 'the test should spool an envelope');
    }
    // Every envelope is refused, so each round turns four pending files into
    // four retired ones. Before the cap counted them, this grew by four a round.
    (new SpoolFlusher($capDirectory, new OutcomeTransport(array_fill(0, 4, Outcome::REJECTED)), 60))->flush();
    expect(
        $countFiles($capDirectory) <= 4,
        'round ' . $round . ': the spool directory should stay within spool_max_files, found '
        . $countFiles($capDirectory)
    );
}

// Pending envelopes outlive retired ones: a retired file cannot be delivered
// any more, so it is the cheaper thing to lose when the cap is reached. The
// rounds above left the directory full of retired files, so the four written
// here can only fit if pruning takes the retired ones first.
expect(
    count(glob($capDirectory . DIRECTORY_SEPARATOR . '.sending-*.rejected') ?: []) > 0,
    'the rounds above should have left retired files behind'
);
for ($index = 0; $index < 4; $index++) {
    expect($capTransport->send($envelope, 2000), 'the test should spool an envelope');
}
expect(
    count(glob($capDirectory . DIRECTORY_SEPARATOR . '*.json') ?: []) === 4,
    'pruning should keep every envelope that can still be sent'
);
expect(
    count(glob($capDirectory . DIRECTORY_SEPARATOR . '.sending-*.rejected') ?: []) === 0,
    'pruning should drop retired files before pending ones'
);
$discardSpool($capDirectory);

// A .tmp file is how an envelope is written before the atomic rename. Pruning
// must not delete one that another process is still writing, even though its
// name sorts among the oldest.
$temporaryDirectory = $spoolWith(4);
$freshTemporary = $temporaryDirectory . DIRECTORY_SEPARATOR . '.19700101000000-1-fresh.json.tmp';
expect(file_put_contents($freshTemporary, '{}') !== false, 'the test should create a temporary file');
$abandonedTemporary = $temporaryDirectory . DIRECTORY_SEPARATOR . '.19700101000001-1-abandoned.json.tmp';
expect(file_put_contents($abandonedTemporary, '{}') !== false, 'the test should create a temporary file');
expect(touch($abandonedTemporary, time() - 3600), 'the test should age the abandoned temporary file');
for ($index = 0; $index < 8; $index++) {
    expect((new SpoolTransport($temporaryDirectory, 4))->send($envelope, 2000), 'the test should spool');
}
expect(is_file($freshTemporary), 'a temporary file still being written must survive pruning');
expect(!is_file($abandonedTemporary), 'a temporary file left by a dead process should be pruned');
@unlink($freshTemporary);
$discardSpool($temporaryDirectory);

// A transport from outside the SDK only answers yes or no, and a no has to keep
// meaning "try again later".
$legacyDirectory = $spoolWith(1);
$legacyReceiver = new RecordingTransport();
$legacyReceiver->accepted = false;
$legacyResult = (new SpoolFlusher($legacyDirectory, $legacyReceiver, 60))->flush();
expect(
    $legacyResult === ['sent' => 0, 'failed' => 1, 'rejected' => 0, 'invalid' => 0],
    'a bool-only transport saying no should still mean retryable: ' . json_encode($legacyResult)
);
expect(count(glob($legacyDirectory . '/*.json') ?: []) === 1, 'the envelope should stay in the spool');
$discardSpool($legacyDirectory);

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

// --- 422: reading error.json instead of losing it ---------------------------
//
// A 422 says the envelope's shape was refused and `issues[].path` says which
// field. That is the one rejection the application can fix, so what is checked
// here is that it arrives: in the warning, and in the response the caller can
// read. Everything else about a rejection stays as it was -- still dropped,
// still no exception, whatever the body turns out to contain.

$rejectionBody = '{"error":{"code":"invalid_envelope",'
    . '"message":"The envelope does not match the MONICA schema",'
    . '"issues":[{"path":"$.items[0].request.method","message":"Invalid type: Expected string"}]}}';
$reportedIssue = ['path' => '$.items[0].request.method', 'message' => 'Invalid type: Expected string'];

/**
 * Every case is asserted against both transports, because "reads the body" is
 * a property of each transport separately: one has a PSR-7 stream, the other a
 * cURL handle.
 *
 * `warnings` is how many times the reporting handler should be called, so a
 * status that must stay silent says so as loudly as one that must warn.
 */
$diagnosticCases = [
    [
        'label' => '422 carrying issues',
        'status' => 422,
        'body' => $rejectionBody,
        'outcome' => Outcome::REJECTED,
        'code' => 'invalid_envelope',
        'message' => 'The envelope does not match the MONICA schema',
        'issues' => [$reportedIssue],
        'warnings' => 1,
        'contains' => [
            'monica: ingest rejected the envelope with 422 (invalid_envelope): 1 issue(s)'
            . '; $.items[0].request.method: Invalid type: Expected string',
        ],
    ],
    [
        'label' => '422 without issues',
        'status' => 422,
        'body' => '{"error":{"code":"invalid_envelope","message":"no field to point at"}}',
        'outcome' => Outcome::REJECTED,
        'code' => 'invalid_envelope',
        'message' => 'no field to point at',
        'issues' => [],
        'warnings' => 1,
        'contains' => ['monica: ingest rejected the envelope with 422 (invalid_envelope): 0 issue(s)'],
    ],
    [
        'label' => '422 with an empty body',
        'status' => 422,
        'body' => '',
        'outcome' => Outcome::REJECTED,
        'code' => null,
        'message' => null,
        'issues' => [],
        'warnings' => 1,
        'contains' => ['monica: ingest rejected the envelope with 422 (unknown): 0 issue(s)'],
    ],
    [
        'label' => '422 with a body that is not JSON',
        'status' => 422,
        'body' => '<html>502 Bad Gateway</html>',
        'outcome' => Outcome::REJECTED,
        'code' => null,
        'message' => null,
        'issues' => [],
        'warnings' => 1,
        'contains' => ['0 issue(s)'],
    ],
    [
        'label' => '422 with a body that does not fit error.json',
        'status' => 422,
        'body' => '{"error":["not","an","object"],"detail":"something else entirely"}',
        'outcome' => Outcome::REJECTED,
        'code' => null,
        'message' => null,
        'issues' => [],
        'warnings' => 1,
        'contains' => ['0 issue(s)'],
    ],
    [
        'label' => '422 whose issues are not issues',
        'status' => 422,
        'body' => '{"error":{"code":"invalid_envelope","message":"m","issues":['
            . '{"path":17,"message":"a path must be a string"},'
            . '{"message":"no path at all"},'
            . '"not an object",'
            . '{"path":"$.items[0].timestamp","message":"kept"}]}}',
        'outcome' => Outcome::REJECTED,
        'code' => 'invalid_envelope',
        'message' => 'm',
        'issues' => [['path' => '$.items[0].timestamp', 'message' => 'kept']],
        'warnings' => 1,
        'contains' => ['1 issue(s); $.items[0].timestamp: kept'],
    ],
    [
        'label' => '422 with a body past the read limit',
        'status' => 422,
        // 64 KiB of valid error.json is still 64 KiB. A body that does not fit
        // is dropped whole rather than parsed from a prefix.
        'body' => '{"error":{"code":"invalid_envelope","message":"' . str_repeat('x', 4096) . '"}}',
        'repeat' => 20,
        'outcome' => Outcome::REJECTED,
        'code' => null,
        'message' => null,
        'issues' => [],
        'warnings' => 1,
        'contains' => ['0 issue(s)'],
    ],
    [
        // 400 is a rejection too, so its body is read -- but it is not the
        // envelope's shape, so there is nothing for the application to fix and
        // nothing to warn about.
        'label' => '400',
        'status' => 400,
        'body' => $rejectionBody,
        'outcome' => Outcome::REJECTED,
        'code' => 'invalid_envelope',
        'message' => 'The envelope does not match the MONICA schema',
        'issues' => [$reportedIssue],
        'warnings' => 0,
        'contains' => [],
    ],
    [
        // A 401 loses more than the envelope in hand: the key is refused, so
        // the run stops. That is worth one line, or the silence that follows
        // looks like MONICA having gone quiet.
        'label' => '401',
        'status' => 401,
        'body' => '{"error":{"code":"invalid_key","message":"the key is not accepted"}}',
        'outcome' => Outcome::REJECTED_STOP,
        'code' => 'invalid_key',
        'message' => 'the key is not accepted',
        'issues' => [],
        'warnings' => 1,
        'contains' => [
            'monica: ingest rejected the envelope with 401 (invalid_key);'
            . ' no further envelopes will be sent',
        ],
    ],
    [
        'label' => '401 with an unreadable body',
        'status' => 401,
        'body' => 'gateway says no',
        'outcome' => Outcome::REJECTED_STOP,
        'code' => null,
        'message' => null,
        'issues' => [],
        'warnings' => 1,
        'contains' => [
            'monica: ingest rejected the envelope with 401 (unknown);'
            . ' no further envelopes will be sent',
        ],
    ],
    [
        // 429 is a wait, not a rejection: nothing in the body would change what
        // the SDK does, so it is not read at all.
        'label' => '429',
        'status' => 429,
        'body' => $rejectionBody,
        'outcome' => Outcome::RETRYABLE,
        'code' => null,
        'message' => null,
        'issues' => [],
        'warnings' => 0,
        'contains' => [],
    ],
    [
        'label' => '503',
        'status' => 503,
        'body' => $rejectionBody,
        'outcome' => Outcome::RETRYABLE,
        'code' => null,
        'message' => null,
        'issues' => [],
        'warnings' => 0,
        'contains' => [],
    ],
    [
        'label' => '202',
        'status' => 202,
        'body' => '',
        'outcome' => Outcome::ACCEPTED,
        'code' => null,
        'message' => null,
        'issues' => [],
        'warnings' => 0,
        'contains' => [],
    ],
];

/**
 * @param array<string, mixed> $case
 * @param list<string> $warnings
 */
$assertCase = static function (
    string $transportName,
    array $case,
    Response $response,
    array $warnings
): void {
    $where = $transportName . ' / ' . $case['label'] . ': ';
    expect($response->status() === $case['status'], $where . 'the status should be reported');
    expect(
        $response->outcome() === $case['outcome'],
        $where . 'expected outcome ' . $case['outcome'] . ', got ' . $response->outcome()
    );
    expect($response->errorCode() === $case['code'], $where . 'unexpected error code');
    expect($response->errorMessage() === $case['message'], $where . 'unexpected error message');
    expect(
        $response->issues() === $case['issues'],
        $where . 'unexpected issues: ' . json_encode($response->issues())
    );
    expect(
        count($warnings) === $case['warnings'],
        $where . 'expected ' . $case['warnings'] . ' warning(s), got ' . count($warnings)
        . ': ' . json_encode($warnings)
    );
    foreach ($case['contains'] as $fragment) {
        expect(
            strpos(implode(PHP_EOL, $warnings), $fragment) !== false,
            $where . 'the warning should contain ' . $fragment . ', got: ' . json_encode($warnings)
        );
    }
};

$rejectionEnvelope = [
    'sdk' => ['name' => 'test', 'version' => '1'],
    'sent_at' => '2026-08-30T00:00:00.000Z',
    'discarded' => 0,
    'items' => [],
];

if (interface_exists(ClientInterface::class) && class_exists(Psr17Factory::class)) {
    $factory = new Psr17Factory();
    $stubClient = new class($factory) implements ClientInterface {
        /** @var Psr17Factory */
        private $factory;
        public int $status = 202;
        public string $body = '';
        public int $calls = 0;

        public function __construct(Psr17Factory $factory)
        {
            $this->factory = $factory;
        }

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            unset($request);
            $this->calls++;
            $response = $this->factory->createResponse($this->status);
            if ($this->body === '') {
                return $response;
            }

            return $response->withBody($this->factory->createStream($this->body));
        }
    };

    foreach ($diagnosticCases as $case) {
        $stubClient->status = $case['status'];
        $stubClient->body = str_repeat($case['body'], $case['repeat'] ?? 1);
        $warnings = [];
        $transport = new Psr18Transport(
            'https://secret@ingest.example.test/1',
            $stubClient,
            $factory,
            $factory,
            new Diagnostics(static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            })
        );
        $assertCase('PSR-18', $case, $transport->sendEnvelopeResponse($rejectionEnvelope, 2000), $warnings);
    }

    // The same rejection as the client sees it: flush() still answers no and
    // the events stay queued, but the reason is now readable.
    $clientWarnings = [];
    $clientResponses = [];
    $rejectingClient = new Client([
        'dsn' => 'https://secret@ingest.example.test/1',
        'environment' => 'test',
        'http_client' => $stubClient,
        'request_factory' => $factory,
        'stream_factory' => $factory,
        'auto_capture' => false,
        'on_diagnostic' => static function (string $message, Response $response) use (&$clientWarnings, &$clientResponses): void {
            $clientWarnings[] = $message;
            $clientResponses[] = $response;
        },
    ]);
    $stubClient->status = 422;
    $stubClient->body = $rejectionBody;
    $rejectingClient->captureMessage('rejected by ingest');
    expect(!$rejectingClient->flush(), 'a 422 should still make flush() answer no');
    expect(count($rejectingClient->queuedEvents()) === 1, 'a rejected envelope should stay queued as before');
    expect(count($clientWarnings) === 1, 'one envelope should warn once, got ' . count($clientWarnings));
    $clientResponse = $rejectingClient->lastResponse();
    expect($clientResponse instanceof Response, 'the client should expose the last response');
    expect($clientResponse->status() === 422, 'the client should expose the rejected status');
    expect(
        $clientResponse->issues() === [$reportedIssue],
        'the client should expose the issues: ' . json_encode($clientResponse->issues())
    );
    expect(
        $clientResponses !== [] && $clientResponses[0]->issues() === [$reportedIssue],
        'the reporting handler should receive the response as well as the message'
    );

    // Without an on_diagnostic, the warning goes where PHP puts things the
    // application did not ask about.
    $logFile = (string) tempnam(sys_get_temp_dir(), 'monica-log-');
    $previousErrorLog = ini_get('error_log');
    ini_set('error_log', $logFile);
    $defaultClient = new Client([
        'dsn' => 'https://secret@ingest.example.test/1',
        'environment' => 'test',
        'http_client' => $stubClient,
        'request_factory' => $factory,
        'stream_factory' => $factory,
        'auto_capture' => false,
    ]);
    $defaultClient->captureMessage('rejected by ingest');
    $defaultClient->flush();
    // ... and it can be turned off, without that being the default.
    $silentClient = new Client([
        'dsn' => 'https://secret@ingest.example.test/1',
        'environment' => 'test',
        'http_client' => $stubClient,
        'request_factory' => $factory,
        'stream_factory' => $factory,
        'auto_capture' => false,
        'on_diagnostic' => false,
    ]);
    $silentClient->captureMessage('rejected by ingest');
    $silentClient->flush();
    ini_set('error_log', $previousErrorLog === false ? '' : (string) $previousErrorLog);
    $logged = (string) file_get_contents($logFile);
    @unlink($logFile);
    expect(
        strpos($logged, '$.items[0].request.method: Invalid type: Expected string') !== false,
        'the default destination for a 422 warning should be error_log(): ' . $logged
    );
    expect(
        substr_count($logged, 'monica: ingest rejected') === 1,
        'on_diagnostic => false should silence the warning: ' . $logged
    );

    // The handler is application code. A rejection that was reported badly is
    // still a rejection -- not a network failure, and not an exception in the
    // handler the SDK is installed in.
    $throwingTransport = new Psr18Transport(
        'https://secret@ingest.example.test/1',
        $stubClient,
        $factory,
        $factory,
        new Diagnostics(static function (): void {
            throw new RuntimeException('the reporting handler is broken');
        })
    );
    expect(
        $throwingTransport->sendEnvelopeResponse($rejectionEnvelope, 2000)->outcome() === Outcome::REJECTED,
        'a throwing diagnostic handler should not change the outcome'
    );

    // 401 is drop_and_stop: the key is refused, so the transport stops. Without
    // the flag it kept posting the same key for every later envelope, which is
    // what makes "no further envelopes will be sent" true rather than a wish.
    $stopWarnings = [];
    $stoppingTransport = new Psr18Transport(
        'https://secret@ingest.example.test/1',
        $stubClient,
        $factory,
        $factory,
        new Diagnostics(static function (string $message) use (&$stopWarnings): void {
            $stopWarnings[] = $message;
        })
    );
    expect(!$stoppingTransport->isStopped(), 'a fresh transport should not be stopped');
    $stubClient->status = 401;
    $stubClient->body = '{"error":{"code":"invalid_key","message":"no"}}';
    $stubClient->calls = 0;
    expect(
        $stoppingTransport->sendEnvelopeResponse($rejectionEnvelope, 2000)->outcome()
        === Outcome::REJECTED_STOP,
        'a 401 should be reported as REJECTED_STOP'
    );
    expect($stoppingTransport->isStopped(), 'a 401 should stop the transport');
    // Whatever MONICA would answer now, the transport is not going to ask.
    $stubClient->status = 202;
    $stubClient->body = '';
    $afterStop = $stoppingTransport->sendEnvelopeResponse($rejectionEnvelope, 2000);
    expect($stubClient->calls === 1, 'a stopped transport should not send a request');
    expect(
        $afterStop->outcome() === Outcome::REJECTED_STOP && $afterStop->status() === 401,
        'a stopped transport should keep answering 401 / REJECTED_STOP'
    );
    expect(
        $afterStop->issues() === []
        && $afterStop->errorCode() === null
        && $afterStop->errorMessage() === null,
        'a short-circuited 401 is synthesised without asking MONICA, so it has no body: '
        . 'no error code, no message, no issues'
    );
    expect(
        count($stopWarnings) === 1,
        'the 401 should be reported once, not once per dropped envelope: '
        . json_encode($stopWarnings)
    );
    expect(!$stoppingTransport->send($rejectionEnvelope, 2000), 'send() should stay no');
    expect($stubClient->calls === 1, 'send() on a stopped transport should not request either');

    // ... and the client says so, instead of looking like MONICA went quiet.
    $stubClient->status = 401;
    $stubClient->body = '{"error":{"code":"invalid_key","message":"no"}}';
    $stoppedClient = new Client([
        'dsn' => 'https://secret@ingest.example.test/1',
        'environment' => 'test',
        'http_client' => $stubClient,
        'request_factory' => $factory,
        'stream_factory' => $factory,
        'auto_capture' => false,
        'on_diagnostic' => false,
    ]);
    expect(!$stoppedClient->isStopped(), 'a fresh client should not be stopped');
    $stoppedClient->captureMessage('refused key');
    expect(!$stoppedClient->flush(), 'a 401 should make flush() answer no');
    expect($stoppedClient->isStopped(), 'a 401 should stop the client');
    $stubClient->calls = 0;
    $stubClient->status = 202;
    $stubClient->body = '';
    expect(!$stoppedClient->flush(), 'a stopped client should keep answering no');
    expect($stubClient->calls === 0, 'a stopped client should not reach MONICA again');
    expect(
        $stoppedClient->lastResponse() !== null && $stoppedClient->lastResponse()->status() === 401,
        'the last response should still say why the client stopped'
    );

    // The spool path reaches ingest through the same transport, so a rejection
    // warns there too -- and the flusher exposes the response it acted on.
    $stubClient->status = 422;
    $stubClient->body = $rejectionBody;
    $spoolRejectDirectory = $spoolWith(1);
    $spoolWarnings = [];
    $spoolFlusher = new SpoolFlusher(
        $spoolRejectDirectory,
        new Psr18Transport(
            'https://secret@ingest.example.test/1',
            $stubClient,
            $factory,
            $factory,
            new Diagnostics(static function (string $message) use (&$spoolWarnings): void {
                $spoolWarnings[] = $message;
            })
        ),
        60
    );
    $spoolRejectResult = $spoolFlusher->flush();
    expect(
        $spoolRejectResult === ['sent' => 0, 'failed' => 0, 'rejected' => 1, 'invalid' => 0],
        'a 422 should still retire the spooled envelope: ' . json_encode($spoolRejectResult)
    );
    expect(
        count($spoolWarnings) === 1
        && strpos($spoolWarnings[0], '$.items[0].request.method') !== false,
        'the spool path should warn about a 422 as well: ' . json_encode($spoolWarnings)
    );
    expect(
        $spoolFlusher->lastResponse() !== null
        && $spoolFlusher->lastResponse()->issues() === [$reportedIssue],
        'the flusher should expose the issues of the envelope it retired'
    );
    $discardSpool($spoolRejectDirectory);
}

// --- 413: splitting an envelope on the byte limits -------------------------
//
// ingest.md caps an envelope at 1 MiB gzipped, and says the SDK splits what
// does not fit on item boundaries. Batching by item count cannot see that: a
// hundred small items fit easily, a hundred large ones do not.

if (interface_exists(ClientInterface::class) && class_exists(Psr17Factory::class)) {
    $factory = new Psr17Factory();

    /**
     * A client that records the envelope of every request, and can answer 413
     * for anything above a given item count. Bodies arrive gzipped, so the
     * recorded envelopes are what the transport really sent.
     */
    $splitClient = new class($factory) implements ClientInterface {
        /** @var Psr17Factory */
        private $factory;
        /** @var list<array<string, mixed>> */
        public array $envelopes = [];
        /** @var list<int> */
        public array $gzipBytes = [];
        public int $status = 202;
        public ?int $maxItems = null;

        public function __construct(Psr17Factory $factory)
        {
            $this->factory = $factory;
        }

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $raw = (string) $request->getBody();
            $json = gzdecode($raw);
            expect($json !== false, 'the request body should always be gzipped');
            $envelope = json_decode((string) $json, true);
            expect(is_array($envelope), 'the request body should decode to an envelope');
            $this->envelopes[] = $envelope;
            $this->gzipBytes[] = strlen($raw);
            if ($this->maxItems !== null && count($envelope['items']) > $this->maxItems) {
                return $this->factory->createResponse(413);
            }

            return $this->factory->createResponse($this->status);
        }
    };

    $itemsOf = static function (array $envelopes): array {
        $messages = [];
        foreach ($envelopes as $envelope) {
            foreach ($envelope['items'] as $item) {
                $messages[] = $item['message'];
            }
        }

        return $messages;
    };

    $bigEnvelope = static function (int $itemCount, int $itemBytes) use ($rejectionEnvelope): array {
        $envelope = $rejectionEnvelope;
        $envelope['discarded'] = 7;
        $envelope['items'] = [];
        for ($index = 0; $index < $itemCount; $index++) {
            $envelope['items'][] = [
                'type' => 'message',
                'message' => 'item ' . $index,
                // Base64 of random bytes does not compress, so the envelope is
                // as large after gzip as it looks. Lorem ipsum would not be.
                'padding' => base64_encode(random_bytes($itemBytes)),
            ];
        }

        return $envelope;
    };

    // Measured by the SDK: four items of ~400 KB are over the 1 MiB cap
    // together and under it in halves.
    $splitTransport = new Psr18Transport(
        'https://secret@ingest.example.test/1',
        $splitClient,
        $factory,
        $factory
    );
    $oversized = $bigEnvelope(4, 400 * 1024);
    $splitResponse = $splitTransport->sendEnvelopeResponse($oversized, 2000);
    expect($splitResponse->outcome() === Outcome::ACCEPTED, 'a split envelope should be accepted');
    expect($splitResponse->droppedItems() === 0, 'nothing should be dropped when halving works');
    expect(
        count($splitClient->envelopes) > 1,
        'an envelope over the gzip limit should be split across requests, got '
        . count($splitClient->envelopes)
    );
    foreach ($splitClient->gzipBytes as $position => $bytes) {
        expect(
            $bytes <= EnvelopeSplitter::MAX_GZIP_BYTES,
            'request ' . $position . ' carries ' . $bytes . ' gzip bytes, over the published limit'
        );
    }
    expect(
        $itemsOf($splitClient->envelopes) === ['item 0', 'item 1', 'item 2', 'item 3'],
        'splitting should keep every item, in order: '
        . json_encode($itemsOf($splitClient->envelopes))
    );
    $reportedDiscarded = array_sum(array_map(
        static function (array $envelope): int {
            return (int) $envelope['discarded'];
        },
        $splitClient->envelopes
    ));
    expect(
        $reportedDiscarded === 7,
        'discarded belongs to the envelope, not to each half: reported ' . $reportedDiscarded
    );

    // MONICA's cap is its own: limits.json is a copy, so a 413 on something the
    // SDK measured as fitting still has to be split and posted again.
    $splitClient->envelopes = [];
    $splitClient->gzipBytes = [];
    $splitClient->maxItems = 1;
    $small = $rejectionEnvelope;
    $small['items'] = [
        ['type' => 'message', 'message' => 'a'],
        ['type' => 'message', 'message' => 'b'],
        ['type' => 'message', 'message' => 'c'],
        ['type' => 'message', 'message' => 'd'],
    ];
    $retriedResponse = $splitTransport->sendEnvelopeResponse($small, 2000);
    expect(
        $retriedResponse->outcome() === Outcome::ACCEPTED,
        'a 413 should be answered by splitting, not by dropping the envelope'
    );
    expect($retriedResponse->droppedItems() === 0, 'four items that fit one by one should all be sent');
    $accepted = array_values(array_filter(
        $splitClient->envelopes,
        static function (array $envelope): bool {
            return count($envelope['items']) === 1;
        }
    ));
    expect(count($accepted) === 4, 'each item should end up in its own envelope, got ' . count($accepted));
    expect(
        $itemsOf($accepted) === ['a', 'b', 'c', 'd'],
        'a 413-driven split should keep every item, in order: ' . json_encode($itemsOf($accepted))
    );

    // A single item that ingest still refuses cannot be split any further.
    // Posting it forever would stall everything behind it, so it is dropped --
    // loudly, and counted in the next envelope's discarded.
    $splitClient->envelopes = [];
    $splitClient->maxItems = 0;
    $dropWarnings = [];
    $droppingTransport = new Psr18Transport(
        'https://secret@ingest.example.test/1',
        $splitClient,
        $factory,
        $factory,
        new Diagnostics(static function (string $message) use (&$dropWarnings): void {
            $dropWarnings[] = $message;
        })
    );
    $singleItem = $rejectionEnvelope;
    $singleItem['items'] = [['type' => 'message', 'message' => 'too big to send']];
    $droppedResponse = $droppingTransport->sendEnvelopeResponse($singleItem, 2000);
    expect(
        $droppedResponse->outcome() === Outcome::ACCEPTED,
        'an unsplittable item counts as handled: retrying it can never work'
    );
    expect(
        $droppedResponse->droppedItems() === 1,
        'the dropped item should be counted: ' . $droppedResponse->droppedItems()
    );
    expect(
        count($dropWarnings) === 1 && strpos($dropWarnings[0], 'monica: dropped 1 item(s)') === 0,
        'dropping an item should be reported: ' . json_encode($dropWarnings)
    );
    expect(
        strpos($dropWarnings[0], 'ingest answered 413') !== false,
        'the warning should say who refused it: ' . $dropWarnings[0]
    );

    // ... and the client tells MONICA about it in the next envelope.
    $splitClient->envelopes = [];
    $splitClient->maxItems = 0;
    $droppingClient = new Client([
        'dsn' => 'https://secret@ingest.example.test/1',
        'environment' => 'test',
        'http_client' => $splitClient,
        'request_factory' => $factory,
        'stream_factory' => $factory,
        'auto_capture' => false,
        'on_diagnostic' => false,
    ]);
    $droppingClient->captureMessage('too big to send');
    expect($droppingClient->flush(), 'an envelope whose only item was dropped should not stay queued');
    expect($droppingClient->queuedEvents() === [], 'the dropped event should leave the queue');
    $splitClient->maxItems = null;
    $droppingClient->captureMessage('the next one');
    expect($droppingClient->flush(), 'the next envelope should be accepted');
    $lastEnvelope = $splitClient->envelopes[count($splitClient->envelopes) - 1];
    expect(
        $lastEnvelope['discarded'] === 1,
        'a dropped item should be reported as discarded in the next envelope: '
        . json_encode($lastEnvelope['discarded'])
    );

    // A single item measured as too big by the SDK never leaves at all: there
    // is no point asking MONICA about 8 MiB of JSON.
    $splitClient->envelopes = [];
    $splitClient->maxItems = null;
    $selfDropWarnings = [];
    $selfDroppingTransport = new Psr18Transport(
        'https://secret@ingest.example.test/1',
        $splitClient,
        $factory,
        $factory,
        new Diagnostics(static function (string $message) use (&$selfDropWarnings): void {
            $selfDropWarnings[] = $message;
        })
    );
    $hugeItem = $bigEnvelope(1, 2 * 1024 * 1024);
    $selfDropped = $selfDroppingTransport->sendEnvelopeResponse($hugeItem, 2000);
    expect($splitClient->envelopes === [], 'an item over the cap should not be posted at all');
    expect($selfDropped->droppedItems() === 1, 'the oversized item should be counted as dropped');
    expect(
        count($selfDropWarnings) === 1
        && strpos($selfDropWarnings[0], 'measured by the SDK') !== false,
        'the SDK should say it refused the item itself: ' . json_encode($selfDropWarnings)
    );

    // A failure part-way through stops the run: whatever refused one piece
    // refuses the rest.
    $splitClient->envelopes = [];
    $splitClient->maxItems = 1;
    $splitClient->status = 500;
    $failingSplit = $splitTransport->sendEnvelopeResponse($small, 2000);
    expect(
        $failingSplit->outcome() === Outcome::RETRYABLE,
        'a 5xx on the first piece should be reported as retryable'
    );
    expect(
        count($splitClient->envelopes) === 3,
        'the run should stop at the failing piece: ' . count($splitClient->envelopes)
    );
    $splitClient->status = 202;
    $splitClient->maxItems = null;
}

// The cURL transport cannot be handed a stubbed client: it talks to a socket,
// so the same matrix runs against PHP's built-in server. Nothing else in this
// file exercises the default transport's response handling at all.
if (function_exists('curl_init') && function_exists('proc_open')) {
    $controlFile = (string) tempnam(sys_get_temp_dir(), 'monica-stub-');
    $logFilePath = (string) tempnam(sys_get_temp_dir(), 'monica-stub-log-');
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    expect($socket !== false, 'the test should be able to reserve a port: ' . (string) $errorMessage);
    $address = (string) stream_socket_get_name($socket, false);
    $port = (int) substr($address, (int) strrpos($address, ':') + 1);
    fclose($socket);

    $server = proc_open(
        escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port
        . ' ' . escapeshellarg(__DIR__ . '/ingest-stub.php'),
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ],
        $pipes,
        null,
        ['MONICA_STUB_FILE' => $controlFile, 'MONICA_STUB_LOG' => $logFilePath]
    );
    expect(is_resource($server), 'the test should be able to start a stub ingest server');

    $ready = false;
    for ($attempt = 0; $attempt < 100 && !$ready; $attempt++) {
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $probeNumber, $probeMessage, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    expect($ready, 'the stub ingest server should start listening');

    try {
        foreach ($diagnosticCases as $case) {
            expect(
                file_put_contents($controlFile, (string) json_encode([
                    'status' => $case['status'],
                    'body' => $case['body'],
                    'repeat' => $case['repeat'] ?? 1,
                ])) !== false,
                'the test should be able to direct the stub server'
            );
            $warnings = [];
            $curlTransport = new CurlTransport(
                'http://secret@127.0.0.1:' . $port . '/1',
                new Diagnostics(static function (string $message) use (&$warnings): void {
                    $warnings[] = $message;
                })
            );
            $assertCase('cURL', $case, $curlTransport->sendEnvelopeResponse($rejectionEnvelope, 5000), $warnings);
        }
        // What arrived, not just what came back. A transport that posts an
        // empty body answers 202 exactly like one that works, so the envelope
        // itself has to be checked on the server side.
        expect(
            file_put_contents($controlFile, (string) json_encode(['status' => 202, 'body' => ''])) !== false,
            'the test should be able to direct the stub server'
        );
        expect(file_put_contents($logFilePath, '') !== false, 'the test should be able to clear the log');
        $postingCurl = new CurlTransport('http://secret@127.0.0.1:' . $port . '/1');
        $postedEnvelope = $rejectionEnvelope;
        $postedEnvelope['items'] = [
            ['type' => 'message', 'message' => 'posted by cURL'],
        ];
        expect(
            $postingCurl->sendEnvelopeResponse($postedEnvelope, 5000)->outcome() === Outcome::ACCEPTED,
            'cURL: a 202 should be accepted'
        );
        $delivered = array_values(array_filter(explode("\n", (string) file_get_contents($logFilePath))));
        expect(count($delivered) === 1, 'cURL: exactly one request should have arrived');
        $arrived = json_decode($delivered[0], true);
        expect(
            is_array($arrived) && $arrived['gzipped'] === true && $arrived['gzip_bytes'] > 0,
            'cURL: the body should arrive as gzip, not empty: ' . $delivered[0]
        );
        expect(
            is_array($arrived) && $arrived['content_encoding'] === 'gzip',
            'cURL: Content-Encoding: gzip should be declared: ' . $delivered[0]
        );
        expect(
            is_array($arrived) && $arrived['messages'] === ['posted by cURL'],
            'cURL: the envelope items should arrive intact: ' . $delivered[0]
        );

        // A 413 over a real socket: the stub refuses anything with more than
        // one item, so the transport has to halve its way down to singles.
        expect(
            file_put_contents($controlFile, (string) json_encode([
                'status' => 202,
                'body' => '',
                'max_items' => 1,
            ])) !== false,
            'the test should be able to direct the stub server'
        );
        expect(file_put_contents($logFilePath, '') !== false, 'the test should be able to clear the log');
        $curlSplit = new CurlTransport('http://secret@127.0.0.1:' . $port . '/1');
        $curlSplitEnvelope = $rejectionEnvelope;
        $curlSplitEnvelope['items'] = [
            ['type' => 'message', 'message' => 'a'],
            ['type' => 'message', 'message' => 'b'],
            ['type' => 'message', 'message' => 'c'],
            ['type' => 'message', 'message' => 'd'],
        ];
        $curlSplitResponse = $curlSplit->sendEnvelopeResponse($curlSplitEnvelope, 5000);
        expect(
            $curlSplitResponse->outcome() === Outcome::ACCEPTED,
            'cURL: a 413 should be answered by splitting'
        );
        expect($curlSplitResponse->droppedItems() === 0, 'cURL: nothing should be dropped');
        $curlRequests = array_map(
            static function (string $line) {
                return json_decode($line, true);
            },
            array_values(array_filter(explode("\n", (string) file_get_contents($logFilePath))))
        );
        $curlSingles = array_values(array_filter(
            $curlRequests,
            static function ($request): bool {
                return is_array($request) && $request['items'] === 1;
            }
        ));
        expect(
            count($curlSingles) === 4,
            'cURL: every item should end up in its own envelope, got ' . count($curlSingles)
        );
        $curlDelivered = [];
        foreach ($curlSingles as $request) {
            $curlDelivered[] = $request['messages'][0];
        }
        expect(
            $curlDelivered === ['a', 'b', 'c', 'd'],
            'cURL: a split should keep every item, in order: ' . json_encode($curlDelivered)
        );

        // ... and an item ingest refuses on its own is dropped, not posted for
        // ever.
        expect(
            file_put_contents($controlFile, (string) json_encode([
                'status' => 202,
                'body' => '',
                'max_items' => 0,
            ])) !== false,
            'the test should be able to direct the stub server'
        );
        $curlDropWarnings = [];
        $curlDropping = new CurlTransport(
            'http://secret@127.0.0.1:' . $port . '/1',
            new Diagnostics(static function (string $message) use (&$curlDropWarnings): void {
                $curlDropWarnings[] = $message;
            })
        );
        $curlSingleItem = $rejectionEnvelope;
        $curlSingleItem['items'] = [['type' => 'message', 'message' => 'too big']];
        $curlDropped = $curlDropping->sendEnvelopeResponse($curlSingleItem, 5000);
        expect(
            $curlDropped->droppedItems() === 1 && $curlDropped->outcome() === Outcome::ACCEPTED,
            'cURL: an unsplittable item should be dropped and counted'
        );
        expect(
            count($curlDropWarnings) === 1
            && strpos($curlDropWarnings[0], 'ingest answered 413') !== false,
            'cURL: dropping an item should be reported: ' . json_encode($curlDropWarnings)
        );

        // The same stop, on the transport that actually opens a socket.
        expect(
            file_put_contents($controlFile, (string) json_encode([
                'status' => 401,
                'body' => '{"error":{"code":"invalid_key","message":"no"}}',
            ])) !== false,
            'the test should be able to direct the stub server'
        );
        $curlStopWarnings = [];
        $stoppingCurl = new CurlTransport(
            'http://secret@127.0.0.1:' . $port . '/1',
            new Diagnostics(static function (string $message) use (&$curlStopWarnings): void {
                $curlStopWarnings[] = $message;
            })
        );
        expect(!$stoppingCurl->isStopped(), 'a fresh cURL transport should not be stopped');
        expect(
            $stoppingCurl->sendEnvelopeResponse($rejectionEnvelope, 5000)->outcome()
            === Outcome::REJECTED_STOP,
            'cURL: a 401 should be reported as REJECTED_STOP'
        );
        expect($stoppingCurl->isStopped(), 'cURL: a 401 should stop the transport');
        // The stub server would answer 202 now, so a 401 can only come from the
        // transport refusing to ask.
        expect(
            file_put_contents($controlFile, (string) json_encode(['status' => 202, 'body' => ''])) !== false,
            'the test should be able to direct the stub server'
        );
        $curlAfterStop = $stoppingCurl->sendEnvelopeResponse($rejectionEnvelope, 5000);
        expect(
            $curlAfterStop->status() === 401 && $curlAfterStop->outcome() === Outcome::REJECTED_STOP,
            'cURL: a stopped transport should not send and should keep answering 401'
        );
        expect(
            count($curlStopWarnings) === 1,
            'cURL: the 401 should be reported once: ' . json_encode($curlStopWarnings)
        );
    } finally {
        proc_terminate($server);
        proc_close($server);
        @unlink($controlFile);
        @unlink($logFilePath);
    }
}

echo "MONICA PHP SDK tests passed\n";
