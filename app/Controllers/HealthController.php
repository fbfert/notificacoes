<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Support\Config;

final class HealthController
{
    public function index(Request $request): never
    {
        $queueStatus = [
            'queued' => (int) (Database::fetchOne('SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = "queued"')['total'] ?? 0),
            'processing' => (int) (Database::fetchOne('SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = "processing"')['total'] ?? 0),
            'sent_mock' => (int) (Database::fetchOne('SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = "sent" AND provider = "mock"')['total'] ?? 0),
            'failed' => (int) (Database::fetchOne('SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = "failed"')['total'] ?? 0),
            'blocked' => (int) (Database::fetchOne('SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = "blocked"')['total'] ?? 0),
        ];

        Response::json([
            'success' => true,
            'app' => 'Tars Notificacoes',
            'env' => Config::appEnv(),
            'timestamp' => date('c'),
            'queue_status' => $queueStatus,
            'sms_driver' => Config::smsDriver(),
            'allow_real_send' => false,
        ]);
    }
}
