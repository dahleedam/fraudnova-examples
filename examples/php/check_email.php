#!/usr/bin/env php
<?php
// Score one email address (1 credit).
// Usage: FRAUDNOVA_API_KEY=fn_... php check_email.php <email>
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
    fail('Usage: php check_email.php <email>');
}

$params = ['email' => $argv[1]];

$body = call('GET', $baseUrl . '/check/email?' . http_build_query($params), $apiKey);

$data = $body['data'];
echo 'fraud_score: ' . $data['fraud_score'] . PHP_EOL;
foreach ($flags as $flag) {
    $value = $data[$flag] ?? null;
    echo $flag . ': ' . (is_bool($value) ? ($value ? 'true' : 'false') : var_export($value, true)) . PHP_EOL;
}
echo 'credits_remaining: ' . ($body['credits_remaining'] ?? 'n/a') . PHP_EOL;
