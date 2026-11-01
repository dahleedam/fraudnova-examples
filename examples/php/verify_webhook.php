#!/usr/bin/env php
<?php
// Verify a FraudNova webhook signature.
//
// FraudNova signs the exact raw request body with HMAC-SHA256, keyed with your endpoint's
// signing secret, and sends it as: X-FraudNova-Signature: sha256=<hex>
//
// Usage:
//   FRAUDNOVA_WEBHOOK_SECRET=... php verify_webhook.php <body-file | -> "<signature-header>"
// Exit status: 0 = valid, 1 = invalid, 2 = bad usage. Pass "-" to read the body from stdin.
//
// In a web handler, call verify_signature() with file_get_contents('php://input')
// and $_SERVER['HTTP_X_FRAUDNOVA_SIGNATURE'], not with re-serialised JSON.

function verify_signature(string $secret, string $rawBody, string $signatureHeader): bool
{
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $signatureHeader);
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $secret = getenv('FRAUDNOVA_WEBHOOK_SECRET');
    if (!$secret || $argc !== 3) {
        fwrite(STDERR, "Usage: FRAUDNOVA_WEBHOOK_SECRET=... php verify_webhook.php <body-file|-> <signature-header>\n");
        exit(2);
    }
    $rawBody = $argv[1] === '-' ? stream_get_contents(STDIN) : file_get_contents($argv[1]);
    if ($rawBody === false) {
        fwrite(STDERR, "Could not read body\n");
        exit(2);
    }
    if (verify_signature($secret, $rawBody, trim($argv[2]))) {
        echo "Signature valid\n";
    } else {
        fwrite(STDERR, "Signature INVALID\n");
        exit(1);
    }
}
