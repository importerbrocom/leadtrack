<?php

namespace App\Core;

/**
 * Wraps the incoming HTTP request (JSON body, query string, multipart form).
 */
final class Request
{
    private array $query;
    private array $body;
    private array $files;
    private array $routeParams = [];
    private string $method;
    private string $path;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->query  = $_GET;
        $this->files  = $_FILES;
        $this->path   = $this->resolvePath();
        $this->body   = $this->resolveBody();
    }

    private function resolvePath(): string
    {
        // Works with both  /api/leads  and  /api/index.php?_route=/leads
        $raw = $_GET['_route'] ?? ($_SERVER['PATH_INFO'] ?? null);

        if ($raw === null) {
            $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
            $raw  = $base !== '' && str_starts_with($uri, $base) ? substr($uri, strlen($base)) : $uri;
        }

        $path = '/' . trim($raw, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function resolveBody(): array
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if (trim($raw) === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw ApiException::badRequest('Request body is not valid JSON');
            }

            return $decoded;
        }

        // multipart/form-data or x-www-form-urlencoded
        if ($_POST !== []) {
            return $_POST;
        }

        // PUT/PATCH with urlencoded body
        if (in_array($this->method, ['PUT', 'PATCH', 'DELETE'], true)) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                parse_str($raw, $parsed);

                return is_array($parsed) ? $parsed : [];
            }
        }

        return [];
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, $default = null)
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function intParam(string $key): int
    {
        return (int) $this->param($key, 0);
    }

    /** Query-string value. */
    public function query(string $key, $default = null)
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) && trim($value) === '' ? $default : $value;
    }

    /** Body value. */
    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    public function body(): array
    {
        return $this->body;
    }

    /** Only the given keys that were actually present in the body. */
    public function only(array $keys): array
    {
        return array_intersect_key($this->body, array_flip($keys));
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = array_change_key_case(apache_request_headers(), CASE_LOWER);
            $header  = $headers['authorization'] ?? '';
        }

        if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            return $m[1];
        }

        return null;
    }

    public function ip(): string
    {
        return $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]
            ?: ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function page(): int
    {
        return max(1, (int) $this->query('page', 1));
    }

    public function perPage(int $default = 25, int $max = 200): int
    {
        return min($max, max(1, (int) $this->query('per_page', $default)));
    }
}
