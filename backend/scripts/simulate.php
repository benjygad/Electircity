<?php
/**
 * Development-only meter simulator. NOT a TANESCO interface.
 * Generates clearly-labelled simulated readings and events.
 *
 * Usage:
 *   php scripts/simulate.php                       # advance every simulated meter by 1 day
 *   php scripts/simulate.php --days=3              # advance by 3 days
 *   php scripts/simulate.php --meter=07210001234 --scenario=spike|zero|outage|tamper
 *
 * Scenarios:
 *   spike   – one day of abnormally high consumption
 *   zero    – a day with zero consumption
 *   outage  – no reading emitted (meter appears silent)
 *   tamper  – explicit tamper event raised as if sent by compatible hardware
 */
declare(strict_types=1);

define('SEMS_CLI', true);
require dirname(__DIR__) . '/src/bootstrap.php';

use Sems\Core\Db;
use Sems\Services\{AnomalyService, ConsumptionService, NotificationService};

$opts = getopt('', ['days::', 'meter::', 'scenario::']);
$days = max(1, (int)($opts['days'] ?? 1));
$meterNumber = $opts['meter'] ?? null;
$scenario = $opts['scenario'] ?? null;
if ($scenario !== null && !in_array($scenario, ['spike', 'zero', 'outage', 'tamper'], true)) {
    fwrite(STDERR, "Unknown scenario '$scenario'\n"); exit(1);
}

$where = "WHERE integration_status IN ('simulated','connected')";
$params = [];
if ($meterNumber) { $where .= ' AND meter_number = ?'; $params[] = $meterNumber; }

$meters = Db::all("SELECT * FROM meters $where", $params);
if (!$meters) { fwrite(STDERR, "No matching meters.\n"); exit(1); }

foreach ($meters as $meter) {
    $meterId = (int)$meter['id'];
    $last = Db::one('SELECT * FROM meter_readings WHERE meter_id = ? ORDER BY reading_time DESC LIMIT 1', [$meterId]);
    $cumulative = $last ? (float)$last['cumulative_kwh'] : 1000.0;
    $cursor = $last ? strtotime($last['reading_time']) : strtotime('-1 day 06:00 UTC');

    if ($scenario === 'tamper') {
        AnomalyService::raise($meterId, 'tamper_event', 'critical',
            'Tamper signal reported for meter ' . $meter['meter_number'] . ' by the (simulated) hardware gateway. Inspect meter seals.',
            'hardware_simulator');
        echo "[tamper] event raised for {$meter['meter_number']}\n";
        continue;
    }

    for ($d = 1; $d <= $days; $d++) {
        $cursor = strtotime('+1 day 06:00 UTC', $cursor);
        $timeUtc = gmdate('Y-m-d H:i:s', $cursor);

        if ($scenario === 'outage') {
            echo "[outage] no reading emitted for {$meter['meter_number']} on " . substr($timeUtc, 0, 10) . "\n";
            continue;
        }
        if ($scenario === 'zero') { $delta = 0.0; }
        elseif ($scenario === 'spike') { $delta = 25.0; }
        else { $delta = max(0, 3.5 + 2.5 * (mt_rand() / mt_getrandmax() - 0.3)); }

        $cumulative += $delta;
        try {
            ConsumptionService::addReading($meterId, $timeUtc, round($cumulative, 3), 'simulated', 229.8, round($delta / 24, 2));
            echo "[reading] {$meter['meter_number']} " . substr($timeUtc, 0, 10) . " +" . round($delta, 2) . " kWh\n";
        } catch (\Throwable $e) {
            echo "[skip] {$meter['meter_number']}: {$e->getMessage()}\n";
        }
    }
}

$summary = AnomalyService::scanAll();
foreach ($meters as $meter) NotificationService::maybeLowCredit((int)$meter['id']);
echo "Anomaly scan complete: {$summary['alerts_created']} new alert(s) across {$summary['meters_scanned']} meter(s).\n";
