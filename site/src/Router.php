<?php

declare(strict_types=1);

namespace SiteGate;

final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * @return array{handler: callable, params: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || !preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = urldecode($value);
                }
            }
            return ['handler' => $route['handler'], 'params' => $params];
        }
        return null;
    }

    /**
     * Вызывает обработчик; null, если маршрута нет (вызывающий отдаёт 404).
     */
    public function dispatch(string $method, string $path): mixed
    {
        $found = $this->match($method, $path);
        if ($found === null) {
            return null;
        }
        return ($found['handler'])($found['params']);
    }
}
