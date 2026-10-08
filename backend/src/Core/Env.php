<?php
declare(strict_types=1);

namespace Sems\Core;

/** Minimal .env loader with safe defaults. Secrets stay server-side. */
final class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (!is_file($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            self::$vars[trim($k)] = trim($v);
        }
        self::$loaded = true;
    }

    public static function isLoaded(): bool { return self::$loaded; }

    public static function get(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? getenv($key) ?: (self::$vars[$key] ?? $default);
    }

    public static function guess(string $key): ?string
    {
        return $_ENV[$key] ?? getenv($key) ?: (self::$vars[$key] ?? null);
    }
}
