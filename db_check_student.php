<?php
/**
 * One-time student-login diagnostic.
 *
 * Run:  https://yashodasportsfaculty.me/db_check_student.php
 * (Optionally: ?email=foo@bar.com to inspect a specific row)
 *
 * Shows: how many students exist, whether the columns the login code
 *        requires are present, and what the row looks like.
 *
 * Delete this file once login works. Safe — read-only.
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/includes/db.php';

echo "db_check_student: connected to " . DB_NAME . "\n\n";

echo "=== students row count ===\n";
$count = db_one('SELECT COUNT(*) AS n FROM students');
printf("  %d students in DB\n\n", (int)($count['n'] ?? 0));

echo "=== students columns (must include email, password_hash, is_active) ===\n";
$cols = db_select("SHOW COLUMNS FROM students");
$want = ['email', 'password_hash', 'is_active', 'is_self_registered', 'registered_at'];
$found = [];
foreach ($cols as $c) {
    $found[$c['Field']] = $c;
    $mark = in_array($c['Field'], $want, true) ? '  *' : '   ';
    printf("  %s %-25s %s\n", $mark, $c['Field'], $c['Type']);
}
foreach ($want as $w) {
    if (!isset($found[$w])) {
        echo "  *** MISSING column: $w ***\n";
    }
}
echo "\n";

echo "=== indexes on students (must include uq_student_email) ===\n";
$idx = db_select("SHOW INDEX FROM students");
$seen = [];
foreach ($idx as $i) {
    $key = $i['Key_name'];
    if (!isset($seen[$key])) {
        $seen[$key] = [];
    }
    $seen[$key][] = $i['Column_name'];
}
foreach ($seen as $name => $cols_list) {
    $mark = ($name === 'uq_student_email') ? '  *' : '   ';
    printf("  %s %-25s (%s)\n", $mark, $name, implode(', ', $cols_list));
}
if (!isset($seen['uq_student_email'])) {
    echo "  *** MISSING unique index uq_student_email on email ***\n";
}
echo "\n";

$email = isset($_GET['email']) ? strtolower(trim((string)$_GET['email'])) : '';
if ($email) {
    echo "=== student row matching email = '$email' ===\n";
    $row = db_one('SELECT id, email, full_name, is_active, is_self_registered,
                          password_hash, registered_at, last_login_at,
                          LENGTH(password_hash) AS hash_len
                     FROM students WHERE email = ?', [$email], 's');
    if (!$row) {
        echo "  *** NO ROW FOUND for this email ***\n";
    } else {
        foreach ($row as $k => $v) {
            if ($k === 'password_hash') {
                printf("  %-20s = %s... (%d chars)\n", $k, substr((string)$v, 0, 30), strlen((string)$v));
            } else {
                printf("  %-20s = %s\n", $k, var_export($v, true));
            }
        }
        echo "\n  password_verify test with DOB-style passwords:\n";
        foreach (['15082004', '01012000', '31121999'] as $test_pw) {
            $ok = password_verify($test_pw, (string)$row['password_hash']);
            echo "    password_verify(\"$test_pw\", hash) = " . ($ok ? 'TRUE' : 'false') . "\n";
        }
    }
    echo "\n";
}

echo "=== last 5 registered students ===\n";
$recent = db_select('SELECT id, email, full_name, is_active, is_self_registered,
                            registered_at, LENGTH(password_hash) AS hash_len
                       FROM students ORDER BY registered_at DESC, id DESC LIMIT 5');
foreach ($recent as $r) {
    printf("  id=%-3d  email=%-25s  active=%d  self_reg=%d  hash_len=%d  reg=%s\n",
        $r['id'], $r['email'], $r['is_active'], $r['is_self_registered'],
        $r['hash_len'], $r['registered_at'] ?? 'NULL');
}

echo "\ndb_check_student: done. DELETE this file once login is verified.\n";