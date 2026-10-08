<?php
declare(strict_types=1);

namespace Sems\Core;

/** Fixed-window DB-backed rate limiter for authentication endpoints. */
final class RateLimiter
{
    public static function guard(string $action): void
    {
        $max = (int) Env::get('RATE_LIMIT_MAX', '8');
        $window = (int) Env::get('RATE_LIMIT_WINDOW_SECONDS', '300');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $bucket = $action . ':' . $ip;
        $now = time();

        $row = Db::one('SELECT hits, window_start FROM rate_limits WHERE bucket = ?', [$bucket]);
        if ($row) {
            $start = strtotime($row['window_start'] . ' UTC');
            if ($now - $start >= $window) {
                Db::run('UPDATE rate_limits SET hits = 1, window_start = ? WHERE bucket = ?', [Db::now(), $bucket]);
                return;
            }
            if ($row['hits'] >= $max) throw HttpException::tooMany();
            Db::run('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
            return;
        }
        Db::run('INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)', [$bucket, Db::now()]);
    }
}
