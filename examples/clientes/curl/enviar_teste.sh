#!/usr/bin/env bash

set -euo pipefail

BASE_URL="${BASE_URL:-https://gateway.tars.art.br}"
API_KEY="${API_KEY:-}"
TEST_PHONE="${TEST_PHONE:-5549999999999}"

if [[ -z "$API_KEY" ]]; then
    printf 'API_KEY nao informado.\n' >&2
    exit 1
fi

IDEMPOTENCY_KEY="kit-$(date +%Y%m%d%H%M%S)-$(php -r 'echo bin2hex(random_bytes(4));')"
PAYLOAD=$(printf '{"phone":"%s","message":"Teste de integracao do projeto cliente com o Tars Notificacoes.","type":"test","idempotency_key":"%s"}' "$TEST_PHONE" "$IDEMPOTENCY_KEY")
TMP_BODY="$(mktemp)"
TMP_CODE="$(mktemp)"
trap 'rm -f "$TMP_BODY" "$TMP_CODE"' EXIT

curl -sS -o "$TMP_BODY" -w '%{http_code}' \
    -X POST "${BASE_URL%/}/api/sms/send" \
    -H "Authorization: Bearer $API_KEY" \
    -H 'Content-Type: application/json' \
    --data "$PAYLOAD" > "$TMP_CODE"

HTTP_CODE="$(cat "$TMP_CODE")"
printf 'HTTP: %s\n' "$HTTP_CODE"
printf 'Resposta:\n'
cat "$TMP_BODY"
