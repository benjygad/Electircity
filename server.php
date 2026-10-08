<?php
/**
 * SEMS development server router (PHP built-in server).
 *   php -S 0.0.0.0:8000 -t admin-dashboard server.php
 *
 * Routes /api/* to the backend front controller; everything else is served
 * from admin-dashboard/ (the document root). Production deployments should
 * use Apache/Nginx instead (see docs/SETUP.md).
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($uri, '/api/')) {
    define('SEMS_API_BASE', '/api/v1');
    require __DIR__ . '/backend/public/index.php';
    return true;
}

// Static file inside the document root? Let the built-in server stream it.
if ($uri !== '/' && is_file(__DIR__ . '/admin-dashboard' . $uri)) {
    return false;
}
if ($uri === '/' && is_file(__DIR__ . '/admin-dashboard/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/admin-dashboard/index.html');
    return true;
}

// SPA fallback (hash routing means this is rarely hit)
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/admin-dashboard/index.html');
return true;
