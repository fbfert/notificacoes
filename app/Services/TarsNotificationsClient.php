<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;

final class TarsNotificationsClient
{
    public function sendAdministrativeTest(
        string $phone,
        string $message,
        string $event = 'admin-integration-test',
        string $type = 'test'
    ): array {
        $startedAt = microtime(true);
        $enabled = Config::tarsNotificationsEnabled();
        $baseUrl = rtrim(Config::tarsNotificationsBaseUrl(), '/');
        $apiKey = Config::tarsNotificationsApiKey();
        $timeout = Config::tarsNotificationsTimeout();
        $idempotencyKey = $this->buildIdempotencyKey($event, $phone, $message);
        $normalizedType = Config::normalizeSmsType($type);

        if (!Config::smsTypeAllowed($normalizedType)) {
            return $this->finalizeAndLog([
                'ok' => false,
                'skipped' => false,
                'enabled' => $enabled,
                'http_status' => null,
                'gateway_status' => null,
                'message_id' => null,
                'idempotency_key' => $idempotencyKey,
                'error' => 'Tipo de mensagem invalido. Tipos aceitos: ' . implode(', ', Config::allowedSmsTypes()),
                'response_body' => null,
                'raw_body' => null,
                'duration_ms' => $this->durationMs($startedAt),
            ]);
        }

        if (!$enabled) {
            return $this->finalizeAndLog([
                'ok' => false,
                'skipped' => true,
                'enabled' => false,
                'http_status' => null,
                'gateway_status' => null,
                'message_id' => null,
                'idempotency_key' => $idempotencyKey,
                'error' => 'Integração desativada em TARS_NOTIFICACOES_ENABLED=false',
                'response_body' => null,
                'raw_body' => null,
                'duration_ms' => $this->durationMs($startedAt),
            ]);
        }

        if ($apiKey === '') {
            return $this->finalizeAndLog([
                'ok' => false,
                'skipped' => false,
                'enabled' => true,
                'http_status' => null,
                'gateway_status' => null,
                'message_id' => null,
                'idempotency_key' => $idempotencyKey,
                'error' => 'TARS_NOTIFICACOES_API_KEY nao configurada',
                'response_body' => null,
                'raw_body' => null,
                'duration_ms' => $this->durationMs($startedAt),
            ]);
        }

        $payload = [
            'phone' => $phone,
            'message' => $message,
            'type' => $normalizedType,
            'idempotency_key' => $idempotencyKey,
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            return $this->finalizeAndLog([
                'ok' => false,
                'skipped' => false,
                'enabled' => true,
                'http_status' => null,
                'gateway_status' => null,
                'message_id' => null,
                'idempotency_key' => $idempotencyKey,
                'error' => 'Falha ao serializar payload JSON',
                'response_body' => null,
                'raw_body' => null,
                'duration_ms' => $this->durationMs($startedAt),
            ]);
        }

        [$httpStatus, $rawBody, $curlErrorNumber, $curlError] = $this->performRequest(
            $baseUrl . '/api/sms/send',
            $jsonPayload,
            $apiKey,
            $timeout
        );

        $responseBody = null;
        if (is_string($rawBody) && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $responseBody = $decoded;
            }
        }

        $gatewayStatus = null;
        $messageId = null;
        $error = null;
        if (is_array($responseBody)) {
            $gatewayStatus = $responseBody['data']['status'] ?? $responseBody['status'] ?? null;
            $messageId = $responseBody['data']['message_id'] ?? $responseBody['message_id'] ?? null;
            $error = $responseBody['message'] ?? $responseBody['error'] ?? null;
        }

        if ($curlErrorNumber !== 0) {
            $error = $this->summarizeError($curlError);
        } elseif (!in_array($httpStatus, [200, 202, 401, 415, 422, 500], true)) {
            $error = $this->summarizeError('HTTP inesperado: ' . $httpStatus);
        } elseif ($httpStatus >= 500 && $error === null) {
            $error = 'Erro interno no gateway';
        }

        $ok = in_array($httpStatus, [200, 202], true) && $curlErrorNumber === 0;

        return $this->finalizeAndLog([
            'ok' => $ok,
            'skipped' => false,
            'enabled' => true,
            'http_status' => $httpStatus ?: null,
            'gateway_status' => $gatewayStatus,
            'message_id' => $messageId !== null ? (int) $messageId : null,
            'idempotency_key' => $idempotencyKey,
            'error' => $error !== null ? $this->summarizeError((string) $error) : null,
            'response_body' => $responseBody,
            'raw_body' => is_string($rawBody) ? $this->truncate($rawBody) : null,
            'duration_ms' => $this->durationMs($startedAt),
        ]);
    }

    public function buildIdempotencyKey(string $event, string $phone, string $message): string
    {
        $safeEvent = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($event))) ?: 'evt';
        $safeEvent = substr($safeEvent, 0, 12);
        $base = implode('|', [
            'tars-notificacoes',
            $safeEvent,
            preg_replace('/\D+/', '', $phone),
            hash('sha256', $message),
            gmdate('YmdHi'),
        ]);

        return 'tn:' . $safeEvent . ':' . substr(hash('sha256', $base), 0, 16);
    }

    private function finalizeAndLog(array $result): array
    {
        $this->writeLog($result);

        return $result;
    }

    /**
     * @return array{0:int,1:?string,2:int,3:?string}
     */
    private function performRequest(string $url, string $jsonPayload, string $apiKey, int $timeout): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return [0, null, 1, 'Falha ao inicializar conexao cURL'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_USERAGENT => 'TarsNotificationsClient/0.4',
            ]);

            $rawBody = curl_exec($ch);
            $curlErrorNumber = curl_errno($ch);
            $curlError = curl_error($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return [$httpStatus, is_string($rawBody) ? $rawBody : null, $curlErrorNumber, $curlError !== '' ? $curlError : null];
        }

        return $this->performRequestWithSocket($url, $jsonPayload, $apiKey, $timeout);
    }

    /**
     * @return array{0:int,1:?string,2:int,3:?string}
     */
    private function performRequestWithSocket(string $url, string $jsonPayload, string $apiKey, int $timeout): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'], $parts['path'])) {
            return [0, null, 1, 'URL invalida'];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = (string) $parts['path'];
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        $target = 'tcp://' . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            return [0, null, 1, $errstr !== '' ? $errstr : 'Falha ao abrir socket HTTP'];
        }

        stream_set_timeout($stream, $timeout);

        if ($scheme === 'https' && function_exists('stream_socket_enable_crypto')) {
            $cryptoEnabled = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                fclose($stream);

                return [0, null, 1, 'Falha ao negociar TLS com o gateway'];
            }
        }

        $request = implode("\r\n", [
            'POST ' . $path . ' HTTP/1.1',
            'Host: ' . $host,
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($jsonPayload),
            'Connection: close',
            '',
            $jsonPayload,
        ]);

        $written = fwrite($stream, $request);
        if ($written === false) {
            fclose($stream);

            return [0, null, 1, 'Falha ao enviar requisicao HTTP'];
        }

        $rawResponse = stream_get_contents($stream);
        $meta = stream_get_meta_data($stream);
        fclose($stream);

        if (!empty($meta['timed_out'])) {
            return [0, null, 1, 'Tempo limite excedido'];
        }

        if ($rawResponse === false || $rawResponse === '') {
            return [0, null, 1, 'Resposta vazia do gateway'];
        }

        [$headerText, $body] = array_pad(explode("\r\n\r\n", $rawResponse, 2), 2, '');

        $httpStatus = 0;
        if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d{3})/', $headerText, $matches)) {
            $httpStatus = (int) $matches[1];
        }

        return [$httpStatus, $body !== '' ? $body : null, 0, null];
    }

    private function writeLog(array $result): void
    {
        $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $file = $logDir . DIRECTORY_SEPARATOR . 'tars_notifications_client.log';
        $payload = [
            'ts' => date('Y-m-d H:i:s'),
            'event' => 'send_administrative_test',
            'enabled' => (bool) ($result['enabled'] ?? false),
            'skipped' => (bool) ($result['skipped'] ?? false),
            'http_status' => $result['http_status'] ?? null,
            'gateway_status' => $result['gateway_status'] ?? null,
            'message_id' => $result['message_id'] ?? null,
            'outcome' => (bool) ($result['ok'] ?? false) ? 'ok' : (($result['skipped'] ?? false) ? 'skipped' : 'error'),
            'error' => $result['error'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
        ];

        @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function summarizeError(string $message): string
    {
        $message = trim($message);

        return function_exists('mb_substr') ? mb_substr($message, 0, 220) : substr($message, 0, 220);
    }

    private function truncate(string $value, int $length = 400): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
