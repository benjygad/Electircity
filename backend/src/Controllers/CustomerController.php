<?php
declare(strict_types=1);

namespace Sems\Controllers;

use Sems\Core\{Auth, Audit, Db, HttpException, Input, Response, Validate};
use function Sems\Core\paginate;

/** Admin customer management. Consumers read their own profile via /auth/me. */
final class CustomerController
{
    public static function index(array $args): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        [$limit, $offset, $page] = paginate();
        $q = trim((string)($_GET['q'] ?? ''));
        $status = $_GET['status'] ?? null;

        $where = []; $params = [];
        if ($q !== '') { $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)'; $like = "%$q%"; array_push($params, $like, $like, $like); }
        if (in_array($status, ['active', 'suspended'], true)) { $where[] = 'u.status = ?'; $params[] = $status; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) Db::val("SELECT COUNT(*) FROM users u $whereSql", $params);
        $rows = Db::all(
            "SELECT u.id, u.full_name, u.email, u.phone, u.role, u.status, u.created_at,
                    (SELECT COUNT(*) FROM user_meters um WHERE um.user_id = u.id) AS meter_count
               FROM users u $whereSql ORDER BY u.created_at DESC LIMIT $limit OFFSET $offset",
            $params
        );
        Response::ok(array_map([Input::class, 'stamp'], $rows), 200, ['total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public static function create(array $args): void
    {
        $admin = Auth::requireRole(Auth::ROLE_ADMIN);
        $b = Input::body();
        Validate::required($b, ['full_name', 'email', 'password']);
        $errors = [];
        $email = strtolower(trim($b['email']));
        if (!Validate::email($email)) $errors['email'] = 'A valid email address is required';
        if (!Validate::password($b['password'])) $errors['password'] = 'Min 8 characters with at least one letter and one number';
        if (isset($b['phone']) && $b['phone'] !== '' && !Validate::phone($b['phone'])) $errors['phone'] = 'Use Tanzanian format, e.g. +255712345678';
        if ($errors) throw HttpException::badRequest('Validation failed', $errors);
        if (Db::val('SELECT id FROM users WHERE email = ?', [$email])) throw HttpException::conflict('An account with this email already exists');

        $role = in_array($b['role'] ?? 'consumer', ['consumer', 'technician', 'support'], true) ? ($b['role'] ?? 'consumer') : 'consumer';
        $id = Db::insert('users', [
            'full_name' => trim($b['full_name']),
            'email' => $email,
            'phone' => $b['phone'] ?? null,
            'password_hash' => password_hash($b['password'], PASSWORD_DEFAULT),
            'role' => $role, // admins can create support/technician accounts; never other admins here
            'status' => 'active',
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);
        Audit::log((int)$admin['id'], 'customer_create', 'user', $id, "role=$role");
        Response::ok(AuthController::userOut(Db::one('SELECT * FROM users WHERE id = ?', [$id])), 201);
    }

    public static function show(array $args): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $id = (int)$args['id'];
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) throw HttpException::notFound('Customer not found');
        $out = AuthController::userOut($u);
        $out['meters'] = Db::all(
            'SELECT m.id, m.meter_number, m.meter_type, m.service_location, m.integration_status, um.relationship, um.created_at AS assigned_at
               FROM user_meters um JOIN meters m ON m.id = um.meter_id WHERE um.user_id = ?',
            [$id]
        );
        Response::ok(Input::stamp($out));
    }

    public static function update(array $args): void
    {
        $admin = Auth::requireRole(Auth::ROLE_ADMIN);
        $id = (int)$args['id'];
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) throw HttpException::notFound('Customer not found');
        $b = Input::body();

        $data = [];
        if (isset($b['full_name'])) {
            if (trim($b['full_name']) === '' || !Validate::strLen(trim($b['full_name']), 120)) {
                throw HttpException::badRequest('Validation failed', ['full_name' => 'Invalid name']);
            }
            $data['full_name'] = trim($b['full_name']);
        }
        if (isset($b['phone'])) {
            if ($b['phone'] !== '' && !Validate::phone($b['phone'])) throw HttpException::badRequest('Validation failed', ['phone' => 'Invalid phone']);
            $data['phone'] = $b['phone'] ?: null;
        }
        if (isset($b['status'])) {
            if (!in_array($b['status'], ['active', 'suspended'], true)) throw HttpException::badRequest('Validation failed', ['status' => 'Must be active or suspended']);
            $data['status'] = $b['status'];
        }
        if (!$data) throw HttpException::badRequest('No valid fields to update');
        $data['updated_at'] = Db::now();
        Db::update('users', $data, 'id = ?', [$id]);
        Audit::log((int)$admin['id'], 'customer_update', 'user', $id, implode(',', array_keys($data)));
        Response::ok(AuthController::userOut(Db::one('SELECT * FROM users WHERE id = ?', [$id])));
    }
}
