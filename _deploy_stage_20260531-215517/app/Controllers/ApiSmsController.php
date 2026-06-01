<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Request;
use App\Middleware\ApiKeyMiddleware;
use App\Services\SmsService;
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
        $typeRaw = $payload['type'] ?? 'sms';
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

        if (!is_string($typeRaw) || strtolower($typeRaw) !== 'sms') {
            Response::json([
                'success' => false,
                'error_code' => 'invalid_type',
                'message' => 'Tipo invalido. Apenas sms e permitido nesta etapa',
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
                'type' => 'sms',
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            ]);
        } catch (InvalidArgumentException $e) {
            Response::json([
                'success' => false,
                'error_code' => 'invalid_request',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
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
}
