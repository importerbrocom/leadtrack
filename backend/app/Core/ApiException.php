<?php

namespace App\Core;

/**
 * Any exception the API is happy to describe to the client.
 */
class ApiException extends \RuntimeException
{
    private int $statusCode;
    private array $errors;

    public function __construct(string $message, int $statusCode = 400, array $errors = [])
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->errors     = $errors;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public static function badRequest(string $message = 'Bad request', array $errors = []): self
    {
        return new self($message, 400, $errors);
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'You do not have permission to do that'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message = 'Conflict'): self
    {
        return new self($message, 409);
    }

    public static function validation(array $errors, string $message = 'Validation failed'): self
    {
        return new self($message, 422, $errors);
    }
}
