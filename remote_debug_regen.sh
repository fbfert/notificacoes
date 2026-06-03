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
    date_default_timezone_set((string) ($_ENV["APP_TIMEZONE"] ?? "America/Sao_Paulo"));

    $slug = "debug-" . date("YmdHis") . "-" . substr(bin2hex(random_bytes(3)), 0, 6);
    $key = bin2hex(random_bytes(24));
    $hash = password_hash($key, PASSWORD_DEFAULT);
    $id = \App\Core\Database::insert(
        "INSERT INTO tn_projects (name, slug, api_key_hash, active, daily_limit, monthly_limit, minute_limit, max_attempts, last_used_at, created_at, updated_at) VALUES (:name, :slug, :api_key_hash, 1, NULL, NULL, NULL, 3, NULL, NOW(), NOW())",
        [
            ":name" => "Debug Project",
            ":slug" => $slug,
            ":api_key_hash" => $hash,
        ]
    );
    echo $id . "|" . $key . "|" . $slug;
')
IFS='|' read -r PROJECT_ID PROJECT_KEY PROJECT_SLUG <<< "$PROJECT_DATA"

echo "PROJECT_ID=$PROJECT_ID"
echo "PROJECT_SLUG=$PROJECT_SLUG"

cookie=$(mktemp)
trap 'rm -f "$cookie"' EXIT

admin_page=$(curl -sS -c "$cookie" -b "$cookie" https://gateway.tars.art.br/admin)
csrf=$(printf '%s' "$admin_page" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -n 1)

echo "LOGIN_CSRF=${#csrf} chars"
login_code=$(curl -sS -o /tmp/login_body.$$ -w '%{http_code}' -c "$cookie" -b "$cookie" -X POST https://gateway.tars.art.br/admin/login -H 'Content-Type: application/x-www-form-urlencoded' --data-urlencode "csrf_token=$csrf" --data-urlencode "password=$ADMIN_PASSWORD")
echo "LOGIN_CODE=$login_code"

projects_page=$(curl -sS -c "$cookie" -b "$cookie" https://gateway.tars.art.br/admin/projects)
csrf_projects=$(printf '%s' "$projects_page" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -n 1)
echo "PROJECTS_CSRF=${#csrf_projects} chars"

regen_code=$(curl -sS -o /tmp/regen_body.$$ -w '%{http_code}' -c "$cookie" -b "$cookie" -X POST https://gateway.tars.art.br/admin/projects/$PROJECT_ID/regenerate-key -H 'Content-Type: application/x-www-form-urlencoded' --data-urlencode "csrf_token=$csrf_projects")
echo "REGEN_CODE=$regen_code"
echo '---REGEN BODY START---'
head -c 800 /tmp/regen_body.$$; echo
echo '---REGEN BODY END---'

rm -f /tmp/login_body.$$ /tmp/regen_body.$$ 
