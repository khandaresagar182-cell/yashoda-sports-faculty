<?php
/**
 * Public registration handler.
 * POST -> validates, ensures the email isn't already a real account,
 * stashes the submitted details in `pending_registrations` behind a
 * hashed token, emails a verification link (email_verify.php?token=...),
 * then redirects to a "check your email" page. The actual `students` row
 * is only created once that link is opened and the student sets their
 * own password — see email_verify.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_student()) {
    redirect('student-dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('student-register.php');
}

csrf_check();

/* ---------------- collect + validate ---------------- */

$surname       = trim((string)($_POST['surname']     ?? ''));
$first_name    = trim((string)($_POST['first_name']  ?? ''));
$middle_name   = trim((string)($_POST['middle_name'] ?? ''));
// Stored as "Surname First Middle" everywhere — DB, display, exports.
$full_name     = trim(implode(' ', array_filter([$surname, $first_name, $middle_name])));
$mother_name   = trim((string)($_POST['mother_name'] ?? ''));
$email         = strtolower(trim((string)($_POST['email'] ?? '')));
$mobile        = trim((string)($_POST['mobile'] ?? ''));
$dob           = trim((string)($_POST['dob'] ?? ''));
$department_id = (int)($_POST['department_id'] ?? 0);

$errors = [];

if ($first_name === '' || strlen($first_name) > 60) {
    $errors[] = 'Please enter your first name.';
}
if ($middle_name === '' || strlen($middle_name) > 60) {
    $errors[] = 'Please enter your middle name (max 60 characters).';
}
if ($surname === '' || strlen($surname) > 60) {
    $errors[] = 'Please enter your surname.';
}
if (strlen($full_name) < 2 || strlen($full_name) > 160) {
    $errors[] = 'Please enter your full name (2-160 characters).';
}
if ($mother_name === '' || strlen($mother_name) > 100) {
    $errors[] = 'Please enter your mother name (max 100 characters).';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) {
    $errors[] = 'Please enter a valid email address.';
}
if (!preg_match('/^[0-9]{10}$/', $mobile)) {
    $errors[] = 'Mobile number must be exactly 10 digits.';
}
$gender = trim((string)($_POST['gender'] ?? ''));
if ($gender === '' || !in_array($gender, gender_options(), true)) {
    $errors[] = 'Please select your gender.';
}
$dob_ts = strtotime($dob);
if (!$dob_ts || $dob_ts > time() || $dob_ts < strtotime('1995-01-01')) {
    $errors[] = 'Please enter a valid date of birth (1995 onwards, not in the future).';
}
if ($department_id <= 0) {
    $errors[] = 'Please select your faculty.';
}

// Verify faculty is real + active
$dept = $department_id > 0
    ? db_one('SELECT id, name FROM departments WHERE id = ? AND is_active = 1', [$department_id], 'i')
    : null;
if ($department_id > 0 && !$dept) {
    $errors[] = 'Selected faculty is invalid.';
}

// Email uniqueness against real accounts only — case-insensitive (the
// column is utf8mb4_unicode_ci, so this lookup is already
// case-insensitive, but be explicit). A pending (unverified) registration
// for the same email is NOT an error — re-submitting just replaces it
// with a fresh link below, so a lost/expired email can be retried.
if (!$errors) {
    $existing = db_one('SELECT id FROM students WHERE email = ?', [$email], 's');
    if ($existing) {
        $errors[] = 'An account with this email already exists. Please use the login page or the "Forgot Password" link.';
    }
}

if ($errors) {
    $_SESSION['_register_old'] = [
        'first_name'   => $first_name,
        'middle_name'  => $middle_name,
        'surname'      => $surname,
        'mother_name'  => $mother_name,
        'gender'       => $gender,
        'email'        => $email,
        'mobile'       => $mobile,
        'dob'          => $dob,
        'department_id'=> $department_id,
    ];
    flash_set('register_error', implode(' ', $errors), 'error');
    redirect('student-register.php');
}

/* ---------------- stash pending + send verification link ---------------- */

$token      = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $token);
$expires    = date('Y-m-d H:i:s', time() + 86400); // 24 hours

try {
    // Drop any earlier unverified attempt for this email so re-registering
    // (e.g. because the first link expired or never arrived) just issues a
    // fresh one instead of erroring out.
    db_execute('DELETE FROM pending_registrations WHERE email = ?', [$email], 's');

    db_insert(
        'INSERT INTO pending_registrations
            (email, full_name, mother_name, gender, dob, mobile, department_id, token_hash, expires_at)
         VALUES (?,?,?,?,?,?,?,?,?)',
        [
            $email,
            $full_name,
            $mother_name,
            $gender,
            date('Y-m-d', $dob_ts),
            $mobile,
            $department_id,
            $token_hash,
            $expires,
        ],
        'ssssssiss'
    );
} catch (Throwable $e) {
    error_log('[register] pending insert failed: ' . $e->getMessage());
    $showDebug = getenv('APP_DEBUG') === '1';
    $msg = $showDebug
        ? 'Could not start registration: ' . $e->getMessage()
        : 'Could not start registration. Please try again.';
    flash_set('register_error', $msg, 'error');
    redirect('student-register.php');
}

$verify_url = rtrim(SITE_URL, '/') . '/email_verify.php?token=' . $token;
$emailed    = send_verification_email($email, $full_name, $verify_url);

/* ---------------- stash a one-shot "check your email" payload ---------------- */

$_SESSION['_register_pending'] = [
    'email'   => $email,
    'name'    => $full_name,
    'emailed' => $emailed,
    // Local/dev only — see includes/mailer.php docblock on why a failed
    // send otherwise leaves the student with no way to finish signing up.
    'dev_link' => (APP_ENV === 'local') ? $verify_url : null,
];
redirect('register_success.php');
