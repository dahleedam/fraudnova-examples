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
  trust_proxy_headers: false, // read the IP from CF-Connecting-IP / X-Forwarded-For
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
 * The visitor IP: the connection address, or, when you sit behind a proxy you trust,
 * CF-Connecting-IP then the first X-Forwarded-For entry. Never a query parameter.
 */
export function clientIp(headers, remoteAddress, trustProxyHeaders) {
  const candidates = [];
  if (trustProxyHeaders) {
    candidates.push(headers['cf-connecting-ip']);
    const forwarded = headers['x-forwarded-for'];
    if (typeof forwarded === 'string') candidates.push(forwarded.split(',')[0]);
  }
  candidates.push(remoteAddress);

  for (const candidate of candidates) {
    if (typeof candidate !== 'string') continue;
    const ip = candidate.trim();
    if (isIP(ip) !== 0) return ip;
  }
  return null;
}

/** Fills {name} placeholders from the query (single values only). */
function substitute(template, query) {
  return template.replace(/\{([A-Za-z0-9_]+)\}/g, (_, name) => {
    const values = query.getAll(name);
    return encodeURIComponent(values.length === 1 ? values[0] : '');
  });
}

/** Fills placeholders, then appends query params the URL does not already carry. Fragment stays last. */
export function destination(template, query, decision) { // eslint-disable-line no-unused-vars
  const url = new URL(substitute(template, query));
  for (const name of new Set(query.keys())) {
    const values = query.getAll(name);
    if (name === 'route' || values.length !== 1 || url.searchParams.has(name)) continue;
    url.searchParams.append(name, values[0]);
  }
  return url.toString();
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

  const sdk = client ?? new FraudNova(cfg.api_key, { timeout: cfg.timeout_ms });
  const report = reporter ?? ((url) => fetch(url, { signal: AbortSignal.timeout(1000) }).catch(() => {}));
  const clock = now ?? Date.now;
  const cache = new Map(); // canonical ip -> { expiresAt, result }

  async function decide(ip, userAgent, language) {
    if (ip === null || ip === undefined || isIP(ip) === 0) {
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

    const query = new URL(url, 'http://gate.invalid').searchParams;
    const ip = clientIp(headers, remoteAddress, cfg.trust_proxy_headers);
    // Prefetchers and link checkers send HEAD: never spend a credit, fire a postback,
    // or reveal the money page to them.
    const decision = verb === 'HEAD'
      ? { decision: 'allow', score: null, reason: 'head', cached: false }
      : await decide(ip, headerValue(headers, 'user-agent'), headerValue(headers, 'accept-language'));

    const asked = query.get('route');
    const routeName = asked !== null && Object.hasOwn(cfg.routes, asked) ? asked : 'default';
    const route = cfg.routes[routeName];

    const toMoney = verb !== 'HEAD' && (decision.decision === 'allow' || (decision.decision === 'error' && cfg.fail_open));
    const location = destination(route[toMoney ? 'money' : 'safe'], query, decision);

    if (decision.decision === 'block' && cfg.report_url) {
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
