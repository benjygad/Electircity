<?php
declare(strict_types=1);

namespace Sems\Core;

/**
 * Authentication + role-based authorization.
 * Bearer tokens are random 32-byte values; only their SHA-256 hash is stored.
 * Roles are enforced SERVER-SIDE — a client can never grant itself a role.
 */
final class Auth
{
    public const ROLE_CONSUMER = 'consumer';
    public const ROLE_ADMIN = 'admin';
    /** Reserved for future restricted roles. */
    public const ROLE_TECHNICIAN = 'technician';
    public const ROLE_SUPPORT = 'support';

    private static ?array $user = null;

    /** Authenticate the current request via Authorization: Bearer <token>. */
    public static function authenticate(): ?array
    {
        if (self::$user !== null) return self::$user;

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+([A-Za-z0-9\-_\.]+)$/', $header, $m)) return null;

        $hash = hash('sha256', $m[1]);
        $row = Db::one(
            'SELECT t.user_id, t.expires_at, u.id, u.full_name, u.email, u.phone, u.role, u.status, u.created_at
               FROM auth_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ?',
            [$hash]
        );
        if (!$row) return null;
        if (strtotime($row['expires_at'] . ' UTC') < time()) return null;
        if ($row['status'] !== 'active') return null;

        unset($row['expires_at'], $row['user_id']);
        return self::$user = $row;
    }

    public static function user(): array
    {
        $u = self::authenticate();
        if (!$u) throw HttpException::unauthorized();
        return $u;
    }

    /** Require one of the given roles; otherwise 403. */
    public static function requireRole(string ...$roles): array
    {
        $u = self::user();
        if (!in_array($u['role'], $roles, true)) throw HttpException::forbidden();
        return $u;
    }

    public static function isAdmin(array $u): bool { return $u['role'] === self::ROLE_ADMIN; }

    public static function issueToken(int $userId): array
    {
        $plain = bin2hex(random_bytes(32));
        $ttlHours = (int) Env::get('TOKEN_TTL_HOURS', '72');
        $expires = gmdate('Y-m-d H:i:s', time() + $ttlHours * 3600);
        Db::insert('auth_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expires,
        ]);
        return ['token' => $plain, 'expires_at' => $expires];
    }

    public static function revokeCurrentToken(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9\-_\.]+)$/', $header, $m)) {
            Db::run('DELETE FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $m[1])]);
        }
    }

    /**
     * Ownership gate: consumers may only touch meters linked to their account.
     * Admins may touch any meter. Returns the meter row.
     */
    public static function accessibleMeter(int $meterId): array
    {
        $meter = Db::one('SELECT * FROM meters WHERE id = ?', [$meterId]);
        if (!$meter) throw HttpException::notFound('Meter not found');
        $u = self::user();
        if (self::isAdmin($u)) return $meter;
        $link = Db::one('SELECT id FROM user_meters WHERE user_id = ? AND meter_id = ?', [$u['id'], $meterId]);
        if (!$link) throw HttpException::forbidden('This meter does not belong to your account');
        return $meter;
    }

    /** The consumer's own meter ids (for list queries). */
    public static function ownMeterIds(int $userId): array
    {
        return array_map('intval', array_column(
            Db::all('SELECT meter_id FROM user_meters WHERE user_id = ?', [$userId]),
            'meter_id'
        ));
    }
}
