<?php
declare(strict_types=1);

namespace Sems\Controllers;

use Sems\Core\{Auth, Db, Input, Response};

/** Admin reporting endpoints with date filters (YYYY-MM-DD, UTC). */
final class ReportController
{
    public static function consumption(array $args): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        [$from, $to] = self::range(30);
        $meterFilter = ''; $params = [$from, $to];
        if (!empty($_GET['meter_id']) && ctype_digit($_GET['meter_id'])) { $meterFilter = 'AND cr.meter_id = ?'; $params[] = (int)$_GET['meter_id']; }
        if (!empty($_GET['user_id']) && ctype_digit($_GET['user_id'])) {
            $params[] = (int)$_GET['user_id'];
            $meterFilter .= ' AND cr.meter_id IN (SELECT meter_id FROM user_meters WHERE user_id = ?)';
        }

        $byDay = Db::all(
            "SELECT cr.period_start AS day, ROUND(SUM(cr.energy_kwh),3) AS total_kwh, COUNT(DISTINCT cr.meter_id) AS active_meters
               FROM consumption_records cr
              WHERE cr.period_start BETWEEN ? AND ? $meterFilter
              GROUP BY cr.period_start ORDER BY cr.period_start",
            $params
        );
        $byMeter = Db::all(
            "SELECT m.id AS meter_id, m.meter_number, m.service_location, ROUND(SUM(cr.energy_kwh),3) AS total_kwh, MIN(cr.source) AS primary_source
               FROM consumption_records cr JOIN meters m ON m.id = cr.meter_id
              WHERE cr.period_start BETWEEN ? AND ? $meterFilter
              GROUP BY m.id, m.meter_number, m.service_location ORDER BY total_kwh DESC LIMIT 100",
            $params
        );
        Response::ok([
            'from' => $from, 'to' => $to,
            'grand_total_kwh' => round(array_sum(array_column($byDay, 'total_kwh')), 3),
            'by_day' => $byDay,
            'by_meter' => $byMeter,
        ]);
    }

    public static function transactions(array $args): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        [$from, $to] = self::range(30);
        $rows = Db::all(
            "SELECT tt.id, tt.meter_id, m.meter_number, tt.amount, tt.token_reference, tt.status, tt.source, tt.created_at
               FROM token_transactions tt JOIN meters m ON m.id = tt.meter_id
              WHERE DATE(tt.created_at) BETWEEN ? AND ?
              ORDER BY tt.created_at DESC LIMIT 500",
            [$from, $to]
        );
        Response::ok([
            'from' => $from, 'to' => $to,
            'total_amount_tzs' => round(array_sum(array_column($rows, 'amount')), 2),
            'count' => count($rows),
            'transactions' => array_map([Input::class, 'stamp'], $rows),
        ]);
    }

    public static function faults(array $args): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        [$from, $to] = self::range(90);
        $byStatus = Db::all(
            'SELECT status, COUNT(*) AS cnt FROM fault_reports WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status',
            [$from, $to]
        );
        $byCategory = Db::all(
            'SELECT category, COUNT(*) AS cnt FROM fault_reports WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY category ORDER BY cnt DESC',
            [$from, $to]
        );
        $recent = Db::all(
            "SELECT fr.id, fr.category, fr.status, fr.created_at, u.full_name AS reporter_name, m.meter_number
               FROM fault_reports fr JOIN users u ON u.id = fr.user_id LEFT JOIN meters m ON m.id = fr.meter_id
              WHERE DATE(fr.created_at) BETWEEN ? AND ? ORDER BY fr.created_at DESC LIMIT 200",
            [$from, $to]
        );
        Response::ok(['from' => $from, 'to' => $to, 'by_status' => $byStatus, 'by_category' => $byCategory, 'recent' => array_map([Input::class, 'stamp'], $recent)]);
    }

    private static function range(int $defaultDays): array
    {
        $from = $_GET['from'] ?? gmdate('Y-m-d', strtotime("-$defaultDays days"));
        $to = $_GET['to'] ?? gmdate('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            throw \Sems\Core\HttpException::badRequest('Validation failed', ['from/to' => 'Use YYYY-MM-DD with from <= to']);
        }
        return [$from, $to];
    }
}
