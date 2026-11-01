#!/usr/bin/env node
// Show the remaining credit balance (free, consumes no credit).
// Usage: FRAUDNOVA_API_KEY=fn_... node balance.mjs 
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

const apiKey = process.env.FRAUDNOVA_API_KEY;
if (!apiKey) fail("Set FRAUDNOVA_API_KEY to your FraudNova API key");

const body = await call(`${BASE_URL}/balance`, {
  headers: { Authorization: `Bearer ${apiKey}` },
});
console.log(`credits_remaining: ${body.credits_remaining}`);
