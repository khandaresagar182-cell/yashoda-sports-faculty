<?php
/**
 * ONE-TIME incremental migration runner for v49 (simple jersey details:
 * students.jersey_number / jersey_size columns, drop jersey_forms /
 * jersey_requests, bump already-submitted students' form_step 6 -> 7).
 *
 * db_setup.php refuses to re-run once `students` exists unless ?reset=1,
 * which drops and recreates every table — unusable once a live DB holds
 * real student data. This script instead applies exactly
 * sql/migration-v49-simple-jersey-details.sql and nothing else. Every
 * statement in it is idempotent, so it's safe to hit twice.
 *
 * Gated by the same DB_SETUP_TOKEN env var db_setup.php already uses — no
 * new secret to configure. Self-deletes on success so it doesn't linger
 * as a one-off debug script at the web root (see DEPLOY.md "What NOT to
 * do").
 *
 * Usage: https://your-site/db_migrate_v49.php?t=YOUR_DB_SETUP_TOKEN
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
    echo "db_migrate_v49: DB_SETUP_TOKEN env var is not set on this host.\n";
    exit;
}
$given = (string)($_GET['t'] ?? '');
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    echo "db_migrate_v49: forbidden (wrong or missing token).\n";
    exit;
}

/* ---------------- run migration-v49 only ---------------- */

$path = __DIR__ . '/sql/migration-v49-simple-jersey-details.sql';
if (!is_file($path)) {
    echo "db_migrate_v49: missing $path\n";
    exit(1);
}
$sql = file_get_contents($path);
if ($sql === false) {
    echo "db_migrate_v49: failed to read $path\n";
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
echo "==> running migration-v49-simple-jersey-details.sql\n";

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

$hasNumber = db_select(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'jersey_number'"
);
$hasSize = db_select(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'jersey_size'"
);
$formsGone = db_select("SHOW TABLES LIKE 'jersey_forms'");
$reqsGone  = db_select("SHOW TABLES LIKE 'jersey_requests'");

$numberOk = !empty($hasNumber);
$sizeOk   = !empty($hasSize);
$formsOk  = empty($formsGone);
$reqsOk   = empty($reqsGone);

echo "\n--- verification ---\n";
echo "students.jersey_number:       " . ($numberOk ? "OK\n" : "MISSING\n");
echo "students.jersey_size:         " . ($sizeOk ? "OK\n" : "MISSING\n");
echo "jersey_forms table dropped:   " . ($formsOk ? "OK\n" : "STILL PRESENT\n");
echo "jersey_requests table dropped:" . ($reqsOk ? " OK\n" : " STILL PRESENT\n");

if (!$numberOk || !$sizeOk || !$formsOk || !$reqsOk) {
    echo "\ndb_migrate_v49: verification failed — leaving file in place, investigate.\n";
    exit(1);
}

echo "\ndb_migrate_v49: ALL DONE. Self-deleting this file.\n";
@unlink(__FILE__);
