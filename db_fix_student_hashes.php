<?php
/**
 * One-time student-hash re-hash.
 *
 * Regenerates a bcrypt password_hash for any student whose hash is
 * missing or shorter than 50 chars (corrupted by an old bind bug).
 * The new password is the student's DOB in DDMMYYYY format.
 *
 * Run:  https://yashodasportsfaculty.me/db_fix_student_hashes.php
 *   Default: fixes all students where LENGTH(password_hash) < 50.
 *
 * Optional:
 *   ?id=11            — fix only student id=11
 *   ?email=x@y.z      — fix only this email
 *   ?dry=1            — show what would change without writing
 *
 * Delete this file after running.
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/includes/db.php';

echo "db_fix_student_hashes: connected to " . DB_NAME . "\n\n";

$dry = isset($_GET['dry']);
$byId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$byEmail = isset($_GET['email']) ? strtolower(trim((string)$_GET['email'])) : null;

$where = [];
$params = [];
$types  = '';
if ($byId) {
    $where[] = 'id = ?';
    $params[] = $byId;
    $types   .= 'i';
}
if ($byEmail) {
    $where[] = 'email = ?';
    $params[] = $byEmail;
    $types   .= 's';
}
// Default: target corrupted rows only (LENGTH < 50 means the old bind bug truncated).
$where[] = 'CHAR_LENGTH(COALESCE(password_hash, "")) < 50';

$sql = 'SELECT id, email, full_name, dob, is_active, is_self_registered,
               LENGTH(password_hash) AS hash_len
          FROM students
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY id';
$rows = db_select($sql, $params, $types);
if (!$rows) {
    echo "No matching students (corrupted-hash filter found nothing).\n";
    if (!$byId && !$byEmail) {
        echo "That means your hashes are fine. If login still fails, the issue is elsewhere.\n";
    }
    echo "\ndb_fix_student_hashes: done.\n";
    exit;
}

echo ($dry ? "(DRY RUN — no writes)\n" : "") . "Found " . count($rows) . " student(s):\n\n";
printf("  %-4s  %-25s  %-12s  %s\n", 'id', 'email', 'dob', 'hash_len');

$fixed = 0;
foreach ($rows as $r) {
    printf("  %-4d  %-25s  %-12s  %d\n",
        $r['id'], $r['email'], $r['dob'], $r['hash_len']);

    // dob is YYYY-MM-DD or YYYY-M-D. Normalize.
    $ts = strtotime((string)$r['dob']);
    if (!$ts) {
        echo "      SKIP — could not parse dob\n";
        continue;
    }
    $plain = date('dmY', $ts);
    $hash  = password_hash($plain, PASSWORD_BCRYPT);

    if ($dry) {
        echo "      would set password_hash = " . substr($hash, 0, 30) . "... (DOBrange password = $plain)\n";
    } else {
        $n = db_execute('UPDATE students SET password_hash = ? WHERE id = ?',
            [$hash, (int)$r['id']], 'si');
        if ($n === 1) {
            echo "      FIXED — new hash for DOB-password '$plain'\n";
            $fixed++;
        } else {
            echo "      FAILED — db_execute returned $n\n";
        }
    }
}

echo "\n" . ($dry ? "Dry run complete." : "Fixed $fixed student(s).") . "\n";
echo "\nTry logging in with email + DOB in DDMMYYYY (e.g. 15082004).\n";
echo "DELETE this file from the server when done.\n";