<?php
/**
 * SEMS integration test suite.
 *
 *   php tests/run_tests.php
 *
 * Boots a private API instance (SQLite, port 8123) with a fresh database,
 * then exercises the API end-to-end over HTTP. Repeatable: each run resets
 * the test database. Does not touch the demo/development MySQL database.
 */
declare(strict_types=1);

use Sems\Core\Validate;

$root = dirname(__DIR__, 2);
$backend = dirname(__DIR__);
$port = 8123;
$base = "http://127.0.0.1:$port/api/v1";

$env = array_merge(getenv(), [
    'DB_DRIVER' => 'sqlite',
    'DB_SQLITE_PATH' => $backend . '/storage/sems_test.sqlite',
    'RATE_LIMIT_MAX' => '100000',
    'APP_DEBUG' => '1',
    'APP_ENV' => 'development',
]);

// ---------------------------------------------------------------- fresh DB
@unlink($env['DB_SQLITE_PATH']);
passthru('cd ' . escapeshellarg($backend) .
    ' && DB_DRIVER=sqlite DB_SQLITE_PATH=' . escapeshellarg($env['DB_SQLITE_PATH']) .
    ' php scripts/install.php > /dev/null 2>&1', $rc);
if ($rc !== 0) { fwrite(STDERR, "install.php failed\n"); exit(1); }

// ---------------------------------------------------------------- start server
$cmd = sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($root . '/server.php'));
$proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
if (!is_resource($proc)) { fwrite(STDERR, "Could not start test server\n"); exit(1); }

$up = false;
for ($i = 0; $i < 50; $i++) {
    usleep(200_000);
    $h = @file_get_contents("$base/health");
    if ($h !== false) { $up = true; break; }
}
if (!$up) { fwrite(STDERR, "Test server did not start\n"); proc_terminate($proc); exit(1); }

// ---------------------------------------------------------------- harness
$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; $failures[] = $name; echo "  FAIL  $name" . ($detail ? "  [$detail]" : '') . "\n"; }
}

function http(string $method, string $path, mixed $body = null, ?string $token = null): array
{
    global $base;
    $ch = curl_init("$base$path");
    $headers = ['Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($token) $headers[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, json_decode($raw ?: '{}', true) ?: []];
}

function token_for(string $email, string $password): ?string
{
    [$s, $j] = http('POST', '/auth/login', ['email' => $email, 'password' => $password]);
    return $j['data']['token'] ?? null;
}

echo "\n=== SEMS integration tests ===\n\n[1] Authentication\n";

[$s, $j] = http('POST', '/auth/register', ['full_name' => 'Test Consumer A', 'email' => 'a@test.local', 'password' => 'Passw0rd1', 'phone' => '+255754000001']);
check('consumer registration returns 201', $s === 201, "got $s");
check('registered user role is consumer', ($j['data']['user']['role'] ?? '') === 'consumer');
$userA = (int)($j['data']['user']['id'] ?? 0);

[$s, $j] = http('POST', '/auth/register', ['full_name' => 'Escalation Attempt', 'email' => 'evil@test.local', 'password' => 'Passw0rd1', 'role' => 'admin']);
check('client-supplied role=admin is ignored (still consumer)', ($j['data']['user']['role'] ?? '') === 'consumer');

[$s, $j] = http('POST', '/auth/register', ['full_name' => 'X', 'email' => 'not-an-email', 'password' => 'short']);
check('invalid registration rejected with field errors', $s === 400 && isset($j['error']['fields']['email'], $j['error']['fields']['password']));

[$s, $j] = http('POST', '/auth/register', ['full_name' => 'Dup', 'email' => 'a@test.local', 'password' => 'Passw0rd1']);
check('duplicate email rejected (409)', $s === 409);

[$s, $j] = http('POST', '/auth/login', ['email' => 'a@test.local', 'password' => 'WRONGpass1']);
check('wrong password rejected (401)', $s === 401);

$tokA = token_for('a@test.local', 'Passw0rd1');
check('valid login issues bearer token', is_string($tokA) && strlen($tokA) > 20);

[$s, $j] = http('POST', '/auth/register', ['full_name' => 'Test Consumer B', 'email' => 'b@test.local', 'password' => 'Passw0rd1']);
$tokB = $j['data']['token'] ?? null;
$userB = $j['data']['user']['id'] ?? null;

[$s, $j] = http('GET', '/auth/me', null, $tokA);
check('/auth/me returns the authenticated user', ($j['data']['email'] ?? '') === 'a@test.local');
[$s, $j] = http('GET', '/auth/me');
check('request without token gets 401', $s === 401);
[$s, $j] = http('GET', '/auth/me', null, 'garbage-token');
check('garbage token gets 401', $s === 401);

// ---------------------------------------------------------------- admin via CLI script (secure path)
$adminOut = shell_exec('cd ' . escapeshellarg($backend) . ' && ' .
    'DB_DRIVER=sqlite DB_SQLITE_PATH=' . escapeshellarg($env['DB_SQLITE_PATH']) .
    " php scripts/create_admin.php --email=root@test.local --name='Test Admin' --password='R00tAdmin1' 2>&1");
check('create_admin.php CLI creates initial admin', str_contains((string)$adminOut, 'Administrator created'));
$tokAdmin = token_for('root@test.local', 'R00tAdmin1');
check('admin login works', is_string($tokAdmin));
[$s, $me] = http('GET', '/auth/me', null, $tokAdmin);
$adminId = (int)($me['data']['id'] ?? 0);

echo "\n[2] RBAC\n";
[$s, ] = http('GET', '/customers', null, $tokA);
check('consumer blocked from /customers (403)', $s === 403);
[$s, ] = http('GET', '/alerts', null, $tokA);
check('consumer blocked from /alerts (403)', $s === 403);
[$s, ] = http('GET', '/reports/consumption', null, $tokA);
check('consumer blocked from reports (403)', $s === 403);
[$s, ] = http('GET', '/customers', null, $tokAdmin);
check('admin allowed on /customers', $s === 200);

echo "\n[3] Customer & meter management\n";
[$s, $j] = http('POST', '/customers', ['full_name' => 'Registered By Admin', 'email' => 'c@test.local', 'password' => 'Passw0rd1', 'phone' => '+255754000002'], $tokAdmin);
check('admin can register a customer', $s === 201);
$userC = $j['data']['id'] ?? null;

[$s, $j] = http('POST', '/meters', ['meter_number' => 'bad!', 'service_location' => 'X'], $tokAdmin);
check('invalid meter number rejected', $s === 400 && isset($j['error']['fields']['meter_number']));

[$s, $j] = http('POST', '/meters', ['meter_number' => '07991112223', 'service_location' => 'Test Site A'], $tokAdmin);
check('meter registration works', $s === 201);
[$s, $j] = http('POST', '/meters', ['meter_number' => '07991112223', 'service_location' => 'Dup'], $tokAdmin);
check('duplicate meter number rejected (409)', $s === 409);

[$s, $j] = http('POST', '/meters', ['meter_number' => '07991110001', 'meter_type' => 'single_phase_prepaid', 'service_location' => 'Moshi road', 'integration_status' => 'simulated', 'user_id' => null], $tokAdmin);
check('admin can register a meter', $s === 201);
$meterA = $j['data']['id'] ?? null;

[$s, $j] = http('POST', '/meters', ['meter_number' => '07991110002', 'service_location' => 'Test Site B', 'integration_status' => 'simulated'], $tokAdmin);
$meterB = $j['data']['id'] ?? null;

[$s, ] = http('POST', "/meters/$meterA/assign", ['user_id' => $userA], $tokAdmin);
check('meter assignment to customer works', $s === 201);
[$s, ] = http('POST', "/meters/$meterA/assign", ['user_id' => $userA], $tokAdmin);
check('re-assigning same meter+customer rejected (409)', $s === 409);
[$s, ] = http('POST', "/meters/$meterB/assign", ['user_id' => $userB], $tokAdmin);
check('meter B assigned to consumer B', $s === 201);

[$s, $j] = http('GET', '/meters', null, $tokA);
check('consumer sees only their own meters', $s === 200 && count($j['data']) === 1 && $j['data'][0]['meter_number'] === '07991110001');

echo "\n[4] Readings & consumption math\n";
[$s, $j] = http('POST', "/meters/$meterA/readings", ['reading_time' => '2026-10-01T06:00:00', 'cumulative_kwh' => 1000.0, 'source' => 'simulated'], $tokAdmin);
check('first reading accepted', $s === 201);
[$s, $j] = http('POST', "/meters/$meterA/readings", ['reading_time' => '2026-10-02T06:00:00', 'cumulative_kwh' => 1005.0, 'source' => 'simulated'], $tokAdmin);
check('second reading accepted', $s === 201);
[$s, $j] = http('POST', "/meters/$meterA/readings", ['reading_time' => '2026-10-03T06:00:00', 'cumulative_kwh' => 1009.5, 'source' => 'manual'], $tokAdmin);
check('third reading accepted', $s === 201);

[$s, $j] = http('GET', "/meters/$meterA/consumption?from=2026-10-01&to=2026-10-03", null, $tokA);
check('consumption = end − start (5 + 4.5 kWh)', $s === 200 && abs($j['data']['total_kwh'] - 9.5) < 0.001, 'got ' . ($j['data']['total_kwh'] ?? 'n/a'));

[$s, $j] = http('POST', "/meters/$meterA/readings", ['reading_time' => '2026-10-04T06:00:00', 'cumulative_kwh' => 900.0, 'source' => 'simulated'], $tokAdmin);
check('decreasing cumulative reading rejected (422)', $s === 422);
[$s, $j] = http('GET', '/alerts?meter_id=' . $meterA, null, $tokAdmin);
$types = array_column($j['data'] ?? [], 'alert_type');
check('data_integrity alert raised for decreasing reading', in_array('data_integrity', $types, true));

[$s, $j] = http('POST', "/meters/$meterA/readings", ['reading_time' => '2026-10-02T06:00:00', 'cumulative_kwh' => 1005.0], $tokAdmin);
check('duplicate reading timestamp rejected (409)', $s === 409);

[$s, $j] = http('POST', "/meters/$meterA/readings", ['reading_time' => 'garbage', 'cumulative_kwh' => 5], $tokAdmin);
check('invalid reading_time rejected (400)', $s === 400);

[$s, ] = http('POST', "/meters/$meterA/readings", ['reading_time' => '2026-10-05T06:00:00', 'cumulative_kwh' => 1012.0], $tokA);
check('consumer cannot ingest readings (admin only)', $s === 403);

echo "\n[5] Token transactions (simulated, masked)\n";
[$s, $j] = http('POST', "/meters/$meterA/transactions", ['amount' => 20000, 'token' => '1111 2222 3333 4444 5555'], $tokA);
check('consumer records simulated purchase', $s === 201);
check('token stored masked only', ($j['data']['transaction']['token_reference'] ?? '') === '****-****-5555');
check('response states token NOT sent to physical meter', str_contains($j['data']['notice'] ?? '', 'NOT sent'));

[$s, $j] = http('POST', "/meters/$meterA/transactions", ['amount' => -50], $tokA);
check('negative amount rejected (400)', $s === 400);
[$s, $j] = http('POST', "/meters/$meterA/transactions", ['amount' => 100, 'token' => 'abc'], $tokA);
check('malformed token rejected (400)', $s === 400);

[$s, $j] = http('GET', "/meters/$meterA/transactions?status=recorded", null, $tokA);
check('transaction history filterable by status', $s === 200 && count($j['data']) >= 1);

[$s, ] = http('GET', "/meters/$meterB/transactions", null, $tokA);
check("consumer A blocked from consumer B's transactions (403)", $s === 403);

echo "\n[6] Anomaly detection rules\n";
// Build a stable baseline then a spike on meter B.
$cum = 500.0;
for ($d = 1; $d <= 8; $d++) {
    $t = sprintf('2026-09-%02dT06:00:00', $d);
    $cum += 3.0;
    http('POST', "/meters/$meterB/readings", ['reading_time' => $t, 'cumulative_kwh' => $cum, 'source' => 'simulated'], $tokAdmin);
}
[$s, $j] = http('POST', "/meters/$meterB/readings", ['reading_time' => '2026-09-09T06:00:00', 'cumulative_kwh' => $cum + 30.0, 'source' => 'simulated'], $tokAdmin);
check('spike reading accepted', $s === 201);
[$s, $j] = http('GET', '/alerts?meter_id=' . $meterB, null, $tokAdmin);
$spikes = array_filter($j['data'] ?? [], fn($a) => $a['alert_type'] === 'consumption_spike');
check('consumption_spike alert detected vs baseline', count($spikes) === 1);
$alertId = array_values($spikes)[0]['id'] ?? null;

[$s, $j] = http('POST', "/alerts/$alertId", null, $tokAdmin);
[$s, $j] = http('PATCH', "/alerts/$alertId", ['status' => 'under_investigation', 'admin_notes' => 'Checking seals'], $tokAdmin);
check('alert review status updated', $s === 200 && $j['data']['status'] === 'under_investigation');
[$s, ] = http('PATCH', "/alerts/$alertId", ['status' => 'bogus'], $tokAdmin);
check('invalid alert status rejected (400)', $s === 400);

echo "\n[7] Fault reports & support messaging\n";
[$s, $j] = http('POST', '/fault-reports', ['category' => 'power_outage', 'description' => 'No power since morning.', 'meter_id' => $meterA], $tokA);
check('consumer submits fault report', $s === 201);
$frId = $j['data']['id'] ?? null;

[$s, $j] = http('POST', '/fault-reports', ['category' => 'not_a_category', 'description' => 'x'], $tokA);
check('invalid category rejected (400)', $s === 400);

[$s, $j] = http('GET', '/fault-reports', null, $tokB);
check('consumer B does not see consumer A reports', $s === 200 && count(array_filter($j['data'], fn($r) => $r['id'] === $frId)) === 0);

[$s, ] = http('GET', "/fault-reports/$frId", null, $tokB);
check('consumer B blocked from foreign report (403)', $s === 403);

[$s, $j] = http('PATCH', "/fault-reports/$frId", ['status' => 'in_progress', 'assigned_to' => $adminId], $tokAdmin);
check('admin updates report status', $s === 200 && $j['data']['status'] === 'in_progress');

[$s, $j] = http('POST', "/fault-reports/$frId/messages", ['message' => 'Please check the breaker first.'], $tokAdmin);
check('admin replies on report', $s === 201);
[$s, $j] = http('POST', "/fault-reports/$frId/messages", ['message' => 'Breaker is fine, still no power.'], $tokA);
check('consumer replies on report', $s === 201);
[$s, $j] = http('GET', "/fault-reports/$frId/messages", null, $tokA);
check('message thread has both sides', $s === 200 && count($j['data']) === 2);

echo "\n[8] Notifications\n";
[$s, $j] = http('GET', '/notifications', null, $tokA);
check('consumer receives notifications', $s === 200 && $j['meta']['total'] >= 2);
$nid = $j['data'][0]['id'] ?? null;
[$s, $j] = http('PATCH', "/notifications/$nid", [], $tokA);
check('notification marked read', $s === 200 && $j['data']['read_at'] !== null);
[$s, $j] = http('GET', '/notifications', null, $tokB);
$nb = $j['data'][0]['id'] ?? null;
[$s, $j] = http('PATCH', "/notifications/$nb", [], $tokA);
check("consumer A cannot mark B's notification (403)", $s === 403);

echo "\n[9] Reports, settings & audit\n";
[$s, $j] = http('GET', '/reports/consumption?from=2026-09-01&to=2026-10-31', null, $tokAdmin);
check('consumption report works', $s === 200 && $j['data']['grand_total_kwh'] > 0);
[$s, $j] = http('GET', '/reports/transactions', null, $tokAdmin);
check('transactions report works', $s === 200);
[$s, $j] = http('GET', '/reports/faults', null, $tokAdmin);
check('faults report works', $s === 200);

[$s, $j] = http('PATCH', '/admin/settings', ['tariff_per_kwh_tzs' => 450], $tokAdmin);
check('admin updates tariff setting', $s === 200);
[$s, $j] = http('PATCH', '/admin/settings', ['tariff_per_kwh_tzs' => 450], $tokA);
check('consumer blocked from settings (403)', $s === 403);

[$s, $j] = http('GET', '/admin/audit-logs?limit=50', null, $tokAdmin);
$actions = array_column($j['data'] ?? [], 'action');
check('audit log records admin actions', $s === 200 && in_array('meter_create', $actions, true) && in_array('settings_update', $actions, true));

echo "\n[10] Validation & unit checks\n";
[$s, $j] = http('POST', '/auth/login', 'not-an-array', $tokAdmin);
check('non-JSON body rejected gracefully', $s === 400);
[$s, $j] = http('GET', '/no-such-endpoint', null, $tokAdmin);
check('unknown endpoint returns 404', $s === 404);
[$s, $j] = http('POST', '/auth/logout', [], $tokB);
check('logout revokes token', $s === 200);
[$s, $j] = http('GET', '/auth/me', null, $tokB);
check('revoked token rejected (401)', $s === 401);

// small pure-PHP unit checks
require $backend . '/src/Core/HttpException.php';
require $backend . '/src/Core/Validate.php';
check('maskToken keeps last 4 only', Validate::maskToken('1234-5678-9012-3456-7890') === '****-****-7890');
check('meterNumber validates LUKU style', Validate::meterNumber('07210001234') && !Validate::meterNumber('12345'));
check('phone validates TZ format', Validate::phone('+255754111222') && !Validate::phone('0754'));
check('password rule enforced', Validate::password('Passw0rd1') && !Validate::password('password'));

// ---------------------------------------------------------------- done
proc_terminate($proc);
echo "\n=== Result: $pass passed, $fail failed ===\n";
if ($failures) { echo "Failed: " . implode('; ', $failures) . "\n"; exit(1); }
exit(0);
