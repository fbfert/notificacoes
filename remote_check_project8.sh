set -euo pipefail
cd /var/www/tars-notificacoes
set -a
. ./.env
set +a
php -r '
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
    $row = \App\Core\Database::fetchOne("SELECT id, slug, active FROM tn_projects WHERE id = 8 LIMIT 1");
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
'
