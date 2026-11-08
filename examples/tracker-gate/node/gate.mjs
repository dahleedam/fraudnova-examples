// FraudNova redirect gate for affiliate trackers.
//
// Your tracker sends each click to this gate. The gate scores the visitor's IP with
// FraudNova and answers with a 302 redirect to your money page (looks fine) or your
// safe page (looks risky). Edit config.mjs, not this file.
//
// It fails open by default: if the API cannot be reached, the visitor goes to the
// money page. A broken configuration is the opposite: it throws when the gate is
// created, so it is never silently fail-open.
//
// Decision reasons: score, flag:<name>, country, ok, error, no_ip, head.

import { appendFileSync } from 'node:fs';
import { isIP } from 'node:net';
import { FraudNova } from '@fraudnova/sdk';

export const DEFAULTS = {
  api_key: null, // falls back to the FRAUDNOVA_API_KEY environment variable
  threshold: 75, // block when the fraud score is at or above this (0-100)
  block_on: ['proxy', 'vpn', 'tor', 'bot_status'], // also block when any of these flags is true
  strictness: 1, // 0-3, how strict the scoring is
  cache_ttl: 86400, // seconds a scored IP is remembered (saves credits)
  timeout_ms: 2000, // how long to wait for the API
  fail_open: true, // on an API error: true = money page, false = safe page
  trust_proxy_headers: false, // false, true, 'cf-connecting-ip' or 'x-forwarded-for' (see the README)
  report_url: null, // optional postback URL, called only when a visitor is blocked
  log_file: null, // optional log file; null logs errors to the console only
  allowed_countries: [], // e.g. ['PK', 'IN']; empty = every country is allowed
  routes: {}, // { default: { money, safe }, ... } selected with ?route=name
};

const PLACEHOLDER_KEY = 'fn_your_api_key_here';

function validate(config) {
  const key = config.api_key;
  if (typeof key !== 'string' || key.trim() === '' || key === PLACEHOLDER_KEY) {
    throw new Error('config "api_key" is missing or still the placeholder; set your FraudNova API key (or FRAUDNOVA_API_KEY)');
  }
  if (!Number.isInteger(config.strictness) || config.strictness < 0 || config.strictness > 3) {
    throw new Error('config "strictness" must be an integer from 0 to 3');
  }
  if (!Number.isInteger(config.threshold) || config.threshold < 0 || config.threshold > 100) {
    throw new Error('config "threshold" must be an integer from 0 to 100');
  }
  if (!Number.isInteger(config.timeout_ms) || config.timeout_ms <= 0) {
    throw new Error('config "timeout_ms" must be a positive integer');
  }
  trustMode(config.trust_proxy_headers);
  const routes = config.routes;
  if (typeof routes !== 'object' || routes === null || !Object.hasOwn(routes, 'default')) {
    throw new Error('config "routes" must contain a "default" route');
  }
  for (const [name, route] of Object.entries(routes)) {
    for (const kind of ['money', 'safe']) {
      const url = route?.[kind];
      if (typeof url !== 'string' || !/^https?:\/\/[^\s/]+/i.test(url)) {
        throw new Error(`config route "${name}" needs an absolute http(s) "${kind}" URL`);
      }
    }
  }
}

/** Decides allow/block from a normalized result: score first, then block_on in order, then country. */
export function evaluate(result, config) {
  const cfg = { ...DEFAULTS, ...config };
  const score = Number(result.fraud_score ?? 0) || 0;

  if (score >= cfg.threshold) return { decision: 'block', score, reason: 'score' };

  for (const flag of cfg.block_on) {
    if (result[flag] === true) return { decision: 'block', score, reason: `flag:${flag}` };
  }

  const allowed = cfg.allowed_countries.map((c) => String(c).toUpperCase());
  const country = result.country_code;
  // An unknown country is not blocked: only a known, non-listed country is.
  if (allowed.length > 0 && typeof country === 'string' && country !== '' && !allowed.includes(country.toUpperCase())) {
    return { decision: 'block', score, reason: 'country' };
  }
  return { decision: 'allow', score, reason: 'ok' };
}

/**
 * Normalizes trust_proxy_headers: false (connection address only), true (CF-Connecting-IP, then the
 * first X-Forwarded-For entry), or one header name ('cf-connecting-ip' / 'x-forwarded-for', any case)
 * meaning only that header is trusted. Anything else throws.
 */
export function trustMode(value) {
  if (value === true || value === false) return value;
  if (typeof value === 'string') {
    const name = value.trim().toLowerCase();
    if (name === 'cf-connecting-ip' || name === 'x-forwarded-for') return name;
  }
  throw new Error('config "trust_proxy_headers" must be false, true, \'cf-connecting-ip\' or \'x-forwarded-for\'');
}

/** The eight 16-bit groups of a valid IPv6 address (zone id dropped, embedded IPv4 expanded). */
function ipv6Groups(ip) {
  let text = ip.split('%')[0].toLowerCase();
  const v4 = /(\d+\.\d+\.\d+\.\d+)$/.exec(text);
  if (v4) {
    const o = v4[1].split('.').map(Number);
    text = text.slice(0, -v4[1].length) + `${((o[0] << 8) | o[1]).toString(16)}:${((o[2] << 8) | o[3]).toString(16)}`;
  }
  const [head, tail] = text.includes('::') ? text.split('::') : [text, null];
  const left = head === '' ? [] : head.split(':');
  const right = tail === null || tail === '' ? [] : tail.split(':');
  const fill = tail === null ? [] : Array(8 - left.length - right.length).fill('0');
  return [...left, ...fill, ...right].map((g) => parseInt(g, 16));
}

/**
 * A valid IP in canonical form, or null. An IPv4-mapped IPv6 address (::ffff:203.0.113.5 or
 * ::ffff:cb00:7105, common behind dual-stack servers) becomes plain IPv4, so the API call,
 * the cache key and the log all agree.
 */
function normalizeIp(raw) {
  if (typeof raw !== 'string') return null;
  const ip = raw.trim();
  const version = isIP(ip);
  if (version === 4) return ip;
  if (version !== 6) return null;
  const g = ipv6Groups(ip);
  if (g.slice(0, 5).every((x) => x === 0) && g[5] === 0xffff) {
    return [g[6] >> 8, g[6] & 255, g[7] >> 8, g[7] & 255].join('.');
  }
  return ip;
}

// Ranges that are never a real visitor: private, loopback, link-local, shared (carrier-grade NAT),
// "this network" and reserved. Mirrors PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE.
const V4_NON_PUBLIC = [
  ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
  ['172.16.0.0', 12], ['192.168.0.0', 16], ['240.0.0.0', 4],
].map(([base, bits]) => [v4ToInt(base), bits]);

function v4ToInt(ip) {
  return ip.split('.').reduce((acc, octet) => acc * 256 + Number(octet), 0);
}

/** False for private, loopback, link-local and reserved addresses. Expects a normalized IP. */
export function isPublicIp(ip) {
  const version = isIP(ip);
  if (version === 4) {
    const n = v4ToInt(ip);
    return !V4_NON_PUBLIC.some(([base, bits]) => Math.floor(n / 2 ** (32 - bits)) === Math.floor(base / 2 ** (32 - bits)));
  }
  if (version !== 6) return false;
  const g = ipv6Groups(ip);
  if (g.slice(0, 7).every((x) => x === 0) && (g[7] === 0 || g[7] === 1)) return false; // :: and ::1
  if ((g[0] & 0xfe00) === 0xfc00) return false; // fc00::/7 unique local
  if ((g[0] & 0xffc0) === 0xfe80) return false; // fe80::/10 link-local
  return true;
}

/**
 * The visitor IP: the trusted header(s) first (see trustMode), then the connection address.
 * Private, loopback, link-local and reserved addresses are skipped; null when no public
 * address remains. Never a query parameter.
 */
export function clientIp(headers, remoteAddress, trustProxyHeaders) {
  const trust = trustMode(trustProxyHeaders);
  const candidates = [];
  if (trust === true || trust === 'cf-connecting-ip') candidates.push(headers['cf-connecting-ip']);
  if (trust === true || trust === 'x-forwarded-for') {
    const forwarded = headers['x-forwarded-for'];
    if (typeof forwarded === 'string') candidates.push(forwarded.split(',')[0]);
  }
  candidates.push(remoteAddress);

  for (const candidate of candidates) {
    const ip = normalizeIp(candidate);
    if (ip !== null && isPublicIp(ip)) return ip;
  }
  return null;
}

// Logged at most once per process: the hint is about the deployment, not about one click.
let warnedBehindProxy = false;

/** Test hook: lets the once-per-process proxy hint be logged again. */
export function resetProxyWarning() {
  warnedBehindProxy = false;
}

function warnIfBehindProxy(remoteAddress) {
  if (warnedBehindProxy) return;
  const ip = normalizeIp(remoteAddress);
  if (ip !== null && !isPublicIp(ip)) {
    warnedBehindProxy = true;
    console.error('fraudnova-gate: the connection address is private, so the gate appears to sit behind a proxy and trust_proxy_headers is off; clicks are not scored (no_ip). See the README to set trust_proxy_headers.');
  }
}

// A repeated query parameter uses its LAST value, the same as PHP's $_GET.
function lastValue(query, name) {
  const values = query.getAll(name);
  return values.length === 0 ? null : values[values.length - 1];
}

/** Fills {name} placeholders from the query. */
function substitute(template, query) {
  return template.replace(/\{([A-Za-z0-9_]+)\}/g, (_, name) => encodeURIComponent(lastValue(query, name) ?? ''));
}

/**
 * Fills placeholders, then appends query params the URL does not already carry.
 * Works on the string, so the rest of your URL is left exactly as you wrote it. Fragment stays last.
 */
export function destination(template, query, decision) { // eslint-disable-line no-unused-vars
  let url = substitute(template, query);

  let fragment = '';
  const hashAt = url.indexOf('#');
  if (hashAt !== -1) {
    fragment = url.slice(hashAt);
    url = url.slice(0, hashAt);
  }

  const queryAt = url.indexOf('?');
  const existing = new URLSearchParams(queryAt === -1 ? '' : url.slice(queryAt + 1));

  const extra = [];
  for (const name of new Set(query.keys())) {
    if (name === 'route' || existing.has(name)) continue;
    extra.push(`${encodeURIComponent(name)}=${encodeURIComponent(lastValue(query, name))}`);
  }

  if (extra.length > 0) {
    const glue = queryAt === -1 ? '?' : (url.endsWith('?') || url.endsWith('&') ? '' : '&');
    url += glue + extra.join('&');
  }
  return url + fragment;
}

// Same IP, different spelling (IPv6 case or zero-compression): one cache entry.
function canonicalIp(ip) {
  try {
    return isIP(ip) === 6 ? new URL(`http://[${ip}]/`).hostname : ip;
  } catch {
    return ip;
  }
}

function headerValue(headers, name) {
  const value = headers[name];
  if (typeof value !== 'string') return undefined;
  const trimmed = value.trim().slice(0, 255);
  return trimmed === '' ? undefined : trimmed;
}

export function createGate(config, { client, reporter, now } = {}) {
  const cfg = { ...DEFAULTS, ...config };
  if (typeof cfg.api_key !== 'string' || cfg.api_key === '') {
    cfg.api_key = process.env.FRAUDNOVA_API_KEY ?? '';
  }
  validate(cfg);

  // The SDK does not retry unless you pass it a RetryPolicy, so timeout_ms bounds the whole call.
  const sdk = client ?? new FraudNova(cfg.api_key, { timeout: cfg.timeout_ms });
  const report = reporter ?? ((url) => fetch(url, { signal: AbortSignal.timeout(1000) }).catch(() => {}));
  const clock = now ?? Date.now;
  const cache = new Map(); // canonical ip -> { expiresAt, result }
  let inserts = 0;

  // Expired entries are only dropped when looked up again, so sweep them now and then
  // (every 1,000 inserts, or whenever the cache passes 50,000 entries) to keep memory bounded.
  function prune() {
    const t = clock();
    for (const [k, v] of cache) if (v.expiresAt <= t) cache.delete(k);
  }

  async function decide(rawIp, userAgent, language) {
    const ip = normalizeIp(rawIp);
    if (ip === null || !isPublicIp(ip)) {
      return { decision: 'allow', score: null, reason: 'no_ip', cached: false };
    }

    const key = canonicalIp(ip);
    const hit = cache.get(key);
    let result = hit && hit.expiresAt > clock() ? hit.result : null;
    const cached = result !== null;

    if (result === null) {
      let r;
      try {
        r = await sdk.checkIp(ip, {
          strictness: cfg.strictness,
          ...(userAgent ? { userAgent } : {}),
          ...(language ? { userLanguage: language } : {}),
        });
      } catch (e) {
        // Any SDK failure (402/429/503, timeout, bad response) is handled the same way.
        // Only the class name and status are recorded: the message could echo a URL.
        let error = e?.name || e?.constructor?.name || 'Error';
        if (typeof e?.statusCode === 'number') error += ` HTTP ${e.statusCode}`;
        return { decision: 'error', score: null, reason: 'error', cached: false, error };
      }
      result = {
        fraud_score: r.fraudScore, proxy: r.proxy, vpn: r.vpn, tor: r.tor,
        active_vpn: r.activeVpn, active_tor: r.activeTor, recent_abuse: r.recentAbuse,
        bot_status: r.botStatus, country_code: r.countryCode,
      };
      if (++inserts % 1000 === 0 || cache.size > 50_000) prune();
      cache.set(key, { expiresAt: clock() + cfg.cache_ttl * 1000, result });
    }

    const verdict = evaluate(result, cfg);
    return { ...verdict, cached };
  }

  function log(ip, d, route) {
    if (!cfg.log_file) {
      if (d.error) console.error(`fraudnova-gate: lookup failed (${d.error}), ${d.decision} for ${ip ?? '-'}`);
      return;
    }
    try {
      const line = [
        new Date(clock()).toISOString(), ip ?? '-', d.decision, d.score ?? '-', d.reason, route, d.cached ? 'cached' : 'live',
      ].join(' ') + (d.error ? ` error=${d.error}` : '') + '\n';
      appendFileSync(cfg.log_file, line);
    } catch {
      // A broken log file must never break the redirect.
    }
  }

  async function handle({ method = 'GET', url = '/', headers = {}, remoteAddress = '' }) {
    const verb = String(method).toUpperCase();
    if (verb !== 'GET' && verb !== 'HEAD') {
      return { status: 405, headers: { Allow: 'GET, HEAD', 'Cache-Control': 'no-store' }, body: '' };
    }

    let query;
    try {
      query = new URL(url, 'http://gate.invalid').searchParams;
    } catch {
      query = new URLSearchParams(); // a malformed request line such as "GET //" has no usable query
    }
    const trust = trustMode(cfg.trust_proxy_headers);
    const ip = clientIp(headers, remoteAddress, trust);
    if (trust === false) warnIfBehindProxy(remoteAddress);
    // Prefetchers and link checkers send HEAD: never spend a credit, fire a postback,
    // or reveal the money page to them.
    const decision = verb === 'HEAD'
      ? { decision: 'block', score: null, reason: 'head', cached: false }
      : await decide(ip, headerValue(headers, 'user-agent'), headerValue(headers, 'accept-language'));

    const asked = lastValue(query, 'route');
    const routeName = asked !== null && Object.hasOwn(cfg.routes, asked) ? asked : 'default';
    const route = cfg.routes[routeName];

    const toMoney = verb !== 'HEAD' && (decision.decision === 'allow' || (decision.decision === 'error' && cfg.fail_open));
    const target = route[toMoney ? 'money' : 'safe'];
    let location;
    try {
      location = destination(target, query, decision);
      // e.g. a placeholder in the host that expanded to nothing leaves no host behind.
      if (!/^https?:\/\/[^\s/?#]+/i.test(location)) throw new Error('no host');
      new URL(location);
    } catch {
      location = route.safe;
    }

    if (decision.decision === 'block' && verb !== 'HEAD' && cfg.report_url) {
      try {
        await report(substitute(cfg.report_url, query));
      } catch {
        // A failing postback must never change where the visitor goes.
      }
    }

    log(ip, decision, routeName);
    return { status: 302, headers: { Location: location, 'Cache-Control': 'no-store' }, body: '' };
  }

  return { decide, handle };
}
