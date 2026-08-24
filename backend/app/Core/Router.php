<?php

namespace App\Core;

/**
 * Minimal path router with {param} placeholders.
 */
final class Router
{
    /** @var array<string, array<int, array{pattern:string, keys:array, handler:callable}>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void    { $this->add('GET', $path, $handler); }
    public function post(string $path, callable $handler): void   { $this->add('POST', $path, $handler); }
    public function put(string $path, callable $handler): void    { $this->add('PUT', $path, $handler); }
    public function patch(string $path, callable $handler): void  { $this->add('PATCH', $path, $handler); }
    public function delete(string $path, callable $handler): void { $this->add('DELETE', $path, $handler); }

    private function add(string $method, string $path, callable $handler): void
    {
        $keys = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function ($m) use (&$keys) {
                $keys[] = $m[1];

                return '([^/]+)';
            },
            rtrim($path, '/') ?: '/'
        );

        $this->routes[$method][] = [
            'pattern' => '#^' . $regex . '$#',
            'keys'    => $keys,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path   = $request->path();

        // CORS preflight
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                array_shift($matches);
                $params = [];
                foreach ($route['keys'] as $i => $key) {
                    $params[$key] = $matches[$i] ?? null;
                }
                $request->setRouteParams($params);

                ($route['handler'])($request);

                // A handler that returns without sending anything = 204.
                Response::noContent();
            }
        }

        // Path exists but with a different verb? Report 405 rather than 404.
        foreach ($this->routes as $verb => $routes) {
            if ($verb === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $path)) {
                    throw new ApiException("{$method} is not allowed on {$path}", 405);
                }
            }
        }

        throw ApiException::notFound("No API route matches {$method} {$path}");
    }
}
