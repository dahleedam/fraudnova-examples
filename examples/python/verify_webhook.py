#!/usr/bin/env python3
"""Verify a FraudNova webhook signature.

FraudNova signs the exact raw request body with HMAC-SHA256, keyed with your endpoint's
signing secret, and sends it as: X-FraudNova-Signature: sha256=<hex>

Usage:
  FRAUDNOVA_WEBHOOK_SECRET=... python3 verify_webhook.py <body-file | -> "<signature-header>"
Exit status: 0 = valid, 1 = invalid, 2 = bad usage. Pass "-" to read the body from stdin.

In a web handler, call verify_signature() with the unparsed request body (bytes),
not with re-serialised JSON.
"""
import hashlib
import hmac
import os
import sys


def verify_signature(secret, raw_body, signature_header):
    """Return True if signature_header matches the HMAC-SHA256 of raw_body (bytes)."""
    expected = "sha256=" + hmac.new(secret.encode("utf-8"), raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature_header or "")


def main():
    secret = os.environ.get("FRAUDNOVA_WEBHOOK_SECRET")
    if not secret or len(sys.argv) != 3:
        sys.stderr.write("Usage: FRAUDNOVA_WEBHOOK_SECRET=... python3 verify_webhook.py <body-file|-> <signature-header>\n")
        sys.exit(2)

    if sys.argv[1] == "-":
        raw_body = sys.stdin.buffer.read()
    else:
        with open(sys.argv[1], "rb") as f:
            raw_body = f.read()

    if verify_signature(secret, raw_body, sys.argv[2].strip()):
        print("Signature valid")
    else:
        sys.stderr.write("Signature INVALID\n")
        sys.exit(1)


if __name__ == "__main__":
    main()
