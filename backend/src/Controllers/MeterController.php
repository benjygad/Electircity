<?php
declare(strict_types=1);

namespace Sems\Controllers;

use Sems\Core\{Auth, Audit, Db, HttpException, Input, Response, Validate};
use Sems\Services\{AnomalyService, ConsumptionService, NotificationService};
use function Sems\Core\paginate;

final class MeterController
{
    public const INTEGRATION_STATUSES = ['connected', 'simulated', 'offline', 'integration_unavailable'];
    public const READING_SOURCES = ['simulated', 'manual', 'imported', 'hardware'];

    /** Admin: all meters (filterable). Consumer: only their own. */
    public static function index(array $args): void
    {
        $u = Auth::user();
        if (Auth::isAdmin($u)) {
            [$limit, $offset, $page] = paginate();
            $q = trim((string)($_GET['q'] ?? ''));
            $where = []; $params = [];
            if ($q !== '') { $where[] = '(m.meter_number LIKE ? OR m.service_location LIKE ?)'; array_push($params, "%$q%", "%$q%"); }
            if (in_array($_GET['integration_status'] ?? '', self::INTEGRATION_STATUSES, true)) {
                $where[] = 'm.integration_status = ?'; $params[] = $_GET['integration_status'];
            }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $total = (int) Db::val("SELECT COUNT(*) FROM meters m $whereSql", $params);
            $rows = Db::all(
                "SELECT m.*, (SELECT COUNT(*) FROM user_meters um WHERE um.meter_id = m.id) AS customer_links
                   FROM meters m $whereSql ORDER BY m.created_at DESC LIMIT $limit OFFSET $offset",
                $params
            );
            Response::ok(array_map([self::class, 'decorate'], $rows), 200, ['total' => $total, 'page' => $page, 'limit' => $limit]);
            return;
        }
        $rows = Db::all(
            'SELECT m.*, um.relationship FROM user_meters um JOIN meters m ON m.id = um.meter_id WHERE um.user_id = ?',
            [$u['id']]
        );
        Response::ok(array_map([self::class, 'decorate'], $rows));
    }

    public static function create(array $args): void
    {
        $admin = Auth::requireRole(Auth::ROLE_ADMIN);
        $b = Input::body();
        Validate::required($b, ['meter_number', 'service_location']);
        $errors = [];
        $meterNumber = strtoupper(trim($b['meter_number']));
        if (!Validate::meterNumber($meterNumber)) $errors['meter_number'] = 'Meter number must be 11–16 characters (LUKU-style, mostly digits)';
        if (!Validate::strLen($b['service_location'], 190)) $errors['service_location'] = 'Too long';
        $type = $b['meter_type'] ?? 'single_phase_prepaid';
        if (!Validate::strLen($type, 40)) $errors['meter_type'] = 'Invalid meter type';
        $status = $b['integration_status'] ?? 'simulated';
        if (!in_array($status, self::INTEGRATION_STATUSES, true)) $errors['integration_status'] = 'Invalid integration status';
        if ($errors) throw HttpException::badRequest('Validation failed', $errors);
        if (Db::val('SELECT id FROM meters WHERE meter_number = ?', [$meterNumber])) {
            throw HttpException::conflict('A meter with this number already exists');
        }

        $meterId = Db::transaction(function () use ($meterNumber, $type, $status, $b) {
            $id = Db::insert('meters', [
                'meter_number' => $meterNumber,
                'meter_type' => $type,
                'service_location' => trim($b['service_location']),
                'integration_status' => $status,
                'created_at' => Db::now(),
                'updated_at' => Db::now(),
            ]);
            if (!empty($b['user_id'])) {
                $userId = (int)$b['user_id'];
                if (!Db::val('SELECT id FROM users WHERE id = ?', [$userId])) throw HttpException::badRequest('Validation failed', ['user_id' => 'Unknown customer']);
                Db::insert('user_meters', ['user_id' => $userId, 'meter_id' => $id, 'relationship' => 'owner', 'created_at' => Db::now()]);
            }
            return $id;
        });
        Audit::log((int)$admin['id'], 'meter_create', 'meter', $meterId, $meterNumber);
        Response::ok(self::decorate(Db::one('SELECT * FROM meters WHERE id = ?', [$meterId])), 201);
    }

    public static function show(array $args): void
    {
        $meter = Auth::accessibleMeter((int)$args['id']);
        $out = self::decorate($meter);
        $out['latest_reading'] = Db::one(
            'SELECT reading_time, cumulative_kwh, voltage, current_amps, source FROM meter_readings WHERE meter_id = ? ORDER BY reading_time DESC LIMIT 1',
            [$meter['id']]
        );
        $out['credit'] = ConsumptionService::creditEstimate((int)$meter['id']);
        $out['owners'] = Db::all(
            'SELECT u.id, u.full_name, u.email, um.relationship FROM user_meters um JOIN users u ON u.id = um.user_id WHERE um.meter_id = ?',
            [$meter['id']]
        );
        Response::ok(Input::stamp($out));
    }

    /** Admin-only: assign/unassign a meter to a customer. */
    public static function assign(array $args): void
    {
        $admin = Auth::requireRole(Auth::ROLE_ADMIN);
        $meter = Db::one('SELECT * FROM meters WHERE id = ?', [(int)$args['id']]);
        if (!$meter) throw HttpException::notFound('Meter not found');
        $b = Input::body();
        Validate::required($b, ['user_id']);
        $userId = (int)$b['user_id'];
        if (!Db::val('SELECT id FROM users WHERE id = ?', [$userId])) throw HttpException::badRequest('Validation failed', ['user_id' => 'Unknown customer']);

        $action = $b['action'] ?? 'assign';
        if ($action === 'unassign') {
            Db::run('DELETE FROM user_meters WHERE meter_id = ? AND user_id = ?', [$meter['id'], $userId]);
            Audit::log((int)$admin['id'], 'meter_unassign', 'meter', (int)$meter['id'], "user=$userId");
            Response::ok(['message' => 'Meter unassigned']);
        }
        try {
            Db::insert('user_meters', ['user_id' => $userId, 'meter_id' => $meter['id'], 'relationship' => in_array($b['relationship'] ?? '', ['owner', 'tenant'], true) ? $b['relationship'] : 'owner', 'created_at' => Db::now()]);
        } catch (\PDOException $e) {
            throw HttpException::conflict('This meter is already assigned to that customer');
        }
        Audit::log((int)$admin['id'], 'meter_assign', 'meter', (int)$meter['id'], "user=$userId");
        NotificationService::notifyUser($userId, 'Meter assigned', 'Meter ' . $meter['meter_number'] . ' is now linked to your SEMS account.');
        Response::ok(['message' => 'Meter assigned'], 201);
    }

    public static function readings(array $args): void
    {
        $meter = Auth::accessibleMeter((int)$args['id']);
        [$limit, $offset, $page] = paginate();
        $total = (int) Db::val('SELECT COUNT(*) FROM meter_readings WHERE meter_id = ?', [$meter['id']]);
        $rows = Db::all(
            "SELECT id, reading_time, cumulative_kwh, voltage, current_amps, source, created_at
               FROM meter_readings WHERE meter_id = ? ORDER BY reading_time DESC LIMIT $limit OFFSET $offset",
            [$meter['id']]
        );
        Response::ok(array_map([Input::class, 'stamp'], $rows), 200, ['total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /** Admin/simulator: ingest a reading (manual, imported or simulated). */
    public static function addReading(array $args): void
    {
        $admin = Auth::requireRole(Auth::ROLE_ADMIN);
        $meter = Db::one('SELECT * FROM meters WHERE id = ?', [(int)$args['id']]);
        if (!$meter) throw HttpException::notFound('Meter not found');
        $b = Input::body();
        Validate::required($b, ['reading_time', 'cumulative_kwh']);
        if (!is_numeric($b['cumulative_kwh'])) throw HttpException::badRequest('Validation failed', ['cumulative_kwh' => 'Must be a number']);
        $source = in_array($b['source'] ?? '', self::READING_SOURCES, true) ? $b['source'] : 'manual';
        $timeUtc = str_replace(['T', 'Z'], [' ', ''], trim((string)$b['reading_time']));
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timeUtc)) {
            $ts = strtotime($timeUtc);
            if ($ts === false) throw HttpException::badRequest('Validation failed', ['reading_time' => 'Invalid datetime']);
            $timeUtc = gmdate('Y-m-d H:i:s', $ts);
        }

        $result = ConsumptionService::addReading(
            (int)$meter['id'], $timeUtc, (float)$b['cumulative_kwh'], $source,
            isset($b['voltage']) && is_numeric($b['voltage']) ? (float)$b['voltage'] : null,
            isset($b['current_amps']) && is_numeric($b['current_amps']) ? (float)$b['current_amps'] : null
        );
        Audit::log((int)$admin['id'], 'reading_add', 'meter', (int)$meter['id'], "kwh={$b['cumulative_kwh']} source=$source");

        // Events that genuinely occurred: run anomaly rules + low-credit check.
        AnomalyService::scanMeter((int)$meter['id']);
        NotificationService::maybeLowCredit((int)$meter['id']);

        $result['reading'] = Input::stamp($result['reading']);
        Response::ok($result, 201);
    }

    public static function consumption(array $args): void
    {
        $meter = Auth::accessibleMeter((int)$args['id']);
        $from = $_GET['from'] ?? gmdate('Y-m-d', strtotime('-30 days'));
        $to = $_GET['to'] ?? gmdate('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            throw HttpException::badRequest('Validation failed', ['from/to' => 'Use YYYY-MM-DD with from <= to']);
        }
        $rows = ConsumptionService::dailySeries((int)$meter['id'], $from, $to);
        $tariff = \Sems\Core\Settings::num('tariff_per_kwh_tzs', 400);
        $totalKwh = round(array_sum(array_column($rows, 'kwh')), 3);
        Response::ok([
            'meter_id' => (int)$meter['id'],
            'from' => $from, 'to' => $to,
            'total_kwh' => $totalKwh,
            'estimated_cost_tzs' => round($totalKwh * $tariff, 2),
            'tariff_per_kwh_tzs' => $tariff,
            'tariff_note' => 'Demo tariff assumption — not a verified TANESCO tariff.',
            'series' => array_map([Input::class, 'stamp'], $rows),
        ]);
    }

    private static function decorate(array $m): array
    {
        $m = Input::stamp($m);
        $m['reading_freshness'] = null;
        $latest = Db::val('SELECT MAX(reading_time) FROM meter_readings WHERE meter_id = ?', [$m['id']]);
        if ($latest) {
            $hours = (time() - strtotime($latest . ' UTC')) / 3600;
            $m['last_reading_at'] = str_replace(' ', 'T', $latest) . 'Z';
            $m['reading_freshness'] = $hours <= 36 ? 'fresh' : ($hours <= 96 ? 'stale' : 'outdated');
        }
        return $m;
    }
}
