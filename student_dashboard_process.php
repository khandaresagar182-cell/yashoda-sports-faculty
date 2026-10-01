<?php
/**
 * Student wizard save endpoint.
 *
 * Four modes on POST, all guarded by CSRF + require_student():
 *   ?step=N       (N=1,2,3,4) — save the fields for that wizard step,
 *                                bump students.form_step to N+1, redirect
 *                                to the next step with a green flash.
 *   ?step=5       — handle a single document upload or delete (called via
 *                    fetch() from the documents UI). Returns JSON.
 *   ?step=6       — save jersey_number/jersey_size, bump form_step to 7,
 *                    redirect to the Preview step with a green flash.
 *   ?finalize=1   — student hit Submit on the preview. Set
 *                    form_submitted_at = NOW(), form_step = 7, redirect
 *                    back to step 7 with a success flash.
 *
 * If a DOB is changed on step 1, the password_hash is re-derived from the
 * new DOB (DDMMYYYY), same as the legacy single-page flow — unless the
 * student chose their own password via email_verify.php
 * (students.password_set_by_user = 1), in which case DOB edits leave the
 * password alone.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('student-dashboard.php');
}

csrf_check();

$meId = (int)current_student()['id'];

/* Locked profile: once submitted, every write below is refused until
 * faculty grants edit access (students.edit_unlocked = 1) on
 * student-profile.php. Re-submitting resets the flag back to 0. */
$lockRow = db_one('SELECT form_submitted_at, edit_unlocked FROM students WHERE id = ?', [$meId], 'i');
if ($lockRow && student_form_locked($lockRow)) {
    flash_set('student_dashboard_err',
        'Your profile is locked after submission. Ask your Faculty of Sports to enable editing before making changes.',
        'error');
    redirect('student-dashboard.php');
}

/* -----------------------------------------------------------------
 * Helpers (local — keep this file self-contained)
 * ----------------------------------------------------------------- */

$stepParam = $_GET['step']    ?? null;
$finalize  = !empty($_GET['finalize']);
$deleteId  = (int)($_GET['delete'] ?? 0);

if ($finalize) {
    handle_finalize($meId);
    return;
}

if ($deleteId > 0) {
    handle_doc_delete($meId, $deleteId);
    return;
}

$step = (int)$stepParam;
if ($step < 1 || $step > 6) {
    redirect('student-dashboard.php');
}

if ($step === 5) {
    if (isset($_POST['bank_details'])) {
        handle_bank_details_save($meId);
        return;
    }
    handle_doc_upload($meId);
    return;
}

if ($step === 6) {
    handle_jersey_save($meId);
    return;
}

/* Steps 1-4: field save */
handle_step_save($meId, $step);

/* unreachable */
redirect('student-dashboard.php?step=' . $step);

function is_valid_passing_year(string $y): bool
{
    return (bool)preg_match('/^(19|20)\d{2}$/', $y) && (int)$y <= (int)date('Y') + 1;
}

/* =================================================================
 * Mode 1 — Step field save (1..4)
 * ================================================================= */
function handle_step_save(int $meId, int $step): void
{
    /* ---- load current row (dept + DOB for password-reset detection) ---- */
    $current = db_one(
        'SELECT s.*, d.code AS department_code
           FROM students s
           JOIN departments d ON d.id = s.department_id
          WHERE s.id = ?',
        [$meId], 'i'
    );
    if (!$current) {
        http_response_code(404);
        exit('Student not found.');
    }

    $dept_code              = (string)($current['department_code'] ?? '');
    $uses_father_first_name = in_array($dept_code, ['engineering', 'pharmacy'], true);
    $stores_roll_no         = in_array($dept_code, ['engineering', 'polytechnic', 'dpharm', 'pharmacy', 'ytc_pharmacy', 'management', 'architecture'], true);
    $needs_course_duration  = $dept_code !== 'polytechnic';

    /* Game-picker detection — same lookup as student-dashboard.php. */
    $catalog_rows = db_select(
        'SELECT game_code, max_picks FROM dept_game_catalog
          WHERE department_id = ? AND is_active = 1',
        [(int)$current['department_id']], 'i'
    );
    $uses_game_picker = !empty($catalog_rows);
    $catalog_codes    = array_column($catalog_rows, 'game_code');
    $catalog_codes_set = array_flip(array_map('strval', $catalog_codes));

    /* ---- pick the field set this step owns ---- */
    $errors = [];

    if ($step === 1) {
        /* Personal */
        $surname     = trim((string)($_POST['surname']     ?? ''));
        $first_name  = trim((string)($_POST['first_name']  ?? ''));
        $middle_name = trim((string)($_POST['middle_name'] ?? ''));
        $posted_full = trim((string)($_POST['full_name']   ?? ''));
        $full_name   = trim(implode(' ', array_filter([$surname, $first_name, $middle_name])));
        if ($full_name === '') $full_name = $posted_full;

        $mother_name  = trim((string)($_POST['mother_name']  ?? ''));
        $dob          = trim((string)($_POST['dob']          ?? ''));
        $aadhar_number = preg_replace('/\D+/', '', (string)($_POST['aadhar_number'] ?? ''));
        $gender       = trim((string)($_POST['gender']       ?? ''));
        $blood_group  = trim((string)($_POST['blood_group']  ?? ''));
        $mobile       = trim((string)($_POST['mobile']       ?? ''));
        $same_as_mobile = !empty($_POST['same_as_mobile']);
        $whatsapp_no  = $same_as_mobile
            ? $mobile
            : trim((string)($_POST['whatsapp_no']  ?? ''));
        $permanent_address = trim((string)($_POST['permanent_address'] ?? ''));
        $same_as_permanent = !empty($_POST['same_as_permanent']);
        $current_address   = $same_as_permanent
            ? $permanent_address
            : trim((string)($_POST['current_address'] ?? ''));

        if (strlen($full_name) < 2 || strlen($full_name) > 160) {
            $errors[] = 'Full name must be 2-160 characters.';
        }
        $dob_ts = strtotime($dob);
        if (!$dob_ts || $dob_ts > time() || $dob_ts < strtotime('1995-01-01')) {
            $errors[] = 'Please enter a valid date of birth (1995 onwards, not in the future).';
        }
        if (!preg_match('/^[0-9]{10}$/', $mobile)) {
            $errors[] = 'Mobile number must be exactly 10 digits.';
        }
        if ($whatsapp_no !== '' && !preg_match('/^[0-9]{10}$/', $whatsapp_no)) {
            $errors[] = 'WhatsApp number must be exactly 10 digits.';
        }
        if ($gender === '' || !in_array($gender, gender_options(), true)) {
            $errors[] = 'Please select your gender.';
        }
        if ($blood_group !== '' && !in_array($blood_group, blood_options(), true)) {
            $errors[] = 'Please select a valid blood group.';
        }
        // Aadhar is printed on the Shivaji University eligibility proforma,
        // which every department except polytechnic uses.
        if ($dept_code !== 'polytechnic' && !preg_match('/^[0-9]{12}$/', $aadhar_number)) {
            $errors[] = 'Aadhar number must be exactly 12 digits.';
        } elseif ($aadhar_number !== '' && !preg_match('/^[0-9]{12}$/', $aadhar_number)) {
            $errors[] = 'Aadhar number must be exactly 12 digits.';
        }
        if ($permanent_address === '' || strlen($permanent_address) > 500) {
            $errors[] = 'Permanent Address is required (max 500 characters).';
        }
        if ($current_address === '' || strlen($current_address) > 500) {
            $errors[] = 'Current Address is required (max 500 characters).';
        }
        /* parent-name semantics:
           For engineering/pharmacy, the father's first name IS the middle name
           (needed to build the legal full name) and is stored in its own
           `father_name` column. `mother_name` always holds the student's
           actual mother's name, collected separately. Middle name is
           required for every department. */
        $father_name = null;
        if ($middle_name === '') {
            $errors[] = 'Middle name is required.';
        } elseif ($uses_father_first_name) {
            $father_name = $middle_name;
        }

        if ($errors) {
            flash_set('student_dashboard_err', implode(' ', $errors), 'error');
            redirect('student-dashboard.php?step=1');
        }

        /* detect DOB change → reset password (skip if the student chose
         * their own password via email verification) */
        $dob_changed = (date('Y-m-d', $dob_ts) !== (string)$current['dob'])
            && (int)($current['password_set_by_user'] ?? 0) === 0;

        $sql = 'UPDATE students SET
                    full_name = ?, mother_name = ?, father_name = ?, dob = ?, aadhar_number = ?, gender = ?, blood_group = ?,
                    mobile = ?, whatsapp_no = ?, address = ?, permanent_address = ?, current_address = ?';
        $params = [
            $full_name,
            $mother_name !== '' ? $mother_name : null,
            $father_name,
            date('Y-m-d', $dob_ts),
            $aadhar_number !== '' ? $aadhar_number : null,
            $gender !== '' ? $gender : null,
            $blood_group !== '' ? $blood_group : null,
            $mobile,
            $whatsapp_no !== '' ? $whatsapp_no : null,
            $permanent_address, // kept in sync so admin list/exports (still on the legacy `address` column) don't go stale
            $permanent_address,
            $current_address,
        ];
        $types = 'ssssssssssss';

        if ($dob_changed) {
            $new_pw_plain = dob_to_password(date('Y-m-d', $dob_ts));
            $new_pw_hash  = password_hash($new_pw_plain, PASSWORD_BCRYPT);
            $sql .= ', password_hash = ?';
            $params[] = $new_pw_hash;
            $types   .= 's';
        }

        $sql .= ', form_step = GREATEST(COALESCE(form_step, 0), 2) WHERE id = ?';
        $params[] = $meId;
        $types   .= 'i';

        db_execute($sql, $params, $types);

        flash_set('student_dashboard_ok',
            $dob_changed
                ? 'Personal info saved. Your password has been reset to your new DOB (DDMMYYYY).'
                : 'Personal info saved.',
            'success');
        redirect('student-dashboard.php?step=2');
    }

    if ($step === 2) {
        /* Academic */
        $enrollment_no  = trim((string)($_POST['enrollment_no']  ?? ''));
        $roll_no        = trim((string)($_POST['roll_no']        ?? ''));
        $program        = trim((string)($_POST['program']        ?? ''));
        $course_duration = trim((string)($_POST['course_duration_years'] ?? ''));
        $department_name = trim((string)($_POST['department_name'] ?? ''));
        $study_year     = trim((string)($_POST['study_year']     ?? ''));
        $ssc_year       = trim((string)($_POST['ssc_passing_year']     ?? ''));
        $hsc_year       = trim((string)($_POST['hsc_passing_year']     ?? ''));
        $diploma_year   = trim((string)($_POST['diploma_passing_year'] ?? ''));
        $fa_univ_year   = trim((string)($_POST['first_admission_university_year'] ?? ''));
        $fa_course_year = trim((string)($_POST['first_admission_course_year']     ?? ''));
        $fa_class_year  = trim((string)($_POST['first_admission_class_year']      ?? ''));
        $gap_year_raw   = $_POST['has_gap_year'] ?? '';
        $has_gap_year   = in_array((string)$gap_year_raw, ['0', '1'], true) ? (int)$gap_year_raw : null;
        $gap_year_detail = trim((string)($_POST['gap_year_detail'] ?? ''));
        $department_id  = (int)($_POST['department_id'] ?? 0);

        if ($department_id <= 0 || $department_id !== (int)$current['department_id']) {
            $errors[] = 'Invalid department.';
        }
        if ($enrollment_no === '' || strlen($enrollment_no) > 40) {
            $errors[] = 'PRN/Enrollment number is required (max 40 characters).';
        } else {
            /* UNIQUE check — same as admin/student_save.php:93-99 */
            $dup = db_one(
                'SELECT id FROM students WHERE enrollment_no = ? AND id <> ? LIMIT 1',
                [$enrollment_no, $meId], 'si'
            );
            if ($dup) {
                $errors[] = "PRN/Enrollment number '$enrollment_no' is already in use.";
            }
        }
        if ($study_year === '' || !in_array($study_year, year_options(), true)) {
            $errors[] = 'Current Year in which studying is required.';
        }
        if ($program === '' || strlen($program) > 120) {
            $errors[] = 'Program is required (max 120 characters).';
        }
        $valid_durations = ['2 Year', '3 Year', '4 Year', '5 Year', '6 Year'];
        if ($needs_course_duration && !in_array($course_duration, $valid_durations, true)) {
            $errors[] = 'Duration of Course is required.';
        }
        if ($department_name === '' || strlen($department_name) > 120) {
            $errors[] = 'Department is required (max 120 characters).';
        }
        if ($ssc_year === '' || !is_valid_passing_year($ssc_year)) {
            $errors[] = 'SSC Passing Year is required and must be a valid 4-digit year.';
        }
        if ($hsc_year !== '' && !is_valid_passing_year($hsc_year)) {
            $errors[] = 'HSC Passing Year must be a valid 4-digit year.';
        }
        if ($diploma_year !== '' && !is_valid_passing_year($diploma_year)) {
            $errors[] = 'Diploma Passing Year must be a valid 4-digit year.';
        }
        if ($fa_univ_year === '' || !is_valid_passing_year($fa_univ_year)) {
            $errors[] = 'Date & Year of First Admission to University / College is required and must be a valid 4-digit year.';
        }
        if ($fa_course_year === '' || !is_valid_passing_year($fa_course_year)) {
            $errors[] = 'Date & Year of First Admission to Present Course is required and must be a valid 4-digit year.';
        }
        if ($fa_class_year === '' || !is_valid_passing_year($fa_class_year)) {
            $errors[] = 'Date & Year of First Admission to Present Class is required and must be a valid 4-digit year.';
        }
        if ($has_gap_year === null) {
            $errors[] = 'Please choose whether you have a Gap / Year Drop.';
        } elseif ($has_gap_year === 1 && $gap_year_detail === '') {
            $errors[] = 'Please mention the year for your Gap / Year Drop.';
        }

        if ($errors) {
            flash_set('student_dashboard_err', implode(' ', $errors), 'error');
            redirect('student-dashboard.php?step=2');
        }

        $gap_year_detail_to_store = $has_gap_year === 1 ? $gap_year_detail : null;

        $sql = 'UPDATE students SET
                    enrollment_no = ?, roll_no = ?, program = ?, course_duration_years = ?, department_name = ?, study_year = ?,
                    ssc_passing_year = ?, hsc_passing_year = ?, diploma_passing_year = ?,
                    first_admission_university_year = ?, first_admission_course_year = ?, first_admission_class_year = ?,
                    has_gap_year = ?, gap_year_detail = ?,
                    form_step = GREATEST(COALESCE(form_step, 0), 3)
                WHERE id = ?';
        $params = [
            $enrollment_no,
            ($stores_roll_no && $roll_no !== '') ? $roll_no : null,
            $program      !== '' ? $program      : null,
            ($needs_course_duration && $course_duration !== '') ? $course_duration : null,
            $department_name,
            $study_year   !== '' ? $study_year   : null,
            $ssc_year,
            $hsc_year     !== '' ? $hsc_year     : null,
            $diploma_year !== '' ? $diploma_year : null,
            $fa_univ_year,
            $fa_course_year,
            $fa_class_year,
            $has_gap_year,
            $gap_year_detail_to_store,
            $meId,
        ];
        $types = 'ssssssssssssisi';

        db_execute($sql, $params, $types);

        flash_set('student_dashboard_ok', 'Academic details saved.', 'success');
        redirect('student-dashboard.php?step=3');
    }

    if ($step === 3) {
        /* Sports */
        if ($uses_game_picker) {
            /* Picker mode (polytechnic, dpharm, etc.) — validate games[] */
            $posted_games = $_POST['games'] ?? null;
            if (!is_array($posted_games)) {
                $errors[] = 'Please select your games.';
            } else {
                $posted_games = array_values(array_filter(
                    array_map(function ($v) { return trim((string)$v); }, $posted_games),
                    'strlen'
                ));
                if (count($posted_games) < 1) {
                    $errors[] = 'Please select at least 1 game.';
                }
                if (count($posted_games) !== count(array_unique($posted_games))) {
                    $errors[] = 'Duplicate game selections are not allowed.';
                }
                foreach ($posted_games as $gc) {
                    if (!isset($catalog_codes_set[$gc])) {
                        $errors[] = "Invalid game selection: {$gc}.";
                        break;
                    }
                }
            }

            if ($errors) {
                flash_set('student_dashboard_err', implode(' ', $errors), 'error');
                redirect('student-dashboard.php?step=3');
            }

            /* Replace-write to student_selected_games.
               sport_1/sport_2/achievements columns are left alone for picker depts (legacy data is preserved). */
            db_execute(
                'DELETE FROM student_selected_games WHERE student_id = ?',
                [$meId], 'i'
            );
            $ins_sql = 'INSERT INTO student_selected_games (student_id, game_code) VALUES (?, ?)';
            $ins_type = 'is';
            foreach ($posted_games as $gc) {
                db_execute($ins_sql, [$meId, $gc], $ins_type);
            }

            db_execute(
                'UPDATE students SET
                    form_step = GREATEST(COALESCE(form_step, 0), 4)
                 WHERE id = ?',
                [$meId],
                'i'
            );

            flash_set('student_dashboard_ok', 'Sports info saved.', 'success');
            redirect('student-dashboard.php?step=4');
        }

        /* Legacy mode — free-text sport_1/sport_2. */
        $sport_1      = trim((string)($_POST['sport_1']      ?? ''));
        $sport_2      = trim((string)($_POST['sport_2']      ?? ''));

        if ($sport_1 === '') $errors[] = 'Please enter your primary sport.';

        if ($errors) {
            flash_set('student_dashboard_err', implode(' ', $errors), 'error');
            redirect('student-dashboard.php?step=3');
        }

        $sql = 'UPDATE students SET
                    sport_1 = ?, sport_2 = ?,
                    form_step = GREATEST(COALESCE(form_step, 0), 4)
                WHERE id = ?';
        $params = [
            $sport_1,
            $sport_2      !== '' ? $sport_2      : null,
            $meId,
        ];
        $types = 'ssi';

        db_execute($sql, $params, $types);

        flash_set('student_dashboard_ok', 'Sports info saved.', 'success');
        redirect('student-dashboard.php?step=4');
    }

    if ($step === 4) {
        /* Played history — gated by has_played_in_college (Yes/No). */
        $raw = $_POST['has_played_in_college'] ?? null;
        if ($raw === null || $raw === '' || !in_array((string)$raw, ['0', '1'], true)) {
            flash_set('student_dashboard_err', 'Please choose whether you have played any sports representing this college.', 'error');
            redirect('student-dashboard.php?step=4');
        }
        $has_played = (int)$raw;

        // Per tournament level: Yes/No + (if Yes) year of participation.
        // If the student says "No" overall, wipe every level's answer.
        $setSql = ['has_played_in_college = ?'];
        $params = [$has_played];
        $types  = 'i';

        foreach (participation_levels() as $lvl) {
            $lvlRaw = $_POST[$lvl['slug'] . '_played'] ?? '';
            $lvlPlayed = in_array((string)$lvlRaw, ['0', '1'], true) ? (int)$lvlRaw : null;
            if ($has_played === 1 && $lvlPlayed === null) {
                flash_set('student_dashboard_err', 'Please choose Yes or No for ' . $lvl['label'] . ' participation.', 'error');
                redirect('student-dashboard.php?step=4');
            }
            if ($has_played === 0) {
                $lvlPlayed = null;
            }

            $lvlYearRaw = trim((string)($_POST[$lvl['slug'] . '_year'] ?? ''));
            if ($has_played === 1 && $lvlPlayed === 1 && $lvlYearRaw === '') {
                flash_set('student_dashboard_err', 'Please mention the year of participation for ' . $lvl['label'] . '.', 'error');
                redirect('student-dashboard.php?step=4');
            }
            $lvlYear = ($lvlPlayed === 1 && $lvlYearRaw !== '') ? $lvlYearRaw : null;

            $setSql[] = "{$lvl['played_col']} = ?";
            $setSql[] = "{$lvl['year_col']} = ?";
            $params[] = $lvlPlayed;
            $params[] = $lvlYear;
            $types   .= 'is';
        }

        $sql = 'UPDATE students SET ' . implode(', ', $setSql) . ',
                    form_step = GREATEST(COALESCE(form_step, 0), 5)
                WHERE id = ?';
        $params[] = $meId;
        $types   .= 'i';

        db_execute($sql, $params, $types);

        $msg = $has_played === 1
            ? 'Played history saved.'
            : 'No prior college-level sports recorded. You can update this later.';
        flash_set('student_dashboard_ok', $msg, 'success');
        redirect('student-dashboard.php?step=5');
    }
}

/* =================================================================
 * Mode 1b — Jersey details save (step=6)
 * ================================================================= */
function handle_jersey_save(int $meId): void
{
    $number = trim((string)($_POST['jersey_number'] ?? ''));
    $size   = trim((string)($_POST['jersey_size'] ?? ''));
    $shorts = trim((string)($_POST['shorts_size'] ?? ''));
    $track  = trim((string)($_POST['track_size'] ?? ''));

    if ($number !== '' && !preg_match('/^[A-Za-z0-9]{1,10}$/', $number)) {
        flash_set('student_dashboard_err', 'Jersey number can only contain letters and digits (max 10 characters).', 'error');
        redirect('student-dashboard.php?step=6');
    }
    if ($size !== '' && !array_key_exists($size, jersey_size_options())) {
        flash_set('student_dashboard_err', 'Please choose a valid jersey size.', 'error');
        redirect('student-dashboard.php?step=6');
    }
    if ($shorts !== '' && !array_key_exists($shorts, shorts_size_options())) {
        flash_set('student_dashboard_err', 'Please choose a valid shorts size.', 'error');
        redirect('student-dashboard.php?step=6');
    }
    if ($track !== '' && !array_key_exists($track, shorts_size_options())) {
        flash_set('student_dashboard_err', 'Please choose a valid track pant size.', 'error');
        redirect('student-dashboard.php?step=6');
    }

    db_execute(
        'UPDATE students
            SET jersey_number = ?, jersey_size = ?, shorts_size = ?, track_size = ?,
                form_step = GREATEST(COALESCE(form_step, 0), 7)
          WHERE id = ?',
        [
            $number !== '' ? $number : null,
            $size !== '' ? $size : null,
            $shorts !== '' ? $shorts : null,
            $track !== '' ? $track : null,
            $meId,
        ],
        'ssssi'
    );

    flash_set('student_dashboard_ok', 'Jersey details saved.', 'success');
    redirect('student-dashboard.php?step=7');
}

/* =================================================================
 * Mode 2b — Bank account details save (step=5, POST bank_details=1)
 * The five text fields that go with the "Bank passbook" upload.
 * Returns JSON; called via fetch().
 * ================================================================= */
function handle_bank_details_save(int $meId): void
{
    header('Content-Type: application/json; charset=utf-8');

    $acc    = trim((string)($_POST['bank_account_number'] ?? ''));
    $acc2   = trim((string)($_POST['bank_account_number_confirm'] ?? ''));
    $bank   = trim((string)($_POST['bank_name'] ?? ''));
    $branch = trim((string)($_POST['bank_branch'] ?? ''));
    $ifsc   = strtoupper(trim((string)($_POST['bank_ifsc'] ?? '')));

    if ($acc === '' || $acc2 === '' || $bank === '' || $branch === '' || $ifsc === '') {
        echo json_encode(['ok' => false, 'message' => 'All five bank fields are required.']);
        return;
    }
    if (!preg_match('/^[0-9]{6,20}$/', $acc)) {
        echo json_encode(['ok' => false, 'message' => 'Account number must be 6–20 digits.']);
        return;
    }
    if ($acc !== $acc2) {
        echo json_encode(['ok' => false, 'message' => 'Account numbers do not match.']);
        return;
    }
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
        echo json_encode(['ok' => false, 'message' => 'IFSC code format is invalid (e.g. SBIN0001234).']);
        return;
    }
    if (mb_strlen($bank) > 120 || mb_strlen($branch) > 120) {
        echo json_encode(['ok' => false, 'message' => 'Bank / branch name is too long (max 120 characters).']);
        return;
    }

    db_execute(
        'UPDATE students
            SET bank_account_number = ?, bank_name = ?, bank_branch = ?, bank_ifsc = ?
          WHERE id = ?',
        [$acc, $bank, $branch, $ifsc, $meId],
        'ssssi'
    );

    echo json_encode(['ok' => true]);
}

/* =================================================================
 * Mode 2 — Document upload (step=5)
 * Returns JSON; called via fetch().
 * ================================================================= */
function handle_doc_upload(int $meId): void
{
    header('Content-Type: application/json; charset=utf-8');

    /* Allow either doc_<id> upload or plain "doc" without an id (rejected). */
    $req_id = 0;
    foreach ($_FILES as $key => $file_data) {
        if (str_starts_with($key, 'doc_')) {
            $req_id = (int)substr($key, 4);
            break;
        }
    }
    if ($req_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'no_doc_field']);
        return;
    }

    /* Verify the requirement belongs to this student's dept */
    $req = db_one(
        'SELECT dr.id, dr.document_name, dr.allowed_mime_types, d.id AS dept_id
           FROM dept_document_requirements dr
           JOIN students s ON s.department_id = dr.department_id
           JOIN departments d ON d.id = dr.department_id
          WHERE dr.id = ? AND s.id = ?
          LIMIT 1',
        [$req_id, $meId], 'ii'
    );
    if (!$req) {
        echo json_encode(['ok' => false, 'error' => 'invalid_requirement']);
        return;
    }

    $allowed = array_values(array_filter(array_map('trim', explode(',', (string)($req['allowed_mime_types'] ?? 'application/pdf,image/jpeg,image/png')))));
    if (!$allowed) $allowed = ['application/pdf', 'image/jpeg', 'image/png'];

    $up = handle_generic_document_upload('documents', $_FILES[key($_FILES)] ?? null, $allowed, 1024);
    if (!$up['ok']) {
        $err = $up['error'] ?? 'upload_failed';
        $isPhoto = in_array('image/jpeg', $allowed, true) && !in_array('application/pdf', $allowed, true);
        if ($err === 'too_large') {
            echo json_encode(['ok' => false, 'error' => 'too_large',
                'message' => 'File is too large. Maximum allowed size is 1 MB. Please compress and try again.']);
            return;
        }
        if ($err === 'bad_mime' || $err === 'bad_extension') {
            $msg = $isPhoto
                ? 'Only JPEG/JPG files are accepted for the photo. Please upload a JPEG.'
                : 'Only PDF files are accepted for this document. Please upload a PDF.';
            echo json_encode(['ok' => false, 'error' => $err, 'message' => $msg]);
            return;
        }
        echo json_encode(['ok' => false, 'error' => $err]);
        return;
    }

    /* Remove existing record + file for this req (replace semantics) */
    $old_docs = db_select(
        'SELECT id, file_path FROM student_documents WHERE student_id = ? AND requirement_id = ?',
        [$meId, $req_id], 'ii'
    );
    if ($old_docs) {
        foreach ($old_docs as $old) {
            @unlink(__DIR__ . '/' . $old['file_path']);
        }
        db_execute('DELETE FROM student_documents WHERE student_id = ? AND requirement_id = ?',
            [$meId, $req_id], 'ii');
    }

    db_insert(
        'INSERT INTO student_documents (student_id, requirement_id, file_path) VALUES (?,?,?)',
        [$meId, $req_id, $up['path']], 'iis'
    );

    /* If this requirement is the student's Passport-size Photo, also mirror
       the same path into students.photo_path so faculty avatar views (which
       read students.photo_path) keep working. The student's Step 1 no longer
       carries a photo upload — the document requirement is the single source. */
    if ((string)($req['document_name'] ?? '') === 'Passport-size Photo') {
        db_execute(
            'UPDATE students SET photo_path = ? WHERE id = ?',
            [$up['path'], $meId], 'si'
        );
    }

    echo json_encode(['ok' => true, 'path' => $up['path']]);
}

/* =================================================================
 * Mode 3 — Document delete (step=5&delete=<doc_id>)
 * Returns JSON; called via fetch().
 * ================================================================= */
function handle_doc_delete(int $meId, int $docId): void
{
    header('Content-Type: application/json; charset=utf-8');

    $doc = db_one(
        'SELECT sd.id, sd.file_path, dr.document_name
           FROM student_documents sd
           JOIN dept_document_requirements dr ON dr.id = sd.requirement_id
          WHERE sd.id = ? AND sd.student_id = ?',
        [$docId, $meId], 'ii'
    );
    if (!$doc) {
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        return;
    }
    @unlink(__DIR__ . '/' . $doc['file_path']);
    db_execute('DELETE FROM student_documents WHERE id = ?', [$docId], 'i');

    /* If the deleted doc was the student's Passport-size Photo, also clear
       students.photo_path so the avatar doesn't keep showing a missing file. */
    if ((string)($doc['document_name'] ?? '') === 'Passport-size Photo') {
        db_execute(
            'UPDATE students SET photo_path = NULL WHERE id = ? AND photo_path = ?',
            [$meId, $doc['file_path']], 'is'
        );
    }

    echo json_encode(['ok' => true]);
}

/* =================================================================
 * Mode 4 — Final submit (finalize=1)
 * Marks form_submitted_at = NOW() and form_step = 7.
 * ================================================================= */
function handle_finalize(int $meId): void
{
    db_execute(
        'UPDATE students
            SET form_submitted_at = NOW(),
                form_step         = 7,
                edit_unlocked     = 0
          WHERE id = ?',
        [$meId], 'i'
    );
    redirect('student-dashboard.php');
}
