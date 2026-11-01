#!/usr/bin/env php
<?php
// Score several email addresss in one request (1 credit per successful check, up to 100).
// Usage: FRAUDNOVA_API_KEY=fn_... php batch_email.php <email> [<email> ...]
// Requires: PHP 7.4+ with ext-curl (no Composer needed).

$baseUrl = rtrim(getenv('FRAUDNOVA_BASE_URL') ?: 'https://fraudnova.com/api/v1', '/');

function fail(string $message, int $code = 1): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

/** Send a request; return the decoded JSON body or exit non-zero on any HTTP/API error. */
function call(string $method, string $url, ?string $apiKey, ?array $json = null): array
{
    $headers = ['Accept: application/json'];
    if ($apiKey !== null) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    if ($raw === false) {
        fail('Request failed: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = [];
    }
    if ($status !== 200 || empty($body['success'])) {
        fail(sprintf('FraudNova API error (HTTP %d): %s', $status, $body['message'] ?? $raw));
    }
    return $body;
}

$flags = ['valid', 'disposable', 'deliverability', 'dns_valid', 'honeypot', 'suspect', 'recent_abuse', 'leaked'];

$apiKey = getenv('FRAUDNOVA_API_KEY');
if (!$apiKey) {
    fail('Set FRAUDNOVA_API_KEY to your FraudNova API key');
}
if ($argc < 2) {
    fail('Usage: php batch_email.php <email> [<email> ...]');
}

$payload = ['emails' => array_slice($argv, 1)];

$body = call('POST', $baseUrl . '/check/email/batch', $apiKey, $payload);

$failed = 0;
foreach ($body['results'] as $item) {
    if (!empty($item['success'])) {
        $parts = [];
        foreach ($flags as $flag) {
            $value = $item['data'][$flag] ?? null;
            $parts[] = $flag . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : var_export($value, true));
        }
        echo $item['email'] . ' fraud_score=' . $item['data']['fraud_score'] . ' ' . implode(' ', $parts) . PHP_EOL;
    } else {
        $failed++;
        echo $item['email'] . ' error=' . ($item['error'] ?? 'unknown') . PHP_EOL;
    }
}
echo 'credits_remaining: ' . ($body['credits_remaining'] ?? 'n/a') . PHP_EOL;
if ($failed > 0) {
    exit(2); // partial failure: some items were not scored
}
