<?php
/**
 * Student logout. Destroys only the student session (faculty session
 * stays alive, in case an admin is testing both sides).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// CSRF: the app's logout link carries ?_csrf=<token>. A forged request
// without a valid token is a no-op.
$tok = (string)($_GET['_csrf'] ?? $_POST['_csrf'] ?? '');
if ($tok === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $tok)) {
    redirect('student-login.php');
}

student_logout();
flash_set('student_login_info', 'You have been signed out.', 'info');
redirect('student-login.php');
