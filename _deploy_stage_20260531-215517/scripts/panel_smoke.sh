#!/usr/bin/env bash

set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin123}"

ADMIN_URL="${BASE_URL%/}/admin"
TMP_DIR="$(mktemp -d)"
COOKIE_JAR="$TMP_DIR/cookies.txt"
trap 'rm -rf "$TMP_DIR"' EXIT

request() {
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

request_no_cookie() {
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

snippet() {
    local file="$1"
    php -r 'echo substr(preg_replace("/\s+/", " ", file_get_contents($argv[1])), 0, 220);' "$file"
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

login_page_response="$(request GET "$ADMIN_URL" "")"
login_page_status="${login_page_response%%$'\n'*}"
login_page_file="${login_page_response#*$'\n'}"
csrf_token="$(grep -oE 'name="csrf_token" value="[^"]+"' "$login_page_file" | head -1 | sed 's/.*value="//; s/"$//')"

login_response="$(request POST "$ADMIN_URL/login" "csrf_token=${csrf_token}&password=${ADMIN_PASSWORD}" -H 'Content-Type: application/x-www-form-urlencoded')"
login_status="${login_response%%$'\n'*}"
login_file="${login_response#*$'\n'}"
expect_status 'Login do painel' 302 "$login_status" "$login_file"

dashboard_response="$(request GET "$ADMIN_URL" "")"
dashboard_status="${dashboard_response%%$'\n'*}"
dashboard_file="${dashboard_response#*$'\n'}"
expect_status 'Dashboard apos login' 200 "$dashboard_status" "$dashboard_file"

logout_token="$(grep -oE 'name="csrf_token" value="[^"]+"' "$dashboard_file" | head -1 | sed 's/.*value="//; s/"$//')"
logout_response="$(request POST "$ADMIN_URL/logout" "csrf_token=${logout_token}" -H 'Content-Type: application/x-www-form-urlencoded')"
logout_status="${logout_response%%$'\n'*}"
logout_file="${logout_response#*$'\n'}"
expect_status 'Logout do painel' 302 "$logout_status" "$logout_file"

csrf_login_response="$(request_no_cookie POST "$ADMIN_URL/login" "password=${ADMIN_PASSWORD}" -H 'Content-Type: application/x-www-form-urlencoded')"
csrf_login_status="${csrf_login_response%%$'\n'*}"
csrf_login_file="${csrf_login_response#*$'\n'}"
expect_status 'CSRF em /admin/login' 419 "$csrf_login_status" "$csrf_login_file"

login_page_response2="$(request GET "$ADMIN_URL" "")"
login_page_file2="${login_page_response2#*$'\n'}"
csrf_token2="$(grep -oE 'name="csrf_token" value="[^"]+"' "$login_page_file2" | head -1 | sed 's/.*value="//; s/"$//')"
login_response2="$(request POST "$ADMIN_URL/login" "csrf_token=${csrf_token2}&password=${ADMIN_PASSWORD}" -H 'Content-Type: application/x-www-form-urlencoded')"
login_status2="${login_response2%%$'\n'*}"
login_file2="${login_response2#*$'\n'}"
expect_status 'Re-login do painel' 302 "$login_status2" "$login_file2"

csrf_projects_response="$(request POST "$ADMIN_URL/projects" "name=CSRF Test&slug=csrf-test&api_key=test" -H 'Content-Type: application/x-www-form-urlencoded')"
csrf_projects_status="${csrf_projects_response%%$'\n'*}"
csrf_projects_file="${csrf_projects_response#*$'\n'}"
expect_status 'CSRF em /admin/projects' 419 "$csrf_projects_status" "$csrf_projects_file"

printf '\nValidação do painel concluída.\n'
