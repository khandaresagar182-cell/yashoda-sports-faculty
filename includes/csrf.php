<?php
/**
 * CSRF token: per-session, generated lazily, rotated on login.
 * All state-changing endpoints call csrf_check().
 */

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_meta(): string
{
    return '<meta name="csrf-token" content="' . h(csrf_token()) . '">';
}

/**
 * Call at the top of every POST handler. 403 on mismatch.
 * Returns true on success.
 *
 * $redirect_to: when set, a mismatch is treated as an expired form rather
 * than an attack — set a flash message and redirect there so the user
 * lands on a freshly-rendered page with a valid token. Use this only for
 * forms a user can legitimately leave open (e.g. the login page), never
 * for authenticated state-changing endpoints.
 */
function csrf_check(?string $redirect_to = null): bool
{
    $sent = $_POST['_csrf']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';
    $have = $_SESSION['csrf_token'] ?? '';
    if (!$sent || !$have || !hash_equals($have, $sent)) {
        if ($redirect_to !== null
            && function_exists('flash_set')
            && function_exists('redirect')) {
            flash_set('login_error',
                'Your session expired. Please sign in again.',
                'error');
            redirect($redirect_to);
        }
        http_response_code(403);
        if (APP_ENV === 'local') {
            exit('Invalid CSRF token.');
        }
        exit('Forbidden.');
    }
    return true;
}

/**
 * Call after login to force a fresh token.
 */
function csrf_rotate(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
