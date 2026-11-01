#!/usr/bin/env bash
# Show the remaining credit balance (free, consumes no credit).
# Usage: FRAUDNOVA_API_KEY=fn_... ./balance.sh 
# Prints the JSON response (filtered to fraud_score and flags when jq is installed).
set -euo pipefail

: "${FRAUDNOVA_API_KEY:?Set FRAUDNOVA_API_KEY to your FraudNova API key}"
BASE_URL="${FRAUDNOVA_BASE_URL:-https://fraudnova.com/api/v1}"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

status="$(curl -sS -o "$tmp" -w '%{http_code}' -H "Authorization: Bearer $FRAUDNOVA_API_KEY" "$BASE_URL/balance")"

if [ "$status" != "200" ]; then
  echo "FraudNova API error (HTTP $status):" >&2
  cat "$tmp" >&2
  echo >&2
  exit 1
fi

if command -v jq >/dev/null 2>&1; then
  jq -e '.success == true' "$tmp" >/dev/null || { cat "$tmp" >&2; echo >&2; exit 1; }
  jq '{credits_remaining}' "$tmp"
else
  cat "$tmp"; echo
fi
