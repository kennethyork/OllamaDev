<?php

declare(strict_types=1);

namespace OllamaDev;

class ApiRouter
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function put(string $path, callable $handler): void
    {
        $this->routes['PUT'][$path] = $handler;
    }

    public function delete(string $path, callable $handler): void
    {
        $this->routes['DELETE'][$path] = $handler;
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function dispatch(string $method, string $path, string $body = ''): array
    {
        $method = strtoupper($method);

        if (!isset($this->routes[$method])) {
            return $this->json(['error' => 'Method not allowed'], 405);
        }

        $path = rtrim($path, '/') ?: '/';

        foreach ($this->routes[$method] as $route => $handler) {
            $route = rtrim($route, '/') ?: '/';

            $params = [];
            if ($this->matchRoute($route, $path, $params)) {
                return $handler($params, $body);
            }
        }

        return $this->json(['error' => 'Not found'], 404);
    }

    /**
     * @param array<string, string> $params
     */
    private function matchRoute(string $route, string $path, array &$params): bool
    {
        $routeParts = explode('/', $route);
        $pathParts = explode('/', $path);

        if (count($routeParts) !== count($pathParts)) {
            return false;
        }

        $params = [];
        for ($i = 0; $i < count($routeParts); $i++) {
            if (str_starts_with($routeParts[$i], ':')) {
                $params[substr($routeParts[$i], 1)] = rawurldecode($pathParts[$i]);
            } elseif ($routeParts[$i] !== $pathParts[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $data
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function json(mixed $data, int $status = 200): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($data),
        ];
    }
}
