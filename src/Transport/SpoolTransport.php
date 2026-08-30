<?php

declare(strict_types=1);

namespace Monica\Transport;

use RuntimeException;

final class SpoolTransport implements TransportInterface
{
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

    private function prune(): void
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files, SORT_STRING);
        $remove = count($files) - $this->maxFiles;
        for ($index = 0; $index < $remove; $index++) {
            @unlink($files[$index]);
        }
    }
}
