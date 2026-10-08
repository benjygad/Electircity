<?php
declare(strict_types=1);

namespace Sems\Controllers;

use Sems\Core\{Auth, Audit, Db, HttpException, Input, Response, Settings};
use function Sems\Core\paginate;

/** Dashboard summary, audit logs, settings. Admin only. */
final class AdminController
{
    public static function summary(array $args): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $tariff = Settings::num('tariff_per_kwh_tzs', 400);
        $weekFrom = gmdate('Y-m-d', strtotime('-7 days'));

        $summary = [
            'consumers' => (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'consumer'"),
            'meters' => (int) Db::val('SELECT COUNT(*) FROM meters'),
            'meters_by_status' => Db::all('SELECT integration_status, COUNT(*) AS cnt FROM meters GROUP BY integration_status'),
            'open_alerts' => (int) Db::val("SELECT COUNT(*) FROM alerts WHERE status IN ('new','under_investigation')"),
            'critical_alerts' => (int) Db::val("SELECT COUNT(*) FROM alerts WHERE severity = 'critical' AND status IN ('new','under_investigation')"),
            'open_fault_reports' => (int) Db::val("SELECT COUNT(*) FROM fault_reports WHERE status NOT IN ('resolved','closed')"),
            'consumption_last_7d_kwh' => round((float) Db::val('SELECT COALESCE(SUM(energy_kwh),0) FROM consumption_records WHERE period_start >= ?', [$weekFrom]), 3),
            'estimated_value_last_7d_tzs' => round((float) Db::val('SELECT COALESCE(SUM(energy_kwh),0) FROM consumption_records WHERE period_start >= ?', [$weekFrom]) * $tariff, 2),
            'purchases_last_30d_tzs' => round((float) Db::val("SELECT COALESCE(SUM(amount),0) FROM token_transactions WHERE status = 'recorded' AND created_at >= ?", [gmdate('Y-m-d H:i:s', strtotime('-30 days'))]), 2),
            'tariff_per_kwh_tzs' => $tariff,
            'tariff_note' => 'Demo tariff assumption.',
            'consumption_by_day_14d' => Db::all(
                'SELECT period_start AS day, ROUND(SUM(energy_kwh),3) AS kwh FROM consumption_records
                  WHERE period_start >= ? GROUP BY period_start ORDER BY period_start',
                [gmdate('Y-m-d', strtotime('-14 days'))]
            ),
            'recent_activity' => array_map([Input::class, 'stamp'], Db::all(
                "SELECT al.action, al.entity_type, al.entity_id, al.details, al.created_at,
                        (SELECT u.full_name FROM users u WHERE u.id = al.actor_id) AS actor_name
                   FROM audit_logs al ORDER BY al.id DESC LIMIT 8"
            )),
            'recent_registrations' => Db::all(
                "SELECT id, full_name, email, created_at FROM users WHERE role = 'consumer' ORDER BY id DESC LIMIT 5"
            ),
            'data_disclaimer' => 'All figures derive from the SEMS database, which contains simulated demonstration data in this prototype.',
        ];
        Response::ok($summary);
    }

    public static function auditLogs(array $args): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        [$limit, $offset, $page] = paginate();
        $total = (int) Db::val('SELECT COUNT(*) FROM audit_logs');
        $rows = Db::all(
            "SELECT al.*, (SELECT u.full_name FROM users u WHERE u.id = al.actor_id) AS actor_name
               FROM audit_logs al ORDER BY al.id DESC LIMIT $limit OFFSET $offset"
        );
        Response::ok(array_map([Input::class, 'stamp'], $rows), 200, ['total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public static function settings(array $args): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        Response::ok(Db::all('SELECT `key`, value FROM settings ORDER BY `key`'));
    }

    public static function updateSettings(array $args): void
    {
        $admin = Auth::requireRole(Auth::ROLE_ADMIN);
        $b = Input::body();
        $allowed = ['tariff_per_kwh_tzs', 'anomaly_spike_multiplier', 'anomaly_spike_min_kwh', 'anomaly_zero_days', 'anomaly_missing_hours', 'low_credit_threshold_tzs'];
        $changed = [];
        foreach ($allowed as $key) {
            if (isset($b[$key])) {
                if (!is_numeric($b[$key]) || (float)$b[$key] < 0) throw HttpException::badRequest('Validation failed', [$key => 'Must be a non-negative number']);
                Settings::set($key, (string)$b[$key]);
                $changed[] = $key;
            }
        }
        if (!$changed) throw HttpException::badRequest('No valid settings provided');
        Audit::log((int)$admin['id'], 'settings_update', 'settings', null, implode(',', $changed));
        Response::ok(Db::all('SELECT `key`, value FROM settings ORDER BY `key`'));
    }
}
