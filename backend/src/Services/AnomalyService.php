<?php
declare(strict_types=1);

namespace Sems\Services;

use Sems\Core\Db;
use Sems\Core\Settings;

/**
 * Rule-based anomaly detection.
 *
 * Alerts IDENTIFY POTENTIAL ANOMALIES for review — they never automatically
 * accuse a customer of theft. Statuses: new → under_investigation →
 * confirmed | dismissed | resolved.
 *
 * Rules (thresholds configurable in `settings`):
 *  R1 consumption_spike   latest day >> historical baseline
 *  R2 zero_consumption    N consecutive zero-usage days where baseline > 0
 *  R3 missing_readings    no reading for longer than expected interval
 *  R4 tamper_event        explicit event supplied by compatible hardware/simulator
 *  R5 data_integrity      decreasing cumulative reading (raised at ingest)
 */
final class AnomalyService
{
    /** Insert an alert (deduplicated against open alerts of same meter+type) and notify owners. */
    public static function raise(int $meterId, string $type, string $severity, string $description, string $dataSource = 'rule_engine'): ?int
    {
        $open = Db::one(
            "SELECT id FROM alerts WHERE meter_id = ? AND alert_type = ? AND status IN ('new','under_investigation')",
            [$meterId, $type]
        );
        if ($open) return null; // avoid duplicate open alerts

        $id = Db::insert('alerts', [
            'meter_id' => $meterId,
            'alert_type' => $type,
            'severity' => $severity,
            'description' => mb_substr($description, 0, 500),
            'status' => 'new',
            'data_source' => $dataSource,
            'created_at' => Db::now(),
        ]);

        NotificationService::notifyMeterOwners(
            $meterId,
            'Unusual activity on your meter',
            'SEMS flagged a possible anomaly (' . str_replace('_', ' ', $type) . '). This is an automated indication, not an accusation — our team will review it.'
        );
        return $id;
    }

    /** Run detection rules over one meter. Returns number of new alerts. */
    public static function scanMeter(int $meterId): int
    {
        $created = 0;
        $created += self::ruleSpike($meterId);
        $created += self::ruleZeroConsumption($meterId);
        $created += self::ruleMissingReadings($meterId);
        return $created;
    }

    public static function scanAll(): array
    {
        $summary = ['meters_scanned' => 0, 'alerts_created' => 0];
        foreach (Db::all('SELECT id FROM meters') as $m) {
            $summary['meters_scanned']++;
            $summary['alerts_created'] += self::scanMeter((int)$m['id']);
        }
        return $summary;
    }

    /** R1: latest consumption day vs 14-day baseline. */
    private static function ruleSpike(int $meterId): int
    {
        $multiplier = Settings::num('anomaly_spike_multiplier', 2.0);
        $minKwh = Settings::num('anomaly_spike_min_kwh', 2.0);

        $days = Db::all(
            'SELECT period_start, SUM(energy_kwh) AS kwh FROM consumption_records
              WHERE meter_id = ? AND period_start >= ? GROUP BY period_start ORDER BY period_start DESC LIMIT 15',
            [$meterId, gmdate('Y-m-d', strtotime('-45 days'))]
        );
        if (count($days) < 4) return 0;

        $latest = array_shift($days);                 // most recent day
        $baselineVals = array_map(fn($d) => (float)$d['kwh'], $days);
        $baseline = array_sum($baselineVals) / max(1, count($baselineVals));
        $latestKwh = (float)$latest['kwh'];

        if ($baseline <= 0.05 || $latestKwh < $minKwh || $latestKwh < $multiplier * $baseline) return 0;

        $severity = $latestKwh >= 3 * $baseline ? 'critical' : 'warning';
        return self::raise($meterId, 'consumption_spike', $severity, sprintf(
            'Consumption on %s reached %.2f kWh — %.1fx the recent daily baseline of %.2f kWh.',
            $latest['period_start'], $latestKwh, $latestKwh / max(0.001, $baseline), $baseline
        )) ? 1 : 0;
    }

    /** R2: N consecutive zero-usage days despite an established baseline. */
    private static function ruleZeroConsumption(int $meterId): int
    {
        $zeroDays = (int) Settings::num('anomaly_zero_days', 3);
        $lastReading = Db::one(
            'SELECT reading_time FROM meter_readings WHERE meter_id = ? ORDER BY reading_time DESC LIMIT 1',
            [$meterId]
        );
        if (!$lastReading) return 0;

        $today = substr($lastReading['reading_time'], 0, 10);
        $from = gmdate('Y-m-d', strtotime($today . ' -' . ($zeroDays + 14) . ' days'));
        $byDay = [];
        foreach (Db::all(
            'SELECT period_start, SUM(energy_kwh) AS kwh FROM consumption_records WHERE meter_id = ? AND period_start BETWEEN ? AND ? GROUP BY period_start',
            [$meterId, $from, $today]
        ) as $r) $byDay[$r['period_start']] = (float)$r['kwh'];

        // walk backwards from the last active day
        $cursor = $today; $zeros = 0; $history = [];
        for ($i = 0; $i < $zeroDays + 14; $i++) {
            $kwh = $byDay[$cursor] ?? 0.0;
            if ($kwh <= 0.0005) $zeros++; else break;
            $cursor = gmdate('Y-m-d', strtotime($cursor . ' -1 day'));
        }
        foreach ($byDay as $v) $history[] = $v;
        $baseline = count($history) ? array_sum($history) / count($history) : 0;

        if ($zeros < $zeroDays || $baseline < 0.5) return 0;
        return self::raise($meterId, 'zero_consumption', 'warning', sprintf(
            'No meaningful consumption recorded for %d consecutive days although this meter typically uses about %.2f kWh/day. Possible disconnection, vacancy or meter fault.',
            $zeros, $baseline
        )) ? 1 : 0;
    }

    /** R3: readings stopped arriving. */
    private static function ruleMissingReadings(int $meterId): int
    {
        $maxHours = Settings::num('anomaly_missing_hours', 36);
        $meter = Db::one('SELECT integration_status FROM meters WHERE id = ?', [$meterId]);
        if (!$meter || $meter['integration_status'] === 'offline') return 0;

        $last = Db::val('SELECT MAX(reading_time) FROM meter_readings WHERE meter_id = ?', [$meterId]);
        if (!$last) return 0;
        $ageHours = (time() - strtotime($last . ' UTC')) / 3600;
        if ($ageHours < $maxHours) return 0;

        return self::raise($meterId, 'missing_readings', 'warning', sprintf(
            'No meter reading received for %.0f hours (expected at least every %.0f hours). Last reading: %s UTC.',
            $ageHours, $maxHours, $last
        )) ? 1 : 0;
    }
}
