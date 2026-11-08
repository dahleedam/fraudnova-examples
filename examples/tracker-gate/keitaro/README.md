# FraudNova filter for Keitaro

A custom stream filter for self-hosted [Keitaro](https://keitaro.io). Add it to a stream and Keitaro asks FraudNova about the visitor's IP: the stream can then take only the clean clicks, or only the risky ones. It is one PHP file, plain curl, no Composer. It needs PHP with `ext-curl` and works on PHP 7.4 or newer.

Keitaro's documentation describes where filters live and what a filter class looks like. It says nothing about making outbound HTTP calls from inside a filter, so this one is conservative: the call is capped at 2 seconds and any failure counts as "not risky" (fail open). Test it on a staging stream first.

## Install

1. Copy `FraudNovaFilter.php` to `/var/www/keitaro/var/filters/fraudnova.php`. The file name must be `fraudnova.php` to match the class name.
2. Set your API key (create one at [fraudnova.com/dashboard/api-keys](https://fraudnova.com/dashboard/api-keys)), either way:
   - **Edit the constant**: open the file and replace `fn_your_api_key_here` in `const API_KEY`. This is the simplest option.
   - **Environment variable**: set `FRAUDNOVA_API_KEY`. It is read first and wins over the constant. For PHP-FPM add `env[FRAUDNOVA_API_KEY] = fn_your_key` to the pool config and reload PHP-FPM. If you do not control the pool config, edit the constant instead.
3. In Keitaro open a stream, add a filter and look for **fraudnova** in the filter dropdown (it appears under its class name). Keitaro's docs do not describe how a dropped-in filter is registered, so if it is not listed, contact Keitaro support.

## Use it

Add the filter to a stream and pick a mode. The value is the fraud-score threshold; leave it empty for **75**. An empty, zero, non-numeric or over-100 value also means 75, so a typo never blocks all traffic.

| Mode | Passes | Typical use |
| --- | --- | --- |
| Reject | Only **clean** clicks (score below the value and none of the flags below) | A money stream: mode Reject, value `75`, so risky visitors fall through to your next stream or default |
| Accept | Only **risky** clicks | A stream that sends risky traffic to a safe page |

In short: **Reject** keeps risky visitors out of the stream, **Accept** keeps only the risky ones in.

A click counts as **risky** when the fraud score is at or above the value, or when any of these is true: `proxy`, `vpn`, `tor`, `bot_status`. Change `BLOCK_ON` at the top of the file to adjust that list.

## Behaviour

- **Fail open.** An API error, timeout, non-200 answer or unexpected response means the click is treated as clean. Failed lookups are never cached.
- **Cache.** A scored IP is remembered for 24 hours (`CACHE_TTL`) in `sys_get_temp_dir()/fraudnova-filter/`, one small file per IP, so repeat clicks cost no credit. The cached data is re-checked against the current threshold. Set `FRAUDNOVA_FILTER_CACHE` to move the cache directory.
- **Cost.** One credit per new IP per day. The same IP can be evaluated by several streams without extra lookups thanks to the cache. Your key's rate limit and in-flight cap apply; see the [API docs](https://fraudnova.com/docs).
- **Strictness.** The filter always sends `strictness=1`; it is not configurable here.
- **Shared hosts.** The default cache path under the system temp directory is predictable. On a shared host set `FRAUDNOVA_FILTER_CACHE` to a directory only your user can read.
- **Timeout.** 2 seconds (`TIMEOUT`), with a 1 second connect timeout.

Full click-by-click guides: https://fraudnova.com/guides/fraud-filtering-for-affiliate-trackers

## Test

```bash
php -l FraudNovaFilter.php
php tests/FilterDecisionTest.php   # prints "ok"
```

The test stubs Keitaro's classes and the HTTP call, so it needs neither Keitaro nor network access.
