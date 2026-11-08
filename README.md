# FraudNova API examples

Runnable client examples, an OpenAPI 3.1 spec and a Postman collection for the [FraudNova](https://fraudnova.com) fraud-detection API.

FraudNova scores IP addresses (proxy, VPN, Tor, recent abuse, bot status, geolocation), email addresses (validity, disposable domains, deliverability, abuse signals) and phone numbers (validity, VOIP, prepaid, carrier, line type, abuse signals) over a REST API and returns a `fraud_score` plus the individual risk flags. Billing is prepaid credits: one check costs one credit, new accounts receive free trial credits after email verification, and more credits are sold as packs (card or crypto). There are no subscriptions. See the [API documentation](https://fraudnova.com/docs) for the full reference.

## 30-second quickstart

1. Create an account at [fraudnova.com](https://fraudnova.com) and verify your email to receive the free trial credits.
2. Create an API key under **Dashboard, API keys** ([fraudnova.com/dashboard/api-keys](https://fraudnova.com/dashboard/api-keys)). Keys start with `fn_` and are shown once.
3. Make a call:

```bash
export FRAUDNOVA_API_KEY=fn_your_api_key_here

curl -H "Authorization: Bearer $FRAUDNOVA_API_KEY" \
  "https://fraudnova.com/api/v1/check?ip=8.8.8.8&strictness=2"
```

Every successful single check returns this envelope (see [`openapi.json`](openapi.json) for every field):

```json
{
  "success": true,
  "data": {
    "fraud_score": 20,
    "proxy": false,
    "vpn": false,
    "tor": false,
    "active_vpn": false,
    "active_tor": false,
    "recent_abuse": false,
    "bot_status": false,
    "country_code": "US",
    "region": "California",
    "city": "Mountain View",
    "ISP": "Google LLC",
    "organization": "AS15169 Google LLC",
    "ASN": 15169
  },
  "credits_remaining": 4
}
```

(Abridged: the response also carries `operating_system`, `browser`, `device_brand` and `device_model`, which are populated when you pass `user_agent`.) Errors use `{ "success": false, "message": "..." }` with status 401 (bad token), 402 (insufficient credits), 422 (invalid input), 429 (rate or concurrency limit, with a `Retry-After` header giving the seconds to wait) or 503 (upstream unavailable).

## Endpoints

Base URL: `https://fraudnova.com/api/v1`. Authenticate with `Authorization: Bearer fn_...`.

| Endpoint | Purpose | Cost | Learn more |
| --- | --- | --- | --- |
| `GET /check?ip=` | Score one IP address | 1 credit | [IP fraud detection](https://fraudnova.com/ip-fraud-detection-api), [proxy/VPN detection](https://fraudnova.com/proxy-vpn-detection-api), [docs](https://fraudnova.com/docs) |
| `GET /check/email?email=` | Score one email address | 1 credit | [Email validation](https://fraudnova.com/email-validation-api), [docs](https://fraudnova.com/docs) |
| `GET /check/phone?phone=&country=` | Score one phone number (`country` is an optional ISO-3166 alpha-2 hint) | 1 credit | [Phone validation](https://fraudnova.com/phone-validation-api), [docs](https://fraudnova.com/docs) |
| `POST /check/batch` | Score up to 100 IPs (`{"ips": [...]}`) | 1 credit per successful check | [docs](https://fraudnova.com/docs) |
| `POST /check/email/batch` | Score up to 100 emails (`{"emails": [...]}`) | 1 credit per successful check | [docs](https://fraudnova.com/docs) |
| `POST /check/phone/batch` | Score up to 100 phone numbers (`{"phones": [...], "country": "US"}`) | 1 credit per successful check | [docs](https://fraudnova.com/docs) |
| `GET /balance` | Remaining credit balance | free | [docs](https://fraudnova.com/docs) |
| `GET /my-ip` | The caller's IP address; no API key needed | free | [docs](https://fraudnova.com/docs) |

Batch endpoints return HTTP 200 with one result per input; an item that fails carries `"success": false` and an `error` of `invalid_ip` / `invalid_email` / `invalid_phone`, `insufficient_credits` or `upstream_failed`. Single-check rate limits and the per-account concurrency cap are described in the [docs](https://fraudnova.com/docs).

Related pages: [status](https://fraudnova.com/status), [changelog](https://fraudnova.com/changelog), [guides](https://fraudnova.com/guides). Official clients: the [PHP SDK](https://github.com/dahleedam/fraudnova-php) (`fraudnova/fraudnova-php` on Packagist), the [Python SDK](https://github.com/dahleedam/fraudnova-python) (`fraudnova` on PyPI), the [Node.js SDK](https://github.com/dahleedam/fraudnova-node) (`@fraudnova/sdk` on npm), the [n8n node](https://www.npmjs.com/package/n8n-nodes-fraudnova) and the [WordPress plugin](https://github.com/dahleedam/fraudnova-wordpress) (`fraudnova` in the WordPress plugin directory, WooCommerce order scoring and form protection).

## Try it without an account

Three free checkers run one check per page, no signup:

- [Free IP checker](https://fraudnova.com/free-ip-checker)
- [Free email checker](https://fraudnova.com/free-email-checker)
- [Free phone checker](https://fraudnova.com/free-phone-checker)

## Verify webhooks

You can register HTTPS endpoints under **Dashboard, Webhooks** to receive `balance.low`, `balance.depleted` and `credits.purchased` events. Each delivery is a `POST` whose JSON body is signed with HMAC-SHA256 over the exact raw request body, keyed with the endpoint's signing secret, and sent as:

```
X-FraudNova-Signature: sha256=<hex>
```

Compute the HMAC over the raw bytes (not re-serialised JSON) and compare in constant time. Respond with a 2xx status quickly; non-2xx responses are retried up to 4 times with backoff. Reference verifiers: [`python/verify_webhook.py`](examples/python/verify_webhook.py), [`node/verify_webhook.mjs`](examples/node/verify_webhook.mjs) and [`php/verify_webhook.php`](examples/php/verify_webhook.php). Each exports a `verify_signature` / `verifySignature` function for your handler and works as a CLI. To try it against the bundled sample body:

```bash
export FRAUDNOVA_WEBHOOK_SECRET=your_endpoint_signing_secret
sig="sha256=$(openssl dgst -sha256 -hmac "$FRAUDNOVA_WEBHOOK_SECRET" examples/sample_webhook_body.json | awk '{print $NF}')"

python3 examples/python/verify_webhook.py examples/sample_webhook_body.json "$sig"   # Signature valid, exit 0
node    examples/node/verify_webhook.mjs  examples/sample_webhook_body.json "$sig"
php     examples/php/verify_webhook.php   examples/sample_webhook_body.json "$sig"
```

Exit status is 0 for a valid signature, 1 for an invalid one and 2 for bad usage.

## Run the examples

All scripts live in [`examples/`](examples). They read the key from the `FRAUDNOVA_API_KEY` environment variable (not needed for `my_ip`), take the subject as a command-line argument, print `fraud_score` and the flag fields, and exit non-zero on an HTTP or API error (1) or when a batch has per-item failures (2). `FRAUDNOVA_BASE_URL` optionally overrides `https://fraudnova.com/api/v1`. Each check consumes credits from your account.

```bash
export FRAUDNOVA_API_KEY=fn_your_api_key_here
```

Every language has the same set of files: `check_ip`, `check_email`, `check_phone`, `batch_ip`, `batch_email`, `batch_phone`, `balance`, `my_ip` and `verify_webhook` (the last one not in curl). The phone checks take an optional country as a second argument (`check_phone 18176791808 US`); the phone batch reads an optional `FRAUDNOVA_COUNTRY`.

**curl** (shell; `jq` is optional and only used to trim the output)

```bash
bash examples/curl/check_ip.sh 8.8.8.8
bash examples/curl/check_email.sh user@example.com
bash examples/curl/batch_ip.sh 8.8.8.8 1.1.1.1
bash examples/curl/balance.sh
```

**Python** (3.x, [`requests`](https://pypi.org/project/requests/)). For application code, use the official Python SDK instead: `pip install fraudnova` ([PyPI](https://pypi.org/project/fraudnova/), [GitHub](https://github.com/dahleedam/fraudnova-python)); it covers every endpoint with typed results, exceptions and automatic batch chunking.

```bash
pip install -r examples/python/requirements.txt
python3 examples/python/check_ip.py 8.8.8.8
python3 examples/python/check_phone.py 18176791808 US
python3 examples/python/batch_email.py user@example.com test@example.org
```

**Node.js** (18+, built-in `fetch`, no install step). For application code, use the official Node.js SDK instead: `npm install @fraudnova/sdk` ([npm](https://www.npmjs.com/package/@fraudnova/sdk), [GitHub](https://github.com/dahleedam/fraudnova-node)); it covers every endpoint with typed results, errors and automatic batch chunking.

```bash
node examples/node/check_ip.mjs 8.8.8.8
node examples/node/check_email.mjs user@example.com
node examples/node/batch_phone.mjs 18176791808 14155552671
```

**PHP** (CLI with `ext-curl`, no Composer). For application code, use the official PHP SDK instead: `composer require fraudnova/fraudnova-php guzzlehttp/guzzle` ([Packagist](https://packagist.org/packages/fraudnova/fraudnova-php), [GitHub](https://github.com/dahleedam/fraudnova-php)); it covers every endpoint with typed results, exceptions and a Laravel facade.

```bash
php examples/php/check_ip.php 8.8.8.8
php examples/php/check_email.php user@example.com
php examples/php/batch_ip.php 8.8.8.8 1.1.1.1
```

**n8n** (workflow templates, no install step)

Three importable workflows (webhook lead gate, WooCommerce order risk alert on Telegram, Google Sheets phone enrichment) live in [`examples/n8n`](examples/n8n). Import a JSON file into n8n, create one `FraudNova API` Header Auth credential and set one threshold. See [`examples/n8n/README.md`](examples/n8n/README.md).

**Affiliate tracker gate**

A redirect gate for affiliate trackers: it scores each click's IP with FraudNova and sends the visitor to your money page or a safe page. It comes as a PHP gate and a Node.js gate, plus a custom stream filter for self-hosted Keitaro. Setup, configuration and tracker postback templates are in [`examples/tracker-gate/README.md`](examples/tracker-gate/README.md).

**WordPress / WooCommerce** (official plugin)

The [`fraudnova` plugin](https://wordpress.org/plugins/fraudnova/) scores the IP, billing phone and email of every new WooCommerce order and holds or flags risky cash-on-delivery orders. It also blocks throwaway emails and abusive traffic on WordPress and WooCommerce registration and Contact Form 7. Setup and details are in the [WordPress docs](https://fraudnova.com/docs#wordpress), and the source is on [GitHub](https://github.com/dahleedam/fraudnova-wordpress).

## OpenAPI and Postman

- [`openapi.json`](openapi.json) is a pretty-printed copy of the live spec at [fraudnova.com/docs/openapi.json](https://fraudnova.com/docs/openapi.json). Use it to generate a client in another language.
- [`postman/FraudNova.postman_collection.json`](postman/FraudNova.postman_collection.json) is a Postman collection generated from that spec. Set the collection variable `api_key` to your key; `base_url` defaults to `https://fraudnova.com/api/v1`.

## License

[MIT](LICENSE). Contributions are welcome, see [CONTRIBUTING.md](CONTRIBUTING.md).
