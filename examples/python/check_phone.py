#!/usr/bin/env python3
"""Score one phone number (1 credit).

Usage: FRAUDNOVA_API_KEY=fn_... python3 check_phone.py <phone> [country]
Requires: pip install requests
"""
import os
import sys

import requests

BASE_URL = os.environ.get("FRAUDNOVA_BASE_URL", "https://fraudnova.com/api/v1").rstrip("/")

FLAGS = ["valid", "VOIP", "prepaid", "risky", "active", "recent_abuse", "leaked", "spammer"]


def main():
    api_key = os.environ.get("FRAUDNOVA_API_KEY")
    if not api_key:
        sys.exit("Set FRAUDNOVA_API_KEY to your FraudNova API key")
    if len(sys.argv) < 2:
        sys.exit("Usage: python3 check_phone.py <phone> [country]")

    params = {"phone": sys.argv[1]}
    if len(sys.argv) > 2:
        params["country"] = sys.argv[2]

    try:
        r = requests.get(
            BASE_URL + "/check/phone",
            headers={"Authorization": "Bearer " + api_key},
            params=params,
            timeout=30,
        )
    except requests.RequestException as exc:
        sys.exit("Request failed: %s" % exc)

    try:
        body = r.json()
    except ValueError:
        body = {}
    if r.status_code != 200 or not body.get("success"):
        sys.exit("FraudNova API error (HTTP %d): %s" % (r.status_code, body.get("message", r.text)))

    data = body["data"]
    print("fraud_score: %s" % data["fraud_score"])
    for flag in FLAGS:
        print("%s: %s" % (flag, data.get(flag)))
    print("credits_remaining: %s" % body.get("credits_remaining"))


if __name__ == "__main__":
    main()
