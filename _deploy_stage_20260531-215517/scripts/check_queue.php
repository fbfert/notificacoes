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

use App\Core\Database;

$statuses = [
    'queued' => 'queued',
    'processing' => 'processing',
    'sent_mock' => 'sent_mock',
    'failed' => 'failed',
    'blocked' => 'blocked',
];

echo "Tars Notificacoes - fila\n";
echo str_repeat('=', 40) . "\n";

foreach ($statuses as $label => $status) {
    if ($label === 'sent_mock') {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = "sent" AND provider = "mock"'
        );
        $messages = Database::fetchAll(
            'SELECT id, project_id, phone, message, status, provider_message_id, created_at
             FROM tn_sms_messages
             WHERE status = "sent" AND provider = "mock"
             ORDER BY id DESC
             LIMIT 20'
        );
    } else {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = :status',
            [':status' => $status]
        );
        $messages = Database::fetchAll(
            'SELECT id, project_id, phone, message, status, provider_message_id, created_at
             FROM tn_sms_messages
             WHERE status = :status
             ORDER BY id DESC
             LIMIT 20',
            [':status' => $status]
        );
    }

    $total = (int) ($row['total'] ?? 0);
    echo sprintf("%s: %d\n", $label, $total);

    foreach ($messages as $message) {
        echo sprintf(
            "  #%d project=%d phone=%s status=%s provider_message_id=%s created_at=%s\n",
            (int) $message['id'],
            (int) $message['project_id'],
            (string) ($message['phone'] ?? ''),
            (string) $message['status'],
            (string) ($message['provider_message_id'] ?? ''),
            (string) $message['created_at']
        );
    }
}
