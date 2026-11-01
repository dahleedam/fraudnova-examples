#!/usr/bin/env bash
# Score one email address (1 credit).
# Usage: FRAUDNOVA_API_KEY=fn_... ./check_email.sh <email>
# Prints the JSON response (filtered to fraud_score and flags when jq is installed).
set -euo pipefail

: "${FRAUDNOVA_API_KEY:?Set FRAUDNOVA_API_KEY to your FraudNova API key}"
BASE_URL="${FRAUDNOVA_BASE_URL:-https://fraudnova.com/api/v1}"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
subject="${1:?Usage: $0 <email>}"

args=(-sS -G -o "$tmp" -w '%{http_code}' -H "Authorization: Bearer $FRAUDNOVA_API_KEY"
      --data-urlencode "email=$subject")
status="$(curl "${args[@]}" "$BASE_URL/check/email")"

if [ "$status" != "200" ]; then
  echo "FraudNova API error (HTTP $status):" >&2
  cat "$tmp" >&2
  echo >&2
  exit 1
fi

if command -v jq >/dev/null 2>&1; then
  jq -e '.success == true' "$tmp" >/dev/null || { cat "$tmp" >&2; echo >&2; exit 1; }
  jq '{fraud_score: .data.fraud_score, valid: .data.valid, disposable: .data.disposable, deliverability: .data.deliverability, dns_valid: .data.dns_valid, honeypot: .data.honeypot, suspect: .data.suspect, recent_abuse: .data.recent_abuse, leaked: .data.leaked, credits_remaining}' "$tmp"
else
  cat "$tmp"; echo
fi
