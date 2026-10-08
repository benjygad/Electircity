<?php
/**
 * SEMS API front controller.
 * Served under /api/v1 (see README routing notes for PHP built-in server / Apache).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Sems\Core\{Env, Response};
use Sems\Router;

// ---------------------------------------------------------------- CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = array_map('trim', explode(',', (string) Env::get('CORS_ALLOWED_ORIGIN', '*')));
if (in_array('*', $allowed, true) || in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . (in_array('*', $allowed, true) ? '*' : $origin));
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
    header('Access-Control-Max-Age: 600');
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// ---------------------------------------------------------------- path
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = defined('SEMS_API_BASE') ? SEMS_API_BASE : '/api/v1';
if (!str_starts_with($uri, $base)) {
    Response::fail('Unknown API endpoint', 404);
}
$path = substr($uri, strlen($base)) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

try {
    Router::dispatch($method, $path);
} catch (\Sems\Core\HttpException $e) {
    Response::fail($e->getMessage(), $e->status, $e->errors);
} catch (\Throwable $e) {
    error_log('[SEMS] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::fail(Env::get('APP_DEBUG', '0') === '1' ? $e->getMessage() : 'Internal server error', 500);
}
