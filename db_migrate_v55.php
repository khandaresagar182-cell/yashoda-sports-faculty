<?php
/**
 * ONE-TIME incremental migration runner for v55 (adds `committee_members`,
 * backing an admin-editable Sports Committee section on the homepage —
 * see sql/migration-v55-committee-members.sql).
 *
 * Same shape as db_migrate_v54.php: applies exactly
 * sql/migration-v55-committee-members.sql, idempotent, gated by
 * DB_SETUP_TOKEN, self-deletes on success.
 *
 * Usage: https://your-site/db_migrate_v55.php?t=YOUR_DB_SETUP_TOKEN
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
    echo "db_migrate_v55: DB_SETUP_TOKEN env var is not set on this host.\n";
    exit;
}
$given = (string)($_GET['t'] ?? '');
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    echo "db_migrate_v55: forbidden (wrong or missing token).\n";
    exit;
}

/* ---------------- run migration-v55 only ---------------- */

$path = __DIR__ . '/sql/migration-v55-committee-members.sql';
if (!is_file($path)) {
    echo "db_migrate_v55: missing $path\n";
    exit(1);
}
$sql = file_get_contents($path);
if ($sql === false) {
    echo "db_migrate_v55: failed to read $path\n";
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
echo "==> running migration-v55-committee-members.sql\n";

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
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'committee_members'"
);
$ok = !empty($exists) && (int)$exists['n'] === 1;

$count = $ok ? (int)(db_one('SELECT COUNT(*) AS n FROM committee_members')['n'] ?? 0) : 0;

echo "\n--- verification ---\n";
echo "committee_members table: " . ($ok ? "OK\n" : "MISSING\n");
echo "committee_members rows:  $count\n";

if (!$ok) {
    echo "\ndb_migrate_v55: verification failed — leaving file in place, investigate.\n";
    exit(1);
}

echo "\ndb_migrate_v55: ALL DONE. Self-deleting this file.\n";
@unlink(__FILE__);
