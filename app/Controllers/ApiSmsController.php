<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Core\Request;
use App\Middleware\ApiKeyMiddleware;
use App\Services\SmsService;
use App\Support\Config;
use App\Support\Logger;
use InvalidArgumentException;
use JsonException;

final class ApiSmsController
{
    public function send(Request $request): never
    {
        if (!$request->isJsonContentType()) {
            Response::json([
                'success' => false,
                'error_code' => 'invalid_content_type',
                'message' => 'Content-Type deve ser application/json',
            ], 415);
        }

        try {
            $payload = json_decode($request->rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Response::json([
                'success' => false,
                'error_code' => 'invalid_json',
                'message' => 'JSON invalido',
            ], 400);
        }

        if (!is_array($payload)) {
            Response::json([
                'success' => false,
                'error_code' => 'invalid_json',
                'message' => 'JSON invalido',
            ], 400);
        }

        $phoneRaw = $payload['phone'] ?? null;
        $messageRaw = $payload['message'] ?? null;
        $typeRaw = $payload['type'] ?? null;
        $idempotencyKey = isset($payload['idempotency_key']) ? trim((string) $payload['idempotency_key']) : '';

        if (!array_key_exists('phone', $payload)) {
            Response::json([
                'success' => false,
                'error_code' => 'phone_missing',
                'message' => 'Campo phone obrigatorio',
            ], 422);
        }

        if (!array_key_exists('message', $payload)) {
            Response::json([
                'success' => false,
                'error_code' => 'message_missing',
                'message' => 'Campo message obrigatorio',
            ], 422);
        }

        if (!is_string($phoneRaw) || trim($phoneRaw) === '') {
            Response::json([
                'success' => false,
                'error_code' => 'phone_invalid',
                'message' => 'Telefone invalido',
            ], 422);
        }

        if (!is_string($messageRaw) || trim($messageRaw) === '') {
            Response::json([
                'success' => false,
                'error_code' => 'message_empty',
                'message' => 'Mensagem obrigatoria',
            ], 422);
        }

        $messageLength = function_exists('mb_strlen') ? mb_strlen($messageRaw) : strlen($messageRaw);
        if ($messageLength > 160) {
            Response::json([
                'success' => false,
                'error_code' => 'message_too_long',
                'message' => 'Mensagem deve ter no maximo 160 caracteres',
            ], 422);
        }

        $typeNormalized = Config::normalizeSmsType(is_string($typeRaw) ? $typeRaw : '');
        if (!Config::smsTypeAllowed($typeNormalized)) {
            Response::json([
                'success' => false,
                'error_code' => 'invalid_type',
                'message' => 'Tipo invalido. Tipos aceitos: ' . implode(', ', Config::allowedSmsTypes()),
                'data' => [
                    'accepted_types' => Config::allowedSmsTypes(),
                ],
            ], 422);
        }

        if ($idempotencyKey !== '' && strlen($idempotencyKey) > 80) {
            Response::json([
                'success' => false,
                'error_code' => 'invalid_idempotency_key',
                'message' => 'idempotency_key muito longa',
            ], 422);
        }

        $project = (new ApiKeyMiddleware())->handle($request);

        try {
            $result = (new SmsService())->queueSms($project, $phoneRaw, $messageRaw, [
                'source' => 'api',
                'ip' => $request->server['REMOTE_ADDR'] ?? null,
                'type' => $typeNormalized,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            ]);
        } catch (InvalidArgumentException $e) {
            Response::json([
                'success' => false,
                'error_code' => 'invalid_request',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            error_log(sprintf(
                'Tars Notificacoes API SMS internal error: %s: %s',
                get_class($e),
                $e->getMessage()
            ));
            Logger::warning('API SMS internal error', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            Response::json([
                'success' => false,
                'error_code' => 'internal_error',
                'message' => 'Falha ao processar a mensagem',
            ], 500);
        }

        if (($result['status'] ?? '') === 'blocked') {
            Response::json([
                'success' => false,
                'error_code' => 'message_blocked',
                'message' => $result['error_message'] ?? 'Mensagem bloqueada',
                'data' => $result,
            ], 422);
        }

        Response::json([
            'success' => true,
            'message' => !empty($result['idempotent_hit']) ? 'Mensagem ja existente retornada' : 'Mensagem recebida e enfileirada',
            'data' => $result,
        ], !empty($result['idempotent_hit']) ? 200 : 202);
    }

    public function status(Request $request, string $id): never
    {
        $project = (new ApiKeyMiddleware())->handle($request);

        if (!ctype_digit($id)) {
            Response::json([
                'success' => false,
                'error_code' => 'message_not_found',
                'message' => 'Mensagem nao encontrada',
            ], 404);
        }

        $message = Database::fetchOne(
            'SELECT id, project_id, type, status, provider, created_at, sent_at, delivered_at, failed_at, error_message
             FROM tn_sms_messages
             WHERE id = :id AND project_id = :project_id
             LIMIT 1',
            [
                ':id' => (int) $id,
                ':project_id' => (int) $project['id'],
            ]
        );

        if ($message === null) {
            Response::json([
                'success' => false,
                'error_code' => 'message_not_found',
                'message' => 'Mensagem nao encontrada',
            ], 404);
        }

        Response::json([
            'success' => true,
            'data' => [
                'message_id' => (int) $message['id'],
                'status' => (string) $message['status'],
                'type' => (string) $message['type'],
                'provider' => (string) $message['provider'],
                'created_at' => $message['created_at'],
                'sent_at' => $message['sent_at'],
                'delivered_at' => $message['delivered_at'],
                'failed_at' => $message['failed_at'],
                'error_message' => $message['error_message'] !== null ? (string) $message['error_message'] : null,
            ],
        ]);
    }
}
