<?php
declare(strict_types=1);

namespace Sems\Core;

/** Consistent JSON envelope for all API responses. */
final class Response
{
    public static function send(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok(mixed $data = null, int $status = 200, array $meta = []): never
    {
        $body = ['success' => true, 'data' => $data];
        if ($meta) $body['meta'] = $meta;
        self::send($body, $status);
    }

    public static function fail(string $message, int $status = 400, array $errors = []): never
    {
        $body = ['success' => false, 'error' => ['message' => $message]];
        if ($errors) $body['error']['fields'] = $errors;
        self::send($body, $status);
    }
}
