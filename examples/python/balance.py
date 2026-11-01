#!/usr/bin/env python3
"""Show the remaining credit balance (free, consumes no credit).

Usage: FRAUDNOVA_API_KEY=fn_... python3 balance.py 
Requires: pip install requests
"""
import os
import sys

import requests

BASE_URL = os.environ.get("FRAUDNOVA_BASE_URL", "https://fraudnova.com/api/v1").rstrip("/")


def main():
    api_key = os.environ.get("FRAUDNOVA_API_KEY")
    if not api_key:
        sys.exit("Set FRAUDNOVA_API_KEY to your FraudNova API key")
    try:
        r = requests.get(BASE_URL + "/balance", headers={"Authorization": "Bearer " + api_key}, timeout=30)
    except requests.RequestException as exc:
        sys.exit("Request failed: %s" % exc)
    try:
        body = r.json()
    except ValueError:
        body = {}
    if r.status_code != 200 or not body.get("success"):
        sys.exit("FraudNova API error (HTTP %d): %s" % (r.status_code, body.get("message", r.text)))
    print("credits_remaining: %s" % body["credits_remaining"])


if __name__ == "__main__":
    main()
