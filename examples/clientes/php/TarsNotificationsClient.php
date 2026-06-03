<?php

declare(strict_types=1);

final class TarsNotificationsClient
{
    /**
     * @param array<int, string> $acceptedTypes
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 10,
        private readonly array $acceptedTypes = ['transactional', 'alert', 'test']
    ) {
        $this->baseUrl = rtrim(trim($this->baseUrl), '/');
        $this->apiKey = trim($this->apiKey);
        $this->timeout = max(1, $this->timeout);
    }

    public function send(
        string $phone,
        string $message,
        string $type = 'test',
        ?string $idempotencyKey = null
    ): array {
        $type = strtolower(trim($type));

        if (!in_array($type, $this->acceptedTypes, true)) {
            return [
                'ok' => false,
                'http_status' => 0,
                'gateway_status' => null,
                'message_id' => null,
                'error' => 'Tipo invalido. Tipos aceitos: ' . implode(', ', $this->acceptedTypes),
                'response' => null,
            ];
        }

        $payload = [
            'phone' => $phone,
            'message' => $message,
            'type' => $type,
        ];

        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $payload['idempotency_key'] = trim($idempotencyKey);
        }

        return $this->request('POST', '/api/sms/send', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $payload = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return [
                'ok' => false,
                'http_status' => 0,
                'gateway_status' => null,
                'message_id' => null,
                'error' => 'Falha ao serializar JSON',
                'response' => null,
            ];
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if (function_exists('curl_init')) {
            return $this->requestWithCurl($method, $url, $json, $headers);
        }

        return $this->requestWithStream($method, $url, $json, $headers);
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    private function requestWithCurl(string $method, string $url, string $json, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'http_status' => 0,
                'gateway_status' => null,
                'message_id' => null,
                'error' => 'Falha ao inicializar cURL',
                'response' => null,
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
        ]);

        $raw = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return $this->normalizeResponse($httpStatus, $raw === false ? '' : $raw, $error !== '' ? $error : null);
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    private function requestWithStream(string $method, string $url, string $json, array $headers): array
    {
        $headerLines = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => $headerLines,
                'content' => $json,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $handle = @fopen($url, 'r', false, $context);
        $raw = '';
        $httpStatus = 0;
        $responseHeaders = [];

        if (is_resource($handle)) {
            $meta = stream_get_meta_data($handle);
            if (is_array($meta['wrapper_data'] ?? null)) {
                $responseHeaders = $meta['wrapper_data'];
            }
            $raw = stream_get_contents($handle);
            if ($raw === false) {
                $raw = '';
            }
            fclose($handle);
        }

        foreach ($responseHeaders as $line) {
            if (is_string($line) && preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $matches) === 1) {
                $httpStatus = (int) $matches[1];
                break;
            }
        }

        $error = $handle === false ? 'Falha de conexao HTTP' : null;

        return $this->normalizeResponse($httpStatus, $raw, $error);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeResponse(int $httpStatus, string $rawBody, ?string $error = null): array
    {
        $payload = json_decode($rawBody, true);
        $gatewayStatus = null;
        $messageId = null;

        if (is_array($payload)) {
            $gatewayStatus = $payload['data']['status'] ?? $payload['status'] ?? null;
            $messageId = $payload['data']['message_id'] ?? $payload['message_id'] ?? null;
        }

        $ok = in_array($httpStatus, [200, 202], true);
        if ($ok) {
            $error = null;
        }

        return [
            'ok' => $ok,
            'http_status' => $httpStatus,
            'gateway_status' => is_string($gatewayStatus) ? $gatewayStatus : null,
            'message_id' => is_numeric($messageId) ? (int) $messageId : null,
            'error' => $error,
            'response' => is_array($payload) ? $payload : $rawBody,
        ];
    }
}
