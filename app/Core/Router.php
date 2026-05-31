<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    public function get(string $path, callable|array $handler): void
    {
        $this->routes['GET'][$this->normalize($path)] = $handler;
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->routes['POST'][$this->normalize($path)] = $handler;
    }

    public function dispatch(Request $request): void
    {
        $path = $this->normalize($request->path);
        $handler = $this->routes[$request->method][$path] ?? null;

        if ($handler === null) {
            Response::json([
                'success' => false,
                'message' => 'Rota nao encontrada',
            ], 404);
        }

        $this->run($handler, $request);
    }

    private function run(callable|array $handler, Request $request): void
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            $class = $handler[0];
            $method = $handler[1];
            $instance = new $class();
            $result = $instance->{$method}($request);
        } else {
            $result = $handler($request);
        }

        if ($result === null) {
            return;
        }

        if (is_array($result)) {
            Response::json($result);
        }

        if (is_string($result)) {
            Response::html($result);
        }
    }

    private function normalize(string $path): string
    {
        $normalized = '/' . ltrim($path, '/');

        return rtrim($normalized, '/') ?: '/';
    }
}
