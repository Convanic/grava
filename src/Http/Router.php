<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,handler:callable,middleware:array}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }
    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }
    public function patch(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $pattern, $handler, $middleware);
    }
    public function put(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }
    public function delete(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    private function add(string $method, string $pattern, callable $handler, array $middleware): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'pattern', 'regex', 'handler', 'middleware');
    }

    public function dispatch(Request $request): void
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $m)) {
                if ($route['method'] !== $request->method) {
                    $allowed[] = $route['method'];
                    continue;
                }
                foreach ($m as $k => $v) {
                    if (!is_int($k)) {
                        $request->routeParams[$k] = $v;
                    }
                }
                foreach ($route['middleware'] as $mw) {
                    ($mw)($request);
                }
                ($route['handler'])($request);
                return;
            }
        }

        if (!empty($allowed)) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            Response::error('not_found', 'Method not allowed.', 405);
        }

        Response::error('not_found', 'Not found.', 404);
    }
}
