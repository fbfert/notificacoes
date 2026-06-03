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
        $this->routes['GET'][] = [
            'path' => $this->normalize($path),
            'handler' => $handler,
        ];
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->routes['POST'][] = [
            'path' => $this->normalize($path),
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): void
    {
        $path = $this->normalize($request->path);
        $route = $this->matchRoute($request->method, $path);

        if ($route === null) {
            Response::json([
                'success' => false,
                'message' => 'Rota nao encontrada',
            ], 404);
        }

        $this->run($route['handler'], $request, $route['params']);
    }

    private function run(callable|array $handler, Request $request, array $params = []): void
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            $class = $handler[0];
            $method = $handler[1];
            $instance = new $class();
            $result = $instance->{$method}($request, ...array_values($params));
        } else {
            $result = $handler($request, ...array_values($params));
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

    private function matchRoute(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            $patternParts = $this->pathParts((string) $route['path']);
            $pathParts = $this->pathParts($path);

            if (count($patternParts) !== count($pathParts)) {
                continue;
            }

            $params = [];
            $matched = true;

            foreach ($patternParts as $index => $patternPart) {
                $pathPart = $pathParts[$index] ?? null;
                if ($pathPart === null) {
                    $matched = false;
                    break;
                }

                if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $patternPart, $matches) === 1) {
                    $params[$matches[1]] = $pathPart;
                    continue;
                }

                if ($patternPart !== $pathPart) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function pathParts(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $trimmed), static fn (string $part): bool => $part !== ''));
    }
}
