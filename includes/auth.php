<?php
/**
 * Authentication, authorization, and department-scoping.
 * The single source of truth for security in the app.
 *
 * Session keys:
 *   faculty_id, faculty_username, faculty_name, faculty_role,
 *   must_reset_pw, department_id, department_code, department_name,
 *   login_time, last_activity
 */

declare(strict_types=1);

const SESSION_IDLE_TIMEOUT = 1800; // 30 minutes
const LOGIN_LOCKOUT_MAX     = 5;
const LOGIN_LOCKOUT_WINDOW  = 900; // 15 minutes

/* ---------------- session shape ---------------- */

function auth_session_keys(): array
{
    return [
        'faculty_id', 'faculty_username', 'faculty_name', 'faculty_role',
        'must_reset_pw', 'department_id', 'department_code', 'department_name',
        'login_time', 'last_activity',
    ];
}

function auth_clear_session(): void
{
    foreach (auth_session_keys() as $k) unset($_SESSION[$k]);
}

function auth_alive(): bool
{
    if (empty($_SESSION['faculty_id'])) return false;
    $last = $_SESSION['last_activity'] ?? 0;
    if (time() - (int)$last > SESSION_IDLE_TIMEOUT) {
        auth_clear_session();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

/* ---------------- current user ---------------- */

function current_faculty(): ?array
{
    if (!auth_alive()) return null;
    return [
        'id'            => (int)$_SESSION['faculty_id'],
        'username'      => $_SESSION['faculty_username'],
        'full_name'     => $_SESSION['faculty_name'],
        'role'          => $_SESSION['faculty_role'],
        'must_reset_pw' => (int)($_SESSION['must_reset_pw'] ?? 0),
        'department_id' => isset($_SESSION['department_id']) ? (int)$_SESSION['department_id'] : null,
        'department_code' => $_SESSION['department_code'] ?? null,
        'department_name' => $_SESSION['department_name'] ?? null,
    ];
}

function is_super_admin(): bool
{
    $f = current_faculty();
    return $f && $f['role'] === 'SUPER_ADMIN';
}

/* ---------------- guards ---------------- */

function require_login(): void
{
    if (!current_faculty()) {
        flash_set('login_error', 'Please sign in to continue.', 'error');
        $prefix = is_file('faculty-login.php') ? '' : '../';
        redirect($prefix . 'faculty-login.php');
    }
}

function require_role(string $role): void
{
    require_login();
    $f = current_faculty();
    if (!$f || $f['role'] !== $role) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function require_department(): void
{
    require_login();
    $f = current_faculty();
    if ($f['role'] === 'FACULTY') {
        $departmentId = (int)($f['department_id'] ?? 0);
        $isAssigned = $departmentId > 0 && db_one(
            'SELECT 1 FROM faculty_departments WHERE faculty_id = ? AND department_id = ?',
            [$f['id'], $departmentId],
            'ii'
        );
        if (!$isAssigned) {
            unset($_SESSION['department_id'], $_SESSION['department_code'], $_SESSION['department_name']);
            $prefix = is_file('faculty-select.php') ? '' : '../';
            redirect($prefix . 'faculty-select.php');
        }
    }
}

/* ---------------- login / logout ---------------- */

function login_user(array $faculty, ?int $department_id = null): void
{
    session_regenerate_id(true);
    $_SESSION['faculty_id']     = (int)$faculty['id'];
    $_SESSION['faculty_username']= $faculty['username'];
    $_SESSION['faculty_name']    = $faculty['full_name'];
    $_SESSION['faculty_role']    = $faculty['role'];
    $_SESSION['must_reset_pw']   = (int)$faculty['must_reset_pw'];
    $_SESSION['login_time']      = time();
    $_SESSION['last_activity']   = time();
    csrf_rotate();

    if ($faculty['role'] === 'FACULTY' && $department_id) {
        auth_set_department($department_id);
    }
}

function auth_set_department(int $department_id): void
{
    $dept = db_one('SELECT id, code, name FROM departments WHERE id = ? AND is_active = 1',
        [$department_id], 'i');
    if (!$dept) {
        throw new RuntimeException('Invalid department.');
    }
    $_SESSION['department_id']   = (int)$dept['id'];
    $_SESSION['department_code'] = $dept['code'];
    $_SESSION['department_name'] = $dept['name'];
}

function logout_user(): void
{
    auth_clear_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---------------- login attempts / lockout ---------------- */

function record_login_attempt(string $username, bool $success): void
{
    $ip = inet_pton(client_ip() ?: '0.0.0.0') ?: "\x00\x00\x00\x00";
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
    db_insert(
        'INSERT INTO login_attempts (username, ip, user_agent, success) VALUES (?,?,?,?)',
        [$username, $ip, $ua, $success ? 1 : 0],
        'sibi'
    );
}

function is_locked_out(): bool
{
    $remote = client_ip();
    // If we can't parse the IP, treat the request as untrusted and lock it out
    // (fail-closed) rather than silently disabling brute-force protection.
    $ip = $remote !== '' ? inet_pton($remote) : false;
    if ($ip === false) {
        return true;
    }
    $row = db_one(
        'SELECT COUNT(*) AS n FROM login_attempts
         WHERE ip = ? AND success = 0 AND attempted_at > (NOW() - INTERVAL ? SECOND)',
        [$ip, LOGIN_LOCKOUT_WINDOW],
        'bi'
    );
    return ((int)($row['n'] ?? 0)) >= LOGIN_LOCKOUT_MAX;
}

/* ---------------- department scoping (the security core) ---------------- */

/**
 * For SUPER_ADMIN, returns null (no scope) or the override from ?dept=N.
 * For FACULTY, returns the session's department_id, ignoring any URL param.
 */
function effective_department_id(): ?int
{
    $f = current_faculty();
    if (!$f) return null;
    if ($f['role'] === 'FACULTY') {
        return $f['department_id'] ?: null;
    }
    // SUPER_ADMIN
    if (isset($_GET['dept']) && ctype_digit((string)$_GET['dept'])) {
        $dept_id = (int)$_GET['dept'];
        // Validate the department actually exists; otherwise an unverified int
        // can be passed through, leading to silent zero-row queries.
        $exists = db_one(
            'SELECT id FROM departments WHERE id = ? AND is_active = 1',
            [$dept_id], 'i'
        );
        if ($exists) {
            return $dept_id;
        }
    }
    return null;
}

/**
 * SQL fragment + bound param that restricts a query to the effective department.
 * Returns [string $fragment, array $params, string $types].
 *
 * Use:
 *   [$where, $p, $t] = scope_sql_department('s');
 *   $sql = "SELECT * FROM students s WHERE 1=1 $where ORDER BY s.full_name";
 *   $rows = db_select($sql, $p, $t);
 */
function scope_sql_department(string $alias = 's'): array
{
    $dept = effective_department_id();
    if ($dept === null) {
        return ['', [], ''];
    }
    return [" AND {$alias}.department_id = ? ", [$dept], 'i'];
}

/**
 * Predicate fragment that hides students who haven't completed the wizard.
 * Use on every faculty read query that touches `students` so that
 * half-typed drafts (created at registration but not yet submitted via
 * student_dashboard_process.php?finalize=1) don't leak into faculty
 * dashboards, search, or exports. Mirrors the shape of
 * scope_sql_department() so the splice idioms below are uniform.
 */
function faculty_visible_student_filter(string $alias = 's'): array
{
    return [" AND {$alias}.form_submitted_at IS NOT NULL ", [], ''];
}

/**
 * Verify a POSTed department_id matches the effective scope.
 * Reject with 403 if not. Used by student_save.php.
 */
function assert_department_in_scope(?int $posted_dept_id): void
{
    $f = current_faculty();
    if (!$f || $f['role'] !== 'FACULTY') return;
    if ($posted_dept_id !== (int)$f['department_id']) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

/**
 * Check if the current logged-in faculty user has multiple assigned departments.
 */
function has_multiple_departments(): bool
{
    $f = current_faculty();
    if (!$f || $f['role'] !== 'FACULTY') {
        return false;
    }
    $count = db_one(
        'SELECT COUNT(*) AS n FROM faculty_departments WHERE faculty_id = ?',
        [$f['id']], 'i'
    );
    return ((int)($count['n'] ?? 0)) > 1;
}

/* =====================================================================
 *  Student self-registration & login
 *  Student sessions are completely independent from faculty sessions.
 *  Session keys are prefixed with `student_` to avoid any collision.
 * ===================================================================== */

function student_session_keys(): array
{
    return [
        'student_id', 'student_email', 'student_name',
        'student_department_id', 'student_login_time', 'student_last_activity',
    ];
}

function student_clear_session(): void
{
    foreach (student_session_keys() as $k) unset($_SESSION[$k]);
}

function student_alive(): bool
{
    if (empty($_SESSION['student_id'])) return false;
    $last = $_SESSION['student_last_activity'] ?? 0;
    if (time() - (int)$last > SESSION_IDLE_TIMEOUT) {
        student_clear_session();
        return false;
    }
    $_SESSION['student_last_activity'] = time();
    return true;
}

function current_student(): ?array
{
    if (!student_alive()) return null;
    return [
        'id'            => (int)$_SESSION['student_id'],
        'email'         => $_SESSION['student_email'] ?? '',
        'full_name'     => $_SESSION['student_name']  ?? '',
        'department_id' => isset($_SESSION['student_department_id'])
                            ? (int)$_SESSION['student_department_id'] : null,
    ];
}

function require_student(): void
{
    if (!current_student()) {
        flash_set('student_login_error', 'Please sign in as a student to continue.', 'error');
        redirect('student-login.php');
    }
}

function student_login(array $student): void
{
    session_regenerate_id(true);
    $_SESSION['student_id']              = (int)$student['id'];
    $_SESSION['student_email']           = $student['email'];
    $_SESSION['student_name']            = $student['full_name'];
    $_SESSION['student_department_id']   = isset($student['department_id'])
        ? (int)$student['department_id'] : null;
    $_SESSION['student_login_time']      = time();
    $_SESSION['student_last_activity']   = time();
    csrf_rotate();
}

function student_logout(): void
{
    student_clear_session();
    // Only nuke the cookie/session if no faculty session is alive
    if (!current_faculty()) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}

/**
 * Convert a Y-m-d DOB into the DDMMYYYY password form.
 * Returns null on invalid input.
 */
function dob_to_password(?string $dob_yyyymmdd): ?string
{
    if (!$dob_yyyymmdd) return null;
    $ts = strtotime($dob_yyyymmdd);
    return $ts ? date('dmY', $ts) : null;
}
