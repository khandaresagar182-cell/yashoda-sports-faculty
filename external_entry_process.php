<?php
/**
 * External Entries — fetch()-based per-step save endpoint for the public
 * wizard (external-entry.php), mirroring student_dashboard_process.php's
 * shape: one endpoint, action-dispatched, JSON responses.
 *
 * Steps: 1 Personal (verify-gated — see below), 2 Academic, 3 Played,
 * 4 Documents, 5 Jersey, 6 Preview & Submit. No game-picker step — the
 * game/level/gender/year is fixed by the link.
 *
 * Security: everything except verify_start/resume_request requires a live
 * session created by external_student_login() (see external_entry_verify.php
 * and the resume-token branch below) whose link_id matches the token in
 * the request. Step 1 personal fields are never written to `external_students`
 * here — they're staged behind a token (stage_external_pending()) and only
 * become a real row once external_entry_verify.php consumes it.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function ext_json($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ext_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_check();

// Sign-out (P7-13). Deliberately handled before the "is this link still open"
// check: a student on a shared device must always be able to end their
// session, even if the link has expired or been revoked in the meantime.
if ((string)($_POST['action'] ?? '') === 'logout') {
    external_student_logout();
    ext_json(['ok' => true]);
}

$token = (string)($_POST['token'] ?? '');
$link  = external_link_by_token($token);
if (!$link || !external_link_is_open($link)) {
    ext_json(['ok' => false, 'error' => 'This entry link is no longer open.'], 403);
}
$linkId = (int)$link['id'];

$action = (string)($_POST['action'] ?? '');

/* ----------------------------------------------------------------- *
 * Step 1 — no session required yet.
 * ----------------------------------------------------------------- */

if ($action === 'verify_start') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    // Same breakdown + concatenation technique as register_process.php:
    // stored as one "Surname First Middle" full_name, but collected as
    // three separate fields matching the internal wizard's Step 1.
    $surname    = trim((string)($_POST['surname'] ?? ''));
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $middle_name = trim((string)($_POST['middle_name'] ?? ''));
    $fullName   = trim(implode(' ', array_filter([$surname, $first_name, $middle_name])));

    $personal = [
        'full_name'         => $fullName,
        'mother_name'       => trim((string)($_POST['mother_name'] ?? '')),
        'dob'               => trim((string)($_POST['dob'] ?? '')),
        'gender'            => trim((string)($_POST['gender'] ?? '')),
        'aadhar_number'     => trim((string)($_POST['aadhar_number'] ?? '')),
        'mobile'            => trim((string)($_POST['mobile'] ?? '')),
        'whatsapp_no'       => trim((string)($_POST['whatsapp_no'] ?? '')),
        'permanent_address' => trim((string)($_POST['permanent_address'] ?? '')),
        'current_address'   => trim((string)($_POST['current_address'] ?? '')),
        // The raw external-entry LINK token (this request's own $token,
        // above) — external_entry_links.token_hash is a one-way SHA-256
        // hash, so the only way external_entry_verify.php can redirect
        // back to external-entry.php?token=<raw> after verifying is if
        // we hand it the plaintext token now, riding along in staged_data.
        '_link_token'       => $token,
    ];

    $errors = external_validate_personal($personal + [
        'surname' => $surname, 'first_name' => $first_name, 'middle_name' => $middle_name, 'email' => $email,
    ], ['email' => true]);
    if ($errors) {
        ext_json(['ok' => false, 'errors' => $errors]);
    }

    // Public + unauthenticated + sends email → throttle (P7-04). Runs after
    // validation so a typo doesn't burn the student's quota.
    $blocked = external_mail_throttle($linkId, $email);
    if ($blocked !== null) {
        ext_json(['ok' => false, 'error' => $blocked], 429);
    }

    // An address that already has a verified submission on this link gets the
    // resume link instead — and the SAME response as a brand-new address, so
    // this endpoint can't be used to learn who has submitted (P7-05; matches
    // resume_request below).
    $existing = external_student_by_link_email($linkId, $email);
    if ($existing && !empty($existing['email_verified_at'])) {
        $rt        = issue_external_resume_token((int)$existing['id']);
        $verifyUrl = rtrim(SITE_URL, '/') . '/external-entry.php?token=' . urlencode($token) . '&rtoken=' . $rt;
        $emailed   = send_external_resume_email($email, (string)$existing['full_name'], $verifyUrl);
    } else {
        [$rawToken] = stage_external_pending($linkId, $email, $personal);
        $verifyUrl  = rtrim(SITE_URL, '/') . '/external_entry_verify.php?token=' . $rawToken;
        $emailed    = send_external_verification_email($email, $personal['full_name'], $verifyUrl);
    }

    // Don't tell the student to "check their email" when nothing was sent —
    // this flow has no on-screen credential fallback (see mailer.php's
    // docblock), so a silently-failed send would strand them with no way
    // to know verification never went out. Local dev is exempt because the
    // dev_link below stands in for the email.
    if (!$emailed && APP_ENV !== 'local') {
        ext_json(['ok' => false, 'error' => "We couldn't send the email right now. Please try again in a moment — if this keeps happening, contact the faculty who shared this link with you."]);
    }

    ext_json([
        'ok'       => true,
        'message'  => "Check your email — we've sent you a link. Click it to continue.",
        'emailed'  => $emailed,
        // Local/dev only — same fallback as register_process.php's dev_link,
        // for when SMTP isn't configured on a dev box.
        'dev_link' => (APP_ENV === 'local') ? $verifyUrl : null,
    ]);
}

if ($action === 'resume_request') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 160) {
        // Throttled for every well-formed address (whether or not it matches a
        // submission) so the cooldown itself reveals nothing. A blocked request
        // simply sends nothing; the response below is unchanged.
        if (external_mail_throttle($linkId, $email) === null) {
            $existing = external_student_by_link_email($linkId, $email);
            if ($existing && !empty($existing['email_verified_at'])) {
                $rt = issue_external_resume_token((int)$existing['id']);
                $resumeUrl = rtrim(SITE_URL, '/') . '/external-entry.php?token=' . urlencode($token) . '&rtoken=' . $rt;
                send_external_resume_email($email, (string)$existing['full_name'], $resumeUrl);
            }
        }
    }
    // Same response whether or not the email matches anything — avoid
    // leaking which emails have submissions on this link.
    ext_json(['ok' => true, 'message' => "If that email has a submission on this link, we've sent a link to continue."]);
}

/* ----------------------------------------------------------------- *
 * Everything past this point needs a live, matching session.
 * ----------------------------------------------------------------- */

$current = current_external_student();
if (!$current || $current['link_id'] !== $linkId) {
    ext_json(['ok' => false, 'error' => 'Your session has expired. Please re-enter your email to continue.', 'need_resume' => true], 401);
}

$row = external_student_by_id($current['id']);
if (!$row || (int)$row['link_id'] !== $linkId) {
    ext_json(['ok' => false, 'error' => 'Entry not found.'], 404);
}
if (!empty($row['form_submitted_at']) && $action !== 'finalize') {
    ext_json(['ok' => false, 'error' => 'This submission has already been confirmed and is locked.'], 403);
}
$studentId = (int)$row['id'];

if ($action === 'save_personal') {
    // Post-verification edits to Step 1 (the fields were already written
    // once, from the staged pending data, at verification time).
    $surname    = trim((string)($_POST['surname'] ?? ''));
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $middle_name = trim((string)($_POST['middle_name'] ?? ''));
    $fullName   = trim(implode(' ', array_filter([$surname, $first_name, $middle_name])));
    $mobile   = trim((string)($_POST['mobile'] ?? ''));
    $gender   = trim((string)($_POST['gender'] ?? ''));
    $dob      = trim((string)($_POST['dob'] ?? ''));

    $errors = external_validate_personal([
        'surname' => $surname, 'first_name' => $first_name, 'middle_name' => $middle_name,
        'mother_name' => trim((string)($_POST['mother_name'] ?? '')),
        'dob' => $dob, 'gender' => $gender,
        'aadhar_number' => trim((string)($_POST['aadhar_number'] ?? '')),
        'mobile' => $mobile,
        'whatsapp_no' => trim((string)($_POST['whatsapp_no'] ?? '')),
        'permanent_address' => trim((string)($_POST['permanent_address'] ?? '')),
        'current_address' => trim((string)($_POST['current_address'] ?? '')),
    ]);
    if ($errors) ext_json(['ok' => false, 'errors' => $errors]);

    $personalParams = [
        $fullName,
        trim((string)($_POST['mother_name'] ?? '')) ?: null,
        $dob ?: null,
        $gender ?: null,
        trim((string)($_POST['aadhar_number'] ?? '')) ?: null,
        $mobile ?: null,
        trim((string)($_POST['whatsapp_no'] ?? '')) ?: null,
        trim((string)($_POST['permanent_address'] ?? '')) ?: null,
        trim((string)($_POST['current_address'] ?? '')) ?: null,
    ];
    db_execute(
        'UPDATE external_students
            SET full_name = ?, mother_name = ?, dob = ?, gender = ?, aadhar_number = ?,
                mobile = ?, whatsapp_no = ?, permanent_address = ?, current_address = ?
          WHERE id = ?',
        [...$personalParams, $studentId],
        str_repeat('s', count($personalParams)) . 'i'
    );
    ext_json(['ok' => true, 'next' => 2]);
}

if ($action === 'save_academic') {
    $collegeName = trim((string)($_POST['college_name'] ?? ''));
    $program     = trim((string)($_POST['program'] ?? ''));
    $studyYear   = trim((string)($_POST['study_year'] ?? ''));
    $duration    = trim((string)($_POST['course_duration_years'] ?? ''));
    $admissionYr = trim((string)($_POST['admission_year'] ?? ''));
    $sscYear     = trim((string)($_POST['ssc_passing_year'] ?? ''));
    $hscYear     = trim((string)($_POST['hsc_passing_year'] ?? ''));
    $diplomaYear = trim((string)($_POST['diploma_passing_year'] ?? ''));
    $faUniYear   = trim((string)($_POST['first_admission_university_year'] ?? ''));
    $faCourseYr  = trim((string)($_POST['first_admission_course_year'] ?? ''));
    $faClassYr   = trim((string)($_POST['first_admission_class_year'] ?? ''));
    $hasGapYear  = $_POST['has_gap_year'] ?? '';
    $gapDetail   = trim((string)($_POST['gap_year_detail'] ?? ''));

    $errors = external_validate_academic([
        'college_name' => $collegeName, 'program' => $program, 'study_year' => $studyYear,
        'course_duration_years' => $duration, 'admission_year' => $admissionYr,
        'ssc_passing_year' => $sscYear, 'hsc_passing_year' => $hscYear, 'diploma_passing_year' => $diplomaYear,
        'first_admission_university_year' => $faUniYear, 'first_admission_course_year' => $faCourseYr,
        'first_admission_class_year' => $faClassYr,
        'has_gap_year' => (string)$hasGapYear, 'gap_year_detail' => $gapDetail,
    ]);
    if ($errors) ext_json(['ok' => false, 'errors' => $errors]);

    db_execute(
        'UPDATE external_students
            SET college_name = ?, program = ?, study_year = ?, course_duration_years = ?, admission_year = ?,
                ssc_passing_year = ?, hsc_passing_year = ?, diploma_passing_year = ?,
                first_admission_university_year = ?, first_admission_course_year = ?, first_admission_class_year = ?,
                has_gap_year = ?, gap_year_detail = ?,
                form_step = GREATEST(COALESCE(form_step,0), 2)
          WHERE id = ?',
        [
            $collegeName, $program ?: null, $studyYear ?: null, $duration ?: null, $admissionYr ?: null,
            $sscYear ?: null, $hscYear ?: null, $diplomaYear ?: null,
            $faUniYear ?: null, $faCourseYr ?: null, $faClassYr ?: null,
            $hasGapYear === '' ? null : (int)$hasGapYear,
            ($hasGapYear === '1' && $gapDetail !== '') ? $gapDetail : null,
            $studentId,
        ],
        'sssssssssssisi'
    );
    ext_json(['ok' => true, 'next' => 3]);
}

if ($action === 'save_played') {
    $errors = external_validate_played($_POST);
    if ($errors) ext_json(['ok' => false, 'errors' => $errors]);

    $fields = [];
    $params = [];
    $types  = '';
    foreach (['zonal', 'interzonal', 'all_india', 'west_zone', 'krida_mahotsav'] as $k) {
        $played = !empty($_POST[$k . '_played']) ? 1 : 0;
        $year   = trim((string)($_POST[$k . '_year'] ?? ''));
        $fields[] = "{$k}_played = ?";
        $params[] = $played;
        $types   .= 'i';
        $fields[] = "{$k}_year = ?";
        $params[] = $played ? ($year !== '' ? $year : null) : null;
        $types   .= 's';
    }
    $hasPlayed = !empty($_POST['has_played_in_college']) ? 1 : 0;
    $fields[] = 'has_played_in_college = ?';
    $params[] = $hasPlayed;
    $types   .= 'i';

    $fields[] = 'form_step = GREATEST(COALESCE(form_step,0), 3)';
    $sql = 'UPDATE external_students SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $params[] = $studentId;
    $types   .= 'i';
    db_execute($sql, $params, $types);
    ext_json(['ok' => true, 'next' => 4]);
}

if ($action === 'upload_photo') {
    $result = handle_image_upload('external', $_FILES['photo'] ?? null, 2000, ['jpg', 'jpeg', 'png', 'webp']);
    if (!$result['ok']) {
        ext_json(['ok' => false, 'error' => 'Could not upload photo (' . $result['error'] . ').']);
    }
    if (!empty($row['photo_path']) && strpos((string)$row['photo_path'], '..') === false) {
        // This file lives at the docroot, so __DIR__ resolves relative
        // paths (e.g. "uploads/external/xxx.jpg") directly.
        $old = __DIR__ . '/' . ltrim((string)$row['photo_path'], '/');
        if (is_file($old)) @unlink($old);
    }
    db_execute('UPDATE external_students SET photo_path = ? WHERE id = ?', [$result['path'], $studentId], 'si');
    ext_json(['ok' => true, 'path' => $result['path']]);
}

if ($action === 'upload_doc') {
    $reqId = (int)($_POST['requirement_id'] ?? 0);
    $req = db_one(
        'SELECT id, department_id, allowed_mime_types FROM dept_document_requirements WHERE id = ? AND department_id = ?',
        [$reqId, (int)$row['department_id']], 'ii'
    );
    if (!$req) {
        ext_json(['ok' => false, 'error' => 'Unknown document requirement.'], 400);
    }
    $allowedMimes = array_filter(array_map('trim', explode(',', (string)($req['allowed_mime_types'] ?? 'application/pdf'))));
    if (!$allowedMimes) $allowedMimes = ['application/pdf'];

    $result = handle_generic_document_upload('external', $_FILES['document'] ?? null, $allowedMimes, 5000);
    if (!$result['ok']) {
        ext_json(['ok' => false, 'error' => 'Could not upload document (' . $result['error'] . ').']);
    }

    $existing = db_one(
        'SELECT id, file_path FROM external_student_documents WHERE external_student_id = ? AND requirement_id = ?',
        [$studentId, $reqId], 'ii'
    );
    if ($existing) {
        if (!empty($existing['file_path']) && strpos((string)$existing['file_path'], '..') === false) {
            $old = __DIR__ . '/' . ltrim((string)$existing['file_path'], '/');
            if (is_file($old)) @unlink($old);
        }
        db_execute('UPDATE external_student_documents SET file_path = ?, uploaded_at = NOW() WHERE id = ?', [$result['path'], (int)$existing['id']], 'si');
    } else {
        db_insert(
            'INSERT INTO external_student_documents (external_student_id, requirement_id, file_path) VALUES (?,?,?)',
            [$studentId, $reqId, $result['path']], 'iis'
        );
    }
    ext_json(['ok' => true, 'path' => $result['path']]);
}

if ($action === 'advance_documents') {
    // Documents step has no single form submit (each file uploads
    // independently) — this just bumps form_step so the wizard unlocks
    // Step 5, mirroring the other steps' "Save & Continue".
    db_execute(
        'UPDATE external_students SET form_step = GREATEST(COALESCE(form_step,0), 4) WHERE id = ?',
        [$studentId], 'i'
    );
    ext_json(['ok' => true, 'next' => 5]);
}

if ($action === 'save_jersey') {
    $errors = external_validate_jersey([
        'jersey_number' => trim((string)($_POST['jersey_number'] ?? '')),
        'jersey_size'   => trim((string)($_POST['jersey_size'] ?? '')),
        'shorts_size'   => trim((string)($_POST['shorts_size'] ?? '')),
        'track_size'    => trim((string)($_POST['track_size'] ?? '')),
    ]);
    if ($errors) ext_json(['ok' => false, 'errors' => $errors]);

    db_execute(
        'UPDATE external_students
            SET jersey_number = ?, jersey_size = ?, shorts_size = ?, track_size = ?,
                form_step = GREATEST(COALESCE(form_step,0), 5)
          WHERE id = ?',
        [
            trim((string)($_POST['jersey_number'] ?? '')) ?: null,
            trim((string)($_POST['jersey_size'] ?? '')) ?: null,
            trim((string)($_POST['shorts_size'] ?? '')) ?: null,
            trim((string)($_POST['track_size'] ?? '')) ?: null,
            $studentId,
        ],
        'ssssi'
    );
    ext_json(['ok' => true, 'next' => 6]);
}

if ($action === 'finalize') {
    if (!empty($row['form_submitted_at'])) {
        ext_json(['ok' => true, 'already' => true]);
    }
    if (trim((string)$row['college_name']) === '') {
        ext_json(['ok' => false, 'error' => 'Please complete the Academic step (college name) before submitting.']);
    }
    db_execute(
        'UPDATE external_students SET form_submitted_at = NOW(), form_step = GREATEST(COALESCE(form_step,0), 6) WHERE id = ?',
        [$studentId], 'i'
    );
    send_external_entry_submitted_email((string)$row['email'], (string)$row['full_name'], [
        'Game Level'    => (string)$link['game_level'],
        'Game'          => (string)$link['game_name'],
        'Representing'  => (string)$link['representing_team'],
        'Academic Year' => (string)$link['academic_year'],
        'College'       => (string)$row['college_name'],
    ]);
    ext_json(['ok' => true]);
}

ext_json(['ok' => false, 'error' => 'Unknown action.'], 400);
