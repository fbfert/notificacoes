#!/usr/bin/env bash

set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost}"
API_KEY="${API_KEY:-}"
PHONE_VALIDO="${PHONE_VALIDO:-(11) 99999-9999}"
PHONE_INVALIDO="${PHONE_INVALIDO:-abc}"

API_URL="${BASE_URL%/}/api/sms/send"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

PASS_COUNT=0
FAIL_COUNT=0

pass() {
    printf '[PASS] %s\n' "$1"
    PASS_COUNT=$((PASS_COUNT + 1))
}

fail() {
    printf '[FAIL] %s\n' "$1"
    FAIL_COUNT=$((FAIL_COUNT + 1))
}

request() {
    local method="$1"
    local url="$2"
    local body="$3"
    shift 3
    local extra_headers=("$@")
    local response_file status_file
    response_file="$(mktemp "$TMP_DIR/resp.XXXXXX")"
    status_file="$(mktemp "$TMP_DIR/status.XXXXXX")"

    if [[ -n "$body" ]]; then
        curl -sS -o "$response_file" -w '%{http_code}' -X "$method" "$url" "${extra_headers[@]}" --data "$body" > "$status_file"
    else
        curl -sS -o "$response_file" -w '%{http_code}' -X "$method" "$url" "${extra_headers[@]}" > "$status_file"
    fi

    local http_code
    http_code="$(cat "$status_file")"
    printf '%s\n%s\n' "$http_code" "$response_file"
}

assert_http() {
    local name="$1"
    local expected="$2"
    local actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        pass "$name (HTTP $actual)"
    else
        fail "$name (esperado HTTP $expected, obtido $actual)"
    fi
}

assert_body_contains() {
    local name="$1"
    local file="$2"
    local needle="$3"
    if grep -Fq "$needle" "$file"; then
        pass "$name"
    else
        fail "$name"
    fi
}

extract_json_value() {
    local file="$1"
    local key="$2"
    php -r '
        $payload = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($payload)) { exit(1); }
        $value = $payload;
        foreach (explode(".", $argv[2]) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) { exit(2); }
            $value = $value[$part];
        }
        if (is_array($value)) {
            echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo (string) $value;
        }
    ' "$file" "$key"
}

if [[ -z "$API_KEY" ]]; then
    printf 'API_KEY nao informado.\n'
    exit 1
fi

mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_VALIDO"'","message":"Teste","type":"sms"}' -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'Sem Authorization' 401 "$code"
assert_body_contains 'Sem Authorization - mensagem' "$file" 'API key ausente'

mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_VALIDO"'","message":"Teste","type":"sms"}' -H 'Authorization: Bearer invalida' -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'Authorization invalido' 401 "$code"
assert_body_contains 'Authorization invalido - mensagem' "$file" 'API key invalida'

mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_VALIDO"'","message":"Teste","type":"sms"}' -H "Authorization: Bearer $API_KEY")
code="${response[0]}"
file="${response[1]}"
assert_http 'Sem Content-Type application/json' 415 "$code"
assert_body_contains 'Sem Content-Type - mensagem' "$file" 'Content-Type deve ser application/json'

mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_VALIDO"'","message":"Teste","type":"sms"' -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'JSON invalido' 400 "$code"
assert_body_contains 'JSON invalido - mensagem' "$file" 'JSON invalido'

mapfile -t response < <(request POST "$API_URL" '{"message":"Teste","type":"sms"}' -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'Phone ausente' 422 "$code"
assert_body_contains 'Phone ausente - mensagem' "$file" 'Campo phone obrigatorio'

mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_INVALIDO"'","message":"Teste","type":"sms"}' -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'Phone invalido' 422 "$code"
assert_body_contains 'Phone invalido - mensagem' "$file" 'Telefone invalido'

mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_VALIDO"'","type":"sms"}' -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'Message ausente' 422 "$code"
assert_body_contains 'Message ausente - mensagem' "$file" 'Campo message obrigatorio'

mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_VALIDO"'","message":"","type":"sms"}' -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'Message vazia' 422 "$code"
assert_body_contains 'Message vazia - mensagem' "$file" 'Mensagem obrigatoria'

LONG_MESSAGE="$(printf 'A%.0s' {1..161})"
mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_VALIDO"'","message":"'"$LONG_MESSAGE"'","type":"sms"}' -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'Message longa' 422 "$code"
assert_body_contains 'Message longa - mensagem' "$file" 'Mensagem deve ter no maximo 160 caracteres'

mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_VALIDO"'","message":"Teste","type":"email"}' -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'Type invalido' 422 "$code"
assert_body_contains 'Type invalido - mensagem' "$file" 'Tipo invalido'

IDEMPOTENCY_KEY="smoke-$(date +%s)-$$"
mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_VALIDO"'","message":"Mensagem valida smoke","type":"sms","idempotency_key":"'"$IDEMPOTENCY_KEY"'"}' -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'Envio valido' 202 "$code"
assert_body_contains 'Envio valido - sucesso' "$file" '"success":true'
MESSAGE_ID_ONE="$(extract_json_value "$file" 'data.message_id')"

mapfile -t response < <(request POST "$API_URL" '{"phone":"'"$PHONE_VALIDO"'","message":"Mensagem valida smoke","type":"sms","idempotency_key":"'"$IDEMPOTENCY_KEY"'"}' -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json')
code="${response[0]}"
file="${response[1]}"
assert_http 'Reenvio idempotente' 200 "$code"
assert_body_contains 'Reenvio idempotente - sucesso' "$file" '"success":true'
MESSAGE_ID_TWO="$(extract_json_value "$file" 'data.message_id')"

if [[ "$MESSAGE_ID_ONE" == "$MESSAGE_ID_TWO" ]]; then
    pass 'Idempotency_key nao duplicou mensagem'
else
    fail 'Idempotency_key duplicou mensagem'
fi

printf '\nResumo: %d aprovados, %d falhas\n' "$PASS_COUNT" "$FAIL_COUNT"

if [[ "$FAIL_COUNT" -ne 0 ]]; then
    exit 1
fi
