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

use App\Providers\MockSmsProvider;
use App\Services\QueueService;

$queueService = new QueueService(new MockSmsProvider());
$summary = $queueService->processPending(100);

echo sprintf(
    "[%s] Processados: %d | Enviados: %d | Falhas: %d\n",
    date('Y-m-d H:i:s'),
    $summary['processed'],
    $summary['sent'],
    $summary['failed']
);
