<?php
declare(strict_types=1);

namespace Sems\Controllers;

use Sems\Core\{Auth, Audit, Db, HttpException, Input, Response};
use function Sems\Core\paginate;

/** Admin-only anomaly alert review. */
final class AlertController
{
    public const STATUSES = ['new', 'under_investigation', 'confirmed', 'dismissed', 'resolved'];

    public static function index(array $args): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        [$limit, $offset, $page] = paginate();
        $where = []; $params = [];
        if (in_array($_GET['status'] ?? '', self::STATUSES, true)) { $where[] = 'a.status = ?'; $params[] = $_GET['status']; }
        if (in_array($_GET['severity'] ?? '', ['info', 'warning', 'critical'], true)) { $where[] = 'a.severity = ?'; $params[] = $_GET['severity']; }
        if (!empty($_GET['meter_id']) && ctype_digit($_GET['meter_id'])) { $where[] = 'a.meter_id = ?'; $params[] = (int)$_GET['meter_id']; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) Db::val("SELECT COUNT(*) FROM alerts a $whereSql", $params);
        $rows = Db::all(
            "SELECT a.*, m.meter_number, m.service_location,
                    (SELECT u.full_name FROM users u WHERE u.id = a.assigned_to) AS assigned_to_name
               FROM alerts a JOIN meters m ON m.id = a.meter_id
               $whereSql ORDER BY a.created_at DESC LIMIT $limit OFFSET $offset",
            $params
        );
        Response::ok(array_map([Input::class, 'stamp'], $rows), 200, ['total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public static function show(array $args): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $a = Db::one(
            'SELECT a.*, m.meter_number, m.service_location FROM alerts a JOIN meters m ON m.id = a.meter_id WHERE a.id = ?',
            [(int)$args['id']]
        );
        if (!$a) throw HttpException::notFound('Alert not found');
        Response::ok(Input::stamp($a));
    }

    public static function update(array $args): void
    {
        $admin = Auth::requireRole(Auth::ROLE_ADMIN);
        $a = Db::one('SELECT * FROM alerts WHERE id = ?', [(int)$args['id']]);
        if (!$a) throw HttpException::notFound('Alert not found');
        $b = Input::body();

        $data = [];
        if (isset($b['status'])) {
            if (!in_array($b['status'], self::STATUSES, true)) throw HttpException::badRequest('Validation failed', ['status' => 'Invalid status']);
            $data['status'] = $b['status'];
            $data['resolved_at'] = in_array($b['status'], ['resolved', 'dismissed'], true) ? Db::now() : null;
        }
        if (isset($b['assigned_to'])) {
            $assignee = (int)$b['assigned_to'];
            if ($assignee > 0 && !Db::val('SELECT id FROM users WHERE id = ?', [$assignee])) {
                throw HttpException::badRequest('Validation failed', ['assigned_to' => 'Unknown user']);
            }
            $data['assigned_to'] = $assignee > 0 ? $assignee : null;
        }
        if (isset($b['admin_notes'])) {
            if (mb_strlen($b['admin_notes']) > 2000) throw HttpException::badRequest('Validation failed', ['admin_notes' => 'Too long']);
            $data['admin_notes'] = $b['admin_notes'];
        }
        if (!$data) throw HttpException::badRequest('No valid fields to update');
        Db::update('alerts', $data, 'id = ?', [$a['id']]);
        Audit::log((int)$admin['id'], 'alert_update', 'alert', (int)$a['id'], json_encode(array_diff_key($data, ['admin_notes' => 1])));
        Response::ok(Input::stamp(Db::one('SELECT * FROM alerts WHERE id = ?', [$a['id']])));
    }
}
