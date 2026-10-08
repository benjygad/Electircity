<?php
declare(strict_types=1);

namespace Sems\Controllers;

use Sems\Core\{Auth, Audit, Db, HttpException, Input, Response, Validate};
use Sems\Services\NotificationService;
use function Sems\Core\paginate;

/** Fault/complaint reports + support messaging. */
final class FaultController
{
    public const CATEGORIES = ['power_outage', 'meter_problem', 'suspected_incorrect_reading', 'supply_issue', 'other'];
    public const STATUSES = ['submitted', 'under_review', 'assigned', 'in_progress', 'resolved', 'closed'];

    public static function index(array $args): void
    {
        $u = Auth::user();
        [$limit, $offset, $page] = paginate();
        $where = []; $params = [];
        if (!Auth::isAdmin($u)) { $where[] = 'fr.user_id = ?'; $params[] = $u['id']; }
        if (in_array($_GET['status'] ?? '', self::STATUSES, true)) { $where[] = 'fr.status = ?'; $params[] = $_GET['status']; }
        if (in_array($_GET['category'] ?? '', self::CATEGORIES, true)) { $where[] = 'fr.category = ?'; $params[] = $_GET['category']; }
        if (Auth::isAdmin($u) && !empty($_GET['q'])) {
            $where[] = 'fr.description LIKE ?'; $params[] = '%' . $_GET['q'] . '%';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) Db::val("SELECT COUNT(*) FROM fault_reports fr $whereSql", $params);
        $rows = Db::all(
            "SELECT fr.*, m.meter_number, u.full_name AS reporter_name,
                    (SELECT su.full_name FROM users su WHERE su.id = fr.assigned_to) AS assigned_to_name,
                    (SELECT COUNT(*) FROM support_messages sm WHERE sm.report_id = fr.id) AS message_count
               FROM fault_reports fr
               JOIN users u ON u.id = fr.user_id
               LEFT JOIN meters m ON m.id = fr.meter_id
               $whereSql ORDER BY fr.created_at DESC LIMIT $limit OFFSET $offset",
            $params
        );
        Response::ok(array_map([Input::class, 'stamp'], $rows), 200, ['total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public static function create(array $args): void
    {
        $u = Auth::user();
        $b = Input::body();
        Validate::required($b, ['description']);
        $errors = [];
        $category = $b['category'] ?? 'other';
        if (!in_array($category, self::CATEGORIES, true)) $errors['category'] = 'Unknown category';
        if (!Validate::strLen($b['description'], 2000) || trim($b['description']) === '') $errors['description'] = 'Description is required (max 2000 characters)';
        $meterId = null;
        if (!empty($b['meter_id'])) {
            $meterId = (int)$b['meter_id'];
            if (!Auth::isAdmin($u)) {
                if (!Db::val('SELECT id FROM user_meters WHERE user_id = ? AND meter_id = ?', [$u['id'], $meterId])) {
                    $errors['meter_id'] = 'That meter is not linked to your account';
                }
            } elseif (!Db::val('SELECT id FROM meters WHERE id = ?', [$meterId])) {
                $errors['meter_id'] = 'Unknown meter';
            }
        }
        if ($errors) throw HttpException::badRequest('Validation failed', $errors);

        $id = Db::insert('fault_reports', [
            'user_id' => $u['id'],
            'meter_id' => $meterId,
            'category' => $category,
            'description' => trim($b['description']),
            'status' => 'submitted',
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);
        Audit::log((int)$u['id'], 'fault_report_create', 'fault_report', $id, "category=$category");
        Response::ok(Input::stamp(Db::one('SELECT * FROM fault_reports WHERE id = ?', [$id])), 201);
    }

    public static function show(array $args): void
    {
        $u = Auth::user();
        $fr = self::accessible((int)$args['id'], $u);
        $fr['meter_number'] = Db::val('SELECT meter_number FROM meters WHERE id = ?', [$fr['meter_id'] ?? 0]);
        $fr['reporter_name'] = Db::val('SELECT full_name FROM users WHERE id = ?', [$fr['user_id']]);
        $fr['assigned_to_name'] = Db::val('SELECT full_name FROM users WHERE id = ?', [$fr['assigned_to'] ?? 0]);
        $fr['messages'] = array_map([Input::class, 'stamp'], Db::all(
            'SELECT sm.id, sm.sender_id, u.full_name AS sender_name, u.role AS sender_role, sm.message, sm.created_at
               FROM support_messages sm JOIN users u ON u.id = sm.sender_id
              WHERE sm.report_id = ? ORDER BY sm.created_at',
            [$fr['id']]
        ));
        Response::ok(Input::stamp($fr));
    }

    /** Admin: status/assignment updates. Consumers cannot change statuses. */
    public static function update(array $args): void
    {
        $admin = Auth::requireRole(Auth::ROLE_ADMIN);
        $fr = Db::one('SELECT * FROM fault_reports WHERE id = ?', [(int)$args['id']]);
        if (!$fr) throw HttpException::notFound('Report not found');
        $b = Input::body();

        $data = [];
        if (isset($b['status'])) {
            if (!in_array($b['status'], self::STATUSES, true)) throw HttpException::badRequest('Validation failed', ['status' => 'Invalid status']);
            $data['status'] = $b['status'];
        }
        if (isset($b['assigned_to'])) {
            $assignee = (int)$b['assigned_to'];
            if ($assignee > 0) {
                $row = Db::one('SELECT role FROM users WHERE id = ?', [$assignee]);
                if (!$row) throw HttpException::badRequest('Validation failed', ['assigned_to' => 'Unknown user']);
            }
            $data['assigned_to'] = $assignee > 0 ? $assignee : null;
            if (!isset($data['status']) && $assignee > 0) $data['status'] = 'assigned';
        }
        if (!$data) throw HttpException::badRequest('No valid fields to update');
        $data['updated_at'] = Db::now();
        Db::update('fault_reports', $data, 'id = ?', [$fr['id']]);
        Audit::log((int)$admin['id'], 'fault_report_update', 'fault_report', (int)$fr['id'], json_encode($data));

        NotificationService::notifyUser((int)$fr['user_id'], 'Fault report update',
            'Your report #' . $fr['id'] . ' is now: ' . str_replace('_', ' ', $data['status'] ?? $fr['status']) . '.');
        Response::ok(Input::stamp(Db::one('SELECT * FROM fault_reports WHERE id = ?', [$fr['id']])));
    }

    public static function messages(array $args): void
    {
        $u = Auth::user();
        $fr = self::accessible((int)$args['id'], $u);
        $rows = Db::all(
            'SELECT sm.id, sm.sender_id, uf.full_name AS sender_name, uf.role AS sender_role, sm.message, sm.created_at
               FROM support_messages sm JOIN users uf ON uf.id = sm.sender_id
              WHERE sm.report_id = ? ORDER BY sm.created_at',
            [$fr['id']]
        );
        Response::ok(array_map([Input::class, 'stamp'], $rows));
    }

    public static function addMessage(array $args): void
    {
        $u = Auth::user();
        $fr = self::accessible((int)$args['id'], $u);
        $b = Input::body();
        Validate::required($b, ['message']);
        if (!Validate::strLen($b['message'], 2000)) throw HttpException::badRequest('Validation failed', ['message' => 'Max 2000 characters']);

        $id = Db::insert('support_messages', [
            'report_id' => $fr['id'],
            'sender_id' => $u['id'],
            'message' => trim($b['message']),
            'created_at' => Db::now(),
        ]);
        Db::update('fault_reports', ['updated_at' => Db::now()], 'id = ?', [$fr['id']]);

        // Notify the other side of the conversation.
        if ((int)$fr['user_id'] !== (int)$u['id']) {
            NotificationService::notifyUser((int)$fr['user_id'], 'New support message',
                'There is a new response on your fault report #' . $fr['id'] . '.');
        } else {
            $adminId = Db::val("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
            if ($adminId) NotificationService::notifyUser((int)$adminId, 'Consumer replied on report #' . $fr['id'], mb_substr($b['message'], 0, 120));
        }
        Response::ok(Input::stamp(Db::one(
            'SELECT sm.id, sm.sender_id, u.full_name AS sender_name, u.role AS sender_role, sm.message, sm.created_at
               FROM support_messages sm JOIN users u ON u.id = sm.sender_id WHERE sm.id = ?',
            [$id]
        )), 201);
    }

    private static function accessible(int $id, array $u): array
    {
        $fr = Db::one('SELECT * FROM fault_reports WHERE id = ?', [$id]);
        if (!$fr) throw HttpException::notFound('Report not found');
        if (!Auth::isAdmin($u) && (int)$fr['user_id'] !== (int)$u['id']) {
            throw HttpException::forbidden('You can only access your own reports');
        }
        return $fr;
    }
}
