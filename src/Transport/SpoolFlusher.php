<?php

declare(strict_types=1);

namespace Monica\Transport;

use Throwable;

final class SpoolFlusher
{
    private string $directory;
    private TransportInterface $transport;
    private int $claimTtlSeconds;

    public function __construct(
        string $directory,
        TransportInterface $transport,
        int $claimTtlSeconds = 300
    ) {
        if ($claimTtlSeconds < 1) {
            throw new \InvalidArgumentException('claimTtlSeconds must be positive');
        }
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $this->transport = $transport;
        $this->claimTtlSeconds = $claimTtlSeconds;
    }

    /**
     * @return array{sent: int, failed: int, invalid: int}
     */
    public function flush(int $timeoutMilliseconds = 2000): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'invalid' => 0];
        $this->recoverStaleClaims();
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $claimed = $this->directory . DIRECTORY_SEPARATOR
                . '.sending-' . (getmypid() ?: 0) . '-' . basename($file);
            // Refresh the lease before making the file a claim. If the process
            // dies after rename, another process will recover it after the TTL.
            @touch($file);
            if (!@rename($file, $claimed)) {
                continue;
            }

            try {
                $json = file_get_contents($claimed);
                $envelope = $json === false ? null : json_decode($json, true);
                if (!is_array($envelope)) {
                    $result['invalid']++;
                    @rename($claimed, $claimed . '.invalid');
                    continue;
                }
                if ($this->transport->send($envelope, $timeoutMilliseconds)) {
                    $result['sent']++;
                    @unlink($claimed);
                    continue;
                }
                $result['failed']++;
                @rename($claimed, $file);
                break;
            } catch (Throwable $ignored) {
                $result['failed']++;
                @rename($claimed, $file);
                break;
            }
        }

        return $result;
    }

    private function recoverStaleClaims(): void
    {
        $claims = glob($this->directory . DIRECTORY_SEPARATOR . '.sending-*.json') ?: [];
        $staleBefore = time() - $this->claimTtlSeconds;
        foreach ($claims as $claim) {
            $modifiedAt = @filemtime($claim);
            if ($modifiedAt === false || $modifiedAt > $staleBefore) {
                continue;
            }
            $basename = basename($claim);
            if (preg_match('/^\\.sending-[0-9]+-(.+\\.json)$/', $basename, $matches) !== 1) {
                continue;
            }
            $target = $this->directory . DIRECTORY_SEPARATOR . $matches[1];
            if (is_file($target)) {
                $target = $this->directory . DIRECTORY_SEPARATOR
                    . gmdate('YmdHis') . '-recovered-' . bin2hex(random_bytes(8)) . '.json';
            }
            @rename($claim, $target);
        }
    }
}
