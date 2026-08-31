<?php
/**
 * One-time department visibility diagnostic.
 *
 * Shows departments, faculty, faculty_departments links so we can see
 * why faculty-select.php shows no cards.
 *
 * Delete with `git rm db_dept_check.php`.
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/includes/db.php';

echo "db_dept_check: connected\n\n";

echo "=== departments (active) ===\n";
$depts = db_select('SELECT id, code, name, full_name, is_active, display_order FROM departments ORDER BY display_order');
foreach ($depts as $d) {
    printf("  %2d  %-12s  %-20s  active=%d  order=%d\n",
        $d['id'], $d['code'], $d['name'], $d['is_active'], $d['display_order']);
}

echo "\n=== faculty ===\n";
$fac = db_select('SELECT id, username, full_name, role FROM faculty ORDER BY id');
foreach ($fac as $f) {
    printf("  %2d  %-15s  %-30s  %s\n", $f['id'], $f['username'], $f['full_name'], $f['role']);
}

echo "\n=== faculty_departments ===\n";
$fd = db_select(
    'SELECT fd.faculty_id, fd.department_id, f.username, d.code
       FROM faculty_departments fd
       JOIN faculty f ON f.id = fd.faculty_id
       JOIN departments d ON d.id = fd.department_id
      ORDER BY f.username, d.code'
);
if (!$fd) {
    echo "  (no rows)\n";
} else {
    foreach ($fd as $r) {
        printf("  faculty_id=%d  dept_id=%d  %-15s -> %s\n",
            $r['faculty_id'], $r['department_id'], $r['username'], $r['code']);
    }
}

echo "\ndb_dept_check: done. DELETE db_dept_check.php.\n";