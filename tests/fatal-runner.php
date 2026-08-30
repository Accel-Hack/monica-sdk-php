<?php

declare(strict_types=1);

$spoolDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'monica-fatal-test-' . bin2hex(random_bytes(5));
$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg(__DIR__ . '/fatal-child.php')
    . ' ' . escapeshellarg($spoolDirectory)
    . ' 2>&1';
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
if ($exitCode === 0) {
    throw new RuntimeException('fatal child should exit unsuccessfully');
}

$files = glob($spoolDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [];
if (count($files) !== 1) {
    throw new RuntimeException('fatal shutdown should create exactly one spool envelope');
}
$json = file_get_contents($files[0]);
$envelope = $json === false ? null : json_decode($json, true);
$item = is_array($envelope) && isset($envelope['items'][0]) ? $envelope['items'][0] : null;
if (!is_array($item) || $item['level'] !== 'fatal') {
    throw new RuntimeException('shutdown event should use fatal level');
}
if ($item['exception']['values'][0]['mechanism']['handled'] !== false) {
    throw new RuntimeException('shutdown event should be marked unhandled');
}
if ($item['exception']['values'][0]['type'] !== 'E_USER_ERROR') {
    throw new RuntimeException('shutdown event should retain the PHP fatal type');
}

@unlink($files[0]);
@rmdir($spoolDirectory);
echo "MONICA PHP fatal shutdown test passed\n";
