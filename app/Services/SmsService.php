<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Config;
use App\Support\Logger;
use App\Support\PhoneNormalizer;
use InvalidArgumentException;
use Throwable;

final class SmsService
{
    public function queueSms(array $project, string $recipient, string $message, array $meta = []): array
    {
        if (!isset($project['id'])) {
            throw new InvalidArgumentException('Projeto invalido');
        }

        $projectId = (int) $project['id'];
        $maxAttempts = max(1, (int) ($project['max_attempts'] ?? Config::queueMaxAttempts()));
        $idempotencyKey = isset($meta['idempotency_key']) ? trim((string) $meta['idempotency_key']) : '';
        $type = Config::normalizeSmsType($meta['type'] ?? null);

        $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if (trim($message) === '') {
            return $this->storeBlocked($projectId, $recipient, null, $message, 'empty_message', 'Mensagem obrigatoria', $meta, $idempotencyKey, $maxAttempts, $type);
        }

        $maxLength = 160;
        if ($messageLength > $maxLength) {
            return $this->storeBlocked(
                $projectId,
                $recipient,
                null,
                $message,
                'message_too_long',
                'Mensagem maior que ' . $maxLength . ' caracteres',
                $meta,
                $idempotencyKey,
                $maxAttempts,
                $type
            );
        }

        $recipientCheck = self::evaluateRecipientForSending($recipient);
        if (!$recipientCheck['allowed']) {
            Logger::security('Envio bloqueado por politica de teste', [
                'project_id' => $projectId,
                'reason' => $recipientCheck['block_reason'],
                'phone' => $recipientCheck['phone'],
            ]);

            return $this->storeBlocked(
                $projectId,
                $recipient,
                $recipientCheck['phone'],
                $message,
                (string) $recipientCheck['block_reason'],
                (string) $recipientCheck['error_message'],
                $meta,
                $idempotencyKey,
                $maxAttempts,
                $type
            );
        }

        $phone = (string) $recipientCheck['phone'];

        if ($idempotencyKey !== '') {
            $existing = Database::fetchOne(
                'SELECT * FROM tn_sms_messages WHERE project_id = :project_id AND idempotency_key = :idempotency_key LIMIT 1',
                [
                    ':project_id' => $projectId,
                    ':idempotency_key' => $idempotencyKey,
                ]
            );

            if ($existing !== null) {
                return $this->formatMessageResult($existing, true);
            }
        }

        if ($this->isOptedOut($phone)) {
            return $this->storeBlocked($projectId, $recipient, $phone, $message, 'optout', 'Telefone bloqueado por opt-out', $meta, $idempotencyKey, $maxAttempts, $type);
        }

        $type = Config::normalizeSmsType($meta['type'] ?? null);
        if (!Config::smsTypeAllowed($type)) {
            return $this->storeBlocked(
                $projectId,
                $recipient,
                $phone,
                $message,
                'invalid_type',
                'Tipo de mensagem invalido. Tipos aceitos: ' . implode(', ', Config::allowedSmsTypes()),
                $meta,
                $idempotencyKey,
                $maxAttempts,
                $type
            );
        }

        if ($this->isOverMinuteLimit($project)) {
            return $this->storeBlocked(
                $projectId,
                $recipient,
                $phone,
                $message,
                'minute_limit_reached',
                'Limite por minuto do projeto atingido',
                $meta,
                $idempotencyKey,
                $maxAttempts,
                $type
            );
        }

        if ($this->isOverLimit($project, 'daily_limit', 'DAY')) {
            return $this->storeBlocked($projectId, $recipient, $phone, $message, 'daily_limit_reached', 'Limite diario do projeto atingido', $meta, $idempotencyKey, $maxAttempts, $type);
        }

        if ($this->isOverLimit($project, 'monthly_limit', 'MONTH')) {
            return $this->storeBlocked($projectId, $recipient, $phone, $message, 'monthly_limit_reached', 'Limite mensal do projeto atingido', $meta, $idempotencyKey, $maxAttempts, $type);
        }

        $attempts = 0;
        $status = 'queued';

        return $this->insertMessage(
            $projectId,
            $recipient,
            $phone,
            $message,
            $status,
            null,
            'mock',
            $meta,
            $idempotencyKey,
            $attempts,
            $maxAttempts,
            $type
        );
    }

    /**
     * @return array{allowed: bool, phone: ?string, block_reason: ?string, error_message: ?string}
     */
    public static function evaluateRecipientForSending(string $recipientRaw): array
    {
        try {
            $phone = PhoneNormalizer::normalizeBrazilian($recipientRaw);
        } catch (InvalidArgumentException $e) {
            return [
                'allowed' => false,
                'phone' => null,
                'block_reason' => 'invalid_phone',
                'error_message' => $e->getMessage(),
            ];
        }

        if (!Config::smsTestOnly()) {
            return [
                'allowed' => true,
                'phone' => $phone,
                'block_reason' => null,
                'error_message' => null,
            ];
        }

        $allowedPhones = Config::smsAllowedTestPhones();
        if ($allowedPhones === []) {
            return [
                'allowed' => false,
                'phone' => $phone,
                'block_reason' => 'test_only_allowlist_empty',
                'error_message' => 'Envio bloqueado: SMS_TEST_ONLY=true e SMS_ALLOWED_TEST_PHONES vazio',
            ];
        }

        if (!in_array($phone, $allowedPhones, true)) {
            return [
                'allowed' => false,
                'phone' => $phone,
                'block_reason' => 'test_only_destination_not_allowed',
                'error_message' => 'Destino fora da lista permitida em SMS_TEST_ONLY',
            ];
        }

        return [
            'allowed' => true,
            'phone' => $phone,
            'block_reason' => null,
            'error_message' => null,
        ];
    }

    private function isOptedOut(string $phone): bool
    {
        $optout = Database::fetchOne('SELECT id FROM tn_optouts WHERE phone = :phone LIMIT 1', [':phone' => $phone]);

        return $optout !== null;
    }

    private function isOverLimit(array $project, string $limitField, string $period): bool
    {
        $limit = $project[$limitField] ?? null;
        if ($limit === null) {
            return false;
        }

        $limit = (int) $limit;
        if ($limit === 0) {
            return true;
        }

        $sql = 'SELECT COUNT(*) AS total FROM tn_sms_messages
                WHERE project_id = :project_id
                  AND status IN ("queued", "processing", "sent", "failed")
                  AND created_at >= DATE_FORMAT(NOW(), :date_format)';

        $dateFormat = $period === 'DAY' ? '%Y-%m-%d 00:00:00' : '%Y-%m-01 00:00:00';
        if ($period === 'MONTH') {
            $sql = 'SELECT COUNT(*) AS total FROM tn_sms_messages
                    WHERE project_id = :project_id
                      AND status IN ("queued", "processing", "sent", "failed")
                      AND created_at >= DATE_FORMAT(NOW(), :date_format)';
        }

        $row = Database::fetchOne($sql, [
            ':project_id' => (int) $project['id'],
            ':date_format' => $dateFormat,
        ]);

        $count = (int) ($row['total'] ?? 0);

        return $count >= $limit;
    }

    private function isOverMinuteLimit(array $project): bool
    {
        $limit = $project['minute_limit'] ?? Config::minuteLimit();
        if ($limit === null) {
            return false;
        }

        $limit = (int) $limit;
        if ($limit === 0) {
            return true;
        }

        $row = Database::fetchOne(
            'SELECT COUNT(*) AS total
             FROM tn_sms_messages
             WHERE project_id = :project_id
               AND created_at >= (NOW() - INTERVAL 1 MINUTE)',
            [
                ':project_id' => (int) $project['id'],
            ]
        );

        $count = (int) ($row['total'] ?? 0);

        return $count >= $limit;
    }

    private function storeBlocked(
        int $projectId,
        string $recipientRaw,
        ?string $phone,
        string $message,
        string $blockReason,
        string $errorMessage,
        array $meta,
        string $idempotencyKey,
        int $maxAttempts,
        string $type
    ): array {
        $messageId = Database::insert(
            'INSERT INTO tn_sms_messages
                (project_id, recipient_raw, phone, message, type, status, error_message, provider, meta_json, idempotency_key, attempts, max_attempts, sent_at, delivered_at, failed_at, created_at, updated_at)
             VALUES
                (:project_id, :recipient_raw, :phone, :message, :type, "blocked", :error_message, "mock", :meta_json, :idempotency_key, 0, :max_attempts, NULL, NULL, NULL, NOW(), NOW())',
            [
                ':project_id' => $projectId,
                ':recipient_raw' => $recipientRaw,
                ':phone' => $phone,
                ':message' => $message,
                ':type' => $type,
                ':error_message' => $errorMessage,
                ':meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                ':max_attempts' => $maxAttempts,
            ]
        );

        $this->logMessageChange($projectId, (int) $messageId, 'blocked', [
            'block_reason' => $blockReason,
            'error_message' => $errorMessage,
        ]);

        return $this->formatMessageResult([
            'id' => (int) $messageId,
            'project_id' => $projectId,
            'recipient_raw' => $recipientRaw,
            'phone' => $phone,
            'message' => $message,
            'status' => 'blocked',
            'error_message' => $errorMessage,
            'provider' => 'mock',
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
        ]);
    }

    private function insertMessage(
        int $projectId,
        string $recipientRaw,
        ?string $phone,
        string $message,
        string $status,
        ?string $errorMessage,
        string $provider,
        array $meta,
        string $idempotencyKey,
        int $attempts,
        int $maxAttempts,
        string $type
    ): array {
        $messageId = Database::insert(
            'INSERT INTO tn_sms_messages
                (project_id, recipient_raw, phone, message, type, status, error_message, provider, meta_json, idempotency_key, attempts, max_attempts, sent_at, delivered_at, failed_at, created_at, updated_at)
             VALUES
                (:project_id, :recipient_raw, :phone, :message, :type, :status, :error_message, :provider, :meta_json, :idempotency_key, :attempts, :max_attempts, NULL, NULL, NULL, NOW(), NOW())',
            [
                ':project_id' => $projectId,
                ':recipient_raw' => $recipientRaw,
                ':phone' => $phone,
                ':message' => $message,
                ':type' => $type,
                ':status' => $status,
                ':error_message' => $errorMessage,
                ':provider' => $provider,
                ':meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                ':attempts' => $attempts,
                ':max_attempts' => $maxAttempts,
            ]
        );

        $this->logMessageChange($projectId, (int) $messageId, $status, [
            'phone' => $phone,
            'recipient_raw' => $recipientRaw,
            'error_message' => $errorMessage,
        ]);

        return $this->formatMessageResult([
            'id' => (int) $messageId,
            'project_id' => $projectId,
            'recipient_raw' => $recipientRaw,
            'phone' => $phone,
            'message' => $message,
            'status' => $status,
            'error_message' => $errorMessage,
            'provider' => $provider,
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
        ]);
    }

    public function markForProcessing(array $message): void
    {
        $this->logMessageChange((int) $message['project_id'], (int) $message['id'], 'processing', [
            'attempts' => (int) ($message['attempts'] ?? 0),
        ]);
    }

    public function markResult(array $message, array $result, string $status, ?string $errorMessage = null): void
    {
        $projectId = (int) $message['project_id'];
        $messageId = (int) $message['id'];

        $sql = 'UPDATE tn_sms_messages
                SET status = :status,
                    provider_message_id = :provider_message_id,
                    error_message = :error_message,
                    sent_at = :sent_at,
                    delivered_at = :delivered_at,
                    failed_at = :failed_at,
                    updated_at = NOW()
                WHERE id = :id';

        $now = date('Y-m-d H:i:s');

        Database::execute($sql, [
            ':status' => $status,
            ':provider_message_id' => $result['provider_message_id'] ?? $result['external_id'] ?? null,
            ':error_message' => $errorMessage,
            ':sent_at' => $status === 'sent' ? ($result['sent_at'] ?? $now) : null,
            ':delivered_at' => $status === 'sent' ? ($result['delivered_at'] ?? $result['sent_at'] ?? $now) : null,
            ':failed_at' => $status === 'failed' ? ($result['failed_at'] ?? $now) : null,
            ':id' => $messageId,
        ]);

        $this->logMessageChange($projectId, $messageId, $status, $result + [
            'error_message' => $errorMessage,
        ]);
    }

    private function logMessageChange(int $projectId, int $messageId, string $action, array $details): void
    {
        Database::insert(
            'INSERT INTO tn_sms_logs
                (project_id, sms_message_id, action, status, details_json, created_at)
             VALUES
                (:project_id, :sms_message_id, :action, :status, :details_json, NOW())',
            [
                ':project_id' => $projectId,
                ':sms_message_id' => $messageId,
                ':action' => $action,
                ':status' => $action,
                ':details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function formatMessageResult(array $message, bool $idempotentHit = false): array
    {
        return [
            'message_id' => (int) $message['id'],
            'project_id' => (int) $message['project_id'],
            'status' => (string) $message['status'],
            'error_message' => $message['error_message'] ?? null,
            'phone' => $message['phone'] ?? null,
            'idempotency_key' => $message['idempotency_key'] ?? null,
            'idempotent_hit' => $idempotentHit,
        ];
    }
}
