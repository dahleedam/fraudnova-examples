#!/usr/bin/env node
// Score several phone numbers in one request (1 credit per successful check, up to 100).
// Usage: FRAUDNOVA_API_KEY=fn_... node batch_phone.mjs <phone> [<phone> ...]
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

const FLAGS = ["valid", "VOIP", "prepaid", "risky", "active", "recent_abuse", "leaked", "spammer"];

const apiKey = process.env.FRAUDNOVA_API_KEY;
if (!apiKey) fail("Set FRAUDNOVA_API_KEY to your FraudNova API key");
if (process.argv.length < 3) fail("Usage: node batch_phone.mjs <phone> [<phone> ...]");

const payload = { phones: process.argv.slice(2) };
if (process.env.FRAUDNOVA_COUNTRY) payload.country = process.env.FRAUDNOVA_COUNTRY;

const body = await call(`${BASE_URL}/check/phone/batch`, {
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
    console.log(`${item.phone} fraud_score=${item.data.fraud_score} ${flags}`);
  } else {
    failed += 1;
    console.log(`${item.phone} error=${item.error}`);
  }
}
console.log(`credits_remaining: ${body.credits_remaining}`);
if (failed > 0) process.exit(2); // partial failure: some items were not scored
