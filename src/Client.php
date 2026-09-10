<?php

declare(strict_types=1);

namespace Monica;

use InvalidArgumentException;
use Monica\Transport\CurlTransport;
use Monica\Transport\Diagnostics;
use Monica\Transport\Outcome;
use Monica\Transport\OutcomeAwareInterface;
use Monica\Transport\Psr18Transport;
use Monica\Transport\Response;
use Monica\Transport\ResponseAwareInterface;
use Monica\Transport\SpoolTransport;
use Monica\Transport\TransportInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

final class Client
{
    public const SDK_NAME = 'ah-monica/monica';
    public const SDK_VERSION = '0.1.1';

    private EventFactory $eventFactory;
    private TransportInterface $transport;
    private string $transportMode;
    /** @var callable|null */
    private $beforeSend;
    /** @var list<array<string, mixed>> */
    private array $queue = [];
    private int $discarded = 0;
    private int $maxQueueSize;
    private int $batchSize;
    private int $requestTimeoutMilliseconds;
    private int $errorTypes;
    private float $sampleRate;
    private bool $handling = false;
    private bool $handlersInstalled = false;
    private bool $shutdownRan = false;
    /** @var callable|null */
    private $previousExceptionHandler;
    /** @var callable|null */
    private $previousErrorHandler;
    private ?string $memoryReserve;
    /** @var callable */
    private $random;
    private ?Response $lastResponse = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options)
    {
        $dsn = isset($options['dsn']) ? trim((string) $options['dsn']) : '';
        $environment = isset($options['environment']) ? trim((string) $options['environment']) : '';
        if ($dsn === '') {
            throw new InvalidArgumentException('dsn must not be empty');
        }
        if ($environment === '') {
            throw new InvalidArgumentException('environment must not be empty');
        }

        $this->transportMode = isset($options['transport']) ? (string) $options['transport'] : 'shutdown';
        if (!in_array($this->transportMode, ['shutdown', 'spool'], true)) {
            throw new InvalidArgumentException('transport must be shutdown or spool');
        }
        $this->maxQueueSize = self::positiveInteger($options, 'max_queue_size', 100);
        $this->batchSize = min(self::positiveInteger($options, 'batch_size', 100), 100, $this->maxQueueSize);
        $this->requestTimeoutMilliseconds = self::positiveInteger($options, 'request_timeout_ms', 2000);
        $this->errorTypes = isset($options['error_types'])
            ? (int) $options['error_types']
            : E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE;
        $this->beforeSend = isset($options['before_send']) && is_callable($options['before_send'])
            ? $options['before_send']
            : null;
        $this->random = isset($options['random']) && is_callable($options['random'])
            ? $options['random']
            : static function (): float {
                return mt_rand() / mt_getrandmax();
            };
        $sampleRate = isset($options['sample_rate']) ? (float) $options['sample_rate'] : 1.0;
        if ($sampleRate < 0.0 || $sampleRate > 1.0) {
            throw new InvalidArgumentException('sample_rate must be between 0 and 1');
        }
        $this->sampleRate = $sampleRate;

        $this->eventFactory = new EventFactory(
            $environment,
            isset($options['release']) && $options['release'] !== false ? (string) $options['release'] : null,
            isset($options['project_root']) ? (string) $options['project_root'] : null,
            isset($options['server_name']) ? (string) $options['server_name'] : null
        );

        if (isset($options['transport_instance'])) {
            if (!$options['transport_instance'] instanceof TransportInterface) {
                throw new InvalidArgumentException('transport_instance must implement TransportInterface');
            }
            $httpTransport = $options['transport_instance'];
        } else {
            $httpTransport = $this->createHttpTransport($dsn, $options);
        }
        if ($this->transportMode === 'spool') {
            $spoolDirectory = isset($options['spool_dir'])
                ? (string) $options['spool_dir']
                : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'monica-spool';
            $spoolMaxFiles = self::positiveInteger($options, 'spool_max_files', 1000);
            $this->transport = new SpoolTransport($spoolDirectory, $spoolMaxFiles);
        } else {
            $this->transport = $httpTransport;
        }

        $reserveBytes = self::positiveInteger($options, 'memory_reserve_bytes', 262144);
        $this->memoryReserve = str_repeat('x', $reserveBytes);

        if (!isset($options['auto_capture']) || (bool) $options['auto_capture']) {
            $this->installHandlers();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function captureException(Throwable $exception, array $context = []): ?string
    {
        if ($this->handling || ($this->random)() >= $this->sampleRate) {
            return null;
        }
        $this->handling = true;
        try {
            return $this->queueEvent($this->eventFactory->fromThrowable($exception, $context));
        } catch (Throwable $ignored) {
            return null;
        } finally {
            $this->handling = false;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function captureMessage(string $message, string $level = 'info', array $context = []): ?string
    {
        if ($this->handling || ($this->random)() >= $this->sampleRate) {
            return null;
        }
        $this->handling = true;
        try {
            return $this->queueEvent($this->eventFactory->fromMessage($message, $level, $context));
        } catch (Throwable $ignored) {
            return null;
        } finally {
            $this->handling = false;
        }
    }

    public function flush(int $timeoutMilliseconds = 2000): bool
    {
        if ($this->handling) {
            return false;
        }
        $timeoutMilliseconds = max(1, $timeoutMilliseconds);
        $accepted = true;
        while ($this->queue !== []) {
            $items = array_slice($this->queue, 0, $this->batchSize);
            $reportedDiscarded = $this->discarded;
            $envelope = [
                'sdk' => ['name' => self::SDK_NAME, 'version' => self::SDK_VERSION],
                'sent_at' => self::timestamp(),
                'discarded' => $reportedDiscarded,
                'items' => $items,
            ];
            $this->handling = true;
            try {
                $response = $this->sendEnvelope(
                    $envelope,
                    min($timeoutMilliseconds, $this->requestTimeoutMilliseconds)
                );
            } catch (Throwable $ignored) {
                $response = Response::forNetworkFailure();
            } finally {
                $this->handling = false;
            }
            $this->lastResponse = $response;
            $sent = $response->outcome() === Outcome::ACCEPTED;
            if (!$sent) {
                $accepted = false;
                break;
            }
            array_splice($this->queue, 0, count($items));
            $this->discarded = 0;
        }

        return $accepted && $this->queue === [];
    }

    public function installHandlers(): void
    {
        if ($this->handlersInstalled) {
            return;
        }
        $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
        $this->previousErrorHandler = set_error_handler([$this, 'handleError'], $this->errorTypes);
        register_shutdown_function([$this, 'handleShutdown']);
        $this->handlersInstalled = true;
    }

    public function handleException(Throwable $exception): void
    {
        $this->captureException($exception, [
            'level' => 'fatal',
            '_mechanism_handled' => false,
        ]);
        if ($this->previousExceptionHandler !== null) {
            call_user_func($this->previousExceptionHandler, $exception);
            return;
        }
        error_log((string) $exception);
    }

    public function handleError(
        int $severity,
        string $message,
        string $file = '',
        int $line = 0,
        ...$rest
    ): bool {
        if (
            !$this->handling
            && ($severity & $this->errorTypes) !== 0
            && ($severity & error_reporting()) !== 0
        ) {
            $this->capturePhpError($severity, $message, $file, $line, true);
        }
        if ($this->previousErrorHandler !== null) {
            return (bool) call_user_func(
                $this->previousErrorHandler,
                $severity,
                $message,
                $file,
                $line,
                ...$rest
            );
        }

        return false;
    }

    public function handleShutdown(): void
    {
        if ($this->shutdownRan) {
            return;
        }
        $this->shutdownRan = true;
        $this->memoryReserve = null;
        $lastError = error_get_last();
        if (
            is_array($lastError)
            && isset($lastError['type'], $lastError['message'], $lastError['file'], $lastError['line'])
            && in_array(
                (int) $lastError['type'],
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
                true
            )
        ) {
            $this->capturePhpError(
                (int) $lastError['type'],
                (string) $lastError['message'],
                (string) $lastError['file'],
                (int) $lastError['line'],
                false
            );
        }

        if (
            $this->transportMode === 'shutdown'
            && PHP_SAPI !== 'cli'
            && function_exists('fastcgi_finish_request')
        ) {
            try {
                fastcgi_finish_request();
            } catch (Throwable $ignored) {
            }
        }
        $this->flush($this->requestTimeoutMilliseconds);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queuedEvents(): array
    {
        return $this->queue;
    }

    /**
     * What MONICA answered to the last envelope `flush()` tried to send: the
     * HTTP status, `error.code`, and for a 422 the `issues[].path` naming the
     * fields ingest refused. Null before the first attempt.
     *
     * `flush()` keeps answering yes or no. This is the same failure with the
     * reason attached, for a caller that wants to log or assert on it.
     */
    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    /**
     * Hand the envelope to the transport, asking for the most detailed answer
     * it can give. A transport from outside the SDK may only answer yes or no,
     * and a no keeps meaning "try again later" as it did before outcomes.
     *
     * @param array<string, mixed> $envelope
     */
    private function sendEnvelope(array $envelope, int $timeoutMilliseconds): Response
    {
        if ($this->transport instanceof ResponseAwareInterface) {
            return $this->transport->sendEnvelopeResponse($envelope, $timeoutMilliseconds);
        }
        if ($this->transport instanceof OutcomeAwareInterface) {
            return Response::forOutcome($this->transport->sendEnvelope($envelope, $timeoutMilliseconds));
        }

        return Response::forOutcome(
            $this->transport->send($envelope, $timeoutMilliseconds)
                ? Outcome::ACCEPTED
                : Outcome::RETRYABLE
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createHttpTransport(string $dsn, array $options): TransportInterface
    {
        // `on_diagnostic` reaches the transport rather than this class: the
        // transport is the only place a response body exists, and it is the one
        // step both the direct path and the spool flusher go through, so the
        // warning is emitted once per envelope on either.
        $diagnostics = Diagnostics::fromOptions($options);
        $client = $options['http_client'] ?? null;
        $requestFactory = $options['request_factory'] ?? null;
        $streamFactory = $options['stream_factory'] ?? null;
        if ($client !== null || $requestFactory !== null || $streamFactory !== null) {
            if (
                !$client instanceof ClientInterface
                || !$requestFactory instanceof RequestFactoryInterface
                || !$streamFactory instanceof StreamFactoryInterface
            ) {
                throw new InvalidArgumentException(
                    'http_client, request_factory and stream_factory must be supplied together'
                );
            }

            return new Psr18Transport($dsn, $client, $requestFactory, $streamFactory, $diagnostics);
        }

        return new CurlTransport($dsn, $diagnostics);
    }

    private function capturePhpError(
        int $severity,
        string $message,
        string $file,
        int $line,
        bool $handled
    ): ?string {
        if ($this->handling) {
            return null;
        }
        $this->handling = true;
        try {
            return $this->queueEvent(
                $this->eventFactory->fromPhpError($severity, $message, $file, $line, $handled)
            );
        } catch (Throwable $ignored) {
            return null;
        } finally {
            $this->handling = false;
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function queueEvent(array $event): ?string
    {
        if ($this->beforeSend !== null) {
            $event = call_user_func($this->beforeSend, $event);
            if ($event === null) {
                return null;
            }
            if (!is_array($event)) {
                return null;
            }
        }
        if (count($this->queue) >= $this->maxQueueSize) {
            array_shift($this->queue);
            $this->discarded++;
        }
        $this->queue[] = $event;

        return isset($event['event_id']) ? (string) $event['event_id'] : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function positiveInteger(array $options, string $key, int $default): int
    {
        if (!isset($options[$key])) {
            return $default;
        }
        $value = (int) $options[$key];
        if ($value < 1) {
            throw new InvalidArgumentException($key . ' must be a positive integer');
        }

        return $value;
    }

    private static function timestamp(): string
    {
        $microtime = microtime(true);
        $seconds = (int) floor($microtime);
        $milliseconds = (int) floor(($microtime - $seconds) * 1000);

        return gmdate('Y-m-d\\TH:i:s', $seconds) . sprintf('.%03dZ', $milliseconds);
    }
}
