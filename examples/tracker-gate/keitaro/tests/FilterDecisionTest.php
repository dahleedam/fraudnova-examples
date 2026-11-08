<?php

// Plain PHP test, no framework: `php tests/FilterDecisionTest.php` prints "ok" and exits 0.
// Minimal stand-ins let the filter class load without Keitaro installed.

namespace Core\Filter {
    abstract class AbstractFilter
    {
    }
}

namespace Core\Locale {
    class LocaleService
    {
        public static function t($key)
        {
            return $key;
        }
    }
}

namespace Traffic\Model {
    class StreamFilter
    {
        const ACCEPT = 'accept';
        const REJECT = 'reject';
    }
}

namespace Traffic {
    class RawClick
    {
    }
}

namespace {
    use Filters\fraudnova;

    $cache = sys_get_temp_dir() . '/fraudnova-filter-test-' . getmypid();
    putenv('FRAUDNOVA_FILTER_CACHE=' . $cache);
    putenv('FRAUDNOVA_API_KEY=fn_test_key');

    require __DIR__ . '/../FraudNovaFilter.php';

    function check($condition, $label)
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: $label\n");
            exit(1);
        }
    }

    $calls = 0;
    $reply = function (array $body, $status = 200) use (&$calls) {
        return function ($url, $key) use ($body, $status, &$calls) {
            $calls++;
            return array($status, json_encode($body));
        };
    };

    $clean = array('success' => true, 'data' => array('fraud_score' => 10, 'proxy' => false, 'vpn' => false, 'tor' => false, 'bot_status' => false));

    check(fraudnova::isRisky('203.0.113.1', 'UA', 'en', 75, $reply($clean)) === false, 'clean IP passes');

    $high = $clean;
    $high['data']['fraud_score'] = 90;
    check(fraudnova::isRisky('203.0.113.2', null, null, 75, $reply($high)) === true, 'score 90 is risky');

    $proxy = $clean;
    $proxy['data']['proxy'] = true;
    check(fraudnova::isRisky('203.0.113.3', null, null, 75, $reply($proxy)) === true, 'proxy flag is risky');

    check(fraudnova::isRisky('203.0.113.4', null, null, 75, $reply(array('success' => false), 503)) === false, '503 fails open');
    check(fraudnova::isRisky('203.0.113.5', null, null, 75, $reply(array('success' => false, 'message' => 'x'))) === false, 'success=false fails open');
    check(fraudnova::isRisky('203.0.113.6', null, null, 75, function () { throw new \RuntimeException('boom'); }) === false, 'exception fails open');

    // Cache: a second call for the same IP must not hit the API.
    $calls = 0;
    fraudnova::isRisky('203.0.113.7', null, null, 75, $reply($high));
    check($calls === 1, 'first call hits the API');
    check(fraudnova::isRisky('203.0.113.7', null, null, 75, $reply($high)) === true, 'cached verdict');
    check($calls === 1, 'second call served from cache');

    // Failures are never cached.
    $calls = 0;
    fraudnova::isRisky('203.0.113.8', null, null, 75, $reply(array(), 503));
    fraudnova::isRisky('203.0.113.8', null, null, 75, $reply($clean));
    check($calls === 2, 'errors are not cached');

    // Cached score is re-evaluated against the current threshold.
    $mid = $clean;
    $mid['data']['fraud_score'] = 60;
    check(fraudnova::isRisky('203.0.113.9', null, null, 75, $reply($mid)) === false, 'score 60 under 75');
    check(fraudnova::isRisky('203.0.113.9', null, null, 50, $reply($mid)) === true, 'cached score 60 over 50');

    check(fraudnova::evaluate(array('fraud_score' => 75), 75) === true, 'score equal to threshold blocks');
    check(fraudnova::evaluate(array('fraud_score' => 74, 'vpn' => false), 75) === false, 'score below threshold passes');

    // No IP at all: nothing to score.
    check(fraudnova::isRisky('', null, null, 75, $reply($high)) === false, 'empty IP fails open');

    array_map('unlink', glob($cache . '/*') ?: array());
    @rmdir($cache);
    echo "ok\n";
}
