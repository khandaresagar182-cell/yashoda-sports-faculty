<?php
/**
 * Public registration handler.
 * POST -> validates, ensures email is unique, creates the student row
 * with password_hash = bcrypt(DOB in DDMMYYYY), then redirects to a
 * one-shot "credentials" page that shows username + password.
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

// Email uniqueness — case-insensitive (the column is utf8mb4_unicode_ci, so
// this lookup is already case-insensitive, but be explicit)
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

/* ---------------- create the account ---------------- */

$plaintext_password = dob_to_password($dob);   // DDMMYYYY
$hash = password_hash($plaintext_password, PASSWORD_BCRYPT);

// enrollment_no is intentionally left NULL for self-registered students.
// The student is required to enter their real enrollment number on
// Step 2 of the wizard (student-dashboard.php). The UNIQUE constraint
// allows multiple NULLs in InnoDB, so there's no collision risk.
$enrollment_no = null;

try {
    $new_id = db_insert(
        'INSERT INTO students
            (enrollment_no, full_name, mother_name, dob, gender, email, mobile, department_id,
             password_hash, is_active, is_self_registered, registered_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',
        [
            $enrollment_no,
            $full_name,
            $mother_name,
            date('Y-m-d', $dob_ts),
            $gender,
            $email,
            $mobile,
            $department_id,
            $hash,
            1,    // is_active
            1,    // is_self_registered
        ],
        'sssssssisii'
    );
} catch (Throwable $e) {
    error_log('[register] insert failed: ' . $e->getMessage());
    // Surface the real error to the user only when the env explicitly asks
    // for it. Default keeps the generic message (don't leak DB internals).
    // APP_DEBUG=1 in .user.ini on the live site lets you see the real
    // failure while debugging without committing the leak to the codebase.
    $showDebug = getenv('APP_DEBUG') === '1';
    $msg = $showDebug
        ? 'Could not create the account: ' . $e->getMessage()
        : 'Could not create the account. Please try again.';
    flash_set('register_error', $msg, 'error');
    redirect('student-register.php');
}

/* ---------------- email the credentials (best-effort) ---------------- */
// Never let a broken/unconfigured mail server block registration — the
// on-screen credentials screen below is the guaranteed fallback either way.
$emailed = send_student_credentials_email($email, $full_name, $email, $plaintext_password);

/* ---------------- stash credentials in a one-shot flash + redirect ---------------- */

$_SESSION['_new_account'] = [
    'email'    => $email,
    'password' => $plaintext_password,
    'name'     => $full_name,
    'emailed'  => $emailed,
    // Show the credentials once and forget. This makes the page
    // non-refreshable: hitting refresh shows an error.
    'shown_at' => time(),
];
redirect('register_success.php');
