<?php
/**
 * Logout endpoint. Clears session and redirects to login.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$loginUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/../faculty-login.php';

// CSRF: logout is state-changing. The app's logout links carry ?_csrf=<token>.
// A forged request without a valid token is a no-op — the user just lands on
// the login page still signed in.
$tok = (string)($_GET['_csrf'] ?? $_POST['_csrf'] ?? '');
if ($tok === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $tok)) {
    header('Location: ' . $loginUrl);
    exit;
}

logout_user();
session_start(); // start a fresh session for the flash
flash_set('login_error', 'You have been signed out.', 'info');
session_write_close();
header('Location: ' . $loginUrl);
exit;
