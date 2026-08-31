<?php
/**
 * Student save endpoint. Handles both INSERT (new) and UPDATE.
 * POST only. CSRF + dept scope + photo upload.
 *
 * Required fields: enrollment_no, full_name, department_id
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method not allowed.');
}

require_login();
csrf_check();

$id = (int)($_POST['id'] ?? 0);

// Collect + sanitize
$enrollment_no = trim((string)($_POST['enrollment_no'] ?? ''));
$roll_no_raw   = trim((string)($_POST['roll_no'] ?? ''));
$full_name     = trim((string)($_POST['full_name'] ?? ''));
$dept_id       = (int)($_POST['department_id'] ?? 0);
$email_raw     = trim((string)($_POST['email']     ?? ''));
$mobile_raw    = trim((string)($_POST['mobile']    ?? ''));
$dob_raw       = trim((string)($_POST['dob']       ?? ''));
$study_year_raw= trim((string)($_POST['study_year']?? ''));
$mother_name_raw = trim((string)($_POST['mother_name'] ?? ''));
$father_name_raw = trim((string)($_POST['father_name'] ?? ''));
$aadhar_number   = preg_replace('/\D+/', '', (string)($_POST['aadhar_number'] ?? ''));

$department = db_one('SELECT code FROM departments WHERE id = ? AND is_active = 1', [$dept_id], 'i');
$is_engineering = ($department['code'] ?? '') === 'engineering';
$uses_father_first_name = in_array($department['code'] ?? '', ['engineering', 'pharmacy'], true);
$is_polytechnic = in_array($department['code'] ?? '', ['polytechnic', 'dpharm'], true);
$is_pharm_faculty_department = in_array(
    $department['code'] ?? '',
    ['pharmacy', 'ytc_pharmacy', 'management', 'architecture'],
    true
);
$stores_roll_no = $is_polytechnic || $is_pharm_faculty_department || $is_engineering;
// mother_name is collected for everyone except polytechnic/dpharm (which never
// asks for it); father_name is separate and only exists for engineering/pharmacy.
$stores_mother_name = $is_engineering || $is_pharm_faculty_department;

// Required-field check (list every missing field by name in the error)
$missing = [];
if ($enrollment_no === '')  $missing[] = 'Enrollment No';
if ($full_name === '')      $missing[] = 'Full Name';
if ($dept_id <= 0)          $missing[] = 'Faculty';
if ($email_raw === '')      $missing[] = 'Email';
if ($mobile_raw === '')     $missing[] = 'Mobile No';
    $address_raw = trim((string)($_POST['address'] ?? ''));
    if ($address_raw === '')     $missing[] = 'Address';
    if (strlen($address_raw) > 500) $missing[] = 'Address (max 500 characters)';
if ($dob_raw === '')        $missing[] = 'Date of Birth';
if ($study_year_raw === '') $missing[] = 'Year of Study';
if ($uses_father_first_name && $father_name_raw === '') $missing[] = "Father's First Name";
// Aadhar prints on the Shivaji eligibility proforma (every dept except polytechnic).
if (($department['code'] ?? '') !== 'polytechnic') {
    if (!preg_match('/^[0-9]{12}$/', $aadhar_number)) $missing[] = 'Aadhar Number (12 digits)';
} elseif ($aadhar_number !== '' && !preg_match('/^[0-9]{12}$/', $aadhar_number)) {
    $missing[] = 'Aadhar Number (12 digits)';
}
// roll_no is optional for polytechnic and dpharm
if ($missing) {
    flash_set('student_error', 'Required field(s) missing: ' . implode(', ', $missing) . '.', 'error');
    redirect($id > 0 ? '../student-profile.php?id=' . $id : '../student-profile.php?new=1');
}

$allowed_study_years = ($department['code'] ?? '') === 'polytechnic'
    ? ['First', 'Second', 'Third']
    : year_options();
if (!in_array($study_year_raw, $allowed_study_years, true)) {
    $message = ($department['code'] ?? '') === 'polytechnic'
        ? 'Polytechnic Year of Study must be First, Second, or Third.'
        : 'Please select a valid Year of Study.';
    flash_set('student_error', $message, 'error');
    redirect($id > 0 ? '../student-profile.php?id=' . $id : '../student-profile.php?new=1');
}

// Format checks (only fire if a value is present, but the required check above
// already guarantees presence for the four fields below)
if (!preg_match('/^[0-9]{10}$/', $mobile_raw)) {
    flash_set('student_error', 'Mobile number must be exactly 10 digits.', 'error');
    redirect($id > 0 ? '../student-profile.php?id=' . $id : '../student-profile.php?new=1');
}
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob_raw, $dob_parts)
    || !checkdate((int)$dob_parts[2], (int)$dob_parts[3], (int)$dob_parts[1])
    || $dob_raw < '1995-01-01'
    || $dob_raw > date('Y-m-d')) {
    flash_set('student_error', 'Date of Birth must be a valid date between 1995 and today.', 'error');
    redirect($id > 0 ? '../student-profile.php?id=' . $id : '../student-profile.php?new=1');
}
if (!filter_var($email_raw, FILTER_VALIDATE_EMAIL)) {
    flash_set('student_error', 'Please enter a valid email address.', 'error');
    redirect($id > 0 ? '../student-profile.php?id=' . $id : '../student-profile.php?new=1');
}

// Faculty can only set department to their own
assert_department_in_scope($dept_id);

// UNIQUE check on enrollment_no
$dup = db_one(
    'SELECT id FROM students WHERE enrollment_no = ? AND id <> ? LIMIT 1',
    [$enrollment_no, $id], 'si'
);
if ($dup) {
    flash_set('student_error', "Enrollment number '$enrollment_no' is already in use.", 'error');
    redirect($id > 0 ? '../student-profile.php?id=' . $id : '../student-profile.php?new=1');
}

// Optional photo upload
$photo_path = null;
if (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $up = handle_image_upload('students', $_FILES['photo'], 2000);
    if (!$up['ok']) {
        flash_set('student_error', 'Photo upload failed: ' . $up['error'], 'error');
        redirect($id > 0 ? '../student-profile.php?id=' . $id : '../student-profile.php?new=1');
    }
    $photo_path = $up['path'];
}

// Pull remaining fields
$data = [
    'enrollment_no' => $enrollment_no,
    'roll_no'       => $stores_roll_no ? ($roll_no_raw !== '' ? $roll_no_raw : null) : null,
    'full_name'     => $full_name,
    'mother_name'   => $stores_mother_name ? ($mother_name_raw !== '' ? $mother_name_raw : null) : null,
    'father_name'   => $uses_father_first_name ? ($father_name_raw !== '' ? $father_name_raw : null) : null,
    'dob'           => ($_POST['dob'] ?? '') ?: null,
    'aadhar_number' => $aadhar_number !== '' ? $aadhar_number : null,
    'gender'        => in_array($_POST['gender'] ?? '', gender_options(), true) ? $_POST['gender'] : null,
    'blood_group'   => in_array($_POST['blood_group'] ?? '', blood_options(), true) ? $_POST['blood_group'] : null,
    'email'         => trim((string)($_POST['email'] ?? '')) ?: null,
    'mobile'        => trim((string)($_POST['mobile'] ?? '')) ?: null,
    'address'       => $address_raw ?: null,
    'department_id' => $dept_id,
    'program'       => trim((string)($_POST['program'] ?? '')) ?: null,
    'academic_year' => trim((string)($_POST['academic_year'] ?? '')) ?: null,
    'study_year'    => $study_year_raw,
    'sport_1'       => trim((string)($_POST['sport_1'] ?? '')) ?: null,
    'sport_2'       => trim((string)($_POST['sport_2'] ?? '')) ?: null,
    'achievements'  => trim((string)($_POST['achievements'] ?? '')) ?: null,
    'has_played_in_college' => isset($_POST['has_played_in_college']) && (int)$_POST['has_played_in_college'] === 1 ? 1 : 0,
];
foreach (participation_levels() as $lvl) {
    $lvlRaw    = $_POST[$lvl['slug'] . '_played'] ?? '';
    $lvlPlayed = in_array((string)$lvlRaw, ['0', '1'], true) ? (int)$lvlRaw : null;
    $lvlYearRaw = trim((string)($_POST[$lvl['slug'] . '_year'] ?? ''));
    $data[$lvl['played_col']] = $lvlPlayed;
    $data[$lvl['year_col']]   = ($lvlPlayed === 1 && $lvlYearRaw !== '') ? $lvlYearRaw : null;
}

if ($id > 0) {
    // UPDATE — must be in scope
    [$scope, $p, $t] = scope_sql_department('s');
    $existing = db_one("SELECT id, photo_path FROM students s WHERE s.id = ? $scope",
        array_merge([$id], $p), 'i' . $t);
    if (!$existing) {
        http_response_code(404);
        exit('Not found.');
    }
    if ($photo_path === null) {
        $photo_path = $existing['photo_path']; // keep existing
    }

    $sql = 'UPDATE students SET
        enrollment_no=?, roll_no=?, full_name=?, mother_name=?, father_name=?, dob=?, aadhar_number=?, gender=?, blood_group=?, email=?, mobile=?,
        address=?, department_id=?, program=?, academic_year=?, study_year=?,
        sport_1=?, sport_2=?, achievements=?, has_played_in_college=?,
        zonal_played=?, zonal_year=?, interzonal_played=?, interzonal_year=?,
        all_india_played=?, all_india_year=?,
        west_zone_played=?, west_zone_year=?,
        krida_mahotsav_played=?, krida_mahotsav_year=?,
        photo_path=?
      WHERE id=?';
    $params = array_values($data);
    $params[] = $photo_path;
    $params[] = $id;
    db_execute($sql, $params);
    if ($is_polytechnic) {
        db_execute(
            'UPDATE final_teams SET roll_no = ? WHERE student_id = ?',
            [$roll_no_raw, $id],
            'si'
        );
    }

    // Best-effort: remove old photo file if it was replaced
    if ($photo_path !== $existing['photo_path'] && !empty($existing['photo_path'])) {
        @unlink(__DIR__ . '/../' . $existing['photo_path']);
    }

    // Parse achievements text and insert into achievements table
    $ach_text = trim((string)($data['achievements'] ?? ''));
    if ($ach_text !== '') {
        foreach (array_filter(array_map('trim', explode("\n", $ach_text))) as $line) {
            if ($line === '') continue;
            db_insert(
                'INSERT INTO achievements (student_id, title, event_name, is_published) VALUES (?,?,?,1)',
                [$id, $line, $line], 'iss'
            );
        }
        // Clear the text field so it doesn't get re-added next save
        db_execute('UPDATE students SET achievements = NULL WHERE id = ?', [$id], 'i');
    }

    flash_set('student_saved', 'Student profile updated successfully.', 'success');
    $final_id = $id;
} else {
    // INSERT
    // Faculty-created students need login credentials too (self-registered
    // students get theirs in register_process.php) — same DOB-derived
    // scheme (DDMMYYYY), so both paths behave identically at first login.
    $plaintext_password = dob_to_password($dob_raw);
    $password_hash = $plaintext_password !== null ? password_hash($plaintext_password, PASSWORD_BCRYPT) : null;

    $sql = 'INSERT INTO students
        (enrollment_no, roll_no, full_name, mother_name, father_name, dob, aadhar_number, gender, blood_group, email, mobile,
         address, department_id, program, academic_year, study_year,
         sport_1, sport_2, achievements, has_played_in_college,
         zonal_played, zonal_year, interzonal_played, interzonal_year,
         all_india_played, all_india_year,
         west_zone_played, west_zone_year,
         krida_mahotsav_played, krida_mahotsav_year,
         photo_path, password_hash, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
    // Get the newly inserted student ID
    $me = current_faculty();
    $params = array_values($data);
    $params[] = $photo_path;
    $params[] = $password_hash;
    $params[] = $me['id'];
    $new_id = db_insert($sql, $params);
    $final_id = $new_id;

    // Parse achievements text and insert into achievements table
    $ach_text = trim((string)($data['achievements'] ?? ''));
    if ($ach_text !== '' && $new_id > 0) {
        foreach (array_filter(array_map('trim', explode("\n", $ach_text))) as $line) {
            if ($line === '') continue;
            db_insert(
                'INSERT INTO achievements (student_id, title, event_name, is_published) VALUES (?,?,?,1)',
                [$new_id, $line, $line], 'iss'
            );
        }
        // Clear the text field
        db_execute('UPDATE students SET achievements = NULL WHERE id = ?', [$new_id], 'i');
    }

    // Email the new login credentials (best-effort — see includes/mailer.php).
    $emailed = false;
    if ($new_id > 0 && $plaintext_password !== null && !empty($data['email'])) {
        $emailed = send_student_credentials_email((string)$data['email'], $full_name, (string)$data['email'], $plaintext_password);
    }

    flash_set('student_saved',
        'Student added successfully.' . ($emailed ? ' Login credentials were emailed to ' . $data['email'] . '.' : ''),
        'success');
}

// Handle Department-Specific Document Uploads
if ($final_id > 0) {
    foreach ($_FILES as $key => $file_data) {
        if (str_starts_with($key, 'doc_')) {
            $req_id = (int)substr($key, 4);
            if ($req_id <= 0) continue;

            // Pull per-row MIME allow-list and size cap from the requirement row.
            $req_row = db_one(
                'SELECT id, document_name, allowed_mime_types
                   FROM dept_document_requirements WHERE id = ?',
                [$req_id], 'i'
            );
            $allowed = $req_row
                ? array_values(array_filter(array_map('trim', explode(',', (string)($req_row['allowed_mime_types'] ?? 'application/pdf')))))
                : ['application/pdf'];
            if (!$allowed) $allowed = ['application/pdf'];
            $isPhoto = in_array('image/jpeg', $allowed, true) && !in_array('application/pdf', $allowed, true);

            // Documents are hard-capped at 1 MB; format depends on the row.
            $up = handle_generic_document_upload('documents', $file_data, $allowed, 1024);
            if (!$up['ok'] && ($file_data['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $err = (string)($up['error'] ?? '');
                if ($err === 'too_large') {
                    flash_set('student_error', 'File "' . h((string)($file_data['name'] ?? '')) . '" is too large. Maximum allowed size is 1 MB. Please compress and try again.', 'error');
                } elseif ($err === 'bad_mime' || $err === 'bad_extension') {
                    $msg = $isPhoto
                        ? 'Only JPEG/JPG files are accepted for the photo. Please upload a JPEG.'
                        : 'Only PDF files are accepted for "' . h((string)($req_row['document_name'] ?? 'this document')) . '". Please upload a PDF.';
                    flash_set('student_error', $msg, 'error');
                } elseif ($err !== '') {
                    flash_set('student_error', 'Document upload failed: ' . $err, 'error');
                }
            }
            if ($up['ok']) {
                // Remove ALL existing records and files for this requirement to prevent duplicates
                $old_docs = db_select('SELECT id, file_path FROM student_documents WHERE student_id = ? AND requirement_id = ?', [$final_id, $req_id], 'ii');
                if ($old_docs) {
                    foreach ($old_docs as $old) {
                        @unlink(__DIR__ . '/../' . $old['file_path']);
                    }
                    db_execute('DELETE FROM student_documents WHERE student_id = ? AND requirement_id = ?', [$final_id, $req_id], 'ii');
                }
                // Save new document
                db_insert(
                    'INSERT INTO student_documents (student_id, requirement_id, file_path) VALUES (?,?,?)',
                    [$final_id, $req_id, $up['path']], 'iis'
                );
            }
        }
    }
}

// Game-checkbox picker (polytechnic + dpharm and any future dept with catalog rows).
// Only the faculty's picker UI sends `games[]`; for other depts we ignore the field.
if ($final_id > 0) {
    $catalog_rows = db_select(
        'SELECT game_code, max_picks FROM dept_game_catalog
          WHERE department_id = ? AND is_active = 1',
        [$dept_id], 'i'
    );
    if (!empty($catalog_rows)) {
        $codes = array_column($catalog_rows, 'game_code');
        $codes_set = array_flip(array_map('strval', $codes));

        $posted = $_POST['games'] ?? null;
        if (is_array($posted)) {
            $posted = array_values(array_filter(
                array_map(function ($v) { return trim((string)$v); }, $posted),
                'strlen'
            ));
        } else {
            $posted = [];
        }

        $err = null;
        if (count($posted) < 1) {
            $err = 'Please select at least 1 game.';
        } elseif (count($posted) !== count(array_unique($posted))) {
            $err = 'Duplicate game selections are not allowed.';
        } else {
            foreach ($posted as $gc) {
                if (!isset($codes_set[$gc])) {
                    $err = "Invalid game selection: {$gc}.";
                    break;
                }
            }
        }
        if ($err) {
            flash_set('student_error', 'Student saved, but games could not be saved: ' . $err, 'error');
        } else {
            db_execute(
                'DELETE FROM student_selected_games WHERE student_id = ?',
                [$final_id], 'i'
            );
            $ins_sql = 'INSERT INTO student_selected_games (student_id, game_code) VALUES (?, ?)';
            foreach ($posted as $gc) {
                db_execute($ins_sql, [$final_id, $gc], 'is');
            }
        }
    }
}

redirect('../student-profile.php?id=' . $final_id);
