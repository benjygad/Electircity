<?php
/**
 * SEMS backend bootstrap: env loading, autoloading, error handling.
 */
declare(strict_types=1);

define('SEMS_ROOT', dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', '0'); // errors are returned as JSON, never printed
date_default_timezone_set('UTC');

spl_autoload_register(function (string $class): void {
    $prefix = 'Sems\\';
    if (str_starts_with($class, $prefix)) {
        $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = SEMS_ROOT . '/src/' . $rel . '.php';
        if (is_file($file)) require $file;
    }
});

require_once SEMS_ROOT . '/src/Core/functions.php';

use Sems\Core\Env;
use Sems\Core\Response;

Env::load(SEMS_ROOT . '/.env');

// For local dev convenience: fall back to .env.example values only when
// APP_ENV=development and no .env exists. Production MUST provide a .env.
if (!Env::isLoaded() && Env::guess('APP_ENV') === null) {
    Env::load(SEMS_ROOT . '/.env.example');
}

set_exception_handler(function (Throwable $e): void {
    $debug = Env::get('APP_DEBUG', '0') === '1';
    error_log('[SEMS] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($e instanceof Response\HttpException) {
        Response::fail($e->getMessage(), $e->status, $e->errors);
    }
    Response::fail(
        $debug ? $e->getMessage() : 'Internal server error',
        500
    );
});
