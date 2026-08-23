<?php

namespace App\Core;

/**
 * JSON / file responses. Every API reply has the shape:
 *   { "success": bool, "message": string|null, "data": mixed, "errors": object|null }
 */
final class Response
{
    public static function json($data = null, int $status = 200, ?string $message = null, array $extra = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $payload = array_merge([
            'success' => $status >= 200 && $status < 300,
            'message' => $message,
            'data'    => $data,
        ], $extra);

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok($data = null, ?string $message = null): void
    {
        self::json($data, 200, $message);
    }

    public static function created($data = null, ?string $message = 'Created'): void
    {
        self::json($data, 201, $message);
    }

    public static function noContent(): void
    {
        http_response_code(204);
        exit;
    }

    public static function error(string $message, int $status = 400, array $errors = []): void
    {
        self::json(null, $status, $message, $errors === [] ? [] : ['errors' => $errors]);
    }

    /**
     * Paginated list response.
     */
    public static function paginated(array $items, int $total, int $page, int $perPage, ?string $message = null): void
    {
        self::json($items, 200, $message, [
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
                'has_more'    => $page * $perPage < $total,
            ],
        ]);
    }

    /**
     * Stream a stored file to the client as a download.
     */
    public static function download(string $absolutePath, string $downloadName, string $mimeType): void
    {
        if (!is_file($absolutePath)) {
            throw ApiException::notFound('File is missing on the server');
        }

        // Clear anything already buffered so the binary stream stays clean.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($absolutePath));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');

        readfile($absolutePath);
        exit;
    }
}
