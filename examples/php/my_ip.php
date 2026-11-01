#!/usr/bin/env php
<?php
// Return the IP address FraudNova sees for this machine (free, no API key needed).
// Usage: FRAUDNOVA_API_KEY=fn_... php my_ip.php 
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

$body = call('GET', $baseUrl . '/my-ip', null);
echo 'ip: ' . $body['ip'] . PHP_EOL;
