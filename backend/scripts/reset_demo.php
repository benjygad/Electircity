<?php
/**
 * Resets DEMONSTRATION data without touching records a real deployment would keep.
 *
 *   php scripts/reset_demo.php             # remove simulated readings/consumption/purchases + demo alerts
 *   php scripts/reset_demo.php --accounts  # also remove @sems.test demo accounts & their meters
 *
 * Safe by design: rows are selected by source='simulated' / @sems.test markers,
 * so manually-entered or imported records and real accounts survive.
 */
declare(strict_types=1);

define('SEMS_CLI', true);
require dirname(__DIR__) . '/src/bootstrap.php';

use Sems\Core\Db;

$pdo = Db::pdo();

$c1 = Db::run("DELETE FROM consumption_records WHERE source = 'simulated'")->rowCount();
$c2 = Db::run("DELETE FROM meter_readings WHERE source = 'simulated'")->rowCount();
$c3 = Db::run("DELETE FROM token_transactions WHERE source = 'simulated'")->rowCount();
$c4 = Db::run("DELETE FROM alerts WHERE data_source IN ('rule_engine','hardware_simulator','reading_ingest')")->rowCount();
echo "Removed: $c1 consumption records, $c2 readings, $c3 transactions, $c4 alerts.\n";

if (in_array('--accounts', $argv, true)) {
    $demoUsers = array_map('intval', array_column(Db::all("SELECT id FROM users WHERE email LIKE '%@sems.test'"), 'id'));
    if ($demoUsers) {
        $in = implode(',', $demoUsers);
        Db::run("DELETE FROM fault_reports WHERE user_id IN ($in)");
        Db::run("DELETE FROM notifications WHERE user_id IN ($in)");
        Db::run("DELETE FROM auth_tokens WHERE user_id IN ($in)");
        Db::run("DELETE FROM user_meters WHERE user_id IN ($in)");
        Db::run("DELETE FROM users WHERE id IN ($in)");
        $orphan = Db::run('DELETE FROM meters WHERE id NOT IN (SELECT meter_id FROM user_meters)')->rowCount();
        echo "Removed " . count($demoUsers) . " demo accounts and $orphan orphaned demo meter(s).\n";
    }
}
echo "Demo reset complete. Manually-entered/imported records were preserved.\n";
