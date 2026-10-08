<?php
declare(strict_types=1);

namespace Sems\Core;

/** Key/value application settings (tariff, anomaly thresholds…). */
final class Settings
{
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (!self::$cache) {
            foreach (Db::all('SELECT `key`, value FROM settings') as $r) {
                // SQLite quotes identifiers differently; `key` works in both via backticks on MySQL.
                self::$cache[$r['key']] = $r['value'];
            }
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        $exists = Db::val('SELECT 1 FROM settings WHERE `key` = ?', [$key]);
        if ($exists) Db::run('UPDATE settings SET value = ? WHERE `key` = ?', [$value, $key]);
        else Db::run('INSERT INTO settings (`key`, value) VALUES (?, ?)', [$key, $value]);
        self::$cache[$key] = $value;
    }

    public static function num(string $key, float $default = 0.0): float
    {
        return (float) (self::get($key) ?? $default);
    }
}
