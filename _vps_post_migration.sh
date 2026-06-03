#!/usr/bin/env bash
set -euo pipefail
umask 027
cd /var/www/tars-notificacoes
archive=/tmp/v04-operational-sync.tar.gz
if [[ ! -f "$archive" ]]; then
    echo "Arquivo de sincronizacao nao encontrado: $archive"
    exit 1
fi

tar -xzf "$archive" -C /var/www/tars-notificacoes

set -a
. /var/www/tars-notificacoes/.env
set +a

mysql_base=(mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" -N -B)

column_exists() {
    local table="$1"
    local column="$2"
    local total
    total=$("${mysql_base[@]}" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${table}' AND COLUMN_NAME = '${column}';")
    [[ "$total" == "1" ]]
}

index_exists() {
    local table="$1"
    local index_name="$2"
    local total
    total=$("${mysql_base[@]}" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${table}' AND INDEX_NAME = '${index_name}';")
    [[ "$total" == "1" ]]
}

column_default() {
    local table="$1"
    local column="$2"
    "${mysql_base[@]}" -e "SELECT IFNULL(COLUMN_DEFAULT, 'NULL') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${table}' AND COLUMN_NAME = '${column}';"
}

apply_sql() {
    local sql="$1"
    "${mysql_base[@]}" -e "$sql"
}

ALTERS=()
EXISTING=()

if column_exists tn_projects minute_limit; then
    EXISTING+=("tn_projects.minute_limit")
else
    apply_sql "ALTER TABLE tn_projects ADD COLUMN minute_limit INT UNSIGNED DEFAULT NULL AFTER monthly_limit;"
    ALTERS+=("tn_projects.minute_limit")
fi

if column_exists tn_projects last_used_at; then
    EXISTING+=("tn_projects.last_used_at")
else
    apply_sql "ALTER TABLE tn_projects ADD COLUMN last_used_at DATETIME DEFAULT NULL AFTER minute_limit;"
    ALTERS+=("tn_projects.last_used_at")
fi

if index_exists tn_projects idx_tn_projects_last_used_at; then
    EXISTING+=("idx_tn_projects_last_used_at")
else
    apply_sql "ALTER TABLE tn_projects ADD KEY idx_tn_projects_last_used_at (last_used_at);"
    ALTERS+=("idx_tn_projects_last_used_at")
fi

if column_exists tn_sms_messages delivered_at; then
    EXISTING+=("tn_sms_messages.delivered_at")
else
    apply_sql "ALTER TABLE tn_sms_messages ADD COLUMN delivered_at DATETIME DEFAULT NULL AFTER sent_at;"
    ALTERS+=("tn_sms_messages.delivered_at")
fi

if column_exists tn_sms_messages failed_at; then
    EXISTING+=("tn_sms_messages.failed_at")
else
    apply_sql "ALTER TABLE tn_sms_messages ADD COLUMN failed_at DATETIME DEFAULT NULL AFTER delivered_at;"
    ALTERS+=("tn_sms_messages.failed_at")
fi

current_type_default=$(column_default tn_sms_messages type)
if [[ "$current_type_default" != "transactional" ]]; then
    apply_sql "ALTER TABLE tn_sms_messages MODIFY COLUMN type VARCHAR(20) NOT NULL DEFAULT 'transactional';"
    ALTERS+=("tn_sms_messages.type default")
else
    EXISTING+=("tn_sms_messages.type default")
fi

if index_exists tn_sms_messages idx_tn_sms_messages_type; then
    EXISTING+=("idx_tn_sms_messages_type")
else
    apply_sql "ALTER TABLE tn_sms_messages ADD KEY idx_tn_sms_messages_type (type);"
    ALTERS+=("idx_tn_sms_messages_type")
fi

echo "ALTERS_APPLIED=${ALTERS[*]:-none}"
echo "EXISTING=${EXISTING[*]:-none}"

# Projeto de teste reutilizavel para smoke_test e edge cases.
TEST_PROJECT_SLUG='projeto-teste-v0-2'
TEST_PROJECT_NAME='Projeto Teste v0.2'
TEST_PHONE='5549999999999'
TEST_API_KEY=$(openssl rand -hex 24)
TEST_API_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$TEST_API_KEY")
project_exists=$("${mysql_base[@]}" -e "SELECT COUNT(*) FROM tn_projects WHERE slug='${TEST_PROJECT_SLUG}';")
if [[ "$project_exists" == "0" ]]; then
    apply_sql "INSERT INTO tn_projects (name, slug, api_key_hash, active, daily_limit, monthly_limit, minute_limit, max_attempts, last_used_at, created_at, updated_at) VALUES ('${TEST_PROJECT_NAME}', '${TEST_PROJECT_SLUG}', '${TEST_API_HASH}', 1, NULL, NULL, NULL, 3, NULL, NOW(), NOW());"
    echo "TEST_PROJECT_ACTION=created"
else
    apply_sql "UPDATE tn_projects SET api_key_hash='${TEST_API_HASH}', active=1, daily_limit=NULL, monthly_limit=NULL, minute_limit=NULL, max_attempts=3, last_used_at=NULL, updated_at=NOW() WHERE slug='${TEST_PROJECT_SLUG}';"
    echo "TEST_PROJECT_ACTION=rotated"
fi

export BASE_URL='https://gateway.tars.art.br'
export API_KEY="$TEST_API_KEY"
export PHONE_VALIDO="$TEST_PHONE"
export PHONE_INVALIDO='abc'
export PHONE_OPT_OUT='5511999999999'
export MYSQL_BIN="${MYSQL_BIN:-$(command -v mysql)}"
export SMS_ALLOWED_TEST_PHONES='5549999999999,5511999999999'

php -l app/Controllers/HealthController.php
php -l app/Controllers/ApiSmsController.php
php -l app/Controllers/MessagesController.php
php -l app/Controllers/ProjectsController.php
php -l app/Core/Router.php
php -l app/Middleware/ApiKeyMiddleware.php
php -l app/Services/SmsService.php
php -l app/Support/Config.php
php -l public_html/index.php
php -l scripts/create_test_project.php
php -l scripts/v04_operational_tests.php

bash scripts/health_check.sh
bash scripts/smoke_test.sh
bash scripts/v02_edge_cases.sh
bash scripts/panel_smoke.sh
php scripts/security_v021_tests.php
php scripts/v04_operational_tests.php
php cron/processar_fila.php
php scripts/check_queue.php

echo "VALIDATION_DONE=1"
