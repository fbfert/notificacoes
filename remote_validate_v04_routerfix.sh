set -euo pipefail
STAMP=$(date +%Y%m%d-%H%M%S)
BASE=/var/www/tars-notificacoes
BACKUP_DIR=/var/www/backups
mkdir -p "$BACKUP_DIR"
tar -czf "$BACKUP_DIR/tars-notificacoes-v04-routerfix-$STAMP.tar.gz" -C /var/www tars-notificacoes
rm -rf "$BASE"/app "$BASE"/config "$BASE"/cron "$BASE"/database "$BASE"/docs "$BASE"/public_html "$BASE"/scripts "$BASE"/README.md "$BASE"/DEPLOY.md "$BASE"/RELEASE_NOTES.md
mkdir -p "$BASE"
tar -xzf /tmp/v04-operational-sync.tar.gz -C "$BASE"
cd "$BASE"
php -l app/Core/Router.php
php -l app/Controllers/HealthController.php
php -l app/Controllers/ApiSmsController.php
php -l app/Controllers/MessagesController.php
php -l app/Controllers/ProjectsController.php
php -l app/Middleware/ApiKeyMiddleware.php
php -l app/Services/SmsService.php
php -l app/Support/Config.php
php -l public_html/index.php
php -l scripts/create_test_project.php
php -l scripts/health_check.sh >/dev/null 2>&1 || true
php scripts/v04_operational_tests.php
php scripts/security_v021_tests.php
bash scripts/smoke_test.sh
bash scripts/v02_edge_cases.sh
bash scripts/panel_smoke.sh
bash scripts/health_check.sh
php cron/processar_fila.php
php scripts/check_queue.php
