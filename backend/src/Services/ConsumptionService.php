<?php
declare(strict_types=1);

namespace Sems\Services;

use Sems\Core\Db;
use Sems\Core\HttpException;
use Sems\Core\Settings;

/**
 * Reading ingestion + consumption derivation.
 *
 * Energy consumed between two successive valid readings:
 *   energy = ending cumulative − starting cumulative.
 * Decreasing cumulative readings, duplicates and invalid values are rejected
 * (and can raise data-integrity alerts) — they never silently skew results.
 */
final class ConsumptionService
{
    /**
     * Ingest one reading. Returns ['reading' => row, 'warnings' => [...]].
     * @throws HttpException on invalid/duplicate/decreasing input.
     */
    public static function addReading(int $meterId, string $readingTimeUtc, float $cumulativeKwh, string $source, ?float $voltage = null, ?float $current = null): array
    {
        if ($cumulativeKwh < 0) throw HttpException::badRequest('cumulative_kwh must be >= 0');
        if (strtotime($readingTimeUtc) === false) throw HttpException::badRequest('reading_time is not a valid datetime');

        $warnings = [];
        $prev = Db::one(
            'SELECT * FROM meter_readings WHERE meter_id = ? AND reading_time < ? ORDER BY reading_time DESC LIMIT 1',
            [$meterId, $readingTimeUtc]
        );
        $dup = Db::one('SELECT id FROM meter_readings WHERE meter_id = ? AND reading_time = ?', [$meterId, $readingTimeUtc]);
        if ($dup) throw new HttpException(409, 'A reading already exists for this meter at that time');

        if ($prev && $cumulativeKwh < (float)$prev['cumulative_kwh']) {
            // Data anomaly: cumulative registers cannot run backwards.
            AnomalyService::raise($meterId, 'data_integrity', 'critical',
                sprintf('Cumulative reading decreased from %.3f to %.3f kWh — possible meter fault, replacement or data error. Reading rejected.',
                    (float)$prev['cumulative_kwh'], $cumulativeKwh), 'reading_ingest');
            throw new HttpException(422, 'Reading rejected: cumulative value is lower than the previous reading');
        }

        $id = Db::insert('meter_readings', [
            'meter_id' => $meterId,
            'reading_time' => $readingTimeUtc,
            'cumulative_kwh' => $cumulativeKwh,
            'voltage' => $voltage,
            'current_amps' => $current,
            'source' => $source,
            'created_at' => Db::now(),
        ]);

        if ($prev) {
            $delta = $cumulativeKwh - (float)$prev['cumulative_kwh'];
            $day = substr($readingTimeUtc, 0, 10);
            $spanHours = max(1e-9, (strtotime($readingTimeUtc) - strtotime($prev['reading_time'])) / 3600);
            if ($spanHours > 30 && $delta > 0) {
                $warnings[] = 'Gap of ' . round($spanHours) . 'h since previous reading; energy attributed to the reading day.';
            }
            Db::transaction(function () use ($meterId, $day, $delta, $source) {
                $existing = Db::one('SELECT id, energy_kwh FROM consumption_records WHERE meter_id = ? AND period_start = ?', [$meterId, $day]);
                if ($existing) {
                    Db::run('UPDATE consumption_records SET energy_kwh = energy_kwh + ?, source = ? WHERE id = ?',
                        [$delta, $source, $existing['id']]);
                } else {
                    Db::insert('consumption_records', [
                        'meter_id' => $meterId, 'period_start' => $day, 'period_end' => $day,
                        'energy_kwh' => $delta, 'source' => $source, 'created_at' => Db::now(),
                    ]);
                }
            });
        }

        return [
            'reading' => Db::one('SELECT * FROM meter_readings WHERE id = ?', [$id]),
            'warnings' => $warnings,
        ];
    }

    /** Daily consumption series for a meter within a date range (UTC days). */
    public static function dailySeries(int $meterId, string $from, string $to): array
    {
        return Db::all(
            'SELECT period_start AS day, ROUND(SUM(energy_kwh),3) AS kwh, MIN(source) AS source
               FROM consumption_records
              WHERE meter_id = ? AND period_start BETWEEN ? AND ?
              GROUP BY period_start ORDER BY period_start',
            [$meterId, $from, $to]
        );
    }

    public static function summarize(int $meterId, int $days): array
    {
        $from = gmdate('Y-m-d', strtotime("-$days days"));
        $rows = self::dailySeries($meterId, $from, gmdate('Y-m-d'));
        return [
            'days' => $days,
            'total_kwh' => round(array_sum(array_column($rows, 'kwh')), 3),
            'per_day' => $rows,
        ];
    }

    /**
     * Prepaid credit estimate: purchases − consumption×tariff.
     * Clearly an ESTIMATE based on the demo tariff until verified.
     */
    public static function creditEstimate(int $meterId): array
    {
        $tariff = Settings::num('tariff_per_kwh_tzs', 400);
        $purchases = (float) Db::val(
            "SELECT COALESCE(SUM(amount),0) FROM token_transactions WHERE meter_id = ? AND status = 'recorded'",
            [$meterId]
        );
        $units = (float) Db::val(
            'SELECT COALESCE(SUM(energy_kwh),0) FROM consumption_records WHERE meter_id = ?',
            [$meterId]
        );
        $spentValue = $units * $tariff;
        return [
            'estimated_credit_tzs' => round($purchases - $spentValue, 2),
            'total_purchases_tzs' => round($purchases, 2),
            'total_units_consumed_kwh' => round($units, 3),
            'estimated_value_consumed_tzs' => round($spentValue, 2),
            'tariff_per_kwh_tzs' => $tariff,
            'assumption' => 'Demo tariff — not a verified TANESCO tariff. Credit is an estimate from simulated data.',
        ];
    }

    /** Money spent vs units consumed vs estimated cost (expenditure view). */
    public static function expenditure(int $meterId): array
    {
        $credit = self::creditEstimate($meterId);
        $purchasesByMonth = Db::all(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, ROUND(SUM(amount),2) AS amount_tzs, COUNT(*) AS purchases
               FROM token_transactions WHERE meter_id = ? AND status = 'recorded' GROUP BY month ORDER BY month",
            [$meterId]
        );
        if (Db::driver() === 'sqlite') {
            $purchasesByMonth = Db::all(
                "SELECT strftime('%Y-%m', created_at) AS month, ROUND(SUM(amount),2) AS amount_tzs, COUNT(*) AS purchases
                   FROM token_transactions WHERE meter_id = ? AND status = 'recorded' GROUP BY month ORDER BY month",
                [$meterId]
            );
        }
        return $credit + ['purchases_by_month' => $purchasesByMonth];
    }
}
