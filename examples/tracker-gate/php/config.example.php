<?php

declare(strict_types=1);

// Copy to config.php and edit. config.php is git-ignored.
return [
    // Your FraudNova API key (create one at https://fraudnova.com/dashboard/api-keys).
    'api_key' => getenv('FRAUDNOVA_API_KEY') ?: 'fn_your_api_key_here',

    // Fraud score (0-100) at or above which the click goes to the safe page.
    'threshold' => 75,

    // Flags that send the click to the safe page when true. Also available: active_vpn, active_tor, recent_abuse.
    'block_on' => ['proxy', 'vpn', 'tor', 'bot_status'],

    // How strict the scoring is, 0 (lenient) to 3 (strict).
    'strictness' => 1,

    // Seconds a scored IP is remembered, so repeat clicks cost no credit.
    'cache_ttl' => 86400,

    // Where cached results are stored (must be writable and not web-readable;
    // cache/.htaccess covers Apache only, nginx users must deny /cache themselves).
    'cache_dir' => __DIR__.'/cache',

    // Give up on the API after this many milliseconds.
    'timeout_ms' => 2000,

    // true: send visitors to the money page when the API errors or times out. false: send them to the safe page.
    'fail_open' => true,

    // Where the visitor IP comes from. false: the connection address only (no proxy in front).
    // 'x-forwarded-for' or 'cf-connecting-ip': trust only that header (behind nginx/Caddy or Cloudflare).
    // true: CF-Connecting-IP, then X-Forwarded-For, only if every request passes a proxy that OVERWRITES both.
    // A header your proxy appends to (or passes through) lets visitors pick their own IP. See the README.
    'trust_proxy_headers' => false,

    // Optional postback sent when a click is blocked. Supports {placeholders} from the query string.
    // It runs synchronously, capped at 1 second, before the visitor is redirected.
    'report_url' => null,

    // Optional file that gets one log line per click.
    'log_file' => null,

    // Optional list of allowed ISO country codes, e.g. ['US', 'PK']. Empty = any country.
    'allowed_countries' => [],

    // Destinations. Use ?route=name on the gate URL to pick one; unknown names use "default".
    // {placeholders} are filled from the query string; other query params are passed through.
    'routes' => [
        'default' => [
            'money' => 'https://your-landing-page.example/offer?clickid={clickid}',
            'safe' => 'https://your-safe-page.example/',
        ],
    ],
];
