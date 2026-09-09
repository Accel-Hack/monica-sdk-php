<?php

declare(strict_types=1);

namespace Monica\Transport;

use RuntimeException;

final class SpoolTransport implements TransportInterface
{
    /** How long a `.tmp` file is assumed to still be in the middle of a write. */
    private const TEMPORARY_GRACE_SECONDS = 300;

    private string $directory;
    private int $maxFiles;

    public function __construct(string $directory, int $maxFiles = 1000)
    {
        if ($directory === '') {
            throw new RuntimeException('spool_dir must not be empty');
        }
        if ($maxFiles < 1) {
            throw new RuntimeException('spool_max_files must be positive');
        }
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $this->maxFiles = $maxFiles;
    }

    public function send(array $envelope, int $timeoutMilliseconds): bool
    {
        unset($timeoutMilliseconds);
        $this->ensureDirectory();
        $json = json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $name = sprintf(
            '%s-%s-%s.json',
            gmdate('YmdHis'),
            getmypid() ?: 0,
            bin2hex(random_bytes(8))
        );
        $target = $this->directory . DIRECTORY_SEPARATOR . $name;
        $temporary = $this->directory . DIRECTORY_SEPARATOR . '.' . $name . '.tmp';
        // The temporary filename is random and becomes visible only through an
        // atomic rename, so a process-wide advisory lock is unnecessary.
        if (file_put_contents($temporary, $json) === false) {
            return false;
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            return false;
        }
        $this->prune();

        return true;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create MONICA spool directory');
        }
        @chmod($this->directory, 0700);
    }

    /**
     * Keep the directory within `maxFiles`.
     *
     * The flusher retires what it cannot deliver by renaming it aside
     * (`.rejected`, `.invalid`), and a process that dies between the write and
     * the rename leaves a `.tmp` behind. None of those match `*.json`, so
     * counting only pending envelopes would let the directory grow without
     * bound while reporting itself as capped.
     *
     * Envelopes still waiting to be sent are the last thing to go: a retired
     * file cannot be delivered any more, so it is cheaper to lose.
     *
     * Live claims (`.sending-*.json`) are neither counted nor removed. Another
     * process is mid-request on them, and the flusher recovers them by lease if
     * that process dies.
     */
    private function prune(): void
    {
        $pending = glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $retired = array_merge(
            glob($this->directory . DIRECTORY_SEPARATOR . '.sending-*.json.rejected') ?: [],
            glob($this->directory . DIRECTORY_SEPARATOR . '.sending-*.json.invalid') ?: [],
            $this->abandonedTemporaries()
        );
        $remove = count($pending) + count($retired) - $this->maxFiles;
        if ($remove <= 0) {
            return;
        }

        self::sortByAge($retired);
        self::sortByAge($pending);
        foreach (array_merge($retired, $pending) as $file) {
            if ($remove-- <= 0) {
                return;
            }
            @unlink($file);
        }
    }

    /**
     * `.tmp` files old enough that no one can still be writing them.
     *
     * A temporary file is visible for as long as one `file_put_contents` takes,
     * so anything older than the grace period is the remains of a process that
     * died mid-write. Without the grace period this would delete a concurrent
     * writer's file: its name sorts among the oldest here, not the newest.
     *
     * @return list<string>
     */
    private function abandonedTemporaries(): array
    {
        $abandoned = [];
        $writtenBefore = time() - self::TEMPORARY_GRACE_SECONDS;
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '.*.json.tmp') ?: [] as $file) {
            $modifiedAt = @filemtime($file);
            if ($modifiedAt !== false && $modifiedAt < $writtenBefore) {
                $abandoned[] = $file;
            }
        }

        return $abandoned;
    }

    /**
     * Oldest first. Every name this class and the flusher produce carries the
     * creation time as the first run of 14 digits, which is what makes files
     * from the different shapes comparable at all: sorting the paths would
     * order them by their prefix (`.sending-`, `.`) instead of by age.
     *
     * @param list<string> $files
     */
    private static function sortByAge(array &$files): void
    {
        usort($files, static function (string $a, string $b): int {
            return [self::createdAt($a), $a] <=> [self::createdAt($b), $b];
        });
    }

    private static function createdAt(string $path): string
    {
        return preg_match('/(\d{14})/', basename($path), $matches) === 1 ? $matches[1] : '';
    }
}
