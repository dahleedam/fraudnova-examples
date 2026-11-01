#!/usr/bin/env bash
# Score several phone numbers in one request (1 credit per successful check, up to 100).
# Usage: FRAUDNOVA_API_KEY=fn_... ./batch_phone.sh <phone> [<phone> ...]
# Prints the JSON response (filtered to fraud_score and flags when jq is installed).
set -euo pipefail

: "${FRAUDNOVA_API_KEY:?Set FRAUDNOVA_API_KEY to your FraudNova API key}"
BASE_URL="${FRAUDNOVA_BASE_URL:-https://fraudnova.com/api/v1}"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

json_escape() {
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\"/\\\"}"
  printf '%s' "$s"
}

[ "$#" -ge 1 ] || { echo "Usage: $0 <phone> [<phone> ...]" >&2; exit 2; }

items=""
for v in "$@"; do
  items+="${items:+,}\"$(json_escape "$v")\""
done
body="{\"phones\":[$items]${FRAUDNOVA_COUNTRY:+,\"country\":\"$(json_escape "$FRAUDNOVA_COUNTRY")\"}}"

status="$(curl -sS -o "$tmp" -w '%{http_code}' -X POST \
  -H "Authorization: Bearer $FRAUDNOVA_API_KEY" -H "Content-Type: application/json" \
  -d "$body" "$BASE_URL/check/phone/batch")"

if [ "$status" != "200" ]; then
  echo "FraudNova API error (HTTP $status):" >&2
  cat "$tmp" >&2
  echo >&2
  exit 1
fi

if command -v jq >/dev/null 2>&1; then
  jq -e '.success == true' "$tmp" >/dev/null || { cat "$tmp" >&2; echo >&2; exit 1; }
  jq '{credits_remaining, results: [.results[] | {phone, success, error, fraud_score: .data.fraud_score}]}' "$tmp"
  # Exit 2 when some items were not scored (per-item errors).
  jq -e 'all(.results[]; .success)' "$tmp" >/dev/null || exit 2
else
  cat "$tmp"; echo
fi
