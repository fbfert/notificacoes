set -euo pipefail
BASE=/var/www/tars-notificacoes
cd "$BASE"
set -a
. ./.env
set +a

PROJECT_DATA=$(php -r '
    define("BASE_PATH", "/var/www/tars-notificacoes");
    require BASE_PATH . "/app/Support/Env.php";
    spl_autoload_register(static function (string $class): void {
        $prefix = "App\\";
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = BASE_PATH . "/app/" . str_replace("\\", "/", $relative) . ".php";
        if (is_file($path)) {
            require_once $path;
        }
    });
    \App\Support\Env::load(BASE_PATH . "/.env");
    $slug = "suite-" . date("YmdHis") . "-" . substr(bin2hex(random_bytes(3)), 0, 6);
    $key = bin2hex(random_bytes(24));
    $hash = password_hash($key, PASSWORD_DEFAULT);
    $id = \App\Core\Database::insert(
        "INSERT INTO tn_projects (name, slug, api_key_hash, active, daily_limit, monthly_limit, minute_limit, max_attempts, last_used_at, created_at, updated_at) VALUES (:name, :slug, :api_key_hash, 1, NULL, NULL, NULL, 3, NULL, NOW(), NOW())",
        [
            ":name" => "Suite Project",
            ":slug" => $slug,
            ":api_key_hash" => $hash,
        ]
    );
    echo $id . "|" . $key . "|" . $slug;
')
IFS='|' read -r PROJECT_ID PROJECT_KEY PROJECT_SLUG <<< "$PROJECT_DATA"
export BASE_URL='https://gateway.tars.art.br'
export API_KEY="$PROJECT_KEY"
export PROJECT_SLUG="$PROJECT_SLUG"
export PHONE_VALIDO='5511999999999'
export PHONE_INVALIDO='abc'
export PHONE_OPT_OUT='5511999999999'
export MYSQL_BIN="$(command -v mysql)"

echo "PROJECT_ID=$PROJECT_ID"
echo "PROJECT_SLUG=$PROJECT_SLUG"

bash scripts/smoke_test.sh
bash scripts/v02_edge_cases.sh
bash scripts/panel_smoke.sh
bash scripts/health_check.sh
php scripts/security_v021_tests.php
php cron/processar_fila.php
php scripts/check_queue.php
