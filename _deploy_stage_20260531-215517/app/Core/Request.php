<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $body,
        public readonly array $server,
        public readonly string $rawBody
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $normalizedHeaders = [];
        foreach ($headers as $key => $value) {
            $normalizedHeaders[strtolower((string) $key)] = (string) $value;
        }

        $rawBody = file_get_contents('php://input') ?: '';
        $body = $_POST;

        $contentType = strtolower((string) ($normalizedHeaders['content-type'] ?? ''));
        if ($rawBody !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self($method, rtrim($path, '/') ?: '/', $normalizedHeaders, $_GET, $body, $server, $rawBody);
    }

    public function header(string $name, mixed $default = null): mixed
    {
        $key = strtolower($name);

        return $this->headers[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $authorization = (string) $this->header('authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    public function isJsonContentType(): bool
    {
        $contentType = strtolower((string) ($this->headers['content-type'] ?? ''));

        return str_contains($contentType, 'application/json');
    }
}
