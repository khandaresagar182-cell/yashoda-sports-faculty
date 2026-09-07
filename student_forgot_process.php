<?php
/**
 * Student forgot-password handler.
 * Looks up the student by email, resets password_hash back to bcrypt(DOB in
 * DDMMYYYY), and shows the credentials on the forgot-password page itself
 * (via a one-shot flash payload).
 *
 * Privacy note: we deliberately do NOT leak whether the email exists;
 * the UI always says "if we found your account, your password has been reset".
 * To keep the demo straightforward, the UI is also a single page — if the
 * email doesn't exist, we just show a generic error.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('student-forgot-password.php');
}

csrf_check();

if (is_locked_out()) {
    flash_set('student_forgot_error', 'Too many attempts. Please wait a few minutes.', 'error');
    redirect('student-forgot-password.php');
}

$email = strtolower(trim((string)($_POST['email'] ?? '')));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    record_login_attempt($email, false);
    flash_set('student_forgot_error', 'Please enter a valid email address.', 'error');
    redirect('student-forgot-password.php');
}

$student = db_one(
    'SELECT id, email, full_name, dob, password_hash, is_active
       FROM students
      WHERE email = ?',
    [$email], 's'
);

if (!$student || empty($student['dob']) || empty($student['password_hash']) || !$student['is_active']) {
    record_login_attempt($email, false);
    flash_set('student_forgot_error',
        'No active account was found with that email. Please register first or check the address.', 'error');
    redirect('student-forgot-password.php');
}

$plaintext = dob_to_password($student['dob']);
if (!$plaintext) {
    record_login_attempt($email, false);
    flash_set('student_forgot_error', 'Your account is missing a date of birth. Please contact the Faculty of Sports.', 'error');
    redirect('student-forgot-password.php');
}

$new_hash = password_hash($plaintext, PASSWORD_BCRYPT);
// Resetting to the DOB-derived password puts the account back on the
// DOB-password scheme, so student_dashboard_process.php should resume
// syncing the password whenever DOB is edited on Step 1.
db_execute('UPDATE students SET password_hash = ?, password_set_by_user = 0 WHERE id = ?', [$new_hash, (int)$student['id']], 'si');
record_login_attempt($email, true);

$_SESSION['_student_reset_show'] = [
    'email'    => $student['email'],
    'password' => $plaintext,
];
$_SESSION['_student_reset_done'] = true;   // banner for the login page

redirect('student-forgot-password.php');
