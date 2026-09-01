<?php
/**
 * Forgot-password handler. Always responds with a generic message
 * to avoid leaking which accounts exist. In dev (APP_ENV=local) we
 * also flash the reset link so the user can copy it.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method not allowed.');
}

csrf_check();

$key = trim((string)($_POST['email_or_username'] ?? ''));
if ($key === '') {
    flash_set('forgot_info', 'Please enter your email or username.', 'info');
    redirect('forgot-password.php');
}

$user = db_one(
    'SELECT id, email FROM faculty WHERE (email = ? OR username = ?) AND is_active = 1',
    [$key, $key], 'ss'
);

$generic = 'If an account exists for the given email or username, a reset link has been sent. Check your inbox.';

if (!$user) {
    // Don't reveal that the user doesn't exist
    flash_set('forgot_info', $generic, 'info');
    redirect('forgot-password.php');
}

// Generate token, store hash
$token     = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $token);
$expires   = date('Y-m-d H:i:s', time() + 1800); // 30 min

// inet_pton returns 4 or 16 bytes; bind as 'b' (blob) to preserve binary.
$ip_bytes  = @inet_pton(client_ip()) ?: null;
$ip_param  = $ip_bytes ?? "\x00\x00\x00\x00";
$ip_type   = 'b';

db_insert(
    'INSERT INTO password_resets (faculty_id, token_hash, expires_at, ip) VALUES (?,?,?,?)',
    [$user['id'], $token_hash, $expires, $ip_param],
    'iss' . $ip_type
);

$reset_url = SITE_URL . '/reset_password.php?token=' . $token;

// In production: mail($user['email'], 'Reset your password', "Click: $reset_url");
$mail_log = __DIR__ . '/mail.log';
$log_line = date('c') . " | TO={$user['email']} | URL=$reset_url\n";
@file_put_contents($mail_log, $log_line, FILE_APPEND);

$flash = ['msg' => $generic, 'level' => 'info'];
if (APP_ENV === 'local') {
    $flash['dev_link'] = $reset_url;
}
flash_set('forgot_info', $flash['msg'], 'info');
if (isset($flash['dev_link'])) {
    // Dev link piggybacks on the forgot_info flash itself rather than living
    // in $_SESSION — a stale session can't leak someone else's reset URL.
    $existing = flash_get('forgot_info');
    if (is_array($existing)) {
        flash_set('forgot_info', $existing['msg'], $existing['level'] ?? 'info', ['dev_link' => $flash['dev_link']]);
    }
}
redirect('forgot-password.php');
