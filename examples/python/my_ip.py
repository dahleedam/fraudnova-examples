#!/usr/bin/env python3
"""Return the IP address FraudNova sees for this machine (free, no API key needed).

Usage: python3 my_ip.py
Requires: pip install requests
"""
import os
import sys

import requests

BASE_URL = os.environ.get("FRAUDNOVA_BASE_URL", "https://fraudnova.com/api/v1").rstrip("/")


def main():
    try:
        r = requests.get(BASE_URL + "/my-ip", timeout=30)
    except requests.RequestException as exc:
        sys.exit("Request failed: %s" % exc)
    try:
        body = r.json()
    except ValueError:
        body = {}
    if r.status_code != 200 or not body.get("success"):
        sys.exit("FraudNova API error (HTTP %d): %s" % (r.status_code, body.get("message", r.text)))
    print("ip: %s" % body["ip"])


if __name__ == "__main__":
    main()
