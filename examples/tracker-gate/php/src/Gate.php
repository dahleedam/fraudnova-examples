<?php

declare(strict_types=1);

namespace FraudNovaGate;

use FraudNova\Client;
use FraudNova\Exception\ApiException;
use FraudNova\IpOptions;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Scores the visitor IP with FraudNova and redirects to a money page or a safe page.
 *
 * Fails open by default: if the API cannot be reached, the visitor goes to the money page.
 * A broken configuration is the opposite: it throws at construction, so it is never silently fail-open.
 *
 * Decision reasons: score, flag:<name>, country, ok, error, no_ip, head.
 */
final class Gate
{
    private const DEFAULTS = [
        'api_key' => null,
        'threshold' => 75,
        'block_on' => ['proxy', 'vpn', 'tor', 'bot_status'],
        'strictness' => 1,
        'cache_ttl' => 86400,
        'cache_dir' => __DIR__.'/../cache',
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
        self::validate($this->config);

        $this->reporter = $reporter ?? static function (string $url): void {
            (new GuzzleClient(['timeout' => 1, 'connect_timeout' => 1, 'http_errors' => false]))->get($url);
        };
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** The cache directory used when the config does not set one. */
    public static function defaultCacheDir(): string
    {
        return self::DEFAULTS['cache_dir'];
    }

    /** @param array<string,mixed> $config */
    private static function validate(array $config): void
    {
        $key = $config['api_key'];
        if (! is_string($key) || trim($key) === '' || $key === 'fn_your_api_key_here') {
            throw new \InvalidArgumentException('config "api_key" is missing or still the placeholder; set your FraudNova API key (or FRAUDNOVA_API_KEY)');
        }
        if (! is_int($config['strictness']) || $config['strictness'] < 0 || $config['strictness'] > 3) {
            throw new \InvalidArgumentException('config "strictness" must be an integer from 0 to 3');
        }
        if (! is_int($config['threshold']) || $config['threshold'] < 0 || $config['threshold'] > 100) {
            throw new \InvalidArgumentException('config "threshold" must be an integer from 0 to 100');
        }
        if (! is_int($config['timeout_ms']) || $config['timeout_ms'] <= 0) {
            throw new \InvalidArgumentException('config "timeout_ms" must be a positive integer');
        }
        self::trustMode($config['trust_proxy_headers']);
        if (! is_array($config['routes']) || ! isset($config['routes']['default'])) {
            throw new \InvalidArgumentException('config "routes" must contain a "default" route');
        }
        foreach ($config['routes'] as $name => $route) {
            foreach (['money', 'safe'] as $kind) {
                $url = is_array($route) ? ($route[$kind] ?? null) : null;
                if (! is_string($url) || $url === '' || preg_match('#^https?://[^\s/]+#i', $url) !== 1) {
                    throw new \InvalidArgumentException(sprintf('config route "%s" needs an absolute http(s) "%s" URL', $name, $kind));
                }
            }
        }
    }

    /**
     * Normalizes trust_proxy_headers: false (connection address only), true (CF-Connecting-IP, then the
     * first X-Forwarded-For entry), or one header name ('cf-connecting-ip' / 'x-forwarded-for', any case)
     * meaning only that header is trusted.
     *
     * @return bool|'cf-connecting-ip'|'x-forwarded-for'
     */
    public static function trustMode(mixed $value): bool|string
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $name = strtolower(trim($value));
            if ($name === 'cf-connecting-ip' || $name === 'x-forwarded-for') {
                return $name;
            }
        }

        throw new \InvalidArgumentException('config "trust_proxy_headers" must be false, true, \'cf-connecting-ip\' or \'x-forwarded-for\'');
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
        $config['api_key'] = $apiKey;
        self::validate(array_replace(self::DEFAULTS, $config));

        return new self($config, new Client($apiKey, $http, $factory, $factory), null, null);
    }

    /** @return array{decision:string, score:?int, reason:string, cached:bool, error?:string} */
    public function decide(?string $ip, ?string $userAgent, ?string $language): array
    {
        $ip = $ip === null ? null : self::normalizeIp($ip);
        if ($ip === null || ! self::isPublicIp($ip)) {
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
            } catch (\Throwable $e) {
                // Any SDK failure (402/429/503, timeout, bad response) is handled the same way.
                // Only the class name and status are recorded: the message could echo the key or a URL.
                $error = (new \ReflectionClass($e))->getShortName();
                if ($e instanceof ApiException) {
                    $error .= ' HTTP '.$e->statusCode();
                }

                return ['decision' => 'error', 'score' => null, 'reason' => 'error', 'cached' => false, 'error' => $error];
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

        $trust = self::trustMode($this->config['trust_proxy_headers']);
        $ip = self::clientIp($server, $trust);
        if ($trust === false) {
            $this->warnIfBehindProxy($server);
        }
        $decision = $method === 'HEAD'
            // Prefetchers and link checkers send HEAD: no credit, no postback, and they only ever see the safe URL.
            ? ['decision' => 'block', 'score' => null, 'reason' => 'head', 'cached' => false]
            : $this->decide($ip, $this->header($server, 'HTTP_USER_AGENT'), $this->header($server, 'HTTP_ACCEPT_LANGUAGE'));

        $routeName = isset($query['route']) && is_string($query['route']) && isset($this->config['routes'][$query['route']])
            ? $query['route']
            : 'default';
        $route = $this->config['routes'][$routeName];

        $toMoney = $decision['decision'] === 'allow' || ($decision['decision'] === 'error' && $this->config['fail_open']);
        $location = self::destination((string) $route[$toMoney ? 'money' : 'safe'], $query, $decision);
        // e.g. a placeholder in the host that expanded to nothing leaves no host behind.
        $parts = parse_url($location);
        if (preg_match('#^https?://[^\s/?\#]+#i', $location) !== 1 || ! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
            $location = (string) $route['safe'];
        }

        if ($decision['decision'] === 'block' && $decision['reason'] !== 'head' && is_string($this->config['report_url']) && $this->config['report_url'] !== '') {
            try {
                ($this->reporter)(self::substitute($this->config['report_url'], $query));
            } catch (\Throwable) {
                // A failing postback must never change where the visitor goes.
            }
        }

        $this->log($ip, $decision, $routeName);

        return ['status' => 302, 'headers' => ['Location' => $location, 'Cache-Control' => 'no-store'], 'body' => ''];
    }

    /**
     * The visitor IP: the trusted header(s) first (see trustMode()), then the connection address.
     * Private, loopback, link-local and reserved addresses are skipped; null when no public address remains.
     *
     * @param  array<string,mixed>  $server
     */
    public static function clientIp(array $server, bool|string $trustProxyHeaders): ?string
    {
        $trust = self::trustMode($trustProxyHeaders);
        $candidates = [];
        if ($trust === true || $trust === 'cf-connecting-ip') {
            $candidates[] = $server['HTTP_CF_CONNECTING_IP'] ?? null;
        }
        if ($trust === true || $trust === 'x-forwarded-for') {
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
            $ip = self::normalizeIp($candidate);
            if ($ip !== null && self::isPublicIp($ip)) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * False for private, loopback, link-local, shared (100.64/10), unspecified and other reserved
     * addresses. Expects a normalized IP (an IPv4-mapped address already unmapped).
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            // 100.64.0.0/10 (carrier-grade NAT) is not covered by the flags above.
            $long = (int) ip2long($ip);

            return ($long & 0xFFC00000) !== (100 << 24 | 64 << 16);
        }

        return true;
    }

    /**
     * A private connection address with trust_proxy_headers off almost always means a proxy sits in
     * front of the gate: every click would then be no_ip. Say so in the error log.
     *
     * @param  array<string,mixed>  $server
     */
    private function warnIfBehindProxy(array $server): void
    {
        $remote = $server['REMOTE_ADDR'] ?? null;
        $ip = is_string($remote) ? self::normalizeIp($remote) : null;
        if ($ip !== null && ! self::isPublicIp($ip)) {
            error_log('fraudnova-gate: the connection address is private, so the gate appears to sit behind a proxy and trust_proxy_headers is off; clicks are not scored (no_ip). See the README to set trust_proxy_headers.');
        }
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
        // $decision is unused; kept for interface symmetry with the other decision-aware methods.
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

    private static function canonicalIp(string $ip): string
    {
        $packed = @inet_pton($ip);
        $text = $packed === false ? false : inet_ntop($packed);

        return $text === false ? $ip : $text;
    }

    /**
     * Returns a valid IP in canonical text form, or null. An IPv4-mapped IPv6 address
     * (::ffff:203.0.113.5, common behind dual-stack servers) becomes plain IPv4; a ::ffff: prefix
     * followed by anything that is not a valid IPv4 is rejected.
     */
    private static function normalizeIp(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $ip = self::canonicalIp($ip);
        if (stripos($ip, '::ffff:') === 0) {
            $rest = substr($ip, 7);

            return filter_var($rest, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $rest : null;
        }

        return $ip;
    }

    private function cachePath(string $ip): string
    {
        return rtrim((string) $this->config['cache_dir'], '/').'/'.hash('sha256', self::canonicalIp($ip)).'.json';
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

    /** @param array{decision:string, score:?int, reason:string, cached:bool, error?:string} $decision */
    private function log(?string $ip, array $decision, string $route): void
    {
        $file = $this->config['log_file'];
        if (! is_string($file) || $file === '') {
            if (isset($decision['error'])) {
                error_log('fraudnova-gate: lookup failed ('.$decision['error'].'), '.$decision['decision'].' for '.($ip ?? '-'));
            }

            return;
        }
        try {
            $line = implode(' ', [
                date('c'), $ip ?? '-', $decision['decision'], $decision['score'] ?? '-', $decision['reason'], $route, $decision['cached'] ? 'cached' : 'live',
            ]).(isset($decision['error']) ? ' error='.$decision['error'] : '').PHP_EOL;
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
        }
    }
}
