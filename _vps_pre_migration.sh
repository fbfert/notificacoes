#!/usr/bin/env bash
set -euo pipefail
umask 027
cd /var/www/tars-notificacoes
mkdir -p /var/www/backups
stamp=$(date +%Y%m%d-%H%M%S)
backup_tar=/var/www/backups/tars-notificacoes-v04-pre-migration-${stamp}.tar.gz
backup_sql=/var/www/backups/tars_notificacoes-v04-pre-migration-${stamp}.sql

tar -czf "$backup_tar" -C /var/www tars-notificacoes

set -a
. /var/www/tars-notificacoes/.env
set +a

mysql_base=(mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" -N -B)

mysqldump -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" > "$backup_sql"

check_column() {
    local table="$1"
    local column="$2"
    local total
    total=$("${mysql_base[@]}" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${table}' AND COLUMN_NAME = '${column}';")
    printf '%s.%s=%s\n' "$table" "$column" "$total"
}

check_index() {
    local table="$1"
    local index_name="$2"
    local total
    total=$("${mysql_base[@]}" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${table}' AND INDEX_NAME = '${index_name}';")
    printf '%s.%s=%s\n' "$table" "$index_name" "$total"
}

check_column tn_projects minute_limit
check_column tn_projects last_used_at
check_column tn_sms_messages delivered_at
check_column tn_sms_messages failed_at
check_index tn_projects idx_tn_projects_last_used_at
check_index tn_sms_messages idx_tn_sms_messages_type

printf 'BACKUP_TAR=%s\n' "$backup_tar"
printf 'BACKUP_SQL=%s\n' "$backup_sql"
