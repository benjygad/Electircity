<?php
/**
 * Seeds clearly-labelled DEMONSTRATION data (academic prototype).
 * All seeded accounts use @sems.test emails so reset_demo.php can target them.
 *
 *   php scripts/seed_demo.php
 */
declare(strict_types=1);

if (!defined('SEMS_CLI')) { define('SEMS_CLI', true); require dirname(__DIR__) . '/src/bootstrap.php'; }

use Sems\Core\Db;
use Sems\Services\{AnomalyService, ConsumptionService, NotificationService};

echo "== SEMS demo seed ==\n";

// ---------------------------------------------------------------- users
$demoPassword = password_hash('Consumer@123', PASSWORD_DEFAULT);
$adminPassword = password_hash('Admin@SEMS123', PASSWORD_DEFAULT);

function upsertUser(string $name, string $email, string $hash, string $role, ?string $phone): int {
    $existing = Db::val('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) return (int)$existing;
    return Db::insert('users', [
        'full_name' => $name, 'email' => $email, 'phone' => $phone,
        'password_hash' => $hash, 'role' => $role, 'status' => 'active',
        'created_at' => Db::now(), 'updated_at' => Db::now(),
    ]);
}

$adminId  = upsertUser('SEMS Administrator', 'admin@sems.test', $adminPassword, 'admin', '+255780000000');
$neemaId  = upsertUser('Neema Massawe', 'neema@sems.test', $demoPassword, 'consumer', '+255754111222');
$josephId = upsertUser('Joseph Mushi', 'joseph@sems.test', $demoPassword, 'consumer', '+255767333444');
$aminaId  = upsertUser('Amina Juma', 'amina@sems.test', $demoPassword, 'consumer', '+255713555666');
echo "Users ready (admin id=$adminId).\n";

// ---------------------------------------------------------------- meters
function upsertMeter(string $number, string $type, string $location, string $status): int {
    $existing = Db::val('SELECT id FROM meters WHERE meter_number = ?', [$number]);
    if ($existing) return (int)$existing;
    return Db::insert('meters', [
        'meter_number' => $number, 'meter_type' => $type, 'service_location' => $location,
        'integration_status' => $status, 'created_at' => Db::now(), 'updated_at' => Db::now(),
    ]);
}
function assignMeter(int $userId, int $meterId): void {
    if (!Db::val('SELECT id FROM user_meters WHERE user_id = ? AND meter_id = ?', [$userId, $meterId])) {
        Db::insert('user_meters', ['user_id' => $userId, 'meter_id' => $meterId, 'relationship' => 'owner', 'created_at' => Db::now()]);
    }
}

$m1 = upsertMeter('07210001234', 'single_phase_prepaid', 'Kaloleni, Arusha', 'simulated');
$m2 = upsertMeter('07210005678', 'single_phase_prepaid', 'Njiro, Arusha', 'simulated');
$m3 = upsertMeter('07210009012', 'three_phase_prepaid', 'Dar es Salaam Rd, Arusha', 'simulated');
$m4 = upsertMeter('07210003456', 'single_phase_prepaid', 'Sokon II, Arusha', 'integration_unavailable');
assignMeter($neemaId, $m1); assignMeter($neemaId, $m2);
assignMeter($josephId, $m3);
assignMeter($aminaId, $m4);
echo "Meters ready (4 seeded, 1 deliberately without integration).\n";

// ---------------------------------------------------------------- readings (35 days, simulated)
$days = 35;
function seedHistory(int $meterId, float $base, float $jitter, bool $flatLast3, bool $spikeLast): void {
    if (Db::val('SELECT COUNT(*) FROM meter_readings WHERE meter_id = ?', [$meterId]) > 0) return; // idempotent
    $cumulative = 1000.0 + random_int(0, 500);
    $start = strtotime("-$GLOBALS[days] days 06:00 UTC");
    for ($i = 0; $i <= $GLOBALS['days']; $i++) {
        $t = strtotime("+$i days", $start);
        if ($i > 0) {
            if ($flatLast3 && $i > $GLOBALS['days'] - 3) {
                $delta = 0.0;                                  // zero-consumption scenario
            } elseif ($spikeLast && $i === $GLOBALS['days']) {
                $delta = $base * 5.5;                        // consumption spike scenario
            } else {
                $delta = max(0, $base + $jitter * (mt_rand() / mt_getrandmax() - 0.5) * 2);
            }
            $cumulative += $delta;
        }
        ConsumptionService::addReading($meterId, gmdate('Y-m-d H:i:s', $t), round($cumulative, 3), 'simulated', 230.4, round($delta / 24, 2));
    }
}
seedHistory($m1, 4.2, 1.6, false, false);
seedHistory($m2, 2.1, 0.8, false, false);
seedHistory($m3, 6.5, 2.4, true, false);   // Joseph: unexpected zero usage (alert)
seedHistory($m4, 3.4, 1.2, false, true);   // Amina: spike on last day (alert)
echo "35 days of simulated readings + consumption records created.\n";

// ---------------------------------------------------------------- purchases (simulated, masked refs)
function seedPurchase(int $meterId, float $amount, string $daysAgo): void {
    Db::insert('token_transactions', [
        'meter_id' => $meterId, 'amount' => $amount,
        'token_reference' => '****-****-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT),
        'transaction_type' => 'purchase', 'status' => 'recorded', 'source' => 'simulated',
        'created_at' => gmdate('Y-m-d H:i:s', strtotime($daysAgo)),
    ]);
}
if ((int)Db::val('SELECT COUNT(*) FROM token_transactions') === 0) {
    seedPurchase($m1, 50000, '-30 days'); seedPurchase($m1, 20000, '-12 days');
    seedPurchase($m2, 10000, '-20 days');
    seedPurchase($m3, 100000, '-33 days');
    seedPurchase($m4, 20000, '-28 days'); // small purchase + spike => low credit later
    echo "Token purchase records created (simulated, refs masked).\n";
}

// ---------------------------------------------------------------- fault reports
if ((int)Db::val('SELECT COUNT(*) FROM fault_reports') === 0) {
    $fr1 = Db::insert('fault_reports', [
        'user_id' => $josephId, 'meter_id' => $m3, 'category' => 'power_outage',
        'description' => 'Power has been off in our area since yesterday evening. Neighbours are also affected.',
        'status' => 'in_progress', 'assigned_to' => $adminId, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days')), 'updated_at' => Db::now(),
    ]);
    Db::insert('support_messages', ['report_id' => $fr1, 'sender_id' => $adminId, 'message' => 'Thank you Joseph. We logged this with the feeder team — this is a simulated demo response.', 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day'))]);
    $fr2 = Db::insert('fault_reports', [
        'user_id' => $aminaId, 'meter_id' => $m4, 'category' => 'suspected_incorrect_reading',
        'description' => 'My meter display seems to be counting faster than usual this week.',
        'status' => 'submitted', 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-5 hours')), 'updated_at' => Db::now(),
    ]);
    echo "Fault reports created.\n";
}

// ---------------------------------------------------------------- run detection once + low credit
AnomalyService::scanAll();
NotificationService::maybeLowCredit($m4);
echo "Anomaly rules executed; notifications generated for genuine events only.\n";

echo "\nDemo accounts (DEMONSTRATION ONLY — change/remove for any real deployment):\n";
echo "  admin:    admin@sems.test  / Admin@SEMS123\n";
echo "  consumer: neema@sems.test  / Consumer@123   (meters: 07210001234, 07210005678)\n";
echo "  consumer: joseph@sems.test / Consumer@123   (meter:  07210009012)\n";
echo "  consumer: amina@sems.test  / Consumer@123   (meter:  07210003456)\n";
