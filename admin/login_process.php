<?php
/**
 * Login endpoint. POST only.
 * Validates CSRF, checks lockout, verifies bcrypt, starts session, redirects.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// A user can leave the login page open long enough for its token to go
// stale (session GC, XAMPP restart, back-button/bfcache). Bounce back to a
// fresh login page instead of dead-ending on "Invalid CSRF token.".
csrf_check('../faculty-login.php');

// Already logged in?
if (current_faculty()) {
    $f = current_faculty();
    if ($f['role'] === 'SUPER_ADMIN') {
        redirect('dashboard.php');
    } else {
        redirect('../faculty-select.php');
    }
}

// Lockout check
if (is_locked_out()) {
    flash_set('login_error',
        'Too many failed attempts from your IP. Please wait 15 minutes and try again.',
        'error');
    redirect('../faculty-login.php');
}

$username = trim((string)($_POST['username'] ?? $_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    flash_set('login_error', 'Please enter both username/email and password.', 'error');
    redirect('../faculty-login.php');
}

// Allow login by username OR email
$user = db_one(
    'SELECT id, username, email, full_name, password_hash, role, is_active, must_reset_pw
       FROM faculty
      WHERE (username = ? OR email = ?) AND is_active = 1
      LIMIT 1',
    [$username, $username],
    'ss'
);

if (!$user || !password_verify($password, $user['password_hash'])) {
    record_login_attempt($username, false);
    flash_set('login_error', 'Invalid credentials. Please try again.', 'error');
    redirect('../faculty-login.php');
}

// Optional password-rehash if cost increased
if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
    $new = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    db_execute('UPDATE faculty SET password_hash = ? WHERE id = ?', [$new, $user['id']], 'si');
}

record_login_attempt($username, true);
login_user($user);

// Update last_login_at
db_execute('UPDATE faculty SET last_login_at = NOW() WHERE id = ?', [$user['id']], 'i');

// Redirect by role
if ($user['role'] === 'SUPER_ADMIN') {
    redirect('dashboard.php');
}
redirect('../faculty-select.php');
