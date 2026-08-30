<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Monica\Client;

$spoolDirectory = isset($argv[1]) ? (string) $argv[1] : '';
new Client([
    'dsn' => 'https://secret@ingest.example.test/1',
    'environment' => 'test',
    'transport' => 'spool',
    'spool_dir' => $spoolDirectory,
]);

trigger_error('fatal shutdown test', E_USER_ERROR);
