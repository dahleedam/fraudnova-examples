#!/usr/bin/env python3
"""Score several IP addresss in one request (1 credit per successful check, up to 100).

Usage: FRAUDNOVA_API_KEY=fn_... python3 batch_ip.py <ip> [<ip> ...]
Requires: pip install requests
"""
import os
import sys

import requests

BASE_URL = os.environ.get("FRAUDNOVA_BASE_URL", "https://fraudnova.com/api/v1").rstrip("/")

FLAGS = ["proxy", "vpn", "tor", "active_vpn", "active_tor", "recent_abuse", "bot_status"]


def main():
    api_key = os.environ.get("FRAUDNOVA_API_KEY")
    if not api_key:
        sys.exit("Set FRAUDNOVA_API_KEY to your FraudNova API key")
    if len(sys.argv) < 2:
        sys.exit("Usage: python3 batch_ip.py <ip> [<ip> ...]")

    payload = {"ips": sys.argv[1:], "strictness": 2}

    try:
        r = requests.post(
            BASE_URL + "/check/batch",
            headers={"Authorization": "Bearer " + api_key},
            json=payload,
            timeout=120,
        )
    except requests.RequestException as exc:
        sys.exit("Request failed: %s" % exc)

    try:
        body = r.json()
    except ValueError:
        body = {}
    if r.status_code != 200 or not body.get("success"):
        sys.exit("FraudNova API error (HTTP %d): %s" % (r.status_code, body.get("message", r.text)))

    failed = 0
    for item in body["results"]:
        if item.get("success"):
            data = item["data"]
            flags = " ".join("%s=%s" % (f, data.get(f)) for f in FLAGS)
            print("%s fraud_score=%s %s" % (item["ip"], data["fraud_score"], flags))
        else:
            failed += 1
            print("%s error=%s" % (item["ip"], item.get("error")))
    print("credits_remaining: %s" % body.get("credits_remaining"))
    if failed:
        sys.exit(2)  # partial failure: some items were not scored


if __name__ == "__main__":
    main()
