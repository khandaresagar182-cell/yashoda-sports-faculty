<?php
/**
 * Read-only verification of the csf_portal database.
 *
 * Lists tables and shows row counts for the seed data. Safe to leave in
 * the repo (read-only) or delete via `git rm`.
 *
 * Usage: GET /db_verify.php
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/includes/db.php';

echo "db_verify: connected to " . DB_NAME . " on " . DB_HOST . "\n\n";

echo "=== tables ===\n";
$tables = db_select("SHOW TABLES");
foreach ($tables as $row) {
    echo "  - " . reset($row) . "\n";
}

echo "\n=== row counts (key tables) ===\n";
$countQueries = [
    'departments'              => 'departments',
    'dept_game_catalog'        => 'dept_game_catalog',
    'faculty'                  => 'faculty',
    'dept_document_requirements' => 'dept_document_requirements',
    'students'                 => 'students',
    'student_documents'        => 'student_documents',
    'student_selected_games'   => 'student_selected_games',
];
foreach ($countQueries as $label => $table) {
    try {
        $r = db_one("SELECT COUNT(*) AS c FROM `$table`");
        $n = $r ? (int)$r['c'] : '(missing)';
        echo str_pad($label, 30) . " = $n\n";
    } catch (Throwable $e) {
        echo str_pad($label, 30) . " = ERR: " . $e->getMessage() . "\n";
    }
}

echo "\ndb_verify: done. Safe to delete with `git rm db_verify.php`.\n";