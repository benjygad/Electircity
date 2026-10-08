<?php
declare(strict_types=1);

namespace Sems\Controllers;

use Sems\Core\{Auth, Audit, Db, HttpException, RateLimiter, Response, Validate};

final class AuthController
{
    public static function register(array $args): void
    {
        RateLimiter::guard('register');
        $b = self::body();
        Validate::required($b, ['full_name', 'email', 'password']);
        $errors = [];
        $email = strtolower(trim($b['email']));
        if (!Validate::email($email)) $errors['email'] = 'A valid email address is required';
        if (!Validate::password($b['password'])) $errors['password'] = 'Min 8 characters with at least one letter and one number';
        if (!Validate::strLen($b['full_name'], 120)) $errors['full_name'] = 'Too long';
        if (isset($b['phone']) && $b['phone'] !== '' && !Validate::phone($b['phone'])) $errors['phone'] = 'Use Tanzanian format, e.g. +255712345678';
        if ($errors) throw HttpException::badRequest('Validation failed', $errors);

        if (Db::val('SELECT id FROM users WHERE email = ?', [$email])) {
            throw HttpException::conflict('An account with this email already exists');
        }
        // Server-side role assignment: registrations are ALWAYS consumers.
        $id = Db::insert('users', [
            'full_name' => trim($b['full_name']),
            'email' => $email,
            'phone' => $b['phone'] ?? null,
            'password_hash' => password_hash($b['password'], PASSWORD_DEFAULT),
            'role' => Auth::ROLE_CONSUMER,
            'status' => 'active',
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);
        Audit::log($id, 'register', 'user', $id, 'Consumer self-registration');
        $token = Auth::issueToken($id);
        $user = Db::one('SELECT id, full_name, email, phone, role, status, created_at FROM users WHERE id = ?', [$id]);
        Response::ok(['user' => self::userOut($user), 'token' => $token['token'], 'token_expires_at' => $token['expires_at']], 201);
    }

    public static function login(array $args): void
    {
        RateLimiter::guard('login');
        $b = self::body();
        Validate::required($b, ['email', 'password']);
        $user = Db::one('SELECT * FROM users WHERE email = ?', [strtolower(trim($b['email']))]);
        if (!$user || !password_verify($b['password'], $user['password_hash'])) {
            Audit::log($user['id'] ?? null, 'login_failed', 'user', $user['id'] ?? null, 'Invalid credentials');
            throw HttpException::unauthorized('Invalid email or password');
        }
        if ($user['status'] !== 'active') throw HttpException::forbidden('Account is suspended. Contact an administrator.');

        $token = Auth::issueToken((int)$user['id']);
        Audit::log((int)$user['id'], 'login', 'user', (int)$user['id']);
        Response::ok([
            'user' => self::userOut($user),
            'token' => $token['token'],
            'token_expires_at' => $token['expires_at'],
        ]);
    }

    public static function logout(array $args): void
    {
        Auth::user();
        Auth::revokeCurrentToken();
        Audit::log(Auth::user()['id'] ?? null, 'logout', 'user', Auth::user()['id'] ?? null);
        Response::ok(['message' => 'Logged out']);
    }

    public static function me(array $args): void
    {
        $u = Auth::user();
        $out = self::userOut($u);
        if ($u['role'] === Auth::ROLE_CONSUMER) {
            $out['meters'] = Db::all(
                'SELECT m.id, m.meter_number, m.meter_type, m.service_location, m.integration_status, um.relationship
                   FROM user_meters um JOIN meters m ON m.id = um.meter_id WHERE um.user_id = ?',
                [$u['id']]
            );
        }
        Response::ok($out);
    }

    public static function updateMe(array $args): void
    {
        $u = Auth::user();
        $b = self::body();
        $data = [];
        if (isset($b['full_name'])) {
            if (!Validate::strLen(trim($b['full_name']), 120) || trim($b['full_name']) === '') {
                throw HttpException::badRequest('Validation failed', ['full_name' => 'Invalid name']);
            }
            $data['full_name'] = trim($b['full_name']);
        }
        if (isset($b['phone']) && $b['phone'] !== '') {
            if (!Validate::phone($b['phone'])) throw HttpException::badRequest('Validation failed', ['phone' => 'Invalid Tanzanian phone number']);
            $data['phone'] = $b['phone'];
        }
        if (isset($b['current_password'], $b['new_password'])) {
            $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
            if (!password_verify($b['current_password'], $row['password_hash'])) {
                throw HttpException::badRequest('Validation failed', ['current_password' => 'Incorrect current password']);
            }
            if (!Validate::password($b['new_password'])) {
                throw HttpException::badRequest('Validation failed', ['new_password' => 'Min 8 characters with a letter and a number']);
            }
            $data['password_hash'] = password_hash($b['new_password'], PASSWORD_DEFAULT);
        }
        if (!$data) throw HttpException::badRequest('No valid fields to update');
        $data['updated_at'] = Db::now();
        Db::update('users', $data, 'id = ?', [$u['id']]);
        Audit::log((int)$u['id'], 'profile_update', 'user', (int)$u['id'], implode(',', array_keys($data)));
        Response::ok(self::userOut(Db::one('SELECT * FROM users WHERE id = ?', [$u['id']])));
    }

    /**
     * Forgot password: creates a 6-digit reset code. In a production system the
     * code would be emailed/SMSed; this academic prototype returns it in the
     * response ONLY when APP_ENV=development, so the flow is testable.
     */
    public static function forgotPassword(array $args): void
    {
        RateLimiter::guard('forgot_password');
        $b = self::body();
        Validate::required($b, ['email']);
        $user = Db::one('SELECT id FROM users WHERE email = ?', [strtolower(trim($b['email']))]);
        // Do not reveal whether the account exists.
        if (!$user) Response::ok(['message' => 'If that account exists, a reset code has been issued.']);

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Db::insert('password_resets', [
            'user_id' => $user['id'],
            'code_hash' => hash('sha256', $code),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 1800),
            'created_at' => Db::now(),
        ]);
        $payload = ['message' => 'If that account exists, a reset code has been issued.'];
        if (\Sems\Core\Env::get('APP_ENV', 'production') === 'development') {
            $payload['dev_reset_code'] = $code; // prototype-only, documented
        }
        Response::ok($payload);
    }

    public static function resetPassword(array $args): void
    {
        RateLimiter::guard('reset_password');
        $b = self::body();
        Validate::required($b, ['email', 'code', 'new_password']);
        if (!Validate::password($b['new_password'])) {
            throw HttpException::badRequest('Validation failed', ['new_password' => 'Min 8 characters with a letter and a number']);
        }
        $user = Db::one('SELECT id FROM users WHERE email = ?', [strtolower(trim($b['email']))]);
        $reset = $user ? Db::one(
            "SELECT * FROM password_resets WHERE user_id = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1",
            [$user['id'], Db::now()]
        ) : null;

        if (!$reset || !hash_equals($reset['code_hash'], hash('sha256', trim($b['code'])))) {
            throw HttpException::badRequest('Invalid or expired reset code');
        }
        Db::transaction(function () use ($user, $reset, $b) {
            Db::update('users', [
                'password_hash' => password_hash($b['new_password'], PASSWORD_DEFAULT),
                'updated_at' => Db::now(),
            ], 'id = ?', [$user['id']]);
            Db::update('password_resets', ['used_at' => Db::now()], 'id = ?', [$reset['id']]);
            Db::run('DELETE FROM auth_tokens WHERE user_id = ?', [$user['id']]); // revoke all sessions
        });
        Audit::log((int)$user['id'], 'password_reset', 'user', (int)$user['id']);
        Response::ok(['message' => 'Password updated. Please log in again.']);
    }

    public static function userOut(array $u): array
    {
        return [
            'id' => (int)$u['id'],
            'full_name' => $u['full_name'],
            'email' => $u['email'],
            'phone' => $u['phone'],
            'role' => $u['role'],
            'status' => $u['status'],
            'created_at' => $u['created_at'],
        ];
    }

    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '[]', true);
        if (!is_array($data)) throw HttpException::badRequest('Request body must be valid JSON');
        return $data;
    }
}
