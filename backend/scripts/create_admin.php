<?php
/**
 * SECURE initial-administrator creation (no hardcoded credentials anywhere).
 *
 * Usage:
 *   php scripts/create_admin.php --email=root@example.com --name="Root Admin" --password='S3cure!pass'
 *
 * If --password is omitted you are prompted interactively (hidden input where
 * the terminal supports it). The password is never written to any file.
 */
declare(strict_types=1);

define('SEMS_CLI', true);
require dirname(__DIR__) . '/src/bootstrap.php';

use Sems\Core\{Audit, Db};
use Sems\Controllers\AuthController;

$opts = getopt('', ['email:', 'name:', 'password:']);
$email = strtolower(trim($opts['email'] ?? ''));
$name = trim($opts['name'] ?? '');
$password = $opts['password'] ?? null;

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Valid --email is required.\n"); exit(1); }
if ($name === '') { fwrite(STDERR, "--name is required.\n"); exit(1); }

if ($password === null) {
    if (function_exists('readline')) {
        echo 'Password (min 8 chars, letter+number): ';
        system('stty -echo 2>/dev/null');
        $password = trim((string)fgets(STDIN));
        system('stty echo 2>/dev/null');
        echo "\n";
    } else {
        fwrite(STDERR, "Provide --password=... in non-interactive environments.\n"); exit(1);
    }
}
if (!\Sems\Core\Validate::password($password)) {
    fwrite(STDERR, "Password must be at least 8 characters with a letter and a number.\n"); exit(1);
}

if (Db::val('SELECT id FROM users WHERE email = ?', [$email])) {
    fwrite(STDERR, "A user with that email already exists.\n"); exit(1);
}

$id = Db::insert('users', [
    'full_name' => $name,
    'email' => $email,
    'phone' => null,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'role' => 'admin',
    'status' => 'active',
    'created_at' => Db::now(),
    'updated_at' => Db::now(),
]);
Audit::log($id, 'admin_created_cli', 'user', $id, 'Created via scripts/create_admin.php');
echo "Administrator created (id=$id, email=$email). Store the password in a password manager.\n";
