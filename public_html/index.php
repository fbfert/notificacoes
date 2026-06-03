<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Support/Env.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

\App\Support\Env::load(BASE_PATH . '/.env');

date_default_timezone_set((string) ($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('tn_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

use App\Controllers\AdminDashboardController;
use App\Controllers\ApiSmsController;
use App\Controllers\HealthController;
use App\Controllers\MessagesController;
use App\Controllers\ProjectsController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Support\Csrf;

Csrf::token();

$request = Request::fromGlobals();
$router = new Router();

$router->get('/', [AdminDashboardController::class, 'index']);
$router->get('/admin', [AdminDashboardController::class, 'index']);
$router->post('/admin/tars-notificacoes/test', [AdminDashboardController::class, 'sendTarsNotificationsTest']);
$router->post('/admin/login', [AdminDashboardController::class, 'login']);
$router->post('/admin/logout', [AdminDashboardController::class, 'logout']);
$router->get('/admin/projects', [ProjectsController::class, 'index']);
$router->post('/admin/projects', [ProjectsController::class, 'store']);
$router->post('/admin/projects/{id}/regenerate-key', [ProjectsController::class, 'regenerateKey']);
$router->post('/admin/projects/{id}/activate', [ProjectsController::class, 'activate']);
$router->post('/admin/projects/{id}/deactivate', [ProjectsController::class, 'deactivate']);
$router->get('/admin/messages', [MessagesController::class, 'index']);
$router->get('/admin/messages/{id}', [MessagesController::class, 'show']);
$router->get('/health', [HealthController::class, 'index']);
$router->post('/api/sms/send', [ApiSmsController::class, 'send']);
$router->get('/api/sms/status/{id}', [ApiSmsController::class, 'status']);

try {
    $router->dispatch($request);
} catch (Throwable $e) {
    Response::json([
        'success' => false,
        'message' => 'Erro interno do servidor',
        'error' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? $e->getMessage() : null,
    ], 500);
}
