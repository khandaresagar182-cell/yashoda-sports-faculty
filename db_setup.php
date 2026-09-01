<?php
/**
 * ONE-TIME database setup script.
 *
 * Loads sql/schema.sql, sql/seed.ready.sql and every sql/migration-v*.sql
 * against the configured DB. The script is intended to be hit ONCE after first
 * deployment of the App to a fresh managed database, then DELETED
 * from the repo. It is gated by a token (DB_SETUP_TOKEN env var) so
 * that an accidental public hit cannot wipe a live database.
 *
 * Safety:
 *  - If the `students` table already exists, this script refuses to
 *    run (it would DROP and re-create everything). The user must
 *    DELETE the file once the setup is verified.
 *  - The token check runs BEFORE any DB query, so a wrong token is
 *    a no-op.
 *  - Errors are echoed with file:line precision so you can debug.
 *
 * Usage (after deploying):
 *   https://your-app.ondigitalocean.app/db_setup.php?t=YOUR_TOKEN
 *
 * Cleanup:
 *   After verifying SHOW TABLES is populated, delete this file from
 *   the repo and re-deploy (or `git rm db_setup.php`).
 */

declare(strict_types=1);

// Force display of all errors during setup, regardless of APP_ENV.
// The whole point of this one-time script is to surface the cause.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/bootstrap.php';

/* ---------------- token gate ---------------- */

$expected = getenv('DB_SETUP_TOKEN');
if (!$expected) {
    // No token set at all -> refuse. Force the operator to set one.
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "db_setup: DB_SETUP_TOKEN env var is not set on the App.\n";
    echo "Set it under Settings -> Environment Variables, redeploy, then retry.\n";
    exit;
}
$given = (string)($_GET['t'] ?? '');
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "db_setup: forbidden (wrong or missing token).\n";
    exit;
}

/* ---------------- "already initialized" guard ---------------- */

header('Content-Type: text/plain; charset=utf-8');

// On DigitalOcean managed MySQL the only DB is `defaultdb`. We need to
// connect to it first, create csf_portal, then reconnect.
// On GoDaddy (and most shared hosts) the configured DB already exists —
// we connect to it directly and skip the CREATE DATABASE step.
$dbName = DB_NAME;

echo "==> opening server-level connection ...\n";
$tmp = mysqli_init();
if ($tmp === false) {
    echo "FAILED to init mysqli\n"; exit(1);
}
$use_ssl = (APP_ENV !== 'local') || getenv('DB_SSL') === 'true';
if ($use_ssl) {
    @mysqli_ssl_set($tmp, null, null, null, null, null);
}
// Only set the SSL client flag if SSL is actually requested. MYSQLI_CLIENT_SSL
// is *defined* on any mysqli build that supports SSL — its value is 4 — but
// passing it as a flag forces SSL, which GoDaddy's mysqli stream cannot do
// (their builds lack OpenSSL linked into the stream).
$sslFlag = ($use_ssl && defined('MYSQLI_CLIENT_SSL')) ? MYSQLI_CLIENT_SSL : 0;
// Belt-and-braces: explicitly tell mysqli not to try SSL when we don't want it.
// Available since PHP 8.1; falls back silently if the constant is missing.
if (!$use_ssl && defined('MYSQLI_SSL_MODE_DISABLED')) {
    @mysqli_options($tmp, 101 /* MYSQLI_OPT_SSL_MODE */, 0 /* DISABLED */);
}

// Try connecting to the configured DB first (works on GoDaddy, DO managed
// MySQL after first run, and most shared hosts). If that fails because the
// DB doesn't exist yet, fall back to `defaultdb` (DigitalOcean's initial DB).
$ok = false;
$err = '';
$tryDbs = [$dbName, 'defaultdb'];
foreach ($tryDbs as $tryDb) {
    $ok = mysqli_real_connect(
        $tmp, DB_HOST, DB_USER, DB_PASS, $tryDb, DB_PORT, null, $sslFlag
    );
    if ($ok) {
        echo "    OK — connected to `$tryDb`\n";
        break;
    }
    $err = mysqli_connect_error();
}
if (!$ok) {
    echo "FAILED to connect to any of: " . implode(', ', $tryDbs) . "\n";
    echo "Last error: $err\n";
    exit(1);
}

// Now $tmp is connected to either $dbName (already exists) or 'defaultdb'
// (DO managed MySQL bootstrap). Discover which one and conditionally CREATE.
$currentDbResult = mysqli_query($tmp, "SELECT DATABASE()");
$currentDbRow = $currentDbResult ? mysqli_fetch_row($currentDbResult) : null;
if ($currentDbResult instanceof mysqli_result) mysqli_free_result($currentDbResult);
$currentDb = $currentDbRow[0] ?? '';

if ($currentDb === $dbName) {
    echo "==> $dbName already exists.\n";
} else {
    // We're on `defaultdb` (or similar). Create the real DB.
    echo "==> $dbName does not exist on this server. Creating it ...\n";
    $createSql = "CREATE DATABASE `$dbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    if (!mysqli_query($tmp, $createSql)) {
        echo "FAILED to create $dbName: " . mysqli_error($tmp) . "\n";
        exit(1);
    }
    echo "    OK — created $dbName\n";
}
mysqli_close($tmp);

// Now the regular db() connection to csf_portal will succeed.
$conn = db();
echo "==> connected to " . DB_NAME . "\n";

// SHOW TABLES does not accept LIMIT — use db_select and inspect first row.
// (db_one() auto-appends LIMIT 1, which fails on SHOW statements.)
$check = db_select("SHOW TABLES LIKE 'students'");
if (!empty($check)) {
    if (empty($_GET['reset'])) {
        echo "db_setup: students table already exists. Refusing to re-run.\n";
        echo "If you want a fresh start, pass ?reset=1 along with the token.\n";
        echo "Otherwise, delete db_setup.php from the repo and redeploy.\n";
        exit;
    }
    echo "==> ?reset=1 passed — dropping all tables and re-running from scratch\n";
    $conn2 = db();
    // Disable FK checks for the wipe. SHOW TABLES returns rows in arbitrary
    // order, so a child table may be dropped first (which works) or a parent
    // table may be dropped first (which fails with "foreign key constraint
    // fails"). Disabling checks lets MySQL drop them in any order; we turn
    // checks back on immediately after so the schema.sql CREATE TABLEs run
    // with FK enforcement in effect (which catches schema mistakes).
    db_execute("SET FOREIGN_KEY_CHECKS = 0");
    $tables = db_select("SHOW TABLES");
    foreach ($tables as $row) {
        $name2 = reset($row);
        // SHOW TABLES returns table name as the only column.
        $t = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$name2);
        if ($t === '') continue;
        @db_execute("DROP TABLE IF EXISTS `$t`");
    }
    db_execute("SET FOREIGN_KEY_CHECKS = 1");
    echo "    OK — all tables dropped\n";
    $conn2 = null;
    $check = [];
}

/* ---------------- run the three SQL files ---------------- */

$sqlDir = __DIR__ . '/sql';
$files  = [
    // Base schema + seed data. seed.ready.sql is the canonical production seed
    // (the older sql/seed.sql demo seed was removed in the cleanup pass).
    'schema.sql',
    'seed.ready.sql',
    // All migrations in version order
    'migration-v2.sql',
    'migration-v3.sql',
    'migration-v4.sql',
    'migration-v5.sql',
    'migration-v6-docs.sql',
    'migration-v7-dpharm-docs.sql',
    'migration-v8-fix-document-names.sql',
    'migration-v9-final-teams.sql',
    'migration-v10-student-mother-name.sql',
    'migration-v11-student-roll-no.sql',
    'migration-v12-transfer-pharmacy.sql',
    'migration-v13-jersey.sql',
    'migration-v14-fix-faculty-departments.sql',
    'migration-v15-rename-pharmacy.sql',
    'migration-v16-add-ytc-pharmacy.sql',
    'migration-v18-consolidate-management.sql',
    'migration-v19-form-submitted-at.sql',
    'migration-v20-enrollment-nullable.sql',
    'migration-v21-has-played-in-college.sql',
    'migration-v22-game-catalog.sql',
    'migration-v23-eng-pharm-game-catalog.sql',
    'migration-v24-mgmt-arch-games.sql',
    'migration-v26-pdf-only-docs.sql',
    'migration-v27-passport-photo.sql',
    'migration-v28-admission-ssc-hsc-diploma-years.sql',
    'migration-v29-department-name.sql',
    'migration-v30-permanent-current-address.sql',
    'migration-v31-gap-year.sql',
    'migration-v32-participation-levels.sql',
    'migration-v33-gap-certificate-conditional.sql',
    'migration-v34-standardize-document-requirements.sql',
    'migration-v35-gender-split-lists.sql',
    'migration-v36-jersey-gender.sql',
    'migration-v37-course-duration.sql',
    'migration-v38-edit-unlocked.sql',
    'migration-v39-whatsapp-no.sql',
    'migration-v40-father-name.sql',
    'migration-v41-aadhar-number.sql',
    'migration-v42-first-admission-years.sql',
    'migration-v43-unified-game-catalog.sql',
    'migration-v44-bank-details.sql',
    'migration-v45-eligibility-archive.sql',
    'migration-v46-heal-document-requirements.sql',
    'migration_student_auth.sql',
];

$conn = db(); // fresh handle, charset already set in db()

foreach ($files as $name) {
    $path = $sqlDir . '/' . $name;
    if (!is_file($path)) {
        echo "db_setup: missing file $path\n";
        exit(1);
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        echo "db_setup: failed to read $path\n";
        exit(1);
    }

    // The committed schema.sql hardcodes `csf_portal` because DigitalOcean
    // managed MySQL lets you pick the DB name. On GoDaddy the DB name is
    // forced to `<cpaneluser>_csfportal`. Rewrite CREATE DATABASE / USE
    // statements on the fly so the same SQL files work on both hosts.
    if ($dbName !== 'csf_portal') {
        $sql = preg_replace(
            '/(CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+`?)csf_portal(`?)/i',
            '$1' . str_replace('`', '', $dbName) . '$2',
            $sql
        );
        $sql = preg_replace(
            '/(USE\s+`?)csf_portal(`?\s*;)/i',
            '$1' . str_replace('`', '', $dbName) . '$2',
            $sql
        );
    }

    // Migrations are append-only and were written for empty DBs. But seed.ready.sql
    // already populates faculty_departments and other seedable tables, so the
    // early migrations (v3, v4, v14, v16) can fail with "Duplicate entry" on
    // re-runs of schema+seed. Rewrite their INSERTs to INSERT IGNORE so they
    // become idempotent without changing the committed SQL files. Skip the
    // base seed/schema files — those should fail loudly if there's a real
    // problem, not silently swallow duplicates.
    if (strpos($name, 'migration-') === 0 || strpos($name, 'migration_') === 0) {
        $sql = preg_replace(
            '/^INSERT\s+INTO\b/im',
            'INSERT IGNORE INTO',
            $sql
        );
    }

    echo "==> $name\n";

    // mysqli_multi_query is the only way to run multiple statements from
    // a single string in PHP. We iterate result sets to drain them all.
    if (!mysqli_multi_query($conn, $sql)) {
        echo "    FAILED: " . mysqli_error($conn) . "\n";
        exit(1);
    }
    do {
        $res = mysqli_store_result($conn);
        if ($res instanceof mysqli_result) {
            // Some statements (CREATE, INSERT) return no result set, but
            // SHOW TABLES returns one. Drain it.
            mysqli_free_result($res);
        }
    } while (mysqli_next_result($conn));

    if (mysqli_errno($conn) !== 0) {
        echo "    FAILED mid-stream: " . mysqli_error($conn) . "\n";
        exit(1);
    }
    echo "    OK\n";
}

/* ---------------- post-run verification ---------------- */

echo "\n--- verification ---\n";

$tables = db_select("SHOW TABLES");
echo "Tables in csf_portal:\n";
foreach ($tables as $row) {
    // SHOW TABLES returns a single column whose key is the literal table name.
    $first = reset($row);
    echo "  - $first\n";
}

$counts = db_select(
    "SELECT
        (SELECT COUNT(*) FROM departments)        AS dept_count,
        (SELECT COUNT(*) FROM dept_game_catalog)  AS game_count,
        (SELECT COUNT(*) FROM faculty)            AS faculty_count,
        (SELECT COUNT(*) FROM dept_document_requirements) AS doc_req_count"
);
if ($counts) {
    $c = $counts[0];
    echo "\nRow counts:\n";
    echo "  departments:              $c[dept_count]\n";
    echo "  dept_game_catalog:        $c[game_count]\n";
    echo "  faculty:                  $c[faculty_count]\n";
    echo "  dept_document_requirements: $c[doc_req_count]\n";
}

echo "\ndb_setup: done. DELETE db_setup.php from the repo now.\n";