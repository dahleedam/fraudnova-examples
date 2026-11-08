<?php

declare(strict_types=1);

namespace FraudNovaGate;

use FraudNova\Client;
use FraudNova\IpOptions;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Scores the visitor IP with FraudNova and redirects to a money page or a safe page.
 *
 * Fails open by default: if the API cannot be reached, the visitor goes to the money page.
 */
final class Gate
{
    private const DEFAULTS = [
        'api_key' => null,
        'threshold' => 75,
        'block_on' => ['proxy', 'vpn', 'tor', 'bot_status'],
        'strictness' => 1,
        'cache_ttl' => 86400,
        'cache_dir' => __DIR__.'/cache',
        'timeout_ms' => 2000,
        'fail_open' => true,
        'trust_proxy_headers' => false,
        'report_url' => null,
        'log_file' => null,
        'allowed_countries' => [],
        'routes' => [],
    ];

    /** @var array<string,mixed> */
    private array $config;

    private \Closure $reporter;

    private \Closure $clock;

    /**
     * @param  array<string,mixed>  $config
     * @param  ?\Closure(string $url): void  $reporter
     * @param  ?\Closure(): int  $clock
     */
    public function __construct(array $config, private readonly Client $client, ?\Closure $reporter = null, ?\Closure $clock = null)
    {
        $this->config = array_replace(self::DEFAULTS, $config);

        if (! isset($this->config['routes']['default']['money'], $this->config['routes']['default']['safe'])) {
            throw new \InvalidArgumentException('config "routes" must contain a "default" route with "money" and "safe" URLs');
        }

        $this->reporter = $reporter ?? static function (string $url): void {
            (new GuzzleClient(['timeout' => 1, 'connect_timeout' => 1, 'http_errors' => false]))->get($url);
        };
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Builds the Guzzle-backed client (timeouts from timeout_ms), then the gate.
     *
     * @param  array<string,mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $seconds = max(1, (int) ($config['timeout_ms'] ?? self::DEFAULTS['timeout_ms'])) / 1000;
        $http = new GuzzleClient(['timeout' => $seconds, 'connect_timeout' => $seconds, 'http_errors' => false]);
        $factory = new HttpFactory();
        $apiKey = (string) ($config['api_key'] ?? '');
        if ($apiKey === '') {
            $apiKey = (string) getenv('FRAUDNOVA_API_KEY');
        }

        return new self($config, new Client($apiKey, $http, $factory, $factory), null, null);
    }

    /** @return array{decision:string, score:?int, reason:string, cached:bool} */
    public function decide(?string $ip, ?string $userAgent, ?string $language): array
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return ['decision' => 'allow', 'score' => null, 'reason' => 'no_ip', 'cached' => false];
        }

        $result = $this->readCache($ip);
        $cached = $result !== null;

        if ($result === null) {
            try {
                $r = $this->client->ip($ip, new IpOptions(
                    strictness: (int) $this->config['strictness'],
                    userAgent: $userAgent,
                    userLanguage: $language,
                ));
            } catch (\Throwable) {
                // Any SDK failure (402/429/503, timeout, bad response) is handled the same way.
                return ['decision' => 'error', 'score' => null, 'reason' => 'error', 'cached' => false];
            }

            $result = [
                'fraud_score' => $r->fraudScore,
                'proxy' => $r->proxy,
                'vpn' => $r->vpn,
                'tor' => $r->tor,
                'active_vpn' => $r->activeVpn,
                'active_tor' => $r->activeTor,
                'recent_abuse' => $r->recentAbuse,
                'bot_status' => $r->botStatus,
                'country_code' => $r->countryCode,
            ];
            $this->writeCache($ip, $result);
        }

        $verdict = self::evaluate($result, $this->config);

        return ['decision' => $verdict['decision'], 'score' => $verdict['score'], 'reason' => $verdict['reason'], 'cached' => $cached];
    }

    /**
     * @param  array<string,mixed>  $server
     * @param  array<string,mixed>  $query
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function handle(array $server, array $query): array
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return ['status' => 405, 'headers' => ['Allow' => 'GET, HEAD', 'Cache-Control' => 'no-store'], 'body' => ''];
        }

        $ip = self::clientIp($server, (bool) $this->config['trust_proxy_headers']);
        $decision = $this->decide($ip, $this->header($server, 'HTTP_USER_AGENT'), $this->header($server, 'HTTP_ACCEPT_LANGUAGE'));

        $routeName = isset($query['route']) && is_string($query['route']) && isset($this->config['routes'][$query['route']])
            ? $query['route']
            : 'default';
        $route = $this->config['routes'][$routeName];

        $toMoney = $decision['decision'] === 'allow' || ($decision['decision'] === 'error' && $this->config['fail_open']);
        $location = self::destination((string) $route[$toMoney ? 'money' : 'safe'], $query, $decision);

        if ($decision['decision'] === 'block' && is_string($this->config['report_url']) && $this->config['report_url'] !== '') {
            try {
                ($this->reporter)(self::substitute($this->config['report_url'], $query));
            } catch (\Throwable) {
                // A failing postback must never change where the visitor goes.
            }
        }

        $this->log($ip, $decision, $routeName);

        return ['status' => 302, 'headers' => ['Location' => $location, 'Cache-Control' => 'no-store'], 'body' => ''];
    }

    /** @param array<string,mixed> $server */
    public static function clientIp(array $server, bool $trustProxyHeaders): ?string
    {
        $candidates = [];
        if ($trustProxyHeaders) {
            $candidates[] = $server['HTTP_CF_CONNECTING_IP'] ?? null;
            $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? null;
            if (is_string($forwarded)) {
                $candidates[] = explode(',', $forwarded)[0];
            }
        }
        $candidates[] = $server['REMOTE_ADDR'] ?? null;

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $config
     * @return array{decision:string, score:int, reason:string}
     */
    public static function evaluate(array $result, array $config): array
    {
        $config = array_replace(self::DEFAULTS, $config);
        $score = (int) ($result['fraud_score'] ?? 0);

        if ($score >= (int) $config['threshold']) {
            return ['decision' => 'block', 'score' => $score, 'reason' => 'score'];
        }

        foreach ((array) $config['block_on'] as $flag) {
            if (($result[$flag] ?? null) === true) {
                return ['decision' => 'block', 'score' => $score, 'reason' => 'flag:'.$flag];
            }
        }

        $allowed = array_map('strtoupper', (array) $config['allowed_countries']);
        $country = $result['country_code'] ?? null;
        // An unknown country is not blocked: only a known, non-listed country is.
        if ($allowed !== [] && is_string($country) && $country !== '' && ! in_array(strtoupper($country), $allowed, true)) {
            return ['decision' => 'block', 'score' => $score, 'reason' => 'country'];
        }

        return ['decision' => 'allow', 'score' => $score, 'reason' => 'ok'];
    }

    /**
     * Fills {name} placeholders from the query, then appends query params the URL does not already carry.
     *
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>  $decision
     */
    public static function destination(string $template, array $query, array $decision): string
    {
        $url = self::substitute($template, $query);

        $fragment = '';
        $hashAt = strpos($url, '#');
        if ($hashAt !== false) {
            $fragment = substr($url, $hashAt);
            $url = substr($url, 0, $hashAt);
        }

        $existing = [];
        $queryString = (string) parse_url($url, PHP_URL_QUERY);
        if ($queryString !== '') {
            parse_str($queryString, $existing);
        }

        $extra = [];
        foreach ($query as $name => $value) {
            if ($name === 'route' || ! is_string($value) || array_key_exists($name, $existing)) {
                continue;
            }
            $extra[$name] = $value;
        }

        if ($extra !== []) {
            $url .= (str_contains($url, '?') ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&') : '?').http_build_query($extra, '', '&', PHP_QUERY_RFC3986);
        }

        return $url.$fragment;
    }

    /** @param array<string,mixed> $query */
    private static function substitute(string $template, array $query): string
    {
        return (string) preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', static function (array $m) use ($query): string {
            $value = $query[$m[1]] ?? '';

            return rawurlencode(is_string($value) ? $value : '');
        }, $template);
    }

    /** @param array<string,mixed> $server */
    private function header(array $server, string $key): ?string
    {
        $value = $server[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $value = substr(trim($value), 0, 255);

        return $value === '' ? null : $value;
    }

    private function cachePath(string $ip): string
    {
        return rtrim((string) $this->config['cache_dir'], '/').'/'.hash('sha256', $ip).'.json';
    }

    /** @return ?array<string,mixed> */
    private function readCache(string $ip): ?array
    {
        try {
            $path = $this->cachePath($ip);
            if (! is_file($path)) {
                return null;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (! is_array($data) || ! is_array($data['result'] ?? null) || (int) ($data['expires_at'] ?? 0) <= ($this->clock)()) {
                return null;
            }

            return $data['result'];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $result */
    private function writeCache(string $ip, array $result): void
    {
        try {
            $dir = (string) $this->config['cache_dir'];
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                return;
            }
            if (! is_writable($dir)) {
                return;
            }
            @file_put_contents(
                $this->cachePath($ip),
                json_encode(['expires_at' => ($this->clock)() + (int) $this->config['cache_ttl'], 'result' => $result]),
                LOCK_EX,
            );
        } catch (\Throwable) {
            // An unwritable cache just means the next click is scored again.
        }
    }

    /** @param array{decision:string, score:?int, reason:string, cached:bool} $decision */
    private function log(?string $ip, array $decision, string $route): void
    {
        $file = $this->config['log_file'];
        if (! is_string($file) || $file === '') {
            return;
        }
        try {
            $line = implode(' ', [
                date('c'), $ip ?? '-', $decision['decision'], $decision['score'] ?? '-', $decision['reason'], $route, $decision['cached'] ? 'cached' : 'live',
            ]).PHP_EOL;
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
        }
    }
}
