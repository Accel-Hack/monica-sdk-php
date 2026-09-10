<?php

declare(strict_types=1);

/**
 * Contract test against MONICA's public contract bundle.
 *
 * The contract is not owned by this repository. MONICA publishes it at
 * https://spec.monica.accelhack.net/v1/ and `scripts/spec-sync.php` vendors a
 * copy into `spec/`. This test runs against that copy, so a change to the
 * shared contract fails here instead of failing in ingest.
 *
 * Three layers are checked, because the bundle itself says the schema is not
 * the whole contract:
 *
 *   1. the schema still says what this SDK relies on (`envelope.json`,
 *      `limits.json`)
 *   2. MONICA's own test vectors get the verdict the bundle expects
 *   3. every envelope this SDK can emit satisfies the schema *and* the
 *      obligations `payload.md` and `ingest.md` state in prose
 *
 * Layer 3 matters most. `payload.md` is explicit that a payload can satisfy
 * the published schema and still be rejected by ingest — timestamps are the
 * example the bundle ships vectors for — so passing the schema is not
 * evidence that the SDK is correct.
 */

require __DIR__ . '/bootstrap.php';

require __DIR__ . '/spec/JsonSchema.php';

// The PHP error handler honours error_reporting(), so the set of captured
// events has to be pinned rather than inherited from the runtime's php.ini.
error_reporting(E_ALL);

use Monica\Client;
use Monica\EventFactory;
use Monica\Tests\Spec\JsonSchema;
use Monica\Transport\Dsn;
use Monica\Transport\EnvelopeSplitter;
use Monica\Transport\Outcome;
use Monica\Transport\Response;
use Monica\Transport\RetryPolicy;
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
        fail($label . ' does not satisfy envelope.json:' . PHP_EOL . '  - ' . implode(PHP_EOL . '  - ', $errors));
    }
}

/**
 * @param mixed $envelope
 */
function assertRejected(JsonSchema $schema, $envelope, string $label): void
{
    if ($schema->validate($envelope) === []) {
        fail('envelope.json should reject ' . $label);
    }
}

/**
 * The vendored bundle, verified against `spec.lock.json` before anything reads
 * it. A hand-edited spec would turn this whole file into a test of nothing.
 *
 * @return array{directory: string, revision: string}
 */
function vendoredSpec(): array
{
    $root = dirname(__DIR__);
    $lock = json_decode((string) @file_get_contents($root . '/spec.lock.json'), true);
    if (
        !is_array($lock)
        || !isset($lock['version'], $lock['revision'], $lock['files'])
        || !is_array($lock['files'])
    ) {
        fail('spec.lock.json is missing or unreadable; see README.md');
    }
    $directory = $root . '/spec/' . $lock['version'];

    $digests = [];
    foreach ($lock['files'] as $path => $expected) {
        $file = $directory . '/' . $path;
        if (!is_file($file)) {
            fail(
                'spec/' . $lock['version'] . '/' . $path . ' is not vendored. The contract lives in'
                . ' MONICA and is pulled in by a script:' . PHP_EOL
                . '  php scripts/spec-sync.php' . PHP_EOL
                . 'This test must not be skipped: without it a change to the shared contract'
                . ' would only be caught in ingest.'
            );
        }
        $actual = hash('sha256', (string) file_get_contents($file));
        if ($actual !== $expected) {
            fail(
                'spec/' . $lock['version'] . '/' . $path . ' does not match spec.lock.json. Run'
                . ' `php scripts/spec-sync.php` instead of editing the vendored copy.'
            );
        }
        $digests[$path] = (string) $expected;
    }

    // `revision` is MONICA's fingerprint for the whole bundle. Recomputing it
    // from the digests means a lock whose entries were edited to agree with a
    // doctored spec still fails here.
    ksort($digests, SORT_STRING);
    $lines = [];
    foreach ($digests as $path => $digest) {
        $lines[] = $digest . '  ' . $path;
    }
    if (hash('sha256', implode("\n", $lines)) !== $lock['revision']) {
        fail('spec.lock.json: revision does not match its own files; run `php scripts/spec-sync.php`');
    }

    return ['directory' => $directory, 'revision' => (string) $lock['revision']];
}

/**
 * @return array<string, mixed>
 */
function readJson(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);
    expect(is_array($decoded), $path . ' should decode to a JSON object');

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * RFC 3339 date-time with a timezone, on a date the calendar actually has.
 *
 * `envelope.json` only says `type: "string"` here: the two rules below are
 * prose in payload.md, and MONICA answers 422 when they are broken. The
 * bundle ships `space-separated-timestamp` and `impossible-calendar-date` as
 * vectors with `schema_rejects: false` for exactly this reason.
 */
function isRfc3339(string $value): bool
{
    $matched = preg_match(
        '~^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})$~',
        $value,
        $parts
    );
    if ($matched !== 1) {
        return false;
    }

    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        && (int) $parts[4] < 24 && (int) $parts[5] < 60 && (int) $parts[6] < 61;
}

/**
 * Every stack frame in an envelope, so the frame-level obligations can be
 * checked without knowing which item produced them.
 *
 * @param array<string, mixed> $envelope
 * @return list<array<string, mixed>>
 */
function framesIn(array $envelope): array
{
    $frames = [];
    foreach ($envelope['items'] as $item) {
        foreach ($item['exception']['values'] ?? [] as $value) {
            foreach ($value['stacktrace']['frames'] ?? [] as $frame) {
                $frames[] = $frame;
            }
        }
    }

    return $frames;
}

$vendored = vendoredSpec();
$specDirectory = $vendored['directory'];
$schema = JsonSchema::fromFile($specDirectory . '/envelope.json');
$limits = readJson($specDirectory . '/limits.json');
$transportSpec = readJson($specDirectory . '/transport.json');

// --- 1. the spec itself still says what this SDK relies on ---------------

expect(
    $schema->pointer('/$schema') === 'https://json-schema.org/draft/2020-12/schema',
    'envelope.json must use JSON Schema draft 2020-12'
);
expect(
    $schema->pointer('/$id') === 'https://spec.monica.accelhack.net/v1/envelope.json',
    'envelope.json must still be the v1 bundle this SDK targets'
);

// limits.json is the machine-readable copy of the table in ingest.md. If the
// two ever disagree, the SDK would be sized against the wrong one.
expect(
    $schema->pointer('/properties/items/maxItems') === $limits['items_per_envelope'],
    'envelope.json and limits.json must agree on the item limit'
);
expect(
    $schema->pointer('/$defs/exceptionValue/properties/stacktrace/properties/frames/maxItems')
        === $limits['frames_per_stacktrace'],
    'envelope.json and limits.json must agree on the frame limit'
);
expect($limits['items_per_envelope'] === 100, 'this SDK batches to 100 items per envelope');
expect($limits['frames_per_stacktrace'] === 200, 'this SDK truncates stack traces to 200 frames');

// The byte caps are constants in the SDK because `spec/` is a development-time
// copy and is not shipped in the package, so there is nothing to read at
// runtime. This is the comparison that keeps the copy honest: MONICA changing
// a cap fails here instead of turning into 413s in production.
expect(
    EnvelopeSplitter::MAX_GZIP_BYTES === $limits['envelope_gzip_bytes'],
    'EnvelopeSplitter::MAX_GZIP_BYTES (' . EnvelopeSplitter::MAX_GZIP_BYTES
    . ') must match limits.json (' . $limits['envelope_gzip_bytes'] . ')'
);
expect(
    EnvelopeSplitter::MAX_DECOMPRESSED_BYTES === $limits['envelope_decompressed_bytes'],
    'EnvelopeSplitter::MAX_DECOMPRESSED_BYTES (' . EnvelopeSplitter::MAX_DECOMPRESSED_BYTES
    . ') must match limits.json (' . $limits['envelope_decompressed_bytes'] . ')'
);

$platforms = $schema->pointer('/$defs/errorItem/properties/platform/enum');
expect(
    is_array($platforms) && in_array('php', $platforms, true),
    'envelope.json must accept the php platform'
);
$mechanisms = $schema->pointer('/$defs/mechanism/properties/type/enum');
expect(
    is_array($mechanisms) && in_array('generic', $mechanisms, true),
    'envelope.json must accept the generic mechanism this SDK emits'
);

// payload.md says the schema now expresses the *shape* of a timestamp, which is
// what moves `space-separated-timestamp` to a vector the schema can reject. The
// prose keeps only "the date must exist in the calendar". If the pattern ever
// goes away, this fails rather than leaving isRfc3339 below as the sole guard.
foreach ([
    '/properties/sent_at/pattern',
    '/$defs/errorItem/properties/timestamp/pattern',
    '/$defs/breadcrumb/properties/timestamp/pattern',
] as $pointer) {
    expect(
        is_string($schema->pointer($pointer)) && $schema->pointer($pointer) !== '',
        'envelope.json must constrain the timestamp shape at ' . $pointer
    );
}

// error.json is the shape of a rejection, and the SDK reads it: a 422 body is
// parsed into Monica\Transport\Response so `issues[].path` reaches the caller.
// So this pins both sides -- the schema still saying what the parser assumes,
// and the parser getting the fields out of the body the bundle documents. A
// contract that grew a field the parser drops fails here rather than in ingest.
$errorSchema = JsonSchema::fromFile($specDirectory . '/error.json');
expect(
    $errorSchema->pointer('/$id') === 'https://spec.monica.accelhack.net/v1/error.json',
    'error.json must still be the v1 error schema'
);
expect(
    $errorSchema->pointer('/$defs/validationIssue/required') === ['path', 'message'],
    'a 422 issue must keep carrying both a path and a message'
);
$documentedRejectionBody =
    '{"error":{"code":"invalid_envelope","message":"The envelope does not match the MONICA schema",'
    . '"issues":[{"path":"$.items[0].exception.values[0].mechanism.type","message":"Invalid type"}]}}';
$documentedRejection = json_decode($documentedRejectionBody, false);
$rejectionErrors = $errorSchema->validate($documentedRejection);
if ($rejectionErrors !== []) {
    fail(
        'the rejection shape documented in ingest.md does not satisfy error.json:' . PHP_EOL
        . '  - ' . implode(PHP_EOL . '  - ', $rejectionErrors)
    );
}

// ingest.md's own example of a 422, read the way a transport reads it. The
// point of reading the body at all is `issues[].path`, so it is the path that
// has to come out, not merely a parse that did not throw.
$parsedRejection = Response::forStatus(422, $documentedRejectionBody);
expect(
    $parsedRejection->outcome() === Outcome::REJECTED,
    'reading the body must not change what happens to a 422: it is still dropped'
);
expect(
    $parsedRejection->errorCode() === 'invalid_envelope',
    'error.code from the documented rejection should reach the caller'
);
expect(
    $parsedRejection->issues() === [[
        'path' => '$.items[0].exception.values[0].mechanism.type',
        'message' => 'Invalid type',
    ]],
    'the documented rejection issues should reach the caller: '
    . json_encode($parsedRejection->issues())
);

$definitions = $schema->definitionNames();
$serialized = (string) file_get_contents($specDirectory . '/envelope.json');
preg_match_all('~#/\$defs/([A-Za-z0-9_-]+)~', $serialized, $references);
foreach ($references[1] as $reference) {
    expect(in_array($reference, $definitions, true), 'unknown schema reference: #/$defs/' . $reference);
}

// --- 2. MONICA's own test vectors get the verdict the bundle expects -----

// A vector says both whether MONICA accepts the envelope (`valid`) and
// whether the published schema is able to see the problem
// (`schema_rejects`). Only the second is this validator's business; the first
// is checked against the SDK's own output further down.
$vectorFiles = glob($specDirectory . '/vectors/envelope/*.json') ?: [];
sort($vectorFiles, SORT_STRING);
expect($vectorFiles !== [], 'the bundle must ship envelope test vectors');

$semanticOnly = 0;
foreach ($vectorFiles as $vectorFile) {
    $name = basename($vectorFile, '.json');
    $vector = json_decode((string) file_get_contents($vectorFile), false);
    expect(
        is_object($vector) && property_exists($vector, 'valid') && property_exists($vector, 'envelope'),
        $name . ': a vector needs `valid` and `envelope`'
    );

    // Absent means "the schema agrees with `valid`", which is how every
    // accepted vector in the bundle is written.
    $schemaRejects = property_exists($vector, 'schema_rejects')
        ? (bool) $vector->schema_rejects
        : !$vector->valid;
    $errors = $schema->validate($vector->envelope);

    if ($schemaRejects) {
        if ($errors !== []) {
            continue;
        }
        fail('vector ' . $name . ' should be rejected by envelope.json (' . $vector->description . ')');
    }

    if ($errors !== []) {
        fail(
            'vector ' . $name . ' should satisfy envelope.json (' . $vector->description . '):' . PHP_EOL
            . '  - ' . implode(PHP_EOL . '  - ', $errors)
        );
    }
    if (!$vector->valid) {
        // Passing the schema is not the same as being accepted. Keeping a
        // count here means the SDK-side checks below cannot become the only
        // thing standing between us and a 422 without anyone noticing.
        $semanticOnly++;
    }
}
expect(
    $semanticOnly > 0,
    'the bundle should still carry vectors that the schema cannot reject; if it no longer'
    . ' does, the prose obligations below may have moved into the schema'
);

// --- 3. every envelope this SDK can emit satisfies the schema ------------

$transport = new CapturingTransport();
$client = new Client([
    'dsn' => 'https://msk_secret@ingest.example.test/1',
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
$chained = $transport->envelopes[0];
assertValid($schema, $chained, 'a captured exception with full context');

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
$mixed = $transport->envelopes[0];
assertValid($schema, $mixed, 'messages, an unhandled exception and PHP errors');
expect(
    count($mixed['items']) === 8,
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
    'dsn' => 'https://msk_secret@ingest.example.test/1',
    'environment' => 'contract',
    'transport_instance' => $overflowTransport,
    'auto_capture' => false,
    'max_queue_size' => $limits['items_per_envelope'],
    'batch_size' => $limits['items_per_envelope'],
]);
for ($index = 0; $index < $limits['items_per_envelope'] + 5; $index++) {
    $overflowClient->captureMessage('overflow ' . $index);
}
expect($overflowClient->flush(), 'the overflow envelope should be accepted');
$overflow = $overflowTransport->envelopes[0];
expect(
    count($overflow['items']) === $limits['items_per_envelope'],
    'a full envelope should carry exactly ' . $limits['items_per_envelope'] . ' items'
);
expect($overflow['discarded'] === 5, 'dropped events should be reported as discarded');
assertValid($schema, $overflow, 'a full envelope reporting discarded events');

// The item limit belongs to MONICA, not to this SDK's defaults. Asking for a
// larger batch must still split, or ingest answers 422 on an envelope the
// application had no way to see was too big.
$splitTransport = new CapturingTransport();
$splitClient = new Client([
    'dsn' => 'https://msk_secret@ingest.example.test/1',
    'environment' => 'contract',
    'transport_instance' => $splitTransport,
    'auto_capture' => false,
    'max_queue_size' => $limits['items_per_envelope'] * 4,
    'batch_size' => $limits['items_per_envelope'] * 4,
]);
for ($index = 0; $index < $limits['items_per_envelope'] + 50; $index++) {
    $splitClient->captureMessage('split ' . $index);
}
expect($splitClient->flush(), 'an over-sized batch should still be accepted');
expect(count($splitTransport->envelopes) === 2, 'an over-sized batch should be split across envelopes');
foreach ($splitTransport->envelopes as $position => $envelope) {
    expect(
        count($envelope['items']) <= $limits['items_per_envelope'],
        'envelope ' . $position . ' carries more than the published item limit'
    );
    assertValid($schema, $envelope, 'a split envelope');
}

// Non-ASCII and control characters survive JSON encoding intact.
$unicodeTransport = new CapturingTransport();
$unicodeClient = new Client([
    'dsn' => 'https://msk_secret@ingest.example.test/1',
    'environment' => '本番',
    'transport_instance' => $unicodeTransport,
    'auto_capture' => false,
]);
$unicodeClient->captureException(new RuntimeException("結合できません\tid=1"));
expect($unicodeClient->flush(), 'the unicode envelope should be accepted');
assertValid($schema, $unicodeTransport->envelopes[0], 'a non-ASCII envelope');

// --- 4. the payload obligations the schema cannot express (payload.md) ---

$sdkEnvelopes = [$chained, $mixed, $fatalEnvelope, $overflow, $unicodeTransport->envelopes[0]];

foreach ($sdkEnvelopes as $position => $envelope) {
    expect(
        isRfc3339((string) $envelope['sent_at']),
        'envelope #' . $position . ': sent_at "' . $envelope['sent_at']
            . '" is not an RFC 3339 date-time with a timezone'
    );
    expect(
        $envelope['sdk']['name'] !== '' && $envelope['sdk']['version'] !== '',
        'envelope #' . $position . ': sdk.name and sdk.version must not be empty'
    );
    // JavaScript's safe integer range is not in the JSON Schema vocabulary, so
    // `unsafe-discarded-count` is a vector the schema cannot reject.
    expect(
        $envelope['discarded'] >= 0 && $envelope['discarded'] <= 9007199254740991,
        'envelope #' . $position . ': discarded must stay inside the safe integer range'
    );

    foreach ($envelope['items'] as $item) {
        expect(
            isRfc3339((string) $item['timestamp']),
            'envelope #' . $position . ': timestamp "' . $item['timestamp'] . '" is not RFC 3339'
        );
        foreach ($item['breadcrumbs'] ?? [] as $breadcrumb) {
            expect(
                !isset($breadcrumb['timestamp']) || isRfc3339((string) $breadcrumb['timestamp']),
                'envelope #' . $position . ': a breadcrumb timestamp is not RFC 3339'
            );
        }
    }

    foreach (framesIn($envelope) as $frame) {
        // An empty filename passes the schema's `minLength: 1` only because
        // the SDK substitutes a placeholder; payload.md forbids the empty one.
        expect(
            $frame['filename'] !== '',
            'envelope #' . $position . ': a stack frame has an empty filename'
        );
    }
}

// exception.values runs outermost first, following getPrevious(). Reversing it
// splits one exception into two issues.
$values = $chained['items'][0]['exception']['values'];
expect(count($values) === 2, 'the cause chain should carry both exceptions');
expect($values[0]['type'] === 'RuntimeException', 'exception.values should start at the outermost throwable');
expect($values[1]['type'] === 'LogicException', 'exception.values should follow getPrevious() inwards');

// frames run oldest caller first, throw site last. This is the direction every
// MONICA SDK uses, and the one grouping assumes. Two levels of calls are needed
// to pin the direction: with a throwable raised at the top of this file the
// trace is a single frame, and both orderings look the same.
function specThrowSite(): RuntimeException
{
    return new RuntimeException('locate the throw site');
}

function specCallSite(): RuntimeException
{
    return specThrowSite();
}

$throwSite = specCallSite();
$located = (new EventFactory('contract', null, dirname(__DIR__)))->fromThrowable($throwSite);
$locatedFrames = $located['exception']['values'][0]['stacktrace']['frames'];
expect(count($locatedFrames) === 3, 'the trace should hold both calls and the throw site');
expect(
    $locatedFrames[0]['function'] === 'specCallSite',
    'the first frame should be the oldest caller'
);
expect(
    $locatedFrames[1]['function'] === 'specThrowSite',
    'frames should run from the oldest caller towards the throw site'
);
$last = $locatedFrames[2];
expect(
    $last['filename'] === $throwSite->getFile() && $last['lineno'] === $throwSite->getLine(),
    'the last frame should be where the exception was thrown'
);

// Deep recursion must be truncated rather than sent whole: ingest rejects an
// envelope whose stacktrace exceeds the published frame limit.
function specRecurse(int $depth): RuntimeException
{
    return $depth > 0 ? specRecurse($depth - 1) : new RuntimeException('deep');
}
$deep = (new EventFactory('contract', null, dirname(__DIR__)))
    ->fromThrowable(specRecurse($limits['frames_per_stacktrace'] + 50));
expect(
    count($deep['exception']['values'][0]['stacktrace']['frames']) === $limits['frames_per_stacktrace'],
    'a stacktrace deeper than the limit should be truncated to ' . $limits['frames_per_stacktrace'] . ' frames'
);

// in_app is the SDK's judgement about whose code a frame is, not a copy of the
// path. Marking everything false groups every error at the framework.
$inAppFactory = new EventFactory('contract', null, '/srv/app');
$vendorError = $inAppFactory->fromPhpError(E_WARNING, 'in vendor', '/srv/app/vendor/acme/lib/Client.php', 12, true);
$appError = $inAppFactory->fromPhpError(E_WARNING, 'in app', '/srv/app/src/Handler.php', 12, true);
expect(
    $vendorError['exception']['values'][0]['stacktrace']['frames'][0]['in_app'] === false,
    'vendor/ frames must not be marked in_app'
);
expect(
    $appError['exception']['values'][0]['stacktrace']['frames'][0]['in_app'] === true,
    'frames under the project root must be marked in_app'
);

// fingerprint is the user's grouping key: it goes out exactly as given, and
// never as an empty array.
expect(
    $chained['items'][0]['fingerprint'] === ['things', 'POST'],
    'fingerprint must reach the wire unchanged: no trimming, normalising or joining'
);
expect($chained['items'][0]['fingerprint'] !== [], 'fingerprint must not be an empty array');

// --- 5. the request the SDK actually makes (transport.json) -------------

// transport.json is the machine-readable copy of the tables in ingest.md, so
// everything below is driven by the spec rather than by constants copied out of
// its prose. A change on MONICA's side arrives here as a failure.
$endpoint = $transportSpec['endpoint'];
$authSchemes = [];
foreach ($transportSpec['auth'] as $scheme) {
    $authSchemes[$scheme['kind']] = $scheme;
}
expect(
    isset($authSchemes['secret'], $authSchemes['public']),
    'transport.json should describe both a secret and a public key scheme'
);

// Every section of transport.json has a consumer now. `status` reaches the SDK
// through Outcome and the spool flusher, a 422's error.json body is read so its
// issues get out, a 413 makes the transport split the envelope and post again,
// and `retry` is RetryPolicy plus the retry state the spool keeps per envelope.
// Pinning the vocabulary here turns "MONICA grew an obligation the PHP SDK
// ignores" into a failing test instead of a silent gap.
$implemented = ['endpoint', 'dsn', 'auth', 'status', 'retry'];
$partiallyImplemented = [];
$unimplemented = [];
$declared = array_keys($transportSpec);
sort($declared, SORT_STRING);
$accounted = array_merge($implemented, $partiallyImplemented, $unimplemented);
sort($accounted, SORT_STRING);
expect(
    $declared === $accounted,
    'transport.json declares sections this SDK has not considered: '
    . implode(', ', array_diff($declared, $accounted))
    . ' (see README for what is intentionally unimplemented)'
);
$knownStatuses = ['202', '400', '401', '413', '422', '429', '5xx'];
// json_decode(..., true) turns "202" into an int array key, so the keys have to
// be cast back before they can be compared with the vocabulary above.
$actualStatuses = array_map('strval', array_keys($transportSpec['status']));
sort($actualStatuses, SORT_STRING);
sort($knownStatuses, SORT_STRING);
expect(
    $actualStatuses === $knownStatuses,
    'transport.json changed the status vocabulary: ' . implode(', ', $actualStatuses)
);
expect(
    $transportSpec['status']['202'] === 'accept',
    'a 202 must still mean the envelope left the queue'
);

// How each behaviour in the status table reaches this SDK. Outcome is the only
// place a status is interpreted, so this is the whole of the SDK's conformance
// to the table -- including where it knowingly diverges.
$sdkHandling = [
    'accept' => Outcome::ACCEPTED,
    'drop' => Outcome::REJECTED,
    'drop_and_stop' => Outcome::REJECTED_STOP,
    // Both retry behaviours classify the same way; what separates them is the
    // *timing*, which is RetryPolicy's business rather than Outcome's: a 429
    // waits out Retry-After, a 5xx backs off.
    'wait_retry_after' => Outcome::RETRYABLE,
    'backoff' => Outcome::RETRYABLE,
    // A 413 refuses the bytes, not the content, so the same items can be
    // accepted spread over more envelopes. EnvelopeSplitter halves the piece
    // inside the transport and posts again, which is why a 413 seldom reaches
    // a caller -- but the classification has to say "not permanent".
    'split_and_retry' => Outcome::RETRYABLE,
];
foreach ($transportSpec['status'] as $status => $behaviour) {
    expect(
        isset($sdkHandling[$behaviour]),
        'transport.json asks for a behaviour this SDK does not classify: ' . $behaviour
    );
    // A key like "5xx" stands for a range, so members of it are what can be
    // classified. json_decode(..., true) has already turned "202" into an int.
    $codes = substr((string) $status, -1) === 'x'
        ? [(int) (substr((string) $status, 0, 1) . '00'), (int) (substr((string) $status, 0, 1) . '03')]
        : [(int) $status];
    foreach ($codes as $code) {
        expect(
            Outcome::forStatus($code) === $sdkHandling[$behaviour],
            'HTTP ' . $code . ' should be handled as ' . $behaviour
            . ', not ' . Outcome::forStatus($code)
        );
    }
}
// transport.json says network failures are retryable, and there is no status to
// hang that on: the transports report it directly.
expect(
    $transportSpec['retry']['retry_on_network_error'] === true,
    'transport.json should still ask for network failures to be retried'
);

// The retry numbers are constants in the SDK for the same reason the byte caps
// are: `spec/` is not shipped in the package, so there is nothing to read at
// runtime. This is what keeps the copy honest.
$retrySpec = $transportSpec['retry'];
expect(
    $retrySpec['retryable_statuses'] === ['429', '5xx'],
    'transport.json changed which statuses are retryable: '
    . implode(', ', $retrySpec['retryable_statuses'])
);
expect(
    $retrySpec['retry_after']['integer_seconds_only'] === true,
    'RetryPolicy::parseRetryAfter() only reads whole seconds, as the contract asks'
);
foreach ([
    'retry_after.max_seconds' => [$retrySpec['retry_after']['max_seconds'], RetryPolicy::RETRY_AFTER_MAX_SECONDS],
    'backoff.base_ms' => [$retrySpec['backoff']['base_ms'], RetryPolicy::BACKOFF_BASE_MS],
    'backoff.factor' => [$retrySpec['backoff']['factor'], RetryPolicy::BACKOFF_FACTOR],
    'backoff.max_ms' => [$retrySpec['backoff']['max_ms'], RetryPolicy::BACKOFF_MAX_MS],
    'backoff.jitter_min' => [$retrySpec['backoff']['jitter_min'], RetryPolicy::JITTER_MIN],
    'backoff.jitter_max' => [$retrySpec['backoff']['jitter_max'], RetryPolicy::JITTER_MAX],
] as $name => $pair) {
    expect(
        (float) $pair[0] === (float) $pair[1],
        'RetryPolicy must match transport.json on ' . $name . ': contract says '
        . json_encode($pair[0]) . ', the SDK says ' . json_encode($pair[1])
    );
}

// The contract only says there must be a limit, so the value is this SDK's
// choice -- but there has to be one, or a doomed envelope is retried for ever.
expect(
    RetryPolicy::DEFAULT_MAX_ATTEMPTS >= 1,
    'transport.json asks for a retry limit, so there must be a default'
);
$boundedPolicy = new RetryPolicy(3);
expect(
    $boundedPolicy->mayRetry(2) && !$boundedPolicy->mayRetry(3),
    'the retry limit must actually stop an envelope'
);
// min(base * factor^attempt, max) with 50-100% jitter, read off the contract
// rather than off the constants above.
foreach ([1, 2, 3, 10] as $attempts) {
    $unjittered = min(
        $retrySpec['backoff']['base_ms'] * ($retrySpec['backoff']['factor'] ** ($attempts - 1)),
        $retrySpec['backoff']['max_ms']
    );
    foreach ([0.0, 1.0] as $fraction) {
        $policy = new RetryPolicy(RetryPolicy::DEFAULT_MAX_ATTEMPTS, static function () use ($fraction): float {
            return $fraction;
        });
        $expected = (int) round($unjittered * (
            $retrySpec['backoff']['jitter_min']
            + $fraction * ($retrySpec['backoff']['jitter_max'] - $retrySpec['backoff']['jitter_min'])
        ));
        expect(
            $policy->delayMilliseconds(null, $attempts) === $expected,
            'attempt ' . $attempts . ' with jitter ' . $fraction . ' should wait '
            . $expected . 'ms, not ' . $policy->delayMilliseconds(null, $attempts) . 'ms'
        );
    }
}

// The DSN path is not the ingest path. Sending to the DSN's trailing digits
// would post to a project id that MONICA does not route on.
$parsed = Dsn::parse('https://msk_secret@ingest.example.test/1?q=1#f');
expect(
    $parsed['endpoint'] === 'https://ingest.example.test' . $endpoint['path'],
    'the DSN path, query and fragment must be dropped in favour of ' . $endpoint['path']
);

// A public key is for SDKs that ship inside a client. Sent as a Bearer token by
// a server SDK it is a guaranteed 401, so the DSN is refused up front instead of
// losing every event to a status nobody sees.
$publicKeyRejected = false;
try {
    Dsn::parse('https://' . $authSchemes['public']['key_prefix'] . 'contract@ingest.example.test/1');
} catch (InvalidArgumentException $rejected) {
    $publicKeyRejected = true;
}
expect(
    $publicKeyRejected,
    'a DSN carrying a ' . $authSchemes['public']['key_prefix'] . ' key must be rejected'
);

// https everywhere, except the hosts transport.json names.
foreach ($transportSpec['dsn']['insecure_hosts'] as $host) {
    $accepted = true;
    try {
        Dsn::parse('http://msk_secret@' . $host . '/1');
    } catch (InvalidArgumentException $rejected) {
        $accepted = false;
    }
    expect($accepted, 'plain http must be allowed for ' . $host);
}
$insecureRejected = false;
try {
    Dsn::parse('http://msk_secret@ingest.example.test/1');
} catch (InvalidArgumentException $rejected) {
    $insecureRejected = true;
}
expect($insecureRejected, 'plain http must be rejected for hosts outside dsn.insecure_hosts');

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
    $secretKey = $authSchemes['secret']['key_prefix'] . 'contract';
    $wireClient = new Client([
        'dsn' => 'https://' . $secretKey . '@ingest.example.test/1',
        'environment' => 'contract',
        'http_client' => $httpClient,
        'request_factory' => $factory,
        'stream_factory' => $factory,
        'auto_capture' => false,
    ]);
    $wireClient->captureException(new RuntimeException('over the wire'));
    expect($wireClient->flush(), 'the PSR-18 transport should accept a 202 response');
    $request = $httpClient->request;
    expect($request instanceof RequestInterface, 'the PSR-18 client should receive a request');

    expect(
        $request->getMethod() === $endpoint['method'],
        'the envelope must be sent with ' . $endpoint['method']
    );
    expect(
        (string) $request->getUri() === 'https://ingest.example.test' . $endpoint['path'],
        'the envelope must go to ' . $endpoint['path']
    );
    expect(
        $request->getHeaderLine('Content-Type') === $endpoint['content_type'],
        'the envelope must be declared as ' . $endpoint['content_type']
    );
    expect(
        $request->getHeaderLine('Content-Encoding') === $endpoint['content_encoding'],
        'the envelope must be declared as ' . $endpoint['content_encoding'] . '-encoded'
    );

    // A secret key authenticates with the header transport.json gives for its
    // kind, and must not use the public-key header a server SDK never issues.
    expect(
        $request->getHeaderLine($authSchemes['secret']['header'])
            === str_replace('<key>', $secretKey, $authSchemes['secret']['value']),
        'a secret key must be sent as ' . $authSchemes['secret']['header'] . ': '
            . $authSchemes['secret']['value']
    );
    expect(
        $request->getHeaderLine($authSchemes['public']['header']) === '',
        'a server SDK must not use the public-key header ' . $authSchemes['public']['header']
    );

    $raw = (string) $request->getBody();
    $body = gzdecode($raw);
    expect($body !== false, 'the request body should be gzipped JSON');
    expect(
        strlen($raw) <= $limits['envelope_gzip_bytes'],
        'the gzipped envelope must stay under ' . $limits['envelope_gzip_bytes'] . ' bytes'
    );
    expect(
        strlen((string) $body) <= $limits['envelope_decompressed_bytes'],
        'the decompressed envelope must stay under ' . $limits['envelope_decompressed_bytes'] . ' bytes'
    );

    // One request carries one envelope: the body is a single JSON value, not a
    // concatenation of them.
    $decoded = json_decode((string) $body, false);
    expect(is_object($decoded), 'the request body should decode to exactly one envelope');
    $errors = $schema->validate($decoded);
    if ($errors !== []) {
        fail(
            'the gzipped request body does not satisfy envelope.json:' . PHP_EOL
            . '  - ' . implode(PHP_EOL . '  - ', $errors)
        );
    }
}

// --- 6. the validator has to be able to say no ---------------------------

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

$negativeDiscarded = wire($overflow);
$negativeDiscarded->discarded = -1;
assertRejected($schema, $negativeDiscarded, 'a negative discarded count');

$tooManyItems = wire($overflow);
$tooManyItems->items[] = clone $tooManyItems->items[0];
assertRejected($schema, $tooManyItems, 'an envelope over the item limit');

$noSdk = wire($overflow);
unset($noSdk->sdk);
assertRejected($schema, $noSdk, 'an envelope without sdk metadata');

$badFrame = wire($chained);
unset($badFrame->items[0]->exception->values[0]->stacktrace->frames[0]->in_app);
assertRejected($schema, $badFrame, 'a stack frame without in_app');

$badMechanism = wire($chained);
$badMechanism->items[0]->exception->values[0]->mechanism->type = 'servlet';
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

echo 'MONICA PHP SDK spec contract test passed (' . count($vectorFiles) . ' vectors, contract revision '
    . substr($vendored['revision'], 0, 12) . ")\n";
