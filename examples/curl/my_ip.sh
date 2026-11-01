#!/usr/bin/env bash
# Return the IP address FraudNova sees for this machine (free, no API key needed).
# Usage: ./my_ip.sh
set -euo pipefail

BASE_URL="${FRAUDNOVA_BASE_URL:-https://fraudnova.com/api/v1}"
curl -fsS "$BASE_URL/my-ip"
echo
