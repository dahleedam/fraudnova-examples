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

        private $mode;
        private $payload;

        public function __construct($mode = 'reject', $payload = '')
        {
            $this->mode = $mode;
            $this->payload = $payload;
        }

        public function getMode()
        {
            return $this->mode;
        }

        public function getPayload()
        {
            return $this->payload;
        }
    }
}

namespace Traffic {
    class RawClick
    {
        public function getIpString()
        {
            return '198.51.100.20';
        }

        public function getUserAgent()
        {
            return 'UA';
        }

        public function getLanguage()
        {
            return 'en';
        }
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

    check(fraudnova::thresholdFrom('') === 75, 'empty value is 75');
    check(fraudnova::thresholdFrom('abc') === 75, 'text is 75');
    check(fraudnova::thresholdFrom('0') === 75, 'zero is 75');
    check(fraudnova::thresholdFrom('150') === 75, 'over 100 is 75');
    check(fraudnova::thresholdFrom(null) === 75, 'null is 75');
    check(fraudnova::thresholdFrom(' 60 ') === 60, '60 is 60');

    // isPass(): pre-seed the cache so no HTTP call is made.
    $seed = function (array $data) use ($cache) {
        @mkdir($cache, 0700, true);
        file_put_contents($cache . '/' . sha1('198.51.100.20') . '.json', json_encode(array('at' => time(), 'data' => $data)));
    };
    $click = new \Traffic\RawClick();
    $reject = new \Traffic\Model\StreamFilter('reject', '75');
    $accept = new \Traffic\Model\StreamFilter('accept', '75');
    $filter = new fraudnova();

    $seed(array('fraud_score' => 90));
    check($filter->isPass($reject, $click) === false, 'reject + risky blocks');
    check($filter->isPass($accept, $click) === true, 'accept + risky passes');
    $seed(array('fraud_score' => 5));
    check($filter->isPass($reject, $click) === true, 'reject + clean passes');
    check($filter->isPass($accept, $click) === false, 'accept + clean blocks');

    array_map('unlink', glob($cache . '/*') ?: array());
    @rmdir($cache);
    echo "ok\n";
}
