#!/usr/bin/env node
// Score one IP address (1 credit).
// Usage: FRAUDNOVA_API_KEY=fn_... node check_ip.mjs <ip>
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

const FLAGS = ["proxy", "vpn", "tor", "active_vpn", "active_tor", "recent_abuse", "bot_status"];

const apiKey = process.env.FRAUDNOVA_API_KEY;
if (!apiKey) fail("Set FRAUDNOVA_API_KEY to your FraudNova API key");
if (process.argv.length < 3) fail("Usage: node check_ip.mjs <ip>");

const params = new URLSearchParams({ ip: process.argv[2], strictness: "2" });

const body = await call(`${BASE_URL}/check?${params}`, {
  headers: { Authorization: `Bearer ${apiKey}` },
});

const data = body.data;
console.log(`fraud_score: ${data.fraud_score}`);
for (const flag of FLAGS) console.log(`${flag}: ${data[flag]}`);
console.log(`credits_remaining: ${body.credits_remaining}`);
