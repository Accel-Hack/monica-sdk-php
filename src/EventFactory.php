<?php

declare(strict_types=1);

namespace Monica;

use Throwable;

final class EventFactory
{
    private string $environment;
    private ?string $release;
    private ?string $projectRoot;
    private ?string $serverName;

    public function __construct(
        string $environment,
        ?string $release = null,
        ?string $projectRoot = null,
        ?string $serverName = null
    ) {
        $this->environment = $environment;
        $this->release = $release;
        $this->projectRoot = $projectRoot === null ? null : rtrim($projectRoot, '/\\');
        $this->serverName = $serverName;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function fromThrowable(Throwable $throwable, array $context = []): array
    {
        $values = [];
        $seen = [];
        $current = $throwable;
        $handled = !isset($context['_mechanism_handled']) || (bool) $context['_mechanism_handled'];
        while ($current !== null && count($values) < 10) {
            $objectId = spl_object_hash($current);
            if (isset($seen[$objectId])) {
                break;
            }
            $seen[$objectId] = true;
            $frames = $this->frames($current);
            $value = [
                'type' => get_class($current),
                'value' => $current->getMessage(),
                'mechanism' => ['type' => 'generic', 'handled' => $handled],
            ];
            if ($frames !== []) {
                $value['stacktrace'] = ['frames' => $frames];
            }
            $values[] = $value;
            $current = $current->getPrevious();
        }

        $event = $this->baseEvent(isset($context['level']) ? (string) $context['level'] : 'error');
        $event['message'] = $throwable->getMessage();
        $event['exception'] = ['values' => $values];

        return $this->applyContext($event, $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function fromPhpError(
        int $severity,
        string $message,
        string $file,
        int $line,
        bool $handled,
        array $context = []
    ): array {
        $level = in_array($severity, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
            ? 'fatal'
            : 'warning';
        $event = $this->baseEvent($level);
        $event['message'] = $message;
        $event['exception'] = [
            'values' => [[
                'type' => self::errorType($severity),
                'value' => $message,
                'stacktrace' => [
                    'frames' => [[
                        'filename' => $file !== '' ? $file : '[unknown]',
                        'lineno' => max(1, $line),
                        'in_app' => $this->isInApp($file),
                    ]],
                ],
                'mechanism' => ['type' => 'generic', 'handled' => $handled],
            ]],
        ];

        return $this->applyContext($event, $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function fromMessage(string $message, string $level, array $context = []): array
    {
        $event = $this->baseEvent($level);
        $event['message'] = $message;

        return $this->applyContext($event, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseEvent(string $level): array
    {
        $event = [
            'type' => 'error',
            'event_id' => self::uuidV4(),
            'timestamp' => self::timestamp(),
            'level' => $level,
            'platform' => 'php',
            'environment' => $this->environment,
        ];
        if ($this->release !== null) {
            $event['release'] = $this->release;
        }
        $serverName = $this->serverName;
        if ($serverName === null && function_exists('gethostname')) {
            $detected = gethostname();
            $serverName = $detected === false ? null : $detected;
        }
        if ($serverName !== null && $serverName !== '') {
            $event['server_name'] = $serverName;
        }

        return $event;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function frames(Throwable $throwable): array
    {
        $trace = $throwable->getTrace();
        array_unshift($trace, [
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'function' => '{throw}',
        ]);
        $frames = [];
        foreach (array_reverse(array_slice($trace, 0, 200)) as $frame) {
            $file = isset($frame['file']) ? (string) $frame['file'] : '[internal]';
            $normalized = [
                'filename' => $file,
                'in_app' => $this->isInApp($file),
            ];
            if (isset($frame['function'])) {
                $function = isset($frame['class'])
                    ? (string) $frame['class'] . (isset($frame['type']) ? (string) $frame['type'] : '::') . (string) $frame['function']
                    : (string) $frame['function'];
                $normalized['function'] = $function;
            }
            if (isset($frame['line']) && (int) $frame['line'] > 0) {
                $normalized['lineno'] = (int) $frame['line'];
            }
            $frames[] = $normalized;
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function applyContext(array $event, array $context): array
    {
        foreach (['tags', 'contexts', 'request', 'user', 'fingerprint', 'breadcrumbs'] as $key) {
            if (array_key_exists($key, $context)) {
                $event[$key] = $context[$key];
            }
        }

        return $event;
    }

    private function isInApp(string $file): bool
    {
        if ($file === '' || preg_match('~[\\\\/]vendor[\\\\/]~', $file) === 1) {
            return false;
        }
        if ($this->projectRoot === null) {
            return true;
        }
        $root = $this->projectRoot . DIRECTORY_SEPARATOR;

        return strpos($file, $root) === 0;
    }

    private static function timestamp(): string
    {
        $microtime = microtime(true);
        $seconds = (int) floor($microtime);
        $milliseconds = (int) floor(($microtime - $seconds) * 1000);

        return gmdate('Y-m-d\\TH:i:s', $seconds) . sprintf('.%03dZ', $milliseconds);
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function errorType(int $severity): string
    {
        $types = [
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];

        return isset($types[$severity]) ? $types[$severity] : 'PHPError';
    }
}
