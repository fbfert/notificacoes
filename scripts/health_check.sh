#!/usr/bin/env bash

set -euo pipefail

BASE_URL="${BASE_URL:-https://gateway.tars.art.br}"
API_KEY="${API_KEY:-}"
PHONE_VALIDO="${PHONE_VALIDO:-(11) 99999-9999}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

snippet() {
    local file="$1"
    php -r 'echo substr(preg_replace("/\s+/", " ", file_get_contents($argv[1])), 0, 200);' "$file"
}

request() {
    local method="$1"
    local url="$2"
    local body="$3"
    shift 3
    local response_file status_file
    response_file="$(mktemp "$TMP_DIR/resp.XXXXXX")"
    status_file="$(mktemp "$TMP_DIR/status.XXXXXX")"

    if [[ -n "$body" ]]; then
        curl -sS -o "$response_file" -w '%{http_code}' -X "$method" "$url" "$@" --data "$body" > "$status_file"
    else
        curl -sS -o "$response_file" -w '%{http_code}' -X "$method" "$url" "$@" > "$status_file"
    fi

    printf '%s\n%s\n' "$(cat "$status_file")" "$response_file"
}

api_url="${BASE_URL%/}/api/sms/send"
root_url="${BASE_URL%/}/"
admin_url="${BASE_URL%/}/admin"

printf 'Health check para %s\n' "$BASE_URL"

root_response="$(request GET "$root_url" "")"
root_status="${root_response%%$'\n'*}"
root_file="${root_response#*$'\n'}"
printf '[INFO] GET / -> HTTP %s | corpo=%s\n' "$root_status" "$(snippet "$root_file")"

admin_response="$(request GET "$admin_url" "")"
admin_status="${admin_response%%$'\n'*}"
admin_file="${admin_response#*$'\n'}"
printf '[INFO] GET /admin -> HTTP %s | corpo=%s\n' "$admin_status" "$(snippet "$admin_file")"

health_url="${BASE_URL%/}/health"
health_response="$(request GET "$health_url" "")"
health_status="${health_response%%$'\n'*}"
health_file="${health_response#*$'\n'}"
printf '[INFO] GET /health -> HTTP %s | corpo=%s\n' "$health_status" "$(snippet "$health_file")"

health_ok="$(php -r '
$payload = json_decode(file_get_contents($argv[1]), true);
if (!is_array($payload)) { exit(1); }
if (($payload["success"] ?? false) !== true) { exit(2); }
if (($payload["allow_real_send"] ?? true) !== false) { exit(3); }
if (!isset($payload["queue_status"]) || !is_array($payload["queue_status"])) { exit(4); }
foreach (["api_key","DB_PASSWORD","password"] as $needle) {
    if (strpos(file_get_contents($argv[1]), $needle) !== false) { exit(5); }
}
echo "ok";
' "$health_file")"

if [[ "$health_ok" != "ok" ]]; then
    printf '[FAIL] /health nao retornou o JSON esperado\n'
    exit 1
fi

api_response="$(request POST "$api_url" '{"phone":"'"$PHONE_VALIDO"'","message":"Health check","type":"transactional"}' -H 'Content-Type: application/json')"
api_status="${api_response%%$'\n'*}"
api_file="${api_response#*$'\n'}"
printf '[INFO] POST /api/sms/send sem Authorization -> HTTP %s | corpo=%s\n' "$api_status" "$(snippet "$api_file")"

if [[ "$api_status" != "401" ]]; then
    printf '[FAIL] API sem key nao retornou 401\n'
    exit 1
fi

https_probe="$(curl -sS -o /dev/null -w '%{http_code} %{ssl_verify_result} %{url_effective}' "$BASE_URL")"
printf '[INFO] HTTPS probe -> %s\n' "$https_probe"

https_code="${https_probe%% *}"
rest="${https_probe#* }"
ssl_result="${rest%% *}"

if [[ "$ssl_result" != "0" ]]; then
    printf '[FAIL] HTTPS verify result diferente de zero: %s\n' "$ssl_result"
    exit 1
fi

if [[ "$https_code" != 2* && "$https_code" != 3* && "$https_code" != 4* ]]; then
    printf '[WARN] HTTPS probe nao retornou resposta HTTP comum: %s\n' "$https_probe"
fi

printf '[PASS] Health check basico concluido\n'
