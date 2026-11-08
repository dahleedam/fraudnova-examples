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
4. If anything sits in front of PHP (nginx as a reverse proxy, Caddy, a load balancer or Cloudflare), set `trust_proxy_headers` as described in [Behind a proxy or Cloudflare](#behind-a-proxy-or-cloudflare). With PHP served directly (Apache with mod_php, or nginx straight to PHP-FPM), leave it `false`.
5. Open `https://your-domain/gate.php?route=default` once. It should redirect you to the money or safe page. If the PHP error log says the gate "appears to sit behind a proxy", go back to step 4.

## Deploy the Node gate

1. In `node/` run `npm install`.
2. Copy `config.example.mjs` to `config.mjs`. Set the key (or `FRAUDNOVA_API_KEY`) and your `routes`.
3. Start it with `PORT=8787 node server.mjs` (8787 is the default). Use a process manager such as systemd or pm2 to keep it running.
4. Put it behind nginx or Caddy with TLS, and set `trust_proxy_headers` in `config.mjs` because the proxy is in front: see [Behind a proxy or Cloudflare](#behind-a-proxy-or-cloudflare) for the exact setting and proxy config. Without it the gate sees only your proxy's private address, never scores a click (`no_ip`) and prints a one-line hint to the console.

## Behind a proxy or Cloudflare

The gate scores the address the request came from. Behind a reverse proxy that address is the proxy's, so the gate has to read the visitor's address from a header instead, and a header is only as trustworthy as whatever last wrote it. `trust_proxy_headers` takes one of three forms:

| Value | Where the visitor IP comes from | Use it when |
| --- | --- | --- |
| `false` (default) | The connection address only. | Nothing sits in front of the gate. |
| `'x-forwarded-for'` or `'cf-connecting-ip'` | Only that header (for `X-Forwarded-For`, its first entry), then the connection address if the header is missing or unusable. The other header is ignored. | Your proxy, or Cloudflare, sets that one header on every request. This is the recommended form. |
| `true` | `CF-Connecting-IP`, then the first `X-Forwarded-For` entry, then the connection address. | Only when every request reaches you through Cloudflare or a proxy that OVERWRITES both headers. |

The header name is case-insensitive. Any other value stops the gate at startup with an error.

Recipes:

- **nginx in front of the gate.** Overwrite `X-Forwarded-For` with the real client address and blank `CF-Connecting-IP`, so a visitor cannot inject either header, then use `trust_proxy_headers: 'x-forwarded-for'`:

  ```nginx
  location / {
      proxy_pass http://127.0.0.1:8787;
      proxy_set_header X-Forwarded-For $remote_addr;
      proxy_set_header CF-Connecting-IP "";
  }
  ```

  Do not use `$proxy_add_x_forwarded_for` here: it appends to whatever the visitor sent.
- **Caddy in front of the gate.** `reverse_proxy` replaces `X-Forwarded-For` with the client address by default (as long as you have not configured `trusted_proxies`), so `trust_proxy_headers: 'x-forwarded-for'` works without extra config.
- **Behind Cloudflare.** Use `trust_proxy_headers: 'cf-connecting-ip'`, and restrict your origin's firewall to [Cloudflare's IP ranges](https://www.cloudflare.com/ips/) so nobody can reach the gate directly with a header of their own.

The risk if you get this wrong: with a proxy that appends to (or passes through) the header instead of overwriting it, a visitor can send their own `X-Forwarded-For` or `CF-Connecting-IP` and pick the address the gate scores. They can then choose their own verdict (a clean IP gets them to the money page), and every spoofed address costs you one credit.

Private, loopback, link-local and other reserved addresses (`10.x`, `192.168.x`, `127.x`, `100.64.x`, `::1`, `fc00::/7`, `fe80::/10` and the like, including their `::ffff:` forms) are never scored: the gate skips them and tries the next source. When no public address is left, the click is allowed as `no_ip` with no API call and nothing cached. If the connection address is private and `trust_proxy_headers` is `false`, the gate logs a line saying it appears to sit behind a proxy (the Node gate once per process, the PHP gate on each such request).

## Configuration

| Key | Default | Meaning |
| --- | --- | --- |
| `api_key` | none (required) | Your FraudNova key, or set `FRAUDNOVA_API_KEY`. |
| `threshold` | `75` | Block when the fraud score is at or above this (0-100). |
| `block_on` | `['proxy', 'vpn', 'tor', 'bot_status']` | Also block when any of these flags is true (`active_vpn`, `active_tor`, `recent_abuse` are available too). |
| `strictness` | `1` | How strict the scoring is, 0 (lenient) to 3 (strict). |
| `cache_ttl` | `86400` | Seconds a scored IP is remembered, so repeat clicks cost no credit. |
| `timeout_ms` | `2000` | How long to wait for the API before giving up. Keep it realistic; see [Credits and limits](#credits-and-limits). |
| `fail_open` | `true` | On an API error or timeout: `true` sends the visitor to the money page, `false` to the safe page. |
| `trust_proxy_headers` | `false` | Where the visitor IP comes from: `false` (connection address only), `'x-forwarded-for'` or `'cf-connecting-ip'` (only that header, then the connection), or `true` (both headers, only behind Cloudflare or a proxy that overwrites both). See [Behind a proxy or Cloudflare](#behind-a-proxy-or-cloudflare). |
| `report_url` | `null` | Optional postback URL called when a click is blocked (see below). |
| `log_file` | `null` | Optional file that gets one line per click. Errors go to the PHP error log or the console. |
| `allowed_countries` | `[]` | Optional ISO country codes, for example `['PK', 'IN']`. Empty means any country. A click whose `country_code` is unknown passes this check. |
| `routes` | none (needs `default`) | Named destinations. Each route needs an absolute http(s) `money` and `safe` URL. Pick one with `?route=name`; unknown names use `default`. |

`{placeholders}` in a route URL, such as `{clickid}`, are filled from the gate's query string, and other query parameters are passed through. A parameter that appears more than once uses its last value. Keep `safe` URLs static: placeholders are not substituted on the error fallback path.

Never put a `{placeholder}` in a route URL's scheme or host (`https://{domain}/offer`): anyone could then fill it with their own domain and use your gate as an open redirect. Placeholders belong in the path or query string only.

The gate checks its configuration when it starts and throws on a bad one (placeholder key, missing `default` route, a threshold outside 0-100 and so on), so a typo is never silently fail-open. `HEAD` requests (link checkers, prefetchers) go straight to the route's safe URL with no API call and no postback.

## Point your tracker at it

Use the gate URL as the tracker's redirect target, with the click-id token appended so the tracker can tie later events to the click.

| Tracker | Where to paste the gate URL | Append |
| --- | --- | --- |
| Keitaro | The stream's landing page or offer URL | `?clickid={subid}` |
| Binom | The landing page URL, with `clickid={clickid}` in "Append to lander's URL" (no leading `?`) | `clickid={clickid}` |
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
- A lookup that times out on the gate's side may still be billed: the API charges the credit first and refunds it only when the lookup fails on its own side, not when your gate stops waiting. Do not set `timeout_ms` unrealistically low, or you pay for verdicts you never use.
- Your key has a per-minute rate limit and a cap on checks in flight at once. Both are described in the [API docs](https://fraudnova.com/docs).

## Run the tests

```bash
cd php  && composer install && vendor/bin/phpunit
cd node && npm install && npm test
cd keitaro && php tests/FilterDecisionTest.php
```
