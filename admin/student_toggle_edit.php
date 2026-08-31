<?php
/**
 * Grant or revoke a submitted student's re-edit access.
 *
 * Once a student submits their profile (students.form_submitted_at is
 * set), student-dashboard.php locks their wizard and shows the
 * "Submitted" confirmation screen instead — every step and every
 * write endpoint in student_dashboard_process.php refuses further
 * edits (see student_form_locked() in includes/helpers.php). This
 * endpoint is how faculty re-opens that door for one student: setting
 * edit_unlocked = 1 lets them edit and re-submit; the student's next
 * successful submit resets it back to 0, re-locking the form. Faculty
 * can also revoke a grant before the student re-submits.
 *
 * POST + CSRF + department scope.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method not allowed.');
}

require_login();
require_department();
csrf_check();

$id     = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');

if ($id <= 0 || !in_array($action, ['lock', 'unlock'], true)) {
    flash_set('student_error', 'Invalid request.', 'error');
    redirect('../student-search.php');
}

[$scope, $p, $t] = scope_sql_department('s');

$row = db_one(
    "SELECT id, form_submitted_at FROM students s WHERE s.id = ? $scope",
    array_merge([$id], $p), 'i' . $t
);

if (!$row) {
    flash_set('student_error', 'Student not found.', 'error');
    redirect('../student-search.php');
}

if (empty($row['form_submitted_at'])) {
    flash_set('student_error', 'This student has not submitted their profile yet — nothing to unlock.', 'error');
    redirect('../student-profile.php?id=' . $id);
}

$unlocked = $action === 'unlock' ? 1 : 0;
db_execute('UPDATE students SET edit_unlocked = ? WHERE id = ?', [$unlocked, $id], 'ii');

flash_set('student_saved',
    $unlocked
        ? 'Edit access granted. The student can now update and re-submit their profile.'
        : 'Edit access revoked. The student\'s profile is locked again.',
    'success');
redirect('../student-profile.php?id=' . $id);
