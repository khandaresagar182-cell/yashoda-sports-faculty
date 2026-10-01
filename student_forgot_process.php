<?php
/**
 * Student forgot-password handler.
 *
 * Mirrors the faculty flow (forgot_process.php / reset_password.php):
 * looks up the student by email, and — without revealing whether the
 * account exists — emails a time-limited reset link
 * (student_reset_password.php?token=...) instead of resetting the
 * password immediately. Nothing about the account changes until the
 * student actually clicks that link and chooses a new password.
 *
 * Previously this reset the password straight back to the student's DOB
 * from just an email address, with no proof of inbox access — anyone who
 * knew or guessed a student's DOB could take over their account. The
 * token-based link closes that gap.
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

$generic = 'If an account exists for that email, a password reset link has been sent. Check your inbox.';

$student = db_one(
    'SELECT id, email, full_name, is_active FROM students WHERE email = ?',
    [$email], 's'
);

if (!$student || !$student['is_active']) {
    // Don't reveal whether the account exists.
    record_login_attempt($email, false);
    flash_set('student_forgot_ok', $generic, 'info');
    redirect('student-forgot-password.php');
}

record_login_attempt($email, true);

$token      = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $token);
$expires    = date('Y-m-d H:i:s', time() + 1800); // 30 min, same window as the faculty flow

$ip_bytes = @inet_pton(client_ip()) ?: null;
$ip_param = $ip_bytes ?? "\x00\x00\x00\x00";

db_insert(
    'INSERT INTO student_password_resets (student_id, token_hash, expires_at, ip) VALUES (?,?,?,?)',
    [(int)$student['id'], $token_hash, $expires, $ip_param],
    'issb'
);

$reset_url = rtrim(SITE_URL, '/') . '/student_reset_password.php?token=' . $token;
$emailed   = send_student_password_reset_email($student['email'], $student['full_name'], $reset_url);

flash_set('student_forgot_ok', $generic, 'info', [
    // Local/dev only — mirrors forgot_process.php's dev_link fallback so
    // the flow is testable without a working SMTP config.
    'dev_link' => (APP_ENV === 'local') ? $reset_url : null,
    'emailed'  => $emailed,
]);
redirect('student-forgot-password.php');
