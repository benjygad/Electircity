<?php
declare(strict_types=1);

namespace Sems\Core;

/** HTTP-aware exception rendered as a JSON error envelope. */
final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = []
    ) { parent::__construct($message); }

    public static function badRequest(string $msg, array $errors = []): self
    { return new self(400, $msg, $errors); }
    public static function unauthorized(string $msg = 'Authentication required'): self
    { return new self(401, $msg); }
    public static function forbidden(string $msg = 'You do not have permission to perform this action'): self
    { return new self(403, $msg); }
    public static function notFound(string $msg = 'Resource not found'): self
    { return new self(404, $msg); }
    public static function conflict(string $msg): self
    { return new self(409, $msg); }
    public static function tooMany(string $msg = 'Too many requests. Please try again later.'): self
    { return new self(429, $msg); }
}
