#!/bin/bash
set -euo pipefail
TARGET=/var/www/tars-notificacoes
BACKUP_DIR=/var/www/backups
ARCHIVE=/tmp/tars-notificacoes-20260531-215517.tar.gz
ENV_SRC=/tmp/tars-notificacoes.env

mkdir -p "$BACKUP_DIR"
if [ -d "$TARGET" ] && [ "$(ls -A "$TARGET" 2>/dev/null)" != "" ]; then
  tar -czf "$BACKUP_DIR/tars-notificacoes-20260531-215517.tar.gz" -C /var/www tars-notificacoes
fi

mkdir -p "$TARGET"
tar -xzf "$ARCHIVE" -C "$TARGET"

if [ ! -f "$TARGET/.env" ] && [ -f "$ENV_SRC" ]; then
  cp "$ENV_SRC" "$TARGET/.env"
fi

if [ -f "$TARGET/.env" ]; then
  chmod 640 "$TARGET/.env"
fi

chown -R root:apache "$TARGET"
find "$TARGET" -type d -exec chmod 755 {} +
find "$TARGET" -type f -exec chmod 644 {} +
if [ -d "$TARGET/storage" ]; then
  chown -R apache:apache "$TARGET/storage"
  find "$TARGET/storage" -type d -exec chmod 775 {} +
  find "$TARGET/storage" -type f -exec chmod 664 {} +
fi
if [ -d "$TARGET/storage/logs" ]; then
  find "$TARGET/storage/logs" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
fi
if [ -d "$TARGET/storage/tmp" ]; then
  find "$TARGET/storage/tmp" -mindepth 1 -maxdepth 1 ! -name '.gitkeep' -exec rm -rf {} +
fi

CONF=/etc/httpd/conf.d/gateway.tars.art.br.conf
cat > "$CONF" <<'EOF'
<VirtualHost *:80>
    ServerName gateway.tars.art.br
    DocumentRoot /var/www/tars-notificacoes/public_html

    <Directory /var/www/tars-notificacoes/public_html>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    ErrorLog logs/gateway.tars.art.br-error.log
    CustomLog logs/gateway.tars.art.br-access.log combined
</VirtualHost>
EOF

if command -v apachectl >/dev/null 2>&1; then
  apachectl configtest
else
  httpd -t
fi
systemctl reload httpd

if ! source "$ENV_SRC"; then
  echo "ENV_SRC_MISSING"
  exit 20
fi

if command -v mysql >/dev/null 2>&1; then
  db_exists=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" --password="$DB_PASSWORD" -N -s -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$DB_DATABASE';")
  if [ "$db_exists" = "0" ]; then
    echo "DB_MISSING"
    exit 21
  fi
  table_count=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" --password="$DB_PASSWORD" -N -s -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '$DB_DATABASE' AND TABLE_NAME IN ('tn_projects','tn_sms_messages','tn_sms_logs','tn_sms_templates','tn_optouts','tn_settings');")
  if [ "$table_count" = "0" ]; then
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" --password="$DB_PASSWORD" "$DB_DATABASE" < "$TARGET/database/schema.sql"
  fi
else
  echo "MYSQL_CLIENT_MISSING"
  exit 22
fi

if ! crontab -l 2>/dev/null | grep -Fq '/var/www/tars-notificacoes/cron/processar_fila.php'; then
  (crontab -l 2>/dev/null; echo '* * * * * /usr/bin/php /var/www/tars-notificacoes/cron/processar_fila.php >> /var/www/tars-notificacoes/storage/logs/cron.log 2>&1') | crontab -
fi

if command -v certbot >/dev/null 2>&1; then
  if ! curl -Is https://gateway.tars.art.br >/dev/null 2>&1; then
    if curl -Is http://gateway.tars.art.br >/dev/null 2>&1; then
      echo "CERTBOT_CMD: certbot --apache -d gateway.tars.art.br"
    fi
  fi
fi

echo "DEPLOY_OK"