<?php

declare(strict_types=1);

// Entry point: point your tracker's redirect at https://your-domain/gate.php?clickid={clickid}
require __DIR__.'/vendor/autoload.php';

use FraudNovaGate\Gate;

// A broken config.php must be loud, so loading it and building the gate stay outside the catch below.
$config = require __DIR__.'/config.php';
$gate = Gate::fromConfig($config);

try {
    $response = $gate->handle($_SERVER, $_GET);
} catch (\Throwable $e) {
    // Nothing unexpected may strand the visitor: send them to the safe page and log the class name only.
    error_log('fraudnova-gate: unexpected error ('.(new \ReflectionClass($e))->getShortName().'), sent to the safe page');
    $response = ['status' => 302, 'headers' => ['Location' => (string) $config['routes']['default']['safe'], 'Cache-Control' => 'no-store'], 'body' => ''];
}

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name.': '.$value);
}
echo $response['body'];
