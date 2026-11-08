# Affiliate tracker gate

A small redirect gate that sits between your tracker and your money page. The tracker sends each click to the gate, the gate scores the visitor's IP with [FraudNova](https://fraudnova.com), and the visitor is redirected (HTTP 302) to your money page when the click looks fine or to a safe page when it looks risky (high fraud score, proxy, VPN, Tor, bot, or a country you did not allow). Scored IPs are cached for 24 hours, and if the API is unreachable the gate fails open to the money page by default.

## Requirements

- **PHP gate** (`php/`): PHP 8.2 or newer and Composer.
- **Node gate** (`node/`): Node 18 or newer.
- **Keitaro filter** (`keitaro/`): self-hosted Keitaro, PHP with `ext-curl`, no Composer. See [`keitaro/README.md`](keitaro/README.md).
- A FraudNova API key: [fraudnova.com/dashboard/api-keys](https://fraudnova.com/dashboard/api-keys). Each new IP per day costs one credit.

## How it works

```
traffic source -> tracker -> gate (score IP, cache 24 h) -> money page
                                                         -> safe page
```

## Deploy the PHP gate

1. Upload the `php/` folder to a PHP host and run `composer install --no-dev` inside it.
2. Copy `config.example.php` to `config.php`. Set `api_key` (or the `FRAUDNOVA_API_KEY` environment variable) and your `routes`.
3. Make `cache/` writable by the web server and confirm it is **not** readable from the web. `cache/.htaccess` covers Apache only; on nginx add a `location /cache { deny all; }` rule yourself.
4. Open `https://your-domain/gate.php?route=default` once. It should redirect you to the money or safe page.

## Deploy the Node gate

1. In `node/` run `npm install`.
2. Copy `config.example.mjs` to `config.mjs`. Set the key (or `FRAUDNOVA_API_KEY`) and your `routes`.
3. Start it with `PORT=8787 node server.mjs` (8787 is the default). Use a process manager such as systemd or pm2 to keep it running.
4. Put it behind nginx or Caddy with TLS, and set `trust_proxy_headers: true` in `config.mjs` because the proxy is in front. Without that the gate would score your proxy's address instead of the visitor's.

## Configuration

| Key | Default | Meaning |
| --- | --- | --- |
| `api_key` | none (required) | Your FraudNova key, or set `FRAUDNOVA_API_KEY`. |
| `threshold` | `75` | Block when the fraud score is at or above this (0-100). |
| `block_on` | `['proxy', 'vpn', 'tor', 'bot_status']` | Also block when any of these flags is true (`active_vpn`, `active_tor`, `recent_abuse` are available too). |
| `strictness` | `1` | How strict the scoring is, 0 (lenient) to 3 (strict). |
| `cache_ttl` | `86400` | Seconds a scored IP is remembered, so repeat clicks cost no credit. |
| `timeout_ms` | `2000` | How long to wait for the API before giving up. |
| `fail_open` | `true` | On an API error or timeout: `true` sends the visitor to the money page, `false` to the safe page. |
| `trust_proxy_headers` | `false` | Read the visitor IP from `CF-Connecting-IP` / `X-Forwarded-For`. Enable only behind a proxy you control; `X-Forwarded-For` is trusted only when your proxy overwrites it. |
| `report_url` | `null` | Optional postback URL called when a click is blocked (see below). |
| `log_file` | `null` | Optional file that gets one line per click. Errors go to the PHP error log or the console. |
| `allowed_countries` | `[]` | Optional ISO country codes, for example `['PK', 'IN']`. Empty means any country. |
| `routes` | none (needs `default`) | Named destinations. Each route needs an absolute http(s) `money` and `safe` URL. Pick one with `?route=name`; unknown names use `default`. |

`{placeholders}` in a route URL, such as `{clickid}`, are filled from the gate's query string, and other query parameters are passed through. Keep `safe` URLs static: placeholders are not substituted on the error fallback path.

The gate checks its configuration when it starts and throws on a bad one (placeholder key, missing `default` route, a threshold outside 0-100 and so on), so a typo is never silently fail-open. `HEAD` requests (link checkers, prefetchers) go straight to the route's safe URL with no API call and no postback.

## Point your tracker at it

Use the gate URL as the tracker's redirect target, with the click-id token appended so the tracker can tie later events to the click.

| Tracker | Where to paste the gate URL | Append |
| --- | --- | --- |
| Keitaro | A stream's redirect action (HTTP redirect), or the campaign's landing URL | `?clickid={subid}` |
| Binom | The landing page URL, using "Append to lander's URL" | `?clickid={clickid}` |
| RedTrack | The landing page URL | `?clickid={clickid}` |
| Voluum | The landing page URL | `?clickid={clickid}` |

Full click-by-click guides: https://fraudnova.com/guides/fraud-filtering-for-affiliate-trackers

## Report blocked clicks to your tracker

When `report_url` is set, the gate calls it once for every blocked click (never for `HEAD` requests), with `{clickid}` filled from the query string. The call is synchronous and capped at 1 second, and a failure never changes where the visitor goes. Use the template for your tracker:

| Tracker | `report_url` |
| --- | --- |
| Keitaro | `https://TRACKER/POSTBACK-KEY/postback?subid={clickid}&status=trash` |
| Binom | `https://TRACKER/click.php?cnv_id={clickid}&event10=1` |
| RedTrack | The postback URL RedTrack generates, with `clickid={clickid}&type=blocked` |
| Voluum | `https://TRACKING-DOMAIN/postback?cid={clickid}&et=blocked` |

## Credits and limits

- One credit per new IP per day. Repeat clicks from a cached IP cost nothing, and `HEAD` requests, the fail-open path and bad requests make no billed lookup.
- If the API errors or exceeds `timeout_ms` (2 seconds by default), the visitor goes to the money page unless you set `fail_open` to `false`.
- Your key has a per-minute rate limit and a cap on checks in flight at once. Both are described in the [API docs](https://fraudnova.com/docs).

## Run the tests

```bash
cd php  && composer install && vendor/bin/phpunit
cd node && npm install && npm test
cd keitaro && php tests/FilterDecisionTest.php
```
