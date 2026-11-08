<?php

declare(strict_types=1);

// Entry point: point your tracker's redirect at https://your-domain/go.php?clickid={clickid}
// (Named go.php, not gate.php, because gate.php and Gate.php collide on case-insensitive file systems.)
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/Gate.php';

use FraudNovaGate\Gate;

$config = require __DIR__.'/config.php';

$response = Gate::fromConfig($config)->handle($_SERVER, $_GET);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name.': '.$value);
}
echo $response['body'];
