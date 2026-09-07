<?php

declare(strict_types=1);

/**
 * Contract test against the language-neutral protocol specification.
 *
 * The spec is not owned by this repository: it comes from the `spec/`
 * directory of Accel-Hack/monica, pulled in as a submodule. Every envelope
 * this SDK is able to emit is validated against `spec/event-schema.json`, so a
 * change to the shared contract fails here instead of failing in ingest.
 */

require __DIR__ . '/bootstrap.php';

require __DIR__ . '/spec/JsonSchema.php';

// The PHP error handler honours error_reporting(), so the set of captured
// events has to be pinned rather than inherited from the runtime's php.ini.
error_reporting(E_ALL);

use Monica\Client;
use Monica\EventFactory;
use Monica\Tests\Spec\JsonSchema;
use Monica\Transport\TransportInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class CapturingTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $envelopes = [];

    public function send(array $envelope, int $timeoutMilliseconds): bool
    {
        unset($timeoutMilliseconds);
        $this->envelopes[] = $envelope;

        return true;
    }
}

function fail(string $message): void
{
    throw new RuntimeException($message);
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

/**
 * Round-trips through JSON so the validator sees exactly the shape that
 * leaves the process: objects as objects, lists as lists.
 *
 * @param array<string, mixed> $envelope
 * @return mixed
 */
function wire(array $envelope)
{
    $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    expect($json !== false, 'envelope must be JSON-encodable');

    return json_decode((string) $json, false);
}

/**
 * @param array<string, mixed> $envelope
 */
function assertValid(JsonSchema $schema, array $envelope, string $label): void
{
    $errors = $schema->validate(wire($envelope));
    if ($errors !== []) {
        fail($label . ' does not satisfy event-schema.json:' . PHP_EOL . '  - ' . implode(PHP_EOL . '  - ', $errors));
    }
}

/**
 * @param mixed $envelope
 */
function assertRejected(JsonSchema $schema, $envelope, string $label): void
{
    if ($schema->validate($envelope) === []) {
        fail('event-schema.json should reject ' . $label);
    }
}

function specDirectory(): string
{
    $root = dirname(__DIR__);
    // `.spec-src` is the Accel-Hack/monica submodule; a bare `spec/` supports a
    // checkout that vendors the spec directly.
    foreach (['/.spec-src/spec', '/spec'] as $candidate) {
        if (is_file($root . $candidate . '/event-schema.json')) {
            return $root . $candidate;
        }
    }

    fail(
        'spec/event-schema.json not found. The protocol spec is a submodule; run' . PHP_EOL
        . '  git submodule update --init --depth 1' . PHP_EOL
        . 'and see README.md. This test must not be skipped: without it a change to the'
        . ' shared contract would only be caught in ingest.'
    );
}

$specDirectory = specDirectory();
$schema = JsonSchema::fromFile($specDirectory . '/event-schema.json');

// --- the spec itself still says what this SDK relies on -------------------

expect(
    $schema->pointer('/$schema') === 'https://json-schema.org/draft/2020-12/schema',
    'event-schema.json must use JSON Schema draft 2020-12'
);
expect(
    $schema->pointer('/properties/items/maxItems') === 100,
    'event-schema.json must enforce the 100-item envelope limit'
);
$platforms = $schema->pointer('/$defs/errorItem/properties/platform/enum');
expect(
    is_array($platforms) && in_array('php', $platforms, true),
    'event-schema.json must accept the php platform'
);
$mechanisms = $schema->pointer('/$defs/mechanism/properties/type/enum');
expect(
    is_array($mechanisms) && in_array('generic', $mechanisms, true),
    'event-schema.json must accept the generic mechanism this SDK emits'
);

$definitions = $schema->definitionNames();
$serialized = (string) file_get_contents($specDirectory . '/event-schema.json');
preg_match_all('~#/\$defs/([A-Za-z0-9_-]+)~', $serialized, $references);
foreach ($references[1] as $reference) {
    expect(in_array($reference, $definitions, true), 'unknown schema reference: #/$defs/' . $reference);
}

// --- every envelope this SDK can emit satisfies the schema ---------------

$transport = new CapturingTransport();
$client = new Client([
    'dsn' => 'https://secret@ingest.example.test/1',
    'environment' => 'contract',
    'release' => '1.2.3',
    'server_name' => 'contract-host',
    'project_root' => dirname(__DIR__),
    'transport_instance' => $transport,
    'auto_capture' => false,
]);

$client->captureException(
    new RuntimeException('outer', 0, new LogicException('inner')),
    [
        'tags' => ['service' => 'api', 'route' => '/v1/things'],
        'user' => ['id' => 'u1', 'email' => 'user@example.test', 'ip' => '203.0.113.1'],
        'request' => [
            'url' => 'https://app.example.test/v1/things?page=2',
            'method' => 'POST',
            'headers' => ['content-type' => 'application/json'],
        ],
        'contexts' => ['runtime' => ['name' => 'php', 'version' => PHP_VERSION]],
        'fingerprint' => ['things', 'POST'],
        'breadcrumbs' => [
            [
                'timestamp' => '2026-09-07T00:00:00.000Z',
                'type' => 'http',
                'category' => 'request',
                'message' => 'POST /v1/things',
                'level' => 'info',
                'data' => ['status' => 500],
            ],
        ],
    ]
);
expect($client->flush(), 'the capturing transport should accept the envelope');
assertValid($schema, $transport->envelopes[0], 'a captured exception with full context');

$transport->envelopes = [];
foreach (['fatal', 'error', 'warning', 'info', 'debug'] as $level) {
    $client->captureMessage('message at ' . $level, $level);
}
// handleException also logs through the previous handler; only the event shape
// matters here, so the same path is taken without the logging.
$client->captureException(new RuntimeException('unhandled'), [
    'level' => 'fatal',
    '_mechanism_handled' => false,
]);
$client->handleError(E_USER_NOTICE, 'a notice', __FILE__, __LINE__);
$client->handleError(E_USER_WARNING, 'a warning', '', 0);
expect($client->flush(), 'the capturing transport should accept the second envelope');
assertValid($schema, $transport->envelopes[0], 'messages, an unhandled exception and PHP errors');
expect(
    count($transport->envelopes[0]['items']) === 8,
    'every level, the unhandled exception and both PHP errors should be in the envelope'
);

// A fatal only reaches the queue from the shutdown handler, which cannot run
// mid-process, so the event is built the same way handleShutdown builds it.
$fatalFactory = new EventFactory('contract', '1.2.3', dirname(__DIR__), 'contract-host');
$fatalEnvelope = [
    'sdk' => ['name' => Client::SDK_NAME, 'version' => Client::SDK_VERSION],
    'sent_at' => '2026-09-07T00:00:00.000Z',
    'discarded' => 0,
    'items' => [
        $fatalFactory->fromPhpError(E_ERROR, 'Allowed memory size exhausted', __FILE__, 1, false),
        $fatalFactory->fromPhpError(E_USER_ERROR, 'user fatal', '', 0, false),
    ],
];
assertValid($schema, $fatalEnvelope, 'a fatal shutdown envelope');
expect($fatalEnvelope['items'][0]['level'] === 'fatal', 'E_ERROR should be reported as fatal');

// A full batch with dropped events: the envelope limit and `discarded` are
// part of the contract, not an implementation detail.
$overflowTransport = new CapturingTransport();
$overflowClient = new Client([
    'dsn' => 'https://secret@ingest.example.test/1',
    'environment' => 'contract',
    'transport_instance' => $overflowTransport,
    'auto_capture' => false,
    'max_queue_size' => 100,
    'batch_size' => 100,
]);
for ($index = 0; $index < 105; $index++) {
    $overflowClient->captureMessage('overflow ' . $index);
}
expect($overflowClient->flush(), 'the overflow envelope should be accepted');
$overflow = $overflowTransport->envelopes[0];
expect(count($overflow['items']) === 100, 'a full envelope should carry exactly 100 items');
expect($overflow['discarded'] === 5, 'dropped events should be reported as discarded');
assertValid($schema, $overflow, 'a full envelope reporting discarded events');

// Non-ASCII and control characters survive JSON encoding intact.
$unicodeTransport = new CapturingTransport();
$unicodeClient = new Client([
    'dsn' => 'https://secret@ingest.example.test/1',
    'environment' => '本番',
    'transport_instance' => $unicodeTransport,
    'auto_capture' => false,
]);
$unicodeClient->captureException(new RuntimeException("結合できません\tid=1"));
expect($unicodeClient->flush(), 'the unicode envelope should be accepted');
assertValid($schema, $unicodeTransport->envelopes[0], 'a non-ASCII envelope');

// The bytes that actually go over the wire, not just the PHP array.
if (interface_exists(ClientInterface::class) && class_exists(Psr17Factory::class)) {
    $factory = new Psr17Factory();
    $httpClient = new class($factory) implements ClientInterface {
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
    $wireClient = new Client([
        'dsn' => 'https://secret@ingest.example.test/1',
        'environment' => 'contract',
        'http_client' => $httpClient,
        'request_factory' => $factory,
        'stream_factory' => $factory,
        'auto_capture' => false,
    ]);
    $wireClient->captureException(new RuntimeException('over the wire'));
    expect($wireClient->flush(), 'the PSR-18 transport should accept a 202 response');
    expect($httpClient->request instanceof RequestInterface, 'the PSR-18 client should receive a request');
    $body = gzdecode((string) $httpClient->request->getBody());
    expect($body !== false, 'the request body should be gzipped JSON');
    $decoded = json_decode((string) $body, false);
    expect(is_object($decoded), 'the request body should decode to an envelope');
    $errors = $schema->validate($decoded);
    if ($errors !== []) {
        fail(
            'the gzipped request body does not satisfy event-schema.json:' . PHP_EOL
            . '  - ' . implode(PHP_EOL . '  - ', $errors)
        );
    }
}

// --- the validator has to be able to say no -----------------------------

$valid = wire($overflow);
expect($schema->validate($valid) === [], 'the baseline envelope should be valid');

$missingPlatform = wire($overflow);
unset($missingPlatform->items[0]->platform);
assertRejected($schema, $missingPlatform, 'an error item without a platform');

$unknownLevel = wire($overflow);
$unknownLevel->items[0]->level = 'trace';
assertRejected($schema, $unknownLevel, 'an unknown level');

$badEventId = wire($overflow);
$badEventId->items[0]->event_id = 'not-a-uuid';
assertRejected($schema, $badEventId, 'a malformed event_id');

$badTimestamp = wire($overflow);
$badTimestamp->items[0]->timestamp = '2026-09-07 00:00:00';
assertRejected($schema, $badTimestamp, 'a timestamp that is not RFC 3339');

$negativeDiscarded = wire($overflow);
$negativeDiscarded->discarded = -1;
assertRejected($schema, $negativeDiscarded, 'a negative discarded count');

$tooManyItems = wire($overflow);
$tooManyItems->items[] = clone $tooManyItems->items[0];
assertRejected($schema, $tooManyItems, 'an envelope with 101 items');

$noSdk = wire($overflow);
unset($noSdk->sdk);
assertRejected($schema, $noSdk, 'an envelope without sdk metadata');

$badFrame = wire($transport->envelopes[0]);
unset($badFrame->items[5]->exception->values[0]->stacktrace->frames[0]->in_app);
assertRejected($schema, $badFrame, 'a stack frame without in_app');

$badMechanism = wire($transport->envelopes[0]);
$badMechanism->items[5]->exception->values[0]->mechanism->type = 'servlet';
assertRejected($schema, $badMechanism, 'an unknown mechanism type');

$badTag = wire($overflow);
$badTag->items[0]->tags = json_decode('{"count": 3}', false);
assertRejected($schema, $badTag, 'a non-string tag value');

// Forward compatibility is also part of the contract: a newer SDK's item type
// must not be rejected by an older backend.
$unknownItem = wire($overflow);
$unknownItem->items = [json_decode('{"type": "transaction", "name": "GET /v1/things"}', false)];
expect(
    $schema->validate($unknownItem) === [],
    'unknown item types must stay acceptable for forward compatibility'
);

$relativeSpec = substr($specDirectory, strlen(dirname(__DIR__)) + 1);
echo 'MONICA PHP SDK spec contract test passed (' . $relativeSpec . "/event-schema.json)\n";
