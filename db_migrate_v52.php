<?php
/**
 * ONE-TIME incremental migration runner for v52 (External Students:
 * ssc/hsc/diploma passing years, first-admission years, gap year —
 * the columns admin/external_final_export_docx.php and
 * admin/external_final_export_pdf.php already read but that migration
 * v51 never added).
 *
 * Same shape as db_migrate_v51.php: applies exactly
 * sql/migration-v52-external-academic-fields.sql, every statement in it
 * is idempotent, gated by DB_SETUP_TOKEN, self-deletes on success.
 *
 * Usage: https://your-site/db_migrate_v52.php?t=YOUR_DB_SETUP_TOKEN
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
    echo "db_migrate_v52: DB_SETUP_TOKEN env var is not set on this host.\n";
    exit;
}
$given = (string)($_GET['t'] ?? '');
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    echo "db_migrate_v52: forbidden (wrong or missing token).\n";
    exit;
}

/* ---------------- run migration-v52 only ---------------- */

$path = __DIR__ . '/sql/migration-v52-external-academic-fields.sql';
if (!is_file($path)) {
    echo "db_migrate_v52: missing $path\n";
    exit(1);
}
$sql = file_get_contents($path);
if ($sql === false) {
    echo "db_migrate_v52: failed to read $path\n";
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
echo "==> running migration-v52-external-academic-fields.sql\n";

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

$expectedColumns = [
    'ssc_passing_year',
    'hsc_passing_year',
    'diploma_passing_year',
    'first_admission_university_year',
    'first_admission_course_year',
    'first_admission_class_year',
    'has_gap_year',
    'gap_year_detail',
];

$allOk = true;
echo "\n--- verification ---\n";
foreach ($expectedColumns as $col) {
    $rows = db_select(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'external_students' AND COLUMN_NAME = ?",
        [$col], 's'
    );
    $ok = !empty($rows);
    $allOk = $allOk && $ok;
    echo "external_students.$col: " . ($ok ? "OK\n" : "MISSING\n");
}

if (!$allOk) {
    echo "\ndb_migrate_v52: verification failed — leaving file in place, investigate.\n";
    exit(1);
}

echo "\ndb_migrate_v52: ALL DONE. Self-deleting this file.\n";
@unlink(__FILE__);
