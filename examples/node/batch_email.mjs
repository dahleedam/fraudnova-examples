#!/usr/bin/env node
// Score several email addresss in one request (1 credit per successful check, up to 100).
// Usage: FRAUDNOVA_API_KEY=fn_... node batch_email.mjs <email> [<email> ...]
// Requires Node.js 18+ (built-in fetch).

const BASE_URL = (process.env.FRAUDNOVA_BASE_URL || "https://fraudnova.com/api/v1").replace(/\/+$/, "");

function fail(message) {
  console.error(message);
  process.exit(1);
}

async function call(url, options) {
  let res;
  try {
    res = await fetch(url, options);
  } catch (err) {
    fail(`Request failed: ${err.message}`);
  }
  let body = {};
  const text = await res.text();
  try {
    body = JSON.parse(text);
  } catch {
    // non-JSON body; reported below
  }
  if (!res.ok || !body.success) {
    fail(`FraudNova API error (HTTP ${res.status}): ${body.message ?? text}`);
  }
  return body;
}

const FLAGS = ["valid", "disposable", "deliverability", "dns_valid", "honeypot", "suspect", "recent_abuse", "leaked"];

const apiKey = process.env.FRAUDNOVA_API_KEY;
if (!apiKey) fail("Set FRAUDNOVA_API_KEY to your FraudNova API key");
if (process.argv.length < 3) fail("Usage: node batch_email.mjs <email> [<email> ...]");

const payload = { emails: process.argv.slice(2) };

const body = await call(`${BASE_URL}/check/email/batch`, {
  method: "POST",
  headers: {
    Authorization: `Bearer ${apiKey}`,
    "Content-Type": "application/json",
  },
  body: JSON.stringify(payload),
});

let failed = 0;
for (const item of body.results) {
  if (item.success) {
    const flags = FLAGS.map((f) => `${f}=${item.data[f]}`).join(" ");
    console.log(`${item.email} fraud_score=${item.data.fraud_score} ${flags}`);
  } else {
    failed += 1;
    console.log(`${item.email} error=${item.error}`);
  }
}
console.log(`credits_remaining: ${body.credits_remaining}`);
if (failed > 0) process.exit(2); // partial failure: some items were not scored
