<?php

declare(strict_types=1);

namespace FraudNovaGate\Tests;

use FraudNova\Client;
use FraudNovaGate\Gate;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class StubHttpClient implements ClientInterface
{
    /** @var list<Response|\Throwable> */
    public array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \LogicException('Stub queue is empty');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}

final class GateTest extends TestCase
{
    private StubHttpClient $stub;

    /** @var list<string> */
    private array $tempPaths = [];

    private string|false $previousErrorLog = false;

    protected function setUp(): void
    {
        $this->stub = new StubHttpClient();
        // Keep the gate's error_log() lines out of the test output.
        $this->previousErrorLog = ini_set('error_log', $this->tempFile());
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
        foreach ($this->tempPaths as $path) {
            if (is_dir($path)) {
                array_map('unlink', glob($path.'/*') ?: []);
                @rmdir($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempPaths = [];
    }

    private function tempFile(): string
    {
        $path = sys_get_temp_dir().'/gate-log-'.uniqid();
        $this->tempPaths[] = $path;

        return $path;
    }

    /** @param array<string,mixed> $overrides */
    private function gate(array $overrides = [], ?\Closure $reporter = null, ?\Closure $clock = null): Gate
    {
        $cacheDir = sys_get_temp_dir().'/gate-'.uniqid();
        $this->tempPaths[] = $cacheDir;
        $config = array_replace([
            'api_key' => 'fn_test',
            'cache_dir' => $cacheDir,
            'routes' => [
                'default' => ['money' => 'https://lp.example/?clickid={clickid}', 'safe' => 'https://safe.example/'],
                'alt' => ['money' => 'https://alt.example/', 'safe' => 'https://safe.example/'],
            ],
        ], $overrides);
        $factory = new HttpFactory();
        $client = new Client('fn_test', $this->stub, $factory, $factory);

        return new Gate($config, $client, $reporter, $clock);
    }

    /** @param array<string,mixed> $data */
    private function ok(int $score, array $data = []): Response
    {
        $data = array_replace([
            'fraud_score' => $score, 'proxy' => false, 'vpn' => false, 'tor' => false,
            'active_vpn' => false, 'active_tor' => false, 'recent_abuse' => false,
            'bot_status' => false, 'country_code' => 'US',
        ], $data);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => true, 'data' => $data, 'credits_remaining' => 9]));
    }

    /** @return array<string,mixed> */
    private function server(string $ip = '203.0.113.5'): array
    {
        return ['REMOTE_ADDR' => $ip, 'REQUEST_METHOD' => 'GET'];
    }

    public function test_low_score_allows_and_redirects_to_money_with_clickid(): void
    {
        $this->stub->queue[] = $this->ok(10);
        $gate = $this->gate();

        $decision = $gate->decide('203.0.113.5', null, null);
        $this->assertSame('allow', $decision['decision']);
        $this->assertSame('ok', $decision['reason']);

        $this->stub->queue[] = $this->ok(10);
        $r = $this->gate()->handle($this->server(), ['clickid' => 'abc']);
        $this->assertSame(302, $r['status']);
        $this->assertSame('https://lp.example/?clickid=abc', $r['headers']['Location']);
        $this->assertSame('no-store', $r['headers']['Cache-Control']);
    }

    public function test_score_at_threshold_blocks(): void
    {
        $this->stub->queue[] = $this->ok(75);
        $gate = $this->gate();
        $d = $gate->decide('203.0.113.5', null, null);
        $this->assertSame('block', $d['decision']);
        $this->assertSame('score', $d['reason']);

        $this->stub->queue[] = $this->ok(75);
        $r = $this->gate()->handle($this->server(), []);
        $this->assertStringStartsWith('https://safe.example/', $r['headers']['Location']);
    }

    /** @return array<string,array{string}> */
    public static function flags(): array
    {
        return ['proxy' => ['proxy'], 'vpn' => ['vpn'], 'tor' => ['tor'], 'bot_status' => ['bot_status']];
    }

    #[DataProvider('flags')]
    public function test_each_default_flag_blocks(string $flag): void
    {
        $this->stub->queue[] = $this->ok(5, [$flag => true]);
        $d = $this->gate()->decide('203.0.113.5', null, null);
        $this->assertSame('block', $d['decision']);
        $this->assertSame('flag:'.$flag, $d['reason']);
    }

    public function test_active_vpn_blocks_only_when_opted_in(): void
    {
        $this->stub->queue[] = $this->ok(5, ['active_vpn' => true]);
        $this->assertSame('allow', $this->gate()->decide('203.0.113.5', null, null)['decision']);

        $this->stub->queue[] = $this->ok(5, ['active_vpn' => true]);
        $d = $this->gate(['block_on' => ['proxy', 'vpn', 'tor', 'bot_status', 'active_vpn']])->decide('203.0.113.5', null, null);
        $this->assertSame('block', $d['decision']);
        $this->assertSame('flag:active_vpn', $d['reason']);
    }

    public function test_allowed_countries_blocks_other_countries(): void
    {
        $this->stub->queue[] = $this->ok(5, ['country_code' => 'US']);
        $d = $this->gate(['allowed_countries' => ['PK']])->decide('203.0.113.5', null, null);
        $this->assertSame('block', $d['decision']);
        $this->assertSame('country', $d['reason']);
    }

    public function test_second_call_for_same_ip_uses_cache(): void
    {
        $this->stub->queue[] = $this->ok(10);
        $gate = $this->gate();
        $first = $gate->decide('203.0.113.5', null, null);
        $second = $gate->decide('203.0.113.5', null, null);

        $this->assertCount(1, $this->stub->requests);
        $this->assertFalse($first['cached']);
        $this->assertTrue($second['cached']);
        $this->assertSame('allow', $second['decision']);
    }

    public function test_cache_expires_after_ttl(): void
    {
        $now = 1_000_000;
        $gate = $this->gate([], null, function () use (&$now): int {
            return $now;
        });
        $this->stub->queue[] = $this->ok(10);
        $this->stub->queue[] = $this->ok(10);

        $gate->decide('203.0.113.5', null, null);
        $now += 86400 + 1;
        $gate->decide('203.0.113.5', null, null);

        $this->assertCount(2, $this->stub->requests);
    }

    /** @return array<string,array{\Closure}> */
    public static function failures(): array
    {
        $body = '{"success":false,"message":"x"}';

        return [
            '402' => [fn () => new Response(402, [], $body)],
            '429' => [fn () => new Response(429, [], $body)],
            '503' => [fn () => new Response(503, [], $body)],
            'transport' => [fn () => new ConnectException('timeout', new Request('GET', '/'))],
        ];
    }

    #[DataProvider('failures')]
    public function test_fails_open_on_402_429_503_and_transport_error(\Closure $failure): void
    {
        $this->stub->queue[] = $failure();
        $gate = $this->gate();
        $d = $gate->decide('203.0.113.5', null, null);
        $this->assertSame('error', $d['decision']);
        $this->assertSame('error', $d['reason']);
        $this->assertNull($d['score']);
        $this->assertMatchesRegularExpression('/^\w+Exception( HTTP \d+)?$/', $d['error']);

        $this->stub->queue[] = $failure();
        $r = $this->gate()->handle($this->server(), ['clickid' => 'abc']);
        $this->assertSame('lp.example', parse_url($r['headers']['Location'], PHP_URL_HOST));

        // Nothing cached: a following call makes a fresh request.
        $before = count($this->stub->requests);
        $this->stub->queue[] = $this->ok(10);
        $gate->decide('203.0.113.5', null, null);
        $this->assertSame($before + 1, count($this->stub->requests));
    }

    public function test_fails_closed_when_configured(): void
    {
        $this->stub->queue[] = new Response(503, [], '{"success":false,"message":"x"}');
        $r = $this->gate(['fail_open' => false])->handle($this->server(), []);
        $this->assertStringStartsWith('https://safe.example/', $r['headers']['Location']);
    }

    public function test_no_ip_allows_without_calling_api(): void
    {
        $gate = $this->gate();
        $d = $gate->decide(null, 'ua', 'en');
        $this->assertSame('allow', $d['decision']);
        $this->assertSame('no_ip', $d['reason']);

        $r = $gate->handle(['REMOTE_ADDR' => ''], []);
        $this->assertSame(302, $r['status']);
        $this->assertStringStartsWith('https://lp.example/', $r['headers']['Location']);
        $this->assertCount(0, $this->stub->requests);
    }

    public function test_unknown_route_falls_back_to_default(): void
    {
        $this->stub->queue[] = $this->ok(10);
        $gate = $this->gate();
        $r = $gate->handle($this->server(), ['route' => '../evil']);
        $this->assertStringStartsWith('https://lp.example/', $r['headers']['Location']);

        $r = $gate->handle($this->server(), ['route' => 'alt']);
        $this->assertStringStartsWith('https://alt.example/', $r['headers']['Location']);
    }

    public function test_pass_through_skips_params_already_present(): void
    {
        $this->stub->queue[] = $this->ok(10);
        $r = $this->gate()->handle($this->server(), ['clickid' => 'abc', 'sub1' => 'x', 'route' => 'default']);
        $this->assertSame('https://lp.example/?clickid=abc&sub1=x', $r['headers']['Location']);
    }

    public function test_report_only_on_block(): void
    {
        $reported = [];
        $reporter = function (string $url) use (&$reported): void {
            $reported[] = $url;
        };
        $config = ['report_url' => 'https://t.example/pb?cid={clickid}&et=blocked'];

        $this->stub->queue[] = $this->ok(99);
        $this->gate($config, $reporter)->handle($this->server('203.0.113.10'), ['clickid' => 'abc']);
        $this->assertSame(['https://t.example/pb?cid=abc&et=blocked'], $reported);

        $reported = [];
        $this->stub->queue[] = $this->ok(1);
        $this->gate($config, $reporter)->handle($this->server('203.0.113.11'), ['clickid' => 'abc']);
        $this->stub->queue[] = new Response(503, [], '{"success":false,"message":"x"}');
        $this->gate($config, $reporter)->handle($this->server('203.0.113.12'), ['clickid' => 'abc']);
        $this->assertSame([], $reported);
    }

    public function test_report_failure_does_not_change_the_redirect(): void
    {
        $this->stub->queue[] = $this->ok(99);
        $gate = $this->gate(
            ['report_url' => 'https://t.example/pb?cid={clickid}'],
            function (string $url): void {
                throw new \RuntimeException('boom');
            },
        );
        $r = $gate->handle($this->server(), ['clickid' => 'abc']);
        $this->assertSame(302, $r['status']);
        $this->assertStringStartsWith('https://safe.example/', $r['headers']['Location']);
    }

    public function test_unwritable_cache_dir_still_decides(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gate-file');
        $this->stub->queue[] = $this->ok(99);
        $d = $this->gate(['cache_dir' => $file])->decide('203.0.113.5', null, null);
        $this->assertSame('block', $d['decision']);
        $this->assertFalse($d['cached']);
        unlink($file);
    }

    public function test_client_ip_honours_trusted_headers(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.2'];
        $this->assertSame('10.0.0.1', Gate::clientIp($server, false));
        $this->assertSame('203.0.113.9', Gate::clientIp($server, true));

        unset($server['HTTP_CF_CONNECTING_IP']);
        $this->assertSame('198.51.100.7', Gate::clientIp($server, true));

        $server['HTTP_X_FORWARDED_FOR'] = 'garbage';
        $this->assertSame('10.0.0.1', Gate::clientIp($server, true));
    }

    public function test_user_agent_and_language_are_sent(): void
    {
        $this->stub->queue[] = $this->ok(10);
        $server = $this->server() + ['HTTP_USER_AGENT' => 'Mozilla/5.0 Test', 'HTTP_ACCEPT_LANGUAGE' => 'en-US'];
        $this->gate()->handle($server, []);

        $query = $this->stub->requests[0]->getUri()->getQuery();
        $this->assertStringContainsString('user_agent=', $query);
        $this->assertStringContainsString('user_language=', $query);
        $this->assertStringContainsString('strictness=1', $query);
    }

    public function test_post_is_rejected(): void
    {
        $r = $this->gate()->handle(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.5'], []);
        $this->assertSame(405, $r['status']);
        $this->assertSame('GET, HEAD', $r['headers']['Allow']);
        $this->assertCount(0, $this->stub->requests);
    }

    public function test_error_detail_is_class_and_status_never_the_message(): void
    {
        $this->stub->queue[] = new Response(503, [], '{"success":false,"message":"secret-key-echo"}');
        $d = $this->gate()->decide('203.0.113.5', null, null);
        $this->assertSame('UpstreamException HTTP 503', $d['error']);
        $this->assertStringNotContainsString('secret', $d['error']);
    }

    public function test_runtime_error_goes_to_php_error_log_when_no_log_file(): void
    {
        $errorLog = $this->tempFile();
        $previous = ini_set('error_log', $errorLog);
        try {
            $this->stub->queue[] = new Response(503, [], '{"success":false,"message":"x"}');
            $this->gate()->handle($this->server(), []);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertStringContainsString('UpstreamException HTTP 503', (string) file_get_contents($errorLog));
    }

    public function test_log_line_format(): void
    {
        $log = $this->tempFile();
        $this->stub->queue[] = $this->ok(10);
        $this->gate(['log_file' => $log])->handle($this->server(), []);
        $this->assertMatchesRegularExpression('/^\S+ 203\.0\.113\.5 allow 10 ok default live\n$/', (string) file_get_contents($log));

        $this->stub->queue[] = new Response(503, [], '{"success":false,"message":"x"}');
        $this->gate(['log_file' => $log])->handle($this->server('203.0.113.6'), []);
        $this->assertMatchesRegularExpression('/ 203\.0\.113\.6 error - error default live error=UpstreamException HTTP 503\n$/', (string) file_get_contents($log));
    }

    public function test_trust_proxy_headers_through_handle(): void
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'];

        $this->stub->queue[] = $this->ok(10);
        $this->gate(['trust_proxy_headers' => true])->handle($server, []);
        $this->assertStringContainsString('ip=203.0.113.9', $this->stub->requests[0]->getUri()->getQuery());

        $this->stub->queue[] = $this->ok(10);
        $this->gate()->handle($server, []);
        $this->assertStringContainsString('ip=10.0.0.1', $this->stub->requests[1]->getUri()->getQuery());
    }

    public function test_destination_keeps_fragment_last(): void
    {
        $this->assertSame('https://lp.example/?sub1=x#top', Gate::destination('https://lp.example/#top', ['sub1' => 'x'], []));
        $this->assertSame('https://lp.example/?a=1&sub1=x#top', Gate::destination('https://lp.example/?a=1#top', ['sub1' => 'x'], []));
    }

    public function test_array_query_values_are_skipped(): void
    {
        $this->assertSame('https://lp.example/?clickid=', Gate::destination('https://lp.example/?clickid={clickid}', ['clickid' => ['x'], 'sub1' => ['y']], []));
    }

    public function test_default_cache_dir_is_the_php_cache_directory(): void
    {
        $this->assertSame(realpath(__DIR__.'/../cache'), realpath(Gate::defaultCacheDir()));
    }

    public function test_equivalent_ipv6_spellings_share_a_cache_entry(): void
    {
        $this->stub->queue[] = $this->ok(10);
        $gate = $this->gate();
        $gate->decide('2001:db8:0:0:0:0:0:1', null, null);
        $d = $gate->decide('2001:DB8::1', null, null);
        $this->assertTrue($d['cached']);
        $this->assertCount(1, $this->stub->requests);
    }

    public function test_ipv4_mapped_ipv6_is_unmapped(): void
    {
        $this->assertSame('203.0.113.5', Gate::clientIp(['REMOTE_ADDR' => '::ffff:203.0.113.5'], false));
        $this->assertNull(Gate::clientIp(['REMOTE_ADDR' => '::ffff:999.1.1.1'], false));

        $this->stub->queue[] = $this->ok(10);
        $gate = $this->gate();
        $gate->decide('::ffff:203.0.113.5', null, null);
        $d = $gate->decide('203.0.113.5', null, null);

        $this->assertTrue($d['cached']);
        $this->assertCount(1, $this->stub->requests);
        $this->assertStringContainsString('ip=203.0.113.5', $this->stub->requests[0]->getUri()->getQuery());
    }

    public function test_head_request_does_not_call_api_or_report(): void
    {
        $reported = [];
        $log = $this->tempFile();
        $gate = $this->gate(['report_url' => 'https://t.example/pb', 'log_file' => $log], function (string $u) use (&$reported): void {
            $reported[] = $u;
        });
        $r = $gate->handle(['REQUEST_METHOD' => 'HEAD', 'REMOTE_ADDR' => '203.0.113.5'], ['clickid' => 'abc']);

        $this->assertSame(302, $r['status']);
        $this->assertSame('https://safe.example/?clickid=abc', $r['headers']['Location']);
        $this->assertCount(0, $this->stub->requests);
        $this->assertSame([], $reported);
        $this->assertStringContainsString(' block - head default ', (string) file_get_contents($log));
    }

    public function test_post_response_carries_no_store(): void
    {
        $r = $this->gate()->handle(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.5'], []);
        $this->assertSame('no-store', $r['headers']['Cache-Control']);
    }

    public function test_from_config_uses_env_api_key_fallback(): void
    {
        $previous = getenv('FRAUDNOVA_API_KEY');
        try {
            putenv('FRAUDNOVA_API_KEY=fn_from_env');
            $this->assertInstanceOf(Gate::class, Gate::fromConfig($this->baseConfig(['api_key' => ''])));

            putenv('FRAUDNOVA_API_KEY');
            $this->expectException(\InvalidArgumentException::class);
            Gate::fromConfig($this->baseConfig(['api_key' => '']));
        } finally {
            $previous === false ? putenv('FRAUDNOVA_API_KEY') : putenv('FRAUDNOVA_API_KEY='.$previous);
        }
    }

    public function test_from_config_rejects_the_placeholder_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key');
        Gate::fromConfig($this->baseConfig(['api_key' => 'fn_your_api_key_here']));
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function badConfigs(): array
    {
        $ok = ['money' => 'https://a.example/', 'safe' => 'https://b.example/'];

        return [
            'placeholder key' => [['api_key' => 'fn_your_api_key_here']],
            'empty key' => [['api_key' => '']],
            'strictness 4' => [['strictness' => 4]],
            'strictness string' => [['strictness' => '1']],
            'threshold 101' => [['threshold' => 101]],
            'threshold -1' => [['threshold' => -1]],
            'timeout 0' => [['timeout_ms' => 0]],
            'no default route' => [['routes' => ['alt' => $ok]]],
            'route missing safe' => [['routes' => ['default' => $ok, 'alt' => ['money' => 'https://a.example/']]]],
            'route empty money' => [['routes' => ['default' => ['money' => '', 'safe' => 'https://b.example/']]]],
            'route relative url' => [['routes' => ['default' => ['money' => '/offer', 'safe' => 'https://b.example/']]]],
            'route non-http url' => [['routes' => ['default' => ['money' => 'javascript:alert(1)', 'safe' => 'https://b.example/']]]],
        ];
    }

    /** @param array<string,mixed> $override */
    #[DataProvider('badConfigs')]
    public function test_bad_config_throws_at_construction(array $override): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gate($override);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function baseConfig(array $overrides = []): array
    {
        return array_replace(['routes' => ['default' => ['money' => 'https://lp.example/', 'safe' => 'https://safe.example/']]], $overrides);
    }
}
