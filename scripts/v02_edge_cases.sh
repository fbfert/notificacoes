#!/usr/bin/env bash

set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
API_KEY="${API_KEY:-}"
PROJECT_SLUG="${PROJECT_SLUG:-projeto-teste-v0-2}"
PHONE_VALIDO="${PHONE_VALIDO:-(11) 99999-9999}"
PHONE_OPT_OUT="${PHONE_OPT_OUT:-(11) 99999-9999}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin123}"
MYSQL_BIN="${MYSQL_BIN:-/c/Program Files/MySQL/MySQL Server 8.4/bin/mysql.exe}"
DB_NAME="${DB_NAME:-tars_notificacoes}"
DB_USER="${DB_USER:-root}"

if [[ -z "$API_KEY" ]]; then
    printf 'API_KEY nao informado.\n'
    exit 1
fi

if [[ ! -x "$MYSQL_BIN" ]]; then
    printf 'MySQL client nao encontrado em: %s\n' "$MYSQL_BIN"
    exit 1
fi

API_URL="${BASE_URL%/}/api/sms/send"
ADMIN_URL="${BASE_URL%/}/admin"
TMP_DIR="$(mktemp -d)"
COOKIE_JAR="$TMP_DIR/cookies.txt"
trap 'rm -rf "$TMP_DIR"' EXIT

mysql_query() {
    local sql="$1"
    "$MYSQL_BIN" -u "$DB_USER" "$DB_NAME" --batch --skip-column-names -e "$sql"
}

http_request() {
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

http_request_cookie() {
    local method="$1"
    local url="$2"
    local body="$3"
    shift 3
    local response_file status_file
    response_file="$(mktemp "$TMP_DIR/resp.XXXXXX")"
    status_file="$(mktemp "$TMP_DIR/status.XXXXXX")"

    if [[ -n "$body" ]]; then
        curl -sS -o "$response_file" -w '%{http_code}' -X "$method" "$url" -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$@" --data "$body" > "$status_file"
    else
        curl -sS -o "$response_file" -w '%{http_code}' -X "$method" "$url" -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$@" > "$status_file"
    fi

    printf '%s\n%s\n' "$(cat "$status_file")" "$response_file"
}

snippet() {
    local file="$1"
    php -r 'echo substr(preg_replace("/\s+/", " ", file_get_contents($argv[1])), 0, 220);' "$file"
}

expect_contains() {
    local name="$1"
    local file="$2"
    local needle="$3"
    if grep -Fq "$needle" "$file"; then
        printf '[PASS] %s\n' "$name"
    else
        printf '[FAIL] %s | corpo=%s\n' "$name" "$(snippet "$file")"
        return 1
    fi
}

expect_status() {
    local name="$1"
    local expected="$2"
    local actual="$3"
    local file="$4"
    if [[ "$expected" == "$actual" ]]; then
        printf '[PASS] %s (HTTP %s) | corpo=%s\n' "$name" "$actual" "$(snippet "$file")"
    else
        printf '[FAIL] %s (esperado HTTP %s, obtido %s) | corpo=%s\n' "$name" "$expected" "$actual" "$(snippet "$file")"
        return 1
    fi
}

send_sms() {
    local phone="$1"
    local message="$2"
    local type="${3:-transactional}"
    local idempotency_key="${4:-}"
    local payload

    if [[ -n "$idempotency_key" ]]; then
        payload='{"phone":"'"$phone"'","message":"'"$message"'","type":"'"$type"'","idempotency_key":"'"$idempotency_key"'"}'
    else
        payload='{"phone":"'"$phone"'","message":"'"$message"'","type":"'"$type"'"}'
    fi

    http_request POST "$API_URL" "$payload" -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json'
}

get_project_value() {
    local field="$1"
    mysql_query "SELECT IFNULL(CAST(${field} AS CHAR), 'NULL') FROM tn_projects WHERE slug='${PROJECT_SLUG}' LIMIT 1;"
}

update_project() {
    local clause="$1"
    mysql_query "UPDATE tn_projects SET ${clause}, updated_at=NOW() WHERE slug='${PROJECT_SLUG}';" >/dev/null
}

restore_project() {
    local daily_sql monthly_sql
    if [[ "$orig_daily" == "NULL" ]]; then
        daily_sql="NULL"
    else
        daily_sql="$orig_daily"
    fi
    if [[ "$orig_monthly" == "NULL" ]]; then
        monthly_sql="NULL"
    else
        monthly_sql="$orig_monthly"
    fi
    mysql_query "UPDATE tn_projects SET active=${orig_active}, daily_limit=${daily_sql}, monthly_limit=${monthly_sql}, max_attempts=${orig_attempts}, updated_at=NOW() WHERE slug='${PROJECT_SLUG}';" >/dev/null
}

project_id="$(mysql_query "SELECT id FROM tn_projects WHERE slug='${PROJECT_SLUG}' LIMIT 1;")"
if [[ -z "$project_id" ]]; then
    printf 'Projeto de teste nao encontrado: %s\n' "$PROJECT_SLUG"
    exit 1
fi

orig_active="$(get_project_value active)"
orig_daily="$(get_project_value daily_limit)"
orig_monthly="$(get_project_value monthly_limit)"
orig_attempts="$(get_project_value max_attempts)"

cleanup() {
    restore_project >/dev/null 2>&1 || true
    mysql_query "DELETE FROM tn_optouts WHERE phone='5511999999999';" >/dev/null 2>&1 || true
}
trap cleanup EXIT

printf '== Projeto inativo ==\n'
update_project "active=0"
mapfile -t response < <(send_sms "$PHONE_VALIDO" 'Teste projeto inativo' 'transactional' "inactive-$(date +%s)")
expect_status 'Projeto inativo' 403 "${response[0]}" "${response[1]}"
expect_contains 'Projeto inativo - corpo' "${response[1]}" 'project_inactive'
restore_project

printf '== Opt-out ==\n'
mysql_query "INSERT INTO tn_optouts (phone, reason, created_at) VALUES ('5511999999999', 'teste validation', NOW()) ON DUPLICATE KEY UPDATE reason=VALUES(reason);" >/dev/null
mapfile -t response < <(send_sms "$PHONE_OPT_OUT" 'Teste optout' 'transactional' "optout-$(date +%s)")
expect_status 'Telefone em optout' 422 "${response[0]}" "${response[1]}"
expect_contains 'Telefone em optout - corpo' "${response[1]}" 'Telefone bloqueado por opt-out'
mysql_query "DELETE FROM tn_optouts WHERE phone='5511999999999';" >/dev/null

printf '== daily_limit = 0 ==\n'
update_project "daily_limit=0"
mapfile -t response < <(send_sms "$PHONE_VALIDO" 'Teste daily_limit zero' 'transactional' "daily0-$(date +%s)")
expect_status 'daily_limit=0' 422 "${response[0]}" "${response[1]}"
expect_contains 'daily_limit=0 - corpo' "${response[1]}" 'Limite diario do projeto atingido'
restore_project

printf '== monthly_limit = 0 ==\n'
update_project "monthly_limit=0"
mapfile -t response < <(send_sms "$PHONE_VALIDO" 'Teste monthly_limit zero' 'transactional' "monthly0-$(date +%s)")
expect_status 'monthly_limit=0' 422 "${response[0]}" "${response[1]}"
expect_contains 'monthly_limit=0 - corpo' "${response[1]}" 'Limite mensal do projeto atingido'
restore_project

printf '== Limite diario atingido ==\n'
update_project "daily_limit=1"
mapfile -t response < <(send_sms "$PHONE_VALIDO" 'Teste daily_limit reached' 'transactional' "daily1-$(date +%s)")
expect_status 'Limite diario atingido' 422 "${response[0]}" "${response[1]}"
expect_contains 'Limite diario atingido - corpo' "${response[1]}" 'Limite diario do projeto atingido'
restore_project

printf '== Limite mensal atingido ==\n'
update_project "monthly_limit=1"
mapfile -t response < <(send_sms "$PHONE_VALIDO" 'Teste monthly_limit reached' 'transactional' "monthly1-$(date +%s)")
expect_status 'Limite mensal atingido' 422 "${response[0]}" "${response[1]}"
expect_contains 'Limite mensal atingido - corpo' "${response[1]}" 'Limite mensal do projeto atingido'
restore_project

printf '== Login/logout do painel ==\n'
mapfile -t response < <(http_request_cookie GET "$ADMIN_URL" "" )
login_page="${response[1]}"
csrf_token="$(grep -oE 'name="csrf_token" value="[^"]+"' "$login_page" | head -1 | sed 's/.*value="//; s/"$//')"
mapfile -t response < <(http_request_cookie POST "$ADMIN_URL/login" "csrf_token=${csrf_token}&password=${ADMIN_PASSWORD}" -H 'Content-Type: application/x-www-form-urlencoded')
expect_status 'Login do painel' 302 "${response[0]}" "${response[1]}"
mapfile -t response < <(http_request_cookie GET "$ADMIN_URL" "" )
expect_status 'Dashboard apos login' 200 "${response[0]}" "${response[1]}"
dashboard_page="${response[1]}"
logout_token="$(grep -oE 'name="csrf_token" value="[^"]+"' "$dashboard_page" | head -1 | sed 's/.*value="//; s/"$//')"
mapfile -t response < <(http_request_cookie POST "$ADMIN_URL/logout" "csrf_token=${logout_token}" -H 'Content-Type: application/x-www-form-urlencoded')
expect_status 'Logout do painel' 302 "${response[0]}" "${response[1]}"

printf '== CSRF bloqueio em POST administrativo ==\n'
mapfile -t response < <(http_request_cookie GET "$ADMIN_URL" "" )
csrf_page="${response[1]}"
csrf_token="$(grep -oE 'name="csrf_token" value="[^"]+"' "$csrf_page" | head -1 | sed 's/.*value="//; s/"$//')"
mapfile -t response < <(http_request_cookie POST "$ADMIN_URL/login" "csrf_token=${csrf_token}&password=${ADMIN_PASSWORD}" -H 'Content-Type: application/x-www-form-urlencoded')
expect_status 'Re-login do painel para CSRF' 302 "${response[0]}" "${response[1]}"
mapfile -t response < <(http_request POST "$ADMIN_URL/login" "password=${ADMIN_PASSWORD}" -H 'Content-Type: application/x-www-form-urlencoded')
expect_status 'CSRF em /admin/login' 419 "${response[0]}" "${response[1]}"
mapfile -t response < <(http_request_cookie POST "$ADMIN_URL/projects" "name=CSRF Test&slug=csrf-test&api_key=test" -H 'Content-Type: application/x-www-form-urlencoded')
expect_status 'CSRF em /admin/projects' 419 "${response[0]}" "${response[1]}"

printf '\nValidação estendida concluída.\n'
