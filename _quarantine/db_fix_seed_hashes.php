<?php
/**
 * ONE-TIME seed-hash fix.
 *
 * Re-syncs the canonical seed-user (admin / eng_faculty / poly_faculty /
 * pharm_faculty) password hashes to match the published passwords
 * (Admin@123 / Faculty@123).
 *
 * Gated by DB_SETUP_TOKEN so it can't be hit accidentally.
 *
 * Why this exists: when sql/seed.ready.sql is imported onto a fresh
 * managed database, the bcrypt hashes from the seed file may not match
 * what `password_verify('Faculty@123', $hash)` expects — usually because
 * the hashes were generated on a different PHP build with slightly
 * different algorithm params, or because the seed file predates a
 * password format change.
 *
 * After this runs, the seed users log in with the documented passwords.
 *
 * Usage: GET /db_fix_seed_hashes.php?t=<your DB_SETUP_TOKEN>
 *
 * Delete from repo after success: `git rm db_fix_seed_hashes.php`.
 */

declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

$expected = getenv('DB_SETUP_TOKEN');
if (!$expected) {
    http_response_code(503);
    echo "db_fix_seed_hashes: DB_SETUP_TOKEN env var is not set.\n"; exit;
}
$given = (string)($_GET['t'] ?? '');
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    echo "db_fix_seed_hashes: forbidden.\n"; exit;
}

$users = [
    ['username' => 'admin',         'password' => 'Admin@123'],
    ['username' => 'eng_faculty',   'password' => 'Faculty@123'],
    ['username' => 'poly_faculty',  'password' => 'Faculty@123'],
    ['username' => 'pharm_faculty', 'password' => 'Faculty@123'],
    // dpharm_faculty was added in migration-v16 with the YTC pharmacy dept.
    ['username' => 'dpharm_faculty','password' => 'Faculty@123'],
];

echo "==> Updating seed-user password hashes on " . DB_NAME . "@" . DB_HOST . "\n\n";

foreach ($users as $u) {
    $hash = password_hash($u['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $n = db_execute(
        'UPDATE faculty SET password_hash = ?, updated_at = created_at WHERE username = ?',
        [$hash, $u['username']],
        'ss'
    );
    printf("  %-15s updated, affected=%d\n", $u['username'], $n);
}

echo "\n==> Verifying:\n";
$rows = db_select('SELECT username, password_hash FROM faculty ORDER BY username');
foreach ($rows as $r) {
    $username = $r['username'];
    $hash     = $r['password_hash'];
    $expected_pass = ($username === 'admin') ? 'Admin@123' : 'Faculty@123';
    $ok = password_verify($expected_pass, $hash);
    printf("  %-15s %s verify = %s\n", $username, $expected_pass, $ok ? 'YES' : 'NO');
}

echo "\ndb_fix_seed_hashes: done. DELETE db_fix_seed_hashes.php from the repo.\n";