<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Providers\SmsProviderInterface;
use App\Support\Config;
use App\Support\SmsProviderResolver;

final class QueueService
{
    private readonly SmsProviderInterface $provider;

    public function __construct(?SmsProviderInterface $provider = null)
    {
        $this->provider = $provider ?? (new SmsProviderResolver())->resolve();
    }

    public function processPending(int $limit = 50): array
    {
        $limit = $limit > 0 ? $limit : Config::queueBatchSize();
        $messages = Database::fetchAll(
            'SELECT id FROM tn_sms_messages WHERE status = "queued" ORDER BY id ASC LIMIT ' . max(1, (int) $limit)
        );

        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($messages as $messageStub) {
            $claimed = Database::transaction(function ($pdo) use ($messageStub) {
                $stmt = $pdo->prepare('SELECT * FROM tn_sms_messages WHERE id = :id FOR UPDATE');
                $stmt->execute([':id' => (int) $messageStub['id']]);
                $message = $stmt->fetch();

                if ($message === false || (string) $message['status'] !== 'queued') {
                    return null;
                }

                $maxAttempts = (int) ($message['max_attempts'] ?? Config::queueMaxAttempts());
                $attempts = (int) ($message['attempts'] ?? 0);

                if ($maxAttempts > 0 && $attempts >= $maxAttempts) {
                    $pdo->prepare(
                        'UPDATE tn_sms_messages
                         SET status = "failed",
                             error_message = :error_message,
                             updated_at = NOW()
                         WHERE id = :id'
                    )->execute([
                        ':error_message' => 'Max attempts atingido antes do processamento',
                        ':id' => (int) $message['id'],
                    ]);

                    return [
                        'state' => 'max_attempts',
                        'message' => $message,
                    ];
                }

                $update = $pdo->prepare(
                    'UPDATE tn_sms_messages
                     SET status = "processing",
                         attempts = attempts + 1,
                         updated_at = NOW()
                     WHERE id = :id AND status = "queued"'
                );
                $update->execute([':id' => (int) $message['id']]);

                if ($update->rowCount() !== 1) {
                    return null;
                }

                return [
                    'state' => 'claimed',
                    'message' => array_merge($message, [
                        'attempts' => $attempts + 1,
                    ]),
                ];
            });

            if ($claimed === null) {
                $summary['skipped']++;
                continue;
            }

            $summary['processed']++;
            $message = $claimed['message'];

            if ($claimed['state'] === 'max_attempts') {
                $summary['failed']++;
                Database::insert(
                    'INSERT INTO tn_sms_logs
                        (project_id, sms_message_id, action, status, details_json, created_at)
                     VALUES
                        (:project_id, :sms_message_id, :action, :status, :details_json, NOW())',
                    [
                        ':project_id' => (int) $message['project_id'],
                        ':sms_message_id' => (int) $message['id'],
                        ':action' => 'failed',
                        ':status' => 'failed',
                        ':details_json' => json_encode([
                            'reason' => 'max_attempts',
                            'error_message' => 'Max attempts atingido antes do processamento',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]
                );
                continue;
            }

            (new SmsService())->markForProcessing($message);

            try {
                $result = $this->provider->send(
                    (string) $message['phone'],
                    (string) $message['message'],
                    [
                        'message_id' => (int) $message['id'],
                        'project_id' => (int) $message['project_id'],
                        'attempts' => (int) $message['attempts'],
                    ]
                );

                if (!empty($result['success'])) {
                    (new SmsService())->markResult($message, $result, 'sent', null);
                    $summary['sent']++;
                } else {
                    $errorMessage = (string) ($result['error_message'] ?? 'Falha ao enviar SMS');
                    (new SmsService())->markResult($message, $result, 'failed', $errorMessage);
                    $summary['failed']++;
                }
            } catch (\Throwable $e) {
                $errorMessage = 'Erro no provider mock: ' . $e->getMessage();
                (new SmsService())->markResult($message, [
                    'success' => false,
                    'provider' => 'mock',
                    'error_message' => $errorMessage,
                ], 'failed', $errorMessage);
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
