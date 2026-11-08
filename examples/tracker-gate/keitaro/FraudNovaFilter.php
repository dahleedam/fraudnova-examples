<?php

// FraudNova stream filter for Keitaro.
//
// Install: copy this file to /var/www/keitaro/var/filters/fraudnova.php (see README.md).
// It scores the visitor's IP with FraudNova and lets a stream accept or reject the click on
// the result. Raw curl only, no Composer, written to run on older PHP 7.x servers too.
//
// It fails open: any error, timeout or non-200 answer counts as "not risky".

namespace Filters;

use Core\Filter\AbstractFilter;
use Core\Locale\LocaleService;
use Traffic\Model\StreamFilter;
use Traffic\RawClick;

class fraudnova extends AbstractFilter
{
    // Your FraudNova API key. The FRAUDNOVA_API_KEY environment variable wins when it is set.
    const API_KEY = 'fn_your_api_key_here';

    // Seconds to wait for the API before giving up (fail open).
    const TIMEOUT = 2;

    // Seconds a scored IP is remembered on disk, so repeat clicks cost no credit.
    const CACHE_TTL = 86400;

    // A click is risky when the score reaches the filter value or any of these flags is true.
    const BLOCK_ON = ['proxy', 'vpn', 'tor', 'bot_status'];

    const ENDPOINT = 'https://fraudnova.com/api/v1/check';

    public function getModes()
    {
        return [
            StreamFilter::ACCEPT => LocaleService::t('filters.binary_options.' . StreamFilter::ACCEPT),
            StreamFilter::REJECT => LocaleService::t('filters.binary_options.' . StreamFilter::REJECT),
        ];
    }

    public function getTemplate()
    {
        return '<input class="form-control" ng-model="filter.payload" placeholder="75" />';
    }

    // ACCEPT = pass only risky clicks. REJECT = pass only clean clicks.
    public function isPass(StreamFilter $filter, RawClick $rawClick)
    {
        $risky = self::isRisky(
            (string) $rawClick->getIpString(),
            $rawClick->getUserAgent(),
            $rawClick->getLanguage(),
            self::thresholdFrom($filter->getPayload())
        );

        return $filter->getMode() == StreamFilter::ACCEPT ? $risky : !$risky;
    }

    /** The filter value as a threshold: a whole number from 1 to 100, otherwise 75. */
    public static function thresholdFrom($payload)
    {
        $value = trim((string) $payload);
        if ($value !== '' && ctype_digit($value) && (int) $value >= 1 && (int) $value <= 100) {
            return (int) $value;
        }

        return 75;
    }

    /**
     * True when the IP looks risky. $http is only for tests: callable($url, $apiKey) => [status, body].
     */
    public static function isRisky($ip, $ua, $lang, $threshold, $http = null)
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return false;
        }

        $cacheFile = self::cacheDir() . '/' . sha1($ip) . '.json';
        $cached = self::readCache($cacheFile);
        if ($cached !== null) {
            return self::evaluate($cached, $threshold);
        }

        try {
            $query = ['ip' => $ip, 'strictness' => 1];
            if ($ua) {
                $query['user_agent'] = substr($ua, 0, 255);
            }
            if ($lang) {
                $query['user_language'] = substr($lang, 0, 255);
            }
            $url = self::ENDPOINT . '?' . http_build_query($query);

            $http = $http ?: [self::class, 'curlGet'];
            list($status, $body) = $http($url, self::apiKey());
        } catch (\Throwable $e) {
            return false;
        }

        $json = json_decode((string) $body, true);
        if ($status !== 200 || !is_array($json) || ($json['success'] ?? null) !== true || !is_array($json['data'] ?? null)) {
            return false; // never cache a failure
        }

        self::writeCache($cacheFile, $json['data']);

        return self::evaluate($json['data'], $threshold);
    }

    /** Risky when the score reaches the threshold or any BLOCK_ON flag is true. */
    public static function evaluate(array $data, $threshold)
    {
        if ((int) ($data['fraud_score'] ?? 0) >= $threshold) {
            return true;
        }
        foreach (self::BLOCK_ON as $flag) {
            if (($data[$flag] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    public static function curlGet($url, $apiKey)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, $body === false ? '' : $body];
    }

    private static function apiKey()
    {
        $env = getenv('FRAUDNOVA_API_KEY');

        return $env !== false && $env !== '' ? $env : self::API_KEY;
    }

    private static function cacheDir()
    {
        $dir = getenv('FRAUDNOVA_FILTER_CACHE');

        return $dir !== false && $dir !== '' ? $dir : sys_get_temp_dir() . '/fraudnova-filter';
    }

    private static function readCache($file)
    {
        $raw = @file_get_contents($file);
        $entry = $raw === false ? null : json_decode($raw, true);
        if (!is_array($entry) || !isset($entry['at']) || !is_array($entry['data'] ?? null)) {
            return null;
        }

        return time() - (int) $entry['at'] < self::CACHE_TTL ? $entry['data'] : null;
    }

    private static function writeCache($file, array $data)
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($file, json_encode(['at' => time(), 'data' => $data]), LOCK_EX);
    }
}
