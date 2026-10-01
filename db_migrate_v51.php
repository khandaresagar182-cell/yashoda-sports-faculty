<?php
/**
 * ONE-TIME incremental migration runner for v51 (External Entries:
 * external_entry_links, external_pending_verifications,
 * external_students, external_student_documents, external_team_entries,
 * eligibility_archive.is_external).
 *
 * db_setup.php refuses to re-run once `students` exists unless ?reset=1,
 * which drops and recreates every table — unusable once a live DB holds
 * real student data. This script instead applies exactly
 * sql/migration-v51-external-entries.sql. Every statement in it is
 * idempotent, so it's safe to hit twice.
 *
 * Gated by the same DB_SETUP_TOKEN env var db_setup.php already uses — no
 * new secret to configure. Self-deletes on success so it doesn't linger
 * as a one-off debug script at the web root (see DEPLOY.md "What NOT to
 * do").
 *
 * Usage: https://your-site/db_migrate_v51.php?t=YOUR_DB_SETUP_TOKEN
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
    echo "db_migrate_v51: DB_SETUP_TOKEN env var is not set on this host.\n";
    exit;
}
$given = (string)($_GET['t'] ?? '');
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    echo "db_migrate_v51: forbidden (wrong or missing token).\n";
    exit;
}

/* ---------------- run migration-v51 only ---------------- */

$path = __DIR__ . '/sql/migration-v51-external-entries.sql';
if (!is_file($path)) {
    echo "db_migrate_v51: missing $path\n";
    exit(1);
}
$sql = file_get_contents($path);
if ($sql === false) {
    echo "db_migrate_v51: failed to read $path\n";
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
echo "==> running migration-v51-external-entries.sql\n";

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

$expectedTables = [
    'external_entry_links',
    'external_pending_verifications',
    'external_students',
    'external_student_documents',
    'external_team_entries',
];

$allOk = true;
echo "\n--- verification ---\n";
foreach ($expectedTables as $t) {
    $rows = db_select(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$t], 's'
    );
    $ok = !empty($rows);
    $allOk = $allOk && $ok;
    echo "$t: " . ($ok ? "OK\n" : "MISSING\n");
}

$hasIsExternal = db_select(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eligibility_archive' AND COLUMN_NAME = 'is_external'"
);
$isExternalOk = !empty($hasIsExternal);
$allOk = $allOk && $isExternalOk;
echo "eligibility_archive.is_external: " . ($isExternalOk ? "OK\n" : "MISSING\n");

if (!$allOk) {
    echo "\ndb_migrate_v51: verification failed — leaving file in place, investigate.\n";
    exit(1);
}

echo "\ndb_migrate_v51: ALL DONE. Self-deleting this file.\n";
@unlink(__FILE__);
