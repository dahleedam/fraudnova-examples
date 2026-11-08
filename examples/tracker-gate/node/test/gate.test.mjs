import { test, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { FraudNova } from '@fraudnova/sdk';
import { DEFAULTS, clientIp, createGate, destination, evaluate } from '../gate.mjs';

const IP = '203.0.113.5';
let queued;
let calls;
let tmp;
let errors;
const realConsoleError = console.error;

beforeEach(() => {
  queued = [];
  calls = [];
  errors = [];
  tmp = mkdtempSync(join(tmpdir(), 'gate-'));
  console.error = (...args) => errors.push(args.join(' '));
});

afterEach(() => {
  console.error = realConsoleError;
  rmSync(tmp, { recursive: true, force: true });
});

const fakeFetch = async (url) => {
  calls.push(url);
  const next = queued.shift();
  if (next === undefined) throw new Error('queue is empty');
  return next();
};

const ok = (score, data = {}) => () =>
  new Response(
    JSON.stringify({
      success: true,
      data: {
        fraud_score: score, proxy: false, vpn: false, tor: false, active_vpn: false,
        active_tor: false, recent_abuse: false, bot_status: false, country_code: 'US', ...data,
      },
      credits_remaining: 9,
    }),
    { status: 200, headers: { 'content-type': 'application/json' } },
  );
const failure = (status) => () =>
  new Response('{"success":false,"message":"secret-key-echo"}', {
    status, headers: { 'content-type': 'application/json' },
  });
const transportError = () => () => { throw new TypeError('fetch failed'); };

function gate(overrides = {}, { reporter, now } = {}) {
  const config = {
    api_key: 'fn_test',
    routes: {
      default: { money: 'https://lp.example/?clickid={clickid}', safe: 'https://safe.example/' },
      alt: { money: 'https://alt.example/', safe: 'https://safe.example/' },
    },
    ...overrides,
  };
  const client = new FraudNova('fn_test', { fetch: fakeFetch });
  return createGate(config, { client, reporter, now });
}

const req = (extra = {}) => ({
  method: 'GET', url: '/gate?clickid=abc', headers: { 'user-agent': 'UA', 'accept-language': 'en-US' },
  remoteAddress: IP, ...extra,
});

test('low score allows and redirects to money with clickid', async () => {
  queued.push(ok(10));
  const d = await gate().decide(IP, null, null);
  assert.equal(d.decision, 'allow');
  assert.equal(d.reason, 'ok');

  queued.push(ok(10));
  const r = await gate().handle(req());
  assert.equal(r.status, 302);
  assert.equal(r.headers.Location, 'https://lp.example/?clickid=abc');
  assert.equal(r.headers['Cache-Control'], 'no-store');
});

test('score at threshold blocks', async () => {
  queued.push(ok(75));
  const d = await gate().decide(IP, null, null);
  assert.equal(d.decision, 'block');
  assert.equal(d.reason, 'score');

  queued.push(ok(75));
  const r = await gate().handle(req());
  assert.ok(r.headers.Location.startsWith('https://safe.example/'));
});

for (const flag of ['proxy', 'vpn', 'tor', 'bot_status']) {
  test(`default flag ${flag} blocks`, async () => {
    queued.push(ok(5, { [flag]: true }));
    const d = await gate().decide(IP, null, null);
    assert.equal(d.decision, 'block');
    assert.equal(d.reason, `flag:${flag}`);
  });
}

test('active_vpn blocks only when opted in', async () => {
  queued.push(ok(5, { active_vpn: true }));
  assert.equal((await gate().decide(IP, null, null)).decision, 'allow');

  queued.push(ok(5, { active_vpn: true }));
  const d = await gate({ block_on: [...DEFAULTS.block_on, 'active_vpn'] }).decide(IP, null, null);
  assert.equal(d.decision, 'block');
  assert.equal(d.reason, 'flag:active_vpn');
});

test('allowed_countries blocks other countries, not unknown ones', async () => {
  queued.push(ok(5, { country_code: 'US' }));
  const d = await gate({ allowed_countries: ['PK'] }).decide(IP, null, null);
  assert.equal(d.decision, 'block');
  assert.equal(d.reason, 'country');

  queued.push(ok(5, { country_code: null }));
  assert.equal((await gate({ allowed_countries: ['PK'] }).decide(IP, null, null)).decision, 'allow');
});

test('evaluate checks score, then block_on in order, then country', () => {
  const r = { fraud_score: 90, proxy: true, country_code: 'US' };
  assert.deepEqual(evaluate(r, { ...DEFAULTS, allowed_countries: ['PK'] }), { decision: 'block', score: 90, reason: 'score' });
  const r2 = { fraud_score: 10, vpn: true, proxy: true, country_code: 'US' };
  assert.equal(evaluate(r2, { ...DEFAULTS, allowed_countries: ['PK'] }).reason, 'flag:proxy');
});

test('second call for the same IP uses the cache', async () => {
  queued.push(ok(10));
  const g = gate();
  const first = await g.decide(IP, null, null);
  const second = await g.decide(IP, null, null);
  assert.equal(calls.length, 1);
  assert.equal(first.cached, false);
  assert.equal(second.cached, true);
  assert.equal(second.decision, 'allow');
});

test('cache is per gate instance', async () => {
  queued.push(ok(10), ok(10));
  await gate().decide(IP, null, null);
  await gate().decide(IP, null, null);
  assert.equal(calls.length, 2);
});

test('cache expires after the ttl', async () => {
  let t = 1_000_000;
  const g = gate({}, { now: () => t });
  queued.push(ok(10), ok(10));
  await g.decide(IP, null, null);
  t += 86400 * 1000 + 1;
  await g.decide(IP, null, null);
  assert.equal(calls.length, 2);
});

test('equivalent IPv6 spellings share a cache entry', async () => {
  queued.push(ok(10));
  const g = gate();
  await g.decide('2001:db8:0:0:0:0:0:1', null, null);
  const d = await g.decide('2001:DB8::1', null, null);
  assert.equal(d.cached, true);
  assert.equal(calls.length, 1);
});

const failures = {
  402: failure(402), 429: failure(429), 503: failure(503), transport: transportError(),
};
for (const [name, make] of Object.entries(failures)) {
  test(`fails open on ${name}`, async () => {
    queued.push(make);
    const g = gate();
    const d = await g.decide(IP, null, null);
    assert.equal(d.decision, 'error');
    assert.equal(d.reason, 'error');
    assert.equal(d.score, null);
    assert.match(d.error, /^\w+Error( HTTP \d+)?$/);

    queued.push(make);
    const r = await gate().handle(req());
    assert.equal(new URL(r.headers.Location).host, 'lp.example');

    // Nothing cached: a following call makes a fresh request.
    const before = calls.length;
    queued.push(ok(10));
    await g.decide(IP, null, null);
    assert.equal(calls.length, before + 1);
  });
}

test('fails closed when configured', async () => {
  queued.push(failure(503));
  const r = await gate({ fail_open: false }).handle(req());
  assert.ok(r.headers.Location.startsWith('https://safe.example/'));
});

test('any thrown error from the client fails open', async () => {
  const client = { checkIp: async () => { throw new RangeError('boom'); } };
  const g = createGate({ api_key: 'fn_test', routes: { default: { money: 'https://a.example/', safe: 'https://b.example/' } } }, { client });
  const d = await g.decide(IP, null, null);
  assert.equal(d.decision, 'error');
  assert.equal(d.error, 'RangeError');
});

test('no IP allows without calling the API', async () => {
  const g = gate();
  const d = await g.decide(null, 'ua', 'en');
  assert.equal(d.decision, 'allow');
  assert.equal(d.reason, 'no_ip');

  const r = await g.handle(req({ remoteAddress: '' }));
  assert.equal(r.status, 302);
  assert.ok(r.headers.Location.startsWith('https://lp.example/'));
  assert.equal(calls.length, 0);
});

test('unknown route falls back to default', async () => {
  queued.push(ok(10));
  const g = gate();
  let r = await g.handle(req({ url: '/gate?route=../evil' }));
  assert.ok(r.headers.Location.startsWith('https://lp.example/'));
  r = await g.handle(req({ url: '/gate?route=alt' }));
  assert.ok(r.headers.Location.startsWith('https://alt.example/'));
});

test('pass-through skips params already present and never passes route', async () => {
  queued.push(ok(10));
  const r = await gate().handle(req({ url: '/gate?clickid=abc&sub1=x&route=default' }));
  assert.equal(r.headers.Location, 'https://lp.example/?clickid=abc&sub1=x');
});

test('report only on block', async () => {
  const reported = [];
  const reporter = async (url) => { reported.push(url); };
  const config = { report_url: 'https://t.example/pb?cid={clickid}&et=blocked' };

  queued.push(ok(99));
  await gate(config, { reporter }).handle(req({ remoteAddress: '203.0.113.10' }));
  assert.deepEqual(reported, ['https://t.example/pb?cid=abc&et=blocked']);

  reported.length = 0;
  queued.push(ok(1));
  await gate(config, { reporter }).handle(req({ remoteAddress: '203.0.113.11' }));
  queued.push(failure(503));
  await gate(config, { reporter }).handle(req({ remoteAddress: '203.0.113.12' }));
  assert.deepEqual(reported, []);
});

test('report failure does not change the redirect', async () => {
  queued.push(ok(99));
  const throwing = () => { throw new Error('boom'); };
  const rejecting = () => Promise.reject(new Error('boom'));
  for (const reporter of [throwing, rejecting]) {
    queued.push(ok(99));
    const r = await gate({ report_url: 'https://t.example/pb?cid={clickid}' }, { reporter })
      .handle(req({ remoteAddress: '203.0.113.20' }));
    assert.equal(r.status, 302);
    assert.ok(r.headers.Location.startsWith('https://safe.example/'));
  }
});

test('clientIp honours trusted headers', () => {
  const headers = { 'cf-connecting-ip': '203.0.113.9', 'x-forwarded-for': '198.51.100.7, 10.0.0.2' };
  assert.equal(clientIp(headers, '10.0.0.1', false), '10.0.0.1');
  assert.equal(clientIp(headers, '10.0.0.1', true), '203.0.113.9');
  assert.equal(clientIp({ 'x-forwarded-for': headers['x-forwarded-for'] }, '10.0.0.1', true), '198.51.100.7');
  assert.equal(clientIp({ 'x-forwarded-for': 'garbage' }, '10.0.0.1', true), '10.0.0.1');
  assert.equal(clientIp({}, '', false), null);
});

test('trust_proxy_headers works through handle', async () => {
  const headers = { 'cf-connecting-ip': '203.0.113.9' };
  queued.push(ok(10));
  await gate({ trust_proxy_headers: true }).handle(req({ headers, remoteAddress: '10.0.0.1' }));
  assert.ok(calls[0].includes('ip=203.0.113.9'));

  queued.push(ok(10));
  await gate().handle(req({ headers, remoteAddress: '10.0.0.1' }));
  assert.ok(calls[1].includes('ip=10.0.0.1'));
});

test('the visitor IP is never taken from the query string', async () => {
  queued.push(ok(10));
  await gate().handle(req({ url: '/gate?ip=1.2.3.4' }));
  assert.ok(calls[0].includes(`ip=${IP}`));
});

test('user agent and language are sent', async () => {
  queued.push(ok(10));
  await gate().handle(req());
  assert.ok(calls[0].includes('user_agent=UA'));
  assert.ok(calls[0].includes('user_language=en-US'));
  assert.ok(calls[0].includes('strictness=1'));
});

test('POST is rejected with 405', async () => {
  const r = await gate().handle(req({ method: 'POST' }));
  assert.equal(r.status, 405);
  assert.equal(r.headers.Allow, 'GET, HEAD');
  assert.equal(r.headers['Cache-Control'], 'no-store');
  assert.equal(calls.length, 0);
});

test('error detail is class and status, never the message', async () => {
  queued.push(failure(503));
  const d = await gate().decide(IP, null, null);
  assert.equal(d.error, 'UpstreamError HTTP 503');
  assert.ok(!d.error.includes('secret'));
});

test('runtime error goes to console.error when no log file', async () => {
  queued.push(failure(503));
  await gate().handle(req());
  assert.equal(errors.length, 1);
  assert.ok(errors[0].includes('UpstreamError HTTP 503'));
  assert.ok(!errors[0].includes('fn_test'));
});

test('log line format', async () => {
  const log = join(tmp, 'gate.log');
  queued.push(ok(10));
  await gate({ log_file: log }).handle(req());
  assert.match(readFileSync(log, 'utf8'), /^\S+ 203\.0\.113\.5 allow 10 ok default live\n$/);

  queued.push(failure(503));
  await gate({ log_file: log }).handle(req({ remoteAddress: '203.0.113.6' }));
  assert.match(readFileSync(log, 'utf8'), / 203\.0\.113\.6 error - error default live error=UpstreamError HTTP 503\n$/);
  assert.equal(errors.length, 0);
});

test('destination keeps the fragment last', () => {
  const q = (s) => new URLSearchParams(s);
  assert.equal(destination('https://lp.example/#top', q('sub1=x'), {}), 'https://lp.example/?sub1=x#top');
  assert.equal(destination('https://lp.example/?a=1#top', q('sub1=x'), {}), 'https://lp.example/?a=1&sub1=x#top');
});

test('repeated query values are skipped and placeholders become empty', () => {
  const q = new URLSearchParams('clickid=a&clickid=b&sub1=x&sub1=y');
  assert.equal(destination('https://lp.example/?clickid={clickid}', q, {}), 'https://lp.example/?clickid=');
});

test('HEAD goes to the safe URL without calling the API or reporting', async () => {
  const reported = [];
  const log = join(tmp, 'head.log');
  const g = gate({ report_url: 'https://t.example/pb', log_file: log }, { reporter: async (u) => { reported.push(u); } });
  const r = await g.handle(req({ method: 'HEAD' }));
  assert.equal(r.status, 302);
  assert.equal(r.headers.Location, 'https://safe.example/?clickid=abc');
  assert.equal(calls.length, 0);
  assert.deepEqual(reported, []);
  assert.ok(readFileSync(log, 'utf8').includes(' allow - head default '));
});

test('api_key falls back to FRAUDNOVA_API_KEY', () => {
  const previous = process.env.FRAUDNOVA_API_KEY;
  const routes = { default: { money: 'https://a.example/', safe: 'https://b.example/' } };
  try {
    process.env.FRAUDNOVA_API_KEY = 'fn_from_env';
    assert.ok(createGate({ api_key: '', routes }));
    delete process.env.FRAUDNOVA_API_KEY;
    assert.throws(() => createGate({ api_key: '', routes }), /api_key/);
  } finally {
    if (previous === undefined) delete process.env.FRAUDNOVA_API_KEY;
    else process.env.FRAUDNOVA_API_KEY = previous;
  }
});

const goodRoute = { money: 'https://a.example/', safe: 'https://b.example/' };
const badConfigs = {
  'placeholder key': { api_key: 'fn_your_api_key_here' },
  'empty key': { api_key: '' },
  'strictness 4': { strictness: 4 },
  'strictness string': { strictness: '1' },
  'threshold 101': { threshold: 101 },
  'threshold -1': { threshold: -1 },
  'timeout 0': { timeout_ms: 0 },
  'no default route': { routes: { alt: goodRoute } },
  'route missing safe': { routes: { default: goodRoute, alt: { money: 'https://a.example/' } } },
  'route empty money': { routes: { default: { money: '', safe: 'https://b.example/' } } },
  'route relative url': { routes: { default: { money: '/offer', safe: 'https://b.example/' } } },
  'route non-http url': { routes: { default: { money: 'javascript:alert(1)', safe: 'https://b.example/' } } },
};
for (const [name, override] of Object.entries(badConfigs)) {
  test(`bad config throws at construction: ${name}`, () => {
    const prev = process.env.FRAUDNOVA_API_KEY;
    delete process.env.FRAUDNOVA_API_KEY;
    try {
      assert.throws(() => gate(override), Error);
    } finally {
      if (prev !== undefined) process.env.FRAUDNOVA_API_KEY = prev;
    }
  });
}
