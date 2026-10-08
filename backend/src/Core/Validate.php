<?php
declare(strict_types=1);

namespace Sems\Core;

/** Input validation rules shared by controllers and CLI scripts. */
final class Validate
{
    /** @throws HttpException with field errors */
    public static function required(array $data, array $fields): void
    {
        $missing = [];
        foreach ($fields as $f) {
            if (!isset($data[$f]) || (is_string($data[$f]) && trim($data[$f]) === '')) $missing[$f] = 'required';
        }
        if ($missing) throw HttpException::badRequest('Validation failed', $missing);
    }

    public static function email(string $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_EMAIL) !== false && strlen($v) <= 190;
    }

    public static function phone(string $v): bool
    {
        return preg_match('/^\+?255\d{9}$/', preg_replace('/[\s-]/', '', $v)) === 1;
    }

    /** LUKU-style meter number: 11–16 chars with at least 10 digits. */
    public static function meterNumber(string $v): bool
    {
        $clean = strtoupper(trim($v));
        return preg_match('/^[A-Z0-9]{11,16}$/', $clean) === 1 && preg_match_all('/\d/', $clean) >= 10;
    }

    /** LUKU tokens are 20 digits; 16–24 digits accepted for variants. */
    public static function tokenInput(string $v): bool
    {
        $clean = preg_replace('/[\s-]/', '', $v);
        return preg_match('/^\d{16,24}$/', $clean) === 1;
    }

    /** Mask a token: only last 4 digits retained. Full tokens are never stored. */
    public static function maskToken(string $v): string
    {
        $clean = preg_replace('/[\s-]/', '', $v);
        return '****-****-' . substr($clean, -4);
    }

    public static function strLen(string $v, int $max): bool { return mb_strlen($v) <= $max; }

    public static function password(string $v): bool
    {
        return strlen($v) >= 8 && strlen($v) <= 128
            && preg_match('/[A-Za-z]/', $v) && preg_match('/\d/', $v);
    }

    public static function utcDateTime(string $v): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $v) === 1 && strtotime($v) !== false;
    }
}
