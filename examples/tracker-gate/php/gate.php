<?php

declare(strict_types=1);

// Entry point: point your tracker's redirect at https://your-domain/gate.php?clickid={clickid}
require __DIR__.'/vendor/autoload.php';

use FraudNovaGate\Gate;

$config = require __DIR__.'/config.php';

$response = Gate::fromConfig($config)->handle($_SERVER, $_GET);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name.': '.$value);
}
echo $response['body'];
