<?php
/**
 * ONE-TIME incremental migration runner for v54 (adds
 * `student_password_resets`, backing an emailed reset-link flow for
 * students — see sql/migration-v54-student-password-resets.sql).
 *
 * Same shape as db_migrate_v53.php: applies exactly
 * sql/migration-v54-student-password-resets.sql, idempotent,
 * gated by DB_SETUP_TOKEN, self-deletes on success.
 *
 * Usage: https://your-site/db_migrate_v54.php?t=YOUR_DB_SETUP_TOKEN
 */

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

/* ---------------- token gate ---------------- */

$expected = getenv('DB_SETUP_TOKEN');
if (!$expected) {
    http_response_code(503);
    echo "db_migrate_v54: DB_SETUP_TOKEN env var is not set on this host.\n";
    exit;
}
$given = (string)($_GET['t'] ?? '');
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    echo "db_migrate_v54: forbidden (wrong or missing token).\n";
    exit;
}

/* ---------------- run migration-v54 only ---------------- */

$path = __DIR__ . '/sql/migration-v54-student-password-resets.sql';
if (!is_file($path)) {
    echo "db_migrate_v54: missing $path\n";
    exit(1);
}
$sql = file_get_contents($path);
if ($sql === false) {
    echo "db_migrate_v54: failed to read $path\n";
    exit(1);
}

// Same rewrite db_setup.php does: the file hardcodes `csf_portal` in its
// USE statement, but cPanel forces the DB name to <user>_csfportal.
if (DB_NAME !== 'csf_portal') {
    $sql = preg_replace(
        '/(USE\s+`?)csf_portal(`?\s*;)/i',
        '$1' . str_replace('`', '', DB_NAME) . '$2',
        $sql
    );
}

$conn = db();
echo "==> connected to " . DB_NAME . "\n";
echo "==> running migration-v54-student-password-resets.sql\n";

if (!mysqli_multi_query($conn, $sql)) {
    echo "FAILED: " . mysqli_error($conn) . "\n";
    exit(1);
}
do {
    $res = mysqli_store_result($conn);
    if ($res instanceof mysqli_result) {
        mysqli_free_result($res);
    }
} while (mysqli_next_result($conn));

if (mysqli_errno($conn) !== 0) {
    echo "FAILED mid-stream: " . mysqli_error($conn) . "\n";
    exit(1);
}
echo "    OK\n";

/* ---------------- verify ---------------- */

$exists = db_one(
    "SELECT COUNT(*) AS n FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_password_resets'"
);
$ok = !empty($exists) && (int)$exists['n'] === 1;

echo "\n--- verification ---\n";
echo "student_password_resets table: " . ($ok ? "OK\n" : "MISSING\n");

if (!$ok) {
    echo "\ndb_migrate_v54: verification failed — leaving file in place, investigate.\n";
    exit(1);
}

echo "\ndb_migrate_v54: ALL DONE. Self-deleting this file.\n";
@unlink(__FILE__);
