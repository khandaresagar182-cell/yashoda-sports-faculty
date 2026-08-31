<?php
/**
 * Initialize a blank database with the current schema, seed, and migrations.
 *
 * Railway startup:
 *   php scripts/init_database.php --if-empty
 *
 * Manual initialization:
 *   php scripts/init_database.php --confirm
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ifEmpty = in_array('--if-empty', $argv, true);
$confirmed = in_array('--confirm', $argv, true);

if (!$ifEmpty && !$confirmed) {
    fwrite(STDERR, "Refusing to initialize without --if-empty or --confirm.\n");
    exit(2);
}

require_once __DIR__ . '/../includes/db.php';

$conn = db();

function table_exists(mysqli $conn, string $table): bool
{
    $escaped = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '$escaped'");
    if ($result === false) {
        throw new RuntimeException("Unable to inspect table: $table");
    }
    $exists = mysqli_num_rows($result) > 0;
    mysqli_free_result($result);
    return $exists;
}

function apply_sql_file(mysqli $conn, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Unable to read ' . basename($file));
    }

    // Railway creates MYSQLDATABASE for us. Keep every statement inside that
    // database instead of creating or selecting the local "csf_portal" name.
    $sql = preg_replace(
        '/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+`csf_portal`\s+DEFAULT\s+CHARACTER\s+SET\s+utf8mb4\s+COLLATE\s+utf8mb4_unicode_ci\s*;/i',
        '',
        $sql
    );
    $sql = preg_replace('/^\s*USE\s+`?csf_portal`?\s*;\s*$/im', '', $sql);

    if (!mysqli_multi_query($conn, $sql)) {
        throw new RuntimeException(
            'Database initialization failed in ' . basename($file) . ': ' . mysqli_error($conn)
        );
    }

    do {
        $result = mysqli_store_result($conn);
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }
        if (!mysqli_more_results($conn)) {
            break;
        }
        if (!mysqli_next_result($conn)) {
            throw new RuntimeException(
                'Database initialization failed in ' . basename($file) . ': ' . mysqli_error($conn)
            );
        }
    } while (true);

    echo 'Applied ' . basename($file) . "\n";
}

$existing = mysqli_query($conn, "SHOW TABLES LIKE 'faculty'");
if ($existing === false) {
    throw new RuntimeException('Unable to inspect the target database.');
}

if (mysqli_num_rows($existing) > 0) {
    mysqli_free_result($existing);

    if (!table_exists($conn, 'final_teams')) {
        apply_sql_file($conn, __DIR__ . '/../sql/migration-v9-final-teams.sql');
    }
    if (!table_exists($conn, 'jersey_forms') || !table_exists($conn, 'jersey_requests')) {
        apply_sql_file($conn, __DIR__ . '/../sql/migration-v13-jersey.sql');
    }
    apply_sql_file($conn, __DIR__ . '/../sql/migration-v14-fix-faculty-departments.sql');

    echo "Existing database checked; required feature tables are ready.\n";
    exit(0);
}
mysqli_free_result($existing);

$files = [
    __DIR__ . '/../sql/schema.sql',
    __DIR__ . '/../sql/seed.ready.sql',
    __DIR__ . '/../sql/migration-v2.sql',
    __DIR__ . '/../sql/migration-v5.sql',
    __DIR__ . '/../sql/migration-v6-docs.sql',
    __DIR__ . '/../sql/migration-v8-fix-document-names.sql',
    __DIR__ . '/../sql/migration-v9-final-teams.sql',
    __DIR__ . '/../sql/migration-v10-student-mother-name.sql',
    __DIR__ . '/../sql/migration-v11-student-roll-no.sql',
    __DIR__ . '/../sql/migration-v12-transfer-pharmacy.sql',
    __DIR__ . '/../sql/migration-v13-jersey.sql',
    __DIR__ . '/../sql/migration-v14-fix-faculty-departments.sql',
];

foreach ($files as $file) {
    apply_sql_file($conn, $file);
}

echo "Database initialization completed.\n";
