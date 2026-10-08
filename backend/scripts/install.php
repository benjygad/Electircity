<?php
/**
 * SEMS installer: applies the schema for the configured driver.
 *   php scripts/install.php            # apply schema
 *   php scripts/install.php --seed     # apply schema + demo data
 *
 * For MySQL: creates nothing outside the already-configured database.
 * For SQLite: creates the storage file automatically.
 */
declare(strict_types=1);

define('SEMS_CLI', true);
require dirname(__DIR__) . '/src/bootstrap.php';

use Sems\Core\{Db, Env};

$driver = Env::get('DB_DRIVER', 'mysql');
$file = dirname(__DIR__, 2) . '/database/schema_' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '.sql';
if (!is_file($file)) { fwrite(STDERR, "Schema file not found: $file\n"); exit(1); }

$sql = file_get_contents($file);
// Strip '--' comment lines first so comment-prefixed statements are not skipped.
$sql = implode("\n", array_filter(explode("\n", $sql), fn($l) => !str_starts_with(ltrim($l), '--')));
// Split top-level statements on ';' at end of line (schema has no embedded ';' in strings).
$statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
$pdo = Db::pdo();
$count = 0;
foreach ($statements as $st) {
    if ($st === '' || str_starts_with($st, '--')) continue;
    $pdo->exec($st);
    $count++;
}
echo "Applied $count statements from " . basename($file) . " (driver: $driver)\n";

if (in_array('--seed', $argv, true)) {
    require __DIR__ . '/seed_demo.php';
}
