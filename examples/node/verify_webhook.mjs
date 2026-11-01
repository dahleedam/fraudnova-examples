#!/usr/bin/env node
// Verify a FraudNova webhook signature.
//
// FraudNova signs the exact raw request body with HMAC-SHA256, keyed with your endpoint's
// signing secret, and sends it as: X-FraudNova-Signature: sha256=<hex>
//
// Usage:
//   FRAUDNOVA_WEBHOOK_SECRET=... node verify_webhook.mjs <body-file | -> "<signature-header>"
// Exit status: 0 = valid, 1 = invalid, 2 = bad usage. Pass "-" to read the body from stdin.
//
// In a web handler, call verifySignature() with the unparsed request body (Buffer),
// not with re-serialised JSON (for Express, use express.raw({ type: "application/json" })).

import { createHmac, timingSafeEqual } from "node:crypto";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

export function verifySignature(secret, rawBody, signatureHeader) {
  const expected = Buffer.from("sha256=" + createHmac("sha256", secret).update(rawBody).digest("hex"));
  const received = Buffer.from(signatureHeader || "");
  return expected.length === received.length && timingSafeEqual(expected, received);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const secret = process.env.FRAUDNOVA_WEBHOOK_SECRET;
  if (!secret || process.argv.length !== 4) {
    console.error('Usage: FRAUDNOVA_WEBHOOK_SECRET=... node verify_webhook.mjs <body-file|-> <signature-header>');
    process.exit(2);
  }
  const rawBody = readFileSync(process.argv[2] === "-" ? 0 : process.argv[2]);
  if (verifySignature(secret, rawBody, process.argv[3].trim())) {
    console.log("Signature valid");
  } else {
    console.error("Signature INVALID");
    process.exit(1);
  }
}
