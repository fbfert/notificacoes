#!/usr/bin/env bash

set -euo pipefail

BASE_URL="${BASE_URL:-https://gateway.tars.art.br}"
API_KEY="${API_KEY:-}"
TEST_PHONE="${TEST_PHONE:-5549999999999}"

if [[ -z "$API_KEY" ]]; then
    printf 'API_KEY nao informado.\n' >&2
    exit 1
fi

if [[ -z "$TEST_PHONE" ]]; then
    printf 'TEST_PHONE nao informado.\n' >&2
    exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
    printf 'curl nao encontrado.\n' >&2
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    printf 'php nao encontrado.\n' >&2
    exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

request() {
    local method="$1"
    local url="$2"
    local body="$3"
    shift 3
    local body_file="$TMP_DIR/body_$RANDOM"
    local code_file="$TMP_DIR/code_$RANDOM"

    if [[ -n "$body" ]]; then
        curl -sS -o "$body_file" -w '%{http_code}' -X "$method" "$url" "$@" --data "$body" > "$code_file"
    else
        curl -sS -o "$body_file" -w '%{http_code}' -X "$method" "$url" "$@" > "$code_file"
    fi

    printf '%s\n%s\n' "$(cat "$code_file")" "$body_file"
}

json_value() {
    local file="$1"
    local path="$2"
    php -r '
        $payload = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($payload)) {
            exit(1);
        }
        $value = $payload;
        foreach (explode(".", $argv[2]) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                exit(2);
            }
            $value = $value[$part];
        }
        if (is_array($value)) {
            echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo (string) $value;
        }
    ' "$file" "$path"
}

post_sms() {
    local type="$1"
    local key="$2"
    local body
    body=$(printf '{"phone":"%s","message":"Teste de integracao do projeto cliente com o Tars Notificacoes.","type":"%s","idempotency_key":"%s"}' "$TEST_PHONE" "$type" "$key")
    request POST "${BASE_URL%/}/api/sms/send" "$body" -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json'
}

unique_key="client-kit-$(date +%Y%m%d%H%M%S)-$(php -r 'echo bin2hex(random_bytes(4));')"

mapfile -t response < <(post_sms test "$unique_key")
code="${response[0]}"
body_file="${response[1]}"
printf 'Envio valido HTTP %s\n' "$code"
cat "$body_file"
printf '\n'
if [[ "$code" != "202" ]]; then
    printf 'Falha: esperado HTTP 202 no envio valido.\n' >&2
    exit 1
fi

message_id="$(json_value "$body_file" 'data.message_id')"
if [[ -z "$message_id" ]]; then
    printf 'Falha: message_id nao retornado.\n' >&2
    exit 1
fi
printf 'message_id=%s\n' "$message_id"

mapfile -t replay_response < <(post_sms test "$unique_key")
replay_code="${replay_response[0]}"
replay_body="${replay_response[1]}"
printf 'Reenvio idempotente HTTP %s\n' "$replay_code"
cat "$replay_body"
printf '\n'
if [[ "$replay_code" != "200" ]]; then
    printf 'Falha: esperado HTTP 200 no reenvio idempotente.\n' >&2
    exit 1
fi

mapfile -t invalid_type_response < <(post_sms invalid "$unique_key-invalid")
invalid_type_code="${invalid_type_response[0]}"
invalid_type_body="${invalid_type_response[1]}"
printf 'Type invalido HTTP %s\n' "$invalid_type_code"
cat "$invalid_type_body"
printf '\n'
if [[ "$invalid_type_code" != "422" ]]; then
    printf 'Falha: esperado HTTP 422 para type invalido.\n' >&2
    exit 1
fi

mapfile -t status_response < <(request GET "${BASE_URL%/}/api/sms/status/${message_id}" "" -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json')
status_code="${status_response[0]}"
status_body="${status_response[1]}"
printf 'Status HTTP %s\n' "$status_code"
cat "$status_body"
printf '\n'
if [[ "$status_code" != "200" ]]; then
    printf 'Falha: esperado HTTP 200 no status da mensagem.\n' >&2
    exit 1
fi

mapfile -t invalid_key_response < <(request POST "${BASE_URL%/}/api/sms/send" "$(printf '{"phone":"%s","message":"Teste de integracao do projeto cliente com o Tars Notificacoes.","type":"test","idempotency_key":"%s-invalid"}' "$TEST_PHONE" "$unique_key")" -H 'Authorization: Bearer chave-invalida' -H 'Content-Type: application/json')
invalid_key_code="${invalid_key_response[0]}"
invalid_key_body="${invalid_key_response[1]}"
printf 'API key invalida HTTP %s\n' "$invalid_key_code"
cat "$invalid_key_body"
printf '\n'
if [[ "$invalid_key_code" != "401" ]]; then
    printf 'Falha: esperado HTTP 401 para API key invalida.\n' >&2
    exit 1
fi

printf 'Smoke externo concluido com sucesso.\n'
