<?php
/**
 * External Entries — hub page.
 *
 *   1. Generate Link: Game Level / Game / Gender / Academic Year /
 *      Representing team / Expiry date -> external_entry_links row +
 *      a WhatsApp-style message (see compose_external_whatsapp_message()
 *      in includes/external_entry_helpers.php) with the real link
 *      attached, ready to copy or share via wa.me.
 *   2. Active Links: list + revoke.
 *   3. External Students: submissions for this department (across all
 *      links), with faculty "Add Student" (no email verification — the
 *      faculty vouches for it directly, mirrors how regular students can
 *      be faculty-created) and Delete.
 *
 * Final-team building + eligibility generation lives in
 * external_final_team.php; the archive in external_eligibility_archive.php
 * — kept separate, same granularity as provisional_list/final_list/
 * eligibility_archive.php.
 *
 * Everything here is scoped to effective_department_id(), same as every
 * other admin page (see includes/auth.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_department();

$me     = current_faculty();
$deptId = effective_department_id();

/* ----------------------------------------------------------------- *
 * POST
 * ----------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($deptId === null || $deptId <= 0) {
        flash_set('ext_err', 'Select a faculty first.', 'error');
        redirect('external_entries.php');
    }

    if ($action === 'generate_link') {
        $gameLevel  = (string)($_POST['game_level'] ?? '');
        $gameName   = trim((string)($_POST['game_name'] ?? ''));
        $gender     = (string)($_POST['gender'] ?? '');
        $ay         = trim((string)($_POST['academic_year'] ?? ''));
        $team       = trim((string)($_POST['representing_team'] ?? ''));
        $expiresRaw = trim((string)($_POST['expires_at'] ?? ''));

        $errors = [];
        if (!in_array($gameLevel, ['Interzonal', 'Interuniversity'], true)) {
            $errors[] = 'Select a valid game level.';
        }
        if ($gameName === '' || strlen($gameName) > 80) {
            $errors[] = 'Select a game.';
        }
        if (!array_key_exists($gender, gender_list_options())) {
            $errors[] = 'Select a gender.';
        }
        if (!in_array($ay, academic_year_options(), true)) {
            $errors[] = 'Select a valid academic year.';
        }
        if ($team === '' || strlen($team) > 160) {
            $errors[] = 'Enter the zone / university / team name (max 160 characters).';
        }
        $expiresTs = strtotime($expiresRaw);
        if (!$expiresTs || $expiresTs < strtotime('today')) {
            $errors[] = 'Choose a valid expiry date (today or later).';
        }

        $gameCode = null;
        if (!$errors) {
            $gameCode = resolve_department_game_code($deptId, $gameName);
            if ($gameCode === null) {
                $errors[] = "That game isn't in this faculty's catalog.";
            }
        }

        if ($errors) {
            flash_set('ext_err', implode(' ', $errors), 'error');
            redirect('external_entries.php');
        }

        $expiresAtSql = date('Y-m-d 23:59:59', $expiresTs);
        [$token, $tokenHash] = new_link_token_pair();

        $linkId = db_insert(
            'INSERT INTO external_entry_links
                (department_id, game_level, game_code, game_name, gender, academic_year,
                 representing_team, message_template, token_hash, expires_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$deptId, $gameLevel, $gameCode, $gameName, $gender, $ay, $team, '', $tokenHash, $expiresAtSql, (int)$me['id']],
            'isssssssssi'
        );

        $linkUrl = rtrim(SITE_URL, '/') . '/external-entry.php?token=' . $token;
        $message = compose_external_whatsapp_message($gameLevel, $team, $expiresAtSql, $linkUrl);
        db_execute('UPDATE external_entry_links SET message_template = ? WHERE id = ?', [$message, $linkId], 'si');

        $_SESSION['_ext_link_created'] = [
            'id'       => $linkId,
            'token'    => $token,
            'link_url' => $linkUrl,
            'message'  => $message,
        ];
        flash_set('ext_ok', 'Link generated.', 'success');
        redirect('external_entries.php');
    }

    if ($action === 'revoke_link') {
        $id = (int)($_POST['link_id'] ?? 0);
        db_execute(
            'UPDATE external_entry_links SET revoked_at = NOW() WHERE id = ? AND department_id = ? AND revoked_at IS NULL',
            [$id, $deptId], 'ii'
        );
        flash_set('ext_ok', 'Link revoked — it can no longer be opened.', 'success');
        redirect('external_entries.php');
    }

    if ($action === 'extend_link') {
        $id           = (int)($_POST['link_id'] ?? 0);
        $newExpiryRaw = trim((string)($_POST['new_expires_at'] ?? ''));
        $ts = strtotime($newExpiryRaw);
        if (!$id || !$ts || $ts < strtotime('today')) {
            flash_set('ext_err', 'Choose a valid new expiry date (today or later).', 'error');
            redirect('external_entries.php');
        }
        $newExpiresAtSql = date('Y-m-d 23:59:59', $ts);
        $affected = db_execute(
            'UPDATE external_entry_links SET expires_at = ? WHERE id = ? AND department_id = ? AND revoked_at IS NULL',
            [$newExpiresAtSql, $id, $deptId], 'sii'
        );
        if ($affected > 0) {
            flash_set('ext_ok', 'Link expiry date extended to ' . date('d M Y', $ts) . '.', 'success');
        } else {
            flash_set('ext_err', 'Could not extend — link not found or already revoked.', 'error');
        }
        redirect('external_entries.php');
    }

    if ($action === 'delete_link') {
        $id = (int)($_POST['link_id'] ?? 0);
        $link = db_one(
            'SELECT l.id, (SELECT COUNT(*) FROM external_students es WHERE es.link_id = l.id) AS submission_count
               FROM external_entry_links l WHERE l.id = ? AND l.department_id = ?',
            [$id, $deptId], 'ii'
        );
        if (!$link) {
            flash_set('ext_err', 'Link not found.', 'error');
        } elseif ((int)$link['submission_count'] > 0) {
            // Deleting cascades to external_students (FK ON DELETE CASCADE) —
            // refuse rather than silently wipe real submissions. Faculty
            // must delete those entries individually first (see
            // 'delete_student' below), which also cleans up their files.
            flash_set('ext_err', 'This link has submissions — delete those entries first, or revoke the link instead.', 'error');
        } else {
            db_execute('DELETE FROM external_entry_links WHERE id = ? AND department_id = ?', [$id, $deptId], 'ii');
            flash_set('ext_ok', 'Link deleted.', 'success');
        }
        redirect('external_entries.php');
    }

    if ($action === 'add_student') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        $link   = $linkId > 0 ? external_link_by_id($linkId) : null;
        if (!$link || (int)$link['department_id'] !== $deptId) {
            flash_set('ext_err', 'Select a valid link to add this student to.', 'error');
            redirect('external_entries.php');
        }

        // Same breakdown as the student-facing wizard (external-entry.php
        // Step 1 / external_entry_process.php verify_start) — one
        // "Surname First Middle" full_name, collected as three fields.
        $surname     = trim((string)($_POST['surname'] ?? ''));
        $first_name  = trim((string)($_POST['first_name'] ?? ''));
        $middle_name = trim((string)($_POST['middle_name'] ?? ''));
        $fullName    = trim(implode(' ', array_filter([$surname, $first_name, $middle_name])));

        $email       = strtolower(trim((string)($_POST['email'] ?? '')));
        $motherName  = trim((string)($_POST['mother_name'] ?? ''));
        $dob         = trim((string)($_POST['dob'] ?? ''));
        $gender      = trim((string)($_POST['gender'] ?? ''));
        $aadhar      = trim((string)($_POST['aadhar_number'] ?? ''));
        $mobile      = trim((string)($_POST['mobile'] ?? ''));
        $whatsapp    = trim((string)($_POST['whatsapp_no'] ?? ''));
        $permAddr    = trim((string)($_POST['permanent_address'] ?? ''));
        $currAddr    = trim((string)($_POST['current_address'] ?? ''));

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
        $hasGapYear  = !empty($_POST['has_gap_year']) ? 1 : 0;
        $gapDetail   = trim((string)($_POST['gap_year_detail'] ?? ''));

        $bankAcct   = trim((string)($_POST['bank_account_number'] ?? ''));
        $bankName   = trim((string)($_POST['bank_name'] ?? ''));
        $bankBranch = trim((string)($_POST['bank_branch'] ?? ''));
        $bankIfsc   = trim((string)($_POST['bank_ifsc'] ?? ''));

        $jerseyNo   = trim((string)($_POST['jersey_number'] ?? ''));
        $jerseySize = trim((string)($_POST['jersey_size'] ?? ''));
        $shortsSize = trim((string)($_POST['shorts_size'] ?? ''));
        $trackSize  = trim((string)($_POST['track_size'] ?? ''));

        $hasPlayed = !empty($_POST['has_played_in_college']) ? 1 : 0;
        $played = [];
        foreach (['zonal', 'interzonal', 'all_india', 'west_zone', 'krida_mahotsav'] as $k) {
            $p = !empty($_POST[$k . '_played']) ? 1 : 0;
            $y = trim((string)($_POST[$k . '_year'] ?? ''));
            $played[$k] = ['played' => $p, 'year' => ($p && $y !== '') ? $y : null];
        }

        // Same rule set as the student-facing wizard (includes/external_entry_helpers.php),
        // so the two paths can no longer drift apart. Mobile is mandatory when a
        // faculty adds someone by hand.
        $errors = array_merge(
            external_validate_personal([
                'surname' => $surname, 'first_name' => $first_name, 'middle_name' => $middle_name,
                'email' => $email, 'mother_name' => $motherName, 'dob' => $dob, 'gender' => $gender,
                'aadhar_number' => $aadhar, 'mobile' => $mobile, 'whatsapp_no' => $whatsapp,
                'permanent_address' => $permAddr, 'current_address' => $currAddr,
            ], ['email' => true, 'mobile_required' => true]),
            external_validate_academic([
                'college_name' => $collegeName, 'program' => $program, 'study_year' => $studyYear,
                'course_duration_years' => $duration, 'admission_year' => $admissionYr,
                'ssc_passing_year' => $sscYear, 'hsc_passing_year' => $hscYear, 'diploma_passing_year' => $diplomaYear,
                'first_admission_university_year' => $faUniYear, 'first_admission_course_year' => $faCourseYr,
                'first_admission_class_year' => $faClassYr,
                'has_gap_year' => (string)$hasGapYear, 'gap_year_detail' => $gapDetail,
            ]),
            external_validate_played($_POST),
            external_validate_jersey([
                'jersey_number' => $jerseyNo, 'jersey_size' => $jerseySize,
                'shorts_size' => $shortsSize, 'track_size' => $trackSize,
            ])
        );
        // Bank fields (collected here only): column limits are 30 / 120 / 120 / 15.
        if (mb_strlen($bankAcct, 'UTF-8') > 30 || mb_strlen($bankName, 'UTF-8') > 120
            || mb_strlen($bankBranch, 'UTF-8') > 120 || mb_strlen($bankIfsc, 'UTF-8') > 15) {
            $errors[] = 'A bank detail is too long (account ≤ 30, bank ≤ 120, branch ≤ 120, IFSC ≤ 15 characters).';
        }

        if (!$errors && external_student_by_link_email($linkId, $email)) {
            $errors[] = 'A submission already exists for this email on this link.';
        }

        if ($errors) {
            flash_set('ext_err', implode(' ', $errors), 'error');
            redirect('external_entries.php');
        }

        $cols = [
            'link_id'                => $linkId,
            'department_id'          => $deptId,
            'full_name'              => $fullName,
            'mother_name'            => $motherName ?: null,
            'dob'                    => $dob ?: null,
            'gender'                 => $gender ?: null,
            'aadhar_number'          => $aadhar ?: null,
            'email'                  => $email,
            'mobile'                 => $mobile,
            'whatsapp_no'            => $whatsapp ?: null,
            'permanent_address'      => $permAddr ?: null,
            'current_address'        => $currAddr ?: null,
            'college_name'           => $collegeName,
            'program'                => $program ?: null,
            'study_year'             => $studyYear ?: null,
            'course_duration_years'  => $duration ?: null,
            'admission_year'         => $admissionYr ?: null,
            'ssc_passing_year'       => $sscYear ?: null,
            'hsc_passing_year'       => $hscYear ?: null,
            'diploma_passing_year'   => $diplomaYear ?: null,
            'first_admission_university_year' => $faUniYear ?: null,
            'first_admission_course_year'     => $faCourseYr ?: null,
            'first_admission_class_year'      => $faClassYr ?: null,
            'has_gap_year'           => $hasGapYear,
            'gap_year_detail'        => ($hasGapYear === 1 && $gapDetail !== '') ? $gapDetail : null,
            'academic_year'          => (string)$link['academic_year'],
            'bank_account_number'    => $bankAcct ?: null,
            'bank_name'              => $bankName ?: null,
            'bank_branch'            => $bankBranch ?: null,
            'bank_ifsc'              => $bankIfsc ?: null,
            'jersey_number'          => $jerseyNo ?: null,
            'jersey_size'            => $jerseySize ?: null,
            'shorts_size'            => $shortsSize ?: null,
            'track_size'             => $trackSize ?: null,
            'has_played_in_college'  => $hasPlayed,
            'zonal_played'           => $played['zonal']['played'],
            'zonal_year'             => $played['zonal']['year'],
            'interzonal_played'      => $played['interzonal']['played'],
            'interzonal_year'        => $played['interzonal']['year'],
            'all_india_played'       => $played['all_india']['played'],
            'all_india_year'         => $played['all_india']['year'],
            'west_zone_played'       => $played['west_zone']['played'],
            'west_zone_year'         => $played['west_zone']['year'],
            'krida_mahotsav_played'  => $played['krida_mahotsav']['played'],
            'krida_mahotsav_year'    => $played['krida_mahotsav']['year'],
            'added_by'               => (int)$me['id'],
        ];

        $colNames     = array_keys($cols);
        $placeholders = implode(',', array_fill(0, count($colNames), '?'));
        $types  = '';
        $values = [];
        foreach ($cols as $v) {
            $types   .= is_int($v) ? 'i' : 's';
            $values[] = $v;
        }

        db_insert(
            'INSERT INTO external_students (' . implode(',', $colNames) . ', email_verified_at, form_step)
             VALUES (' . $placeholders . ', NOW(), 1)',
            $values, $types
        );
        flash_set('ext_ok', 'Student added with full details. They can still use "Resume with your email" on the link to add a photo or documents themselves.', 'success');
        redirect('external_entries.php');
    }

    if ($action === 'delete_student') {
        $id  = (int)($_POST['student_id'] ?? 0);
        $row = $id > 0 ? external_student_by_id($id) : null;
        if ($row && (int)$row['department_id'] === $deptId) {
            $docs = db_select('SELECT file_path FROM external_student_documents WHERE external_student_id = ?', [$id], 'i');
            foreach ($docs as $d) {
                $abs = dirname(__DIR__) . '/' . ltrim((string)$d['file_path'], '/');
                if (strpos((string)$d['file_path'], '..') === false && is_file($abs)) @unlink($abs);
            }
            if (!empty($row['photo_path']) && strpos((string)$row['photo_path'], '..') === false) {
                $absPhoto = dirname(__DIR__) . '/' . ltrim((string)$row['photo_path'], '/');
                if (is_file($absPhoto)) @unlink($absPhoto);
            }
            db_execute('DELETE FROM external_students WHERE id = ?', [$id], 'i');
            flash_set('ext_ok', 'Entry deleted.', 'success');
        } else {
            flash_set('ext_err', 'Entry not found.', 'error');
        }
        redirect('external_entries.php');
    }

    http_response_code(400);
    exit('Bad request.');
}

/* ----------------------------------------------------------------- *
 * GET
 * ----------------------------------------------------------------- */
$ok  = flash_get('ext_ok');
$err = flash_get('ext_err');

$justCreated = $_SESSION['_ext_link_created'] ?? null;
if ($justCreated) unset($_SESSION['_ext_link_created']);

$gameOptions = $deptId ? load_department_game_names($deptId) : [];
$ayOptions   = academic_year_options();
$genderOpts  = gender_list_options();

$links     = [];
$students  = [];
$linkFilter = (int)($_GET['link'] ?? 0);
$studentPage  = max(1, (int)($_GET['spage'] ?? 1));
$studentPer   = 50;
$studentTotal = 0;
$studentPages = 1;

if ($deptId !== null && $deptId > 0) {
    $links = db_select(
        "SELECT l.*, (SELECT COUNT(*) FROM external_students es WHERE es.link_id = l.id) AS submission_count,
                (SELECT COUNT(*) FROM external_students es WHERE es.link_id = l.id AND es.form_submitted_at IS NOT NULL) AS submitted_count
           FROM external_entry_links l
          WHERE l.department_id = ?
          ORDER BY l.created_at DESC",
        [$deptId], 'i'
    );

    $studentWhereSql = 'es.department_id = ?';
    $studentParams   = [$deptId];
    $studentTypes    = 'i';
    if ($linkFilter > 0) {
        $studentWhereSql .= ' AND es.link_id = ?';
        $studentParams[]  = $linkFilter;
        $studentTypes    .= 'i';
    }

    $studentTotal = (int)(db_one(
        "SELECT COUNT(*) AS n FROM external_students es WHERE $studentWhereSql",
        $studentParams, $studentTypes
    )['n'] ?? 0);
    $studentPages = max(1, (int)ceil($studentTotal / $studentPer));
    if ($studentPage > $studentPages) $studentPage = $studentPages;
    $studentOffset = ($studentPage - 1) * $studentPer;

    $studentSql = "SELECT es.*, l.game_name AS link_game_name, l.game_level AS link_game_level
                      FROM external_students es
                      JOIN external_entry_links l ON l.id = es.link_id
                     WHERE $studentWhereSql
                     ORDER BY es.created_at DESC
                     LIMIT $studentPer OFFSET $studentOffset";
    $students = db_select($studentSql, $studentParams, $studentTypes);
}

$activeLinksForAdd = array_values(array_filter($links, static fn($l) => empty($l['revoked_at']) && strtotime((string)$l['expires_at']) >= time()));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>External Entries | Sports Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root{--primary-navy:#1a365d;--primary-navy-dark:#0f2744;--primary-navy-light:#2c5282;--accent-gold:#c9a227;--accent-maroon:#722f37;--white:#fff;--off-white:#f8f9fa;--light-gray:#e9ecef;--medium-gray:#6c757d;--dark-gray:#343a40;--text-dark:#212529;--font-primary:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;--sidebar-width:260px;--transition-smooth:all .3s ease-in-out}
        *{margin:0;padding:0;box-sizing:border-box}html,body{height:100%;overflow:hidden}
        body{font-family:var(--font-primary);color:var(--text-dark);background:var(--off-white);line-height:1.6}
        .app-wrapper{display:flex;height:100vh}
        .sidebar{width:var(--sidebar-width);background:linear-gradient(180deg,var(--primary-navy-dark),var(--primary-navy));color:var(--white);display:flex;flex-direction:column;flex-shrink:0;overflow:hidden}
        .sidebar-brand{padding:1.25rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:.75rem}
        .sidebar-brand img{width:42px;height:42px;border-radius:8px;object-fit:contain;background:rgba(255,255,255,.1);padding:3px}
        .sidebar-brand-text h2{font-size:.85rem;font-weight:700;color:var(--white);margin:0}
        .sidebar-brand-text span{font-size:.7rem;color:rgba(255,255,255,.5)}
        .sidebar-nav{flex:1;padding:1rem 0;overflow-y:auto}
        .sidebar-nav-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.35);padding:.75rem 1.5rem .4rem}
        .sidebar-nav a{display:flex;align-items:center;gap:.75rem;padding:.7rem 1.5rem;color:rgba(255,255,255,.65);font-size:.88rem;font-weight:500;text-decoration:none;transition:var(--transition-smooth);border-left:3px solid transparent}
        .sidebar-nav a:hover{color:var(--white);background:rgba(255,255,255,.06);border-left-color:rgba(201,162,39,.4)}
        .sidebar-nav a.active{color:var(--white);background:rgba(201,162,39,.12);border-left-color:var(--accent-gold)}
        .sidebar-nav a.active i{color:var(--accent-gold)}
        .sidebar-nav a i{font-size:1.15rem;width:22px;text-align:center}
        .sidebar-footer{padding:1rem 1.25rem;border-top:1px solid rgba(255,255,255,.08)}
        .sidebar-user{display:flex;align-items:center;gap:.75rem}
        .sidebar-user-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent-gold),var(--accent-maroon));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:var(--white);flex-shrink:0}
        .sidebar-user-info h4{font-size:.82rem;font-weight:600;color:var(--white);margin:0}
        .sidebar-user-info span{font-size:.7rem;color:rgba(255,255,255,.5)}
        .btn-logout{margin-left:auto;background:0 0;border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.6);padding:.35rem .5rem;border-radius:6px;cursor:pointer;font-size:.85rem;text-decoration:none}
        .btn-logout:hover{background:rgba(220,53,69,.2);border-color:rgba(220,53,69,.4);color:#ff8a8a}
        .main-content{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
        .top-bar{background:var(--white);border-bottom:1px solid var(--light-gray);padding:.75rem 2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
        .content-body{flex:1;overflow-y:auto;padding:2rem}
        .page-header{margin-bottom:1.25rem}
        .page-header h1{font-size:1.4rem;font-weight:700;color:var(--primary-navy)}
        .page-header p{color:var(--medium-gray);font-size:.9rem;margin-top:.25rem}
        .btn{padding:.55rem 1.1rem;border-radius:6px;font-size:.88rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;transition:var(--transition-smooth)}
        .btn-primary{background:var(--primary-navy);color:var(--white)}.btn-primary:hover{background:var(--primary-navy-dark)}
        .btn-secondary{background:var(--off-white);color:var(--primary-navy);border:1px solid var(--light-gray)}.btn-secondary:hover{background:var(--light-gray)}
        .btn-danger{background:#fff5f5;color:#c53030;border:1px solid #fed7d7}.btn-danger:hover:not(:disabled){background:#fed7d7}
        .btn-success{background:#e6f9ee;color:#0a3622;border:1px solid #bcebd1}.btn-success:hover{background:#d4f3e2}
        .btn-sm{padding:.35rem .7rem;font-size:.8rem}
        .data-card{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;overflow:hidden;margin-bottom:1.5rem}
        .data-card-header{padding:1rem 1.25rem;border-bottom:1px solid var(--light-gray);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
        .data-card-header h2{font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0}
        .data-card-body{padding:1.25rem}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.7rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.7rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);color:var(--text-dark);vertical-align:middle}
        .data-table tr:last-child td{border-bottom:none}
        .data-table tr:hover{background:var(--off-white)}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .alert-banner.info{background:rgba(13,110,253,.1);color:#052c65;border:1px solid rgba(13,110,253,.2)}
        .empty-row{text-align:center;color:var(--medium-gray);padding:3rem 1rem;font-size:.9rem}
        .empty-row i{font-size:2.5rem;display:block;margin-bottom:.5rem;color:var(--light-gray)}
        .pagination{padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;border-top:1px solid var(--light-gray)}
        .pagination .info{font-size:.85rem;color:var(--medium-gray)}
        .pagination .pages{display:flex;gap:.25rem}
        .pagination .pages a{padding:.35rem .65rem;border:1px solid var(--light-gray);border-radius:4px;font-size:.85rem;color:var(--primary-navy);text-decoration:none;background:var(--white)}
        .pagination .pages a:hover{background:var(--off-white)}
        .pagination .pages a.active{background:var(--primary-navy);color:var(--white);border-color:var(--primary-navy)}
        .pagination .pages a.disabled{opacity:.4;pointer-events:none}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem}
        .form-group label{display:block;font-size:.88rem;font-weight:600;color:var(--primary-navy);margin-bottom:.4rem;text-transform:none;letter-spacing:normal}
        .form-group input,.form-group select{width:100%;padding:.6rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font:inherit;font-size:.92rem}
        .form-group input:focus,.form-group select:focus{outline:none;border-color:var(--primary-navy)}
        .section-head{font-size:.85rem;font-weight:700;color:var(--primary-navy);margin:1.25rem 0 .75rem;padding-bottom:.4rem;border-bottom:1px solid var(--light-gray);display:flex;align-items:center;gap:.4rem}
        .section-head:first-child{margin-top:0}
        .section-head i{color:var(--accent-gold)}
        .checkbox-row{display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem}
        .checkbox-row input{width:auto}
        .played-block{border:1px solid var(--light-gray);border-radius:8px;padding:.85rem;margin-bottom:.6rem}
        .msg-preview{background:var(--off-white);border:1px dashed var(--light-gray);border-radius:8px;padding:1rem;font-size:.86rem;white-space:pre-wrap;color:var(--text-dark);margin-top:1rem}
        .created-panel{background:#e6f9ee;border:1px solid #bcebd1;border-radius:10px;padding:1.25rem;margin-bottom:1.5rem}
        .created-panel h3{color:#0a3622;font-size:1rem;margin-bottom:.6rem;display:flex;align-items:center;gap:.5rem}
        .created-panel textarea{width:100%;min-height:140px;border:1px solid #bcebd1;border-radius:8px;padding:.75rem;font:inherit;font-size:.86rem;resize:vertical;background:#fff}
        .created-actions{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.75rem}
        .badge{display:inline-block;padding:.2rem .55rem;border-radius:5px;font-size:.72rem;font-weight:700}
        .badge-open{background:rgba(25,135,84,.12);color:#0a3622}
        .badge-expired{background:rgba(220,53,69,.1);color:#842029}
        .badge-revoked{background:rgba(108,117,125,.15);color:#495057}
        .badge-verified{background:rgba(25,135,84,.12);color:#0a3622}
        .badge-pending{background:rgba(255,193,7,.15);color:#664d03}
        .badge-submitted{background:rgba(13,110,253,.12);color:#052c65}
        .toggle-link{font-size:.85rem;color:var(--primary-navy);font-weight:600;cursor:pointer;text-decoration:underline}
        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .data-card{overflow-x:auto;-webkit-overflow-scrolling:touch}
            .data-table{min-width:640px}
        }
        @media(max-width:576px){.content-body{padding:1rem .75rem}}
    </style>
</head>
<body>
    <div class="app-wrapper">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <img src="<?= h(url('images/ytc-logo.png')) ?>" alt="YTC Logo">
                <div class="sidebar-brand-text">
                    <h2>Sports Database</h2>
                    <span>Yashoda Technical Campus</span>
                </div>
            </div>
            <nav class="sidebar-nav">
                <div class="sidebar-nav-label">Main</div>
                <?php if (has_multiple_departments()): ?>
                    <a href="../faculty-select.php?change=1"><i class="bi bi-building"></i> <span>Select Faculty</span></a>
                <?php endif; ?>
                <a href="dashboard.php"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a>
                <a href="../student-search.php"><i class="bi bi-search"></i> <span>Search Students</span></a>
                <a href="../student-profile.php?new=1"><i class="bi bi-person-plus"></i> <span>Add Student</span></a>
                <a href="provisional_list.php"><i class="bi bi-clipboard-check"></i> <span>Provisional Players</span></a>
                <a href="final_list.php"><i class="bi bi-check-all"></i> <span>Final Teams</span></a>
                <a href="eligibility_archive.php"><i class="bi bi-folder2-open"></i> <span>Eligibility Archive</span></a>
                <div class="sidebar-nav-label">External Entries</div>
                <a href="external_entries.php" class="active"><i class="bi bi-link-45deg"></i> <span>Links &amp; Entries</span></a>
                <a href="external_final_team.php"><i class="bi bi-people"></i> <span>External Final Team</span></a>
                <a href="external_eligibility_archive.php"><i class="bi bi-folder2"></i> <span>External Archive</span></a>
                <div class="sidebar-nav-label">Other</div>
                <a href="jersey_dashboard.php"><i class="bi bi-person-badge"></i> <span>Jersey Kit</span></a>
                <a href="data_management.php"><i class="bi bi-database-fill-gear"></i> <span>Data Management</span></a>
                <?php if (($me['role'] ?? '') === 'SUPER_ADMIN'): ?>
                    <div class="sidebar-nav-label">Site Content</div>
                    <a href="notices_list.php"><i class="bi bi-megaphone"></i> <span>Notices</span></a>
                    <a href="achievements_list.php"><i class="bi bi-trophy"></i> <span>Achievements</span></a>
                    <a href="committee_manage.php"><i class="bi bi-people-fill"></i> <span>Committee</span></a>
                    <div class="sidebar-nav-label">Admin</div>
                    <a href="faculty_manage.php"><i class="bi bi-people-fill"></i> <span>Faculty Management</span></a>
                    <a href="document_requirements.php"><i class="bi bi-file-earmark-ruled"></i> <span>Document Requirements</span></a>
                    <a href="sports_assign.php"><i class="bi bi-trophy-fill"></i> <span>Sports Assignment</span></a>
                <?php endif; ?>
                <div class="sidebar-nav-label">Site</div>
                <a href="../index.php"><i class="bi bi-globe"></i> <span>View Website</span></a>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar"><?= h(initials($me['full_name'])) ?></div>
                    <div class="sidebar-user-info">
                        <h4><?= h($me['full_name']) ?></h4>
                        <span><?= h($me['department_name'] ?? $me['role']) ?></span>
                    </div>
                    <a href="logout.php?_csrf=<?= h(csrf_token()) ?>" class="btn-logout" title="Logout" aria-label="Logout"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <header class="top-bar">
                <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">External Entries</h2>
            </header>

            <div class="content-body">
                <?php if ($ok):  ?><div class="alert-banner success" role="alert"><i class="bi bi-check-circle"></i> <?= h($ok['msg']) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert-banner error" role="alert"><i class="bi bi-exclamation-circle"></i> <?= h($err['msg']) ?></div><?php endif; ?>

                <?php if ($deptId === null || $deptId <= 0): ?>
                    <div class="data-card"><div class="empty-row">
                        <i class="bi bi-building"></i> Select a faculty to manage external entries.<br><br>
                        <a href="../faculty-select.php?change=1" class="btn btn-secondary btn-sm"><i class="bi bi-building"></i> Select Faculty</a>
                    </div></div>
                <?php else: ?>

                <div class="page-header">
                    <h1>External Entries</h1>
                    <p>Generate a link for interzonal / interuniversity selections outside this college, and manage the submissions that come back.</p>
                </div>

                <?php if ($justCreated): ?>
                    <div class="created-panel">
                        <h3><i class="bi bi-check-circle-fill"></i> Link generated</h3>
                        <textarea id="createdMsg" readonly aria-label="Generated message"><?= h($justCreated['message']) ?></textarea>
                        <div class="created-actions">
                            <button type="button" class="btn btn-primary" onclick="extCopyText('createdMsg', this)"><i class="bi bi-clipboard"></i> Copy Message</button>
                            <a class="btn btn-success" target="_blank" rel="noopener"
                               href="https://wa.me/?text=<?= rawurlencode($justCreated['message']) ?>">
                                <i class="bi bi-whatsapp"></i> Share via WhatsApp
                            </a>
                            <button type="button" class="btn btn-secondary" onclick="extCopyPlain('<?= h(addslashes($justCreated['link_url'])) ?>', this)"><i class="bi bi-link-45deg"></i> Copy Link Only</button>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="data-card">
                    <div class="data-card-header"><h2><i class="bi bi-link-45deg"></i> Generate a new link</h2></div>
                    <div class="data-card-body">
                        <?php if (empty($gameOptions)): ?>
                            <div class="alert-banner info" style="margin-bottom:0"><i class="bi bi-info-circle"></i> This faculty has no games in its catalog yet — ask an admin to set that up first.</div>
                        <?php else: ?>
                        <form method="post" action="external_entries.php" id="genLinkForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="generate_link">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Game Level</label>
                                    <select name="game_level" id="fGameLevel" required>
                                        <option value="">Select level&hellip;</option>
                                        <option value="Interzonal">Interzonal</option>
                                        <option value="Interuniversity">Interuniversity</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Game</label>
                                    <select name="game_name" id="fGameName" required>
                                        <option value="">Select game&hellip;</option>
                                        <?php foreach ($gameOptions as $g): ?>
                                            <option value="<?= h($g) ?>"><?= h($g) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Gender</label>
                                    <select name="gender" id="fGender" required>
                                        <option value="">Select&hellip;</option>
                                        <?php foreach ($genderOpts as $val => $label): ?>
                                            <option value="<?= h($val) ?>"><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Academic Year</label>
                                    <select name="academic_year" id="fAy" required>
                                        <?php foreach ($ayOptions as $y): ?>
                                            <option value="<?= h($y) ?>" <?= $y === current_academic_year() ? 'selected' : '' ?>><?= h($y) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Representing (Zone / University / Team)</label>
                                    <input type="text" name="representing_team" id="fTeam" maxlength="160" placeholder="e.g. West Zone" required>
                                </div>
                                <div class="form-group">
                                    <label>Link Expiry Date</label>
                                    <div style="display:flex;gap:.4rem">
                                        <input type="text" name="expires_at" id="fExpiry" autocomplete="off" placeholder="dd-mm-yyyy" required style="flex:1">
                                        <button type="button" class="btn btn-secondary btn-sm" id="fExpiryBtn" title="Open calendar" aria-label="Open calendar"><i class="bi bi-calendar3"></i></button>
                                    </div>
                                </div>
                            </div>
                            <div class="msg-preview" id="msgPreview">Dear Student, you are hereby informed that you have been provisionally selected in the &hellip; tournament&hellip;</div>
                            <div style="margin-top:1rem">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-magic"></i> Generate Link</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="data-card">
                    <div class="data-card-header"><h2><i class="bi bi-collection"></i> Active links</h2></div>
                    <?php if (!$links): ?>
                        <div class="empty-row"><i class="bi bi-link"></i> No links generated yet.</div>
                    <?php else: ?>
                    <div style="overflow-x:auto">
                    <table class="data-table">
                        <thead><tr>
                            <th>Level</th><th>Game</th><th>Gender</th><th>Year</th><th>Representing</th>
                            <th>Expiry</th><th>Status</th><th>Submissions</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($links as $l): ?>
                            <?php
                                $isRevoked = !empty($l['revoked_at']);
                                $isExpired = !$isRevoked && strtotime((string)$l['expires_at']) < time();
                                $statusBadge = $isRevoked ? ['revoked', 'Revoked'] : ($isExpired ? ['expired', 'Expired'] : ['open', 'Open']);
                            ?>
                            <tr>
                                <td><?= h($l['game_level']) ?></td>
                                <td style="font-weight:600;color:var(--primary-navy)"><?= h($l['game_name']) ?></td>
                                <td><?= h($genderOpts[$l['gender']] ?? $l['gender']) ?></td>
                                <td><?= h($l['academic_year']) ?></td>
                                <td><?= h($l['representing_team']) ?></td>
                                <td><?= h(date('d M Y', strtotime((string)$l['expires_at']))) ?></td>
                                <td><span class="badge badge-<?= $statusBadge[0] ?>"><?= $statusBadge[1] ?></span></td>
                                <td><a href="external_entries.php?link=<?= (int)$l['id'] ?>"><?= (int)$l['submitted_count'] ?> / <?= (int)$l['submission_count'] ?></a></td>
                                <td style="white-space:nowrap">
                                    <div style="display:flex;gap:.4rem;justify-content:flex-end">
                                    <?php if (!$isRevoked): ?>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleExtend(<?= (int)$l['id'] ?>)"><i class="bi bi-calendar-plus"></i> Extend</button>
                                    <form method="post" action="external_entries.php" style="display:inline" onsubmit="return confirm('Revoke this link? Students will no longer be able to open it.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="revoke_link">
                                        <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-slash-circle"></i> Revoke</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ((int)$l['submission_count'] === 0): ?>
                                    <form method="post" action="external_entries.php" style="display:inline" onsubmit="return confirm('Delete this link permanently? This cannot be undone.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_link">
                                        <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete link"><i class="bi bi-trash3"></i> Delete</button>
                                    </form>
                                    <?php else: ?>
                                        <span style="font-size:.85rem;color:var(--medium-gray);align-self:center" title="Delete the submitted entries below first to delete this link">
                                            <i class="bi bi-lock-fill"></i>
                                        </span>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php if (!$isRevoked): ?>
                            <tr id="extendRow<?= (int)$l['id'] ?>" class="extend-row" style="display:none">
                                <td colspan="9" style="background:var(--off-white)">
                                    <form method="post" action="external_entries.php" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="extend_link">
                                        <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
                                        <div class="form-group" style="margin:0">
                                            <label style="margin-bottom:.25rem">New expiry date</label>
                                            <div style="display:flex;gap:.4rem">
                                                <input type="text" name="new_expires_at" class="ext-date-picker" autocomplete="off" placeholder="dd-mm-yyyy" required
                                                       value="<?= h(date('Y-m-d', strtotime((string)$l['expires_at']))) ?>" style="min-width:150px">
                                                <button type="button" class="btn btn-secondary btn-sm ext-date-btn" title="Open calendar" aria-label="Open calendar"><i class="bi bi-calendar3"></i></button>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Save</button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleExtend(<?= (int)$l['id'] ?>)">Cancel</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="data-card">
                    <div class="data-card-header">
                        <h2><i class="bi bi-people"></i> External students<?= $linkFilter > 0 ? ' — filtered' : '' ?></h2>
                        <div style="display:flex;gap:.6rem;align-items:center">
                            <?php if ($linkFilter > 0): ?><a href="external_entries.php" class="btn btn-secondary btn-sm">Clear filter</a><?php endif; ?>
                            <?php if ($activeLinksForAdd): ?>
                                <span class="toggle-link" onclick="document.getElementById('addStudentPanel').style.display='block';this.style.display='none'"><i class="bi bi-person-plus"></i> Add student manually</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($activeLinksForAdd): ?>
                    <div class="data-card-body" id="addStudentPanel" style="display:none;border-bottom:1px solid var(--light-gray)">
                        <div class="alert-banner info"><i class="bi bi-info-circle"></i> Fill this in exactly like the student's own form. Their email is verified automatically — they can still use "Resume with your email" on the link later to add a photo or documents.</div>
                        <form method="post" action="external_entries.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_student">

                            <div class="section-head"><i class="bi bi-link-45deg"></i> Link</div>
                            <div class="form-grid">
                                <div class="form-group" style="grid-column:1/-1">
                                    <label>Link</label>
                                    <select name="link_id" required>
                                        <?php foreach ($activeLinksForAdd as $l): ?>
                                            <option value="<?= (int)$l['id'] ?>" <?= $linkFilter === (int)$l['id'] ? 'selected' : '' ?>><?= h($l['game_name'] . ' — ' . $l['game_level'] . ' — ' . ($genderOpts[$l['gender']] ?? $l['gender']) . ' — ' . $l['academic_year']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="section-head"><i class="bi bi-person-vcard"></i> Name &amp; Demographics</div>
                            <div class="form-grid">
                                <div class="form-group"><label>Surname *</label><input type="text" name="surname" maxlength="60" required></div>
                                <div class="form-group"><label>First Name *</label><input type="text" name="first_name" maxlength="60" required></div>
                                <div class="form-group"><label>Middle Name *</label><input type="text" name="middle_name" maxlength="60" required></div>
                                <div class="form-group"><label>Mother's Name</label><input type="text" name="mother_name" maxlength="160"></div>
                                <div class="form-group"><label>Date of Birth</label><input type="text" name="dob" id="addDob" autocomplete="off" placeholder="dd-mm-yyyy"></div>
                                <div class="form-group"><label>Aadhar Number</label><input type="text" name="aadhar_number" maxlength="12" pattern="[0-9]{12}" inputmode="numeric" placeholder="12-digit Aadhar number"></div>
                                <div class="form-group">
                                    <label>Gender</label>
                                    <select name="gender">
                                        <option value="">Select</option>
                                        <?php foreach (gender_options() as $g): ?><option value="<?= h($g) ?>"><?= h($g) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="section-head"><i class="bi bi-telephone"></i> Contact Details</div>
                            <div class="form-grid">
                                <div class="form-group"><label>Email *</label><input type="email" name="email" maxlength="160" required></div>
                                <div class="form-group"><label>Mobile No. *</label><input type="tel" name="mobile" required pattern="[0-9]{10}" maxlength="10" inputmode="numeric"></div>
                                <div class="form-group"><label>WhatsApp No.</label><input type="tel" name="whatsapp_no" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" placeholder="Leave blank if same as Mobile No."></div>
                                <div class="form-group" style="grid-column:1/-1"><label>Permanent Address</label><input type="text" name="permanent_address" maxlength="500" placeholder="House no / street, area, city, state, pincode"></div>
                                <div class="form-group" style="grid-column:1/-1"><label>Current Address</label><input type="text" name="current_address" maxlength="500" placeholder="House no / street, area, city, state, pincode"></div>
                            </div>

                            <div class="section-head"><i class="bi bi-mortarboard"></i> Academic Details</div>
                            <div class="form-grid">
                                <div class="form-group"><label>College Name *</label><input type="text" name="college_name" maxlength="200" required></div>
                                <div class="form-group"><label>Program / Course</label><input type="text" name="program" maxlength="120" placeholder="e.g. B.Tech, B.Arch, MBA"></div>
                                <div class="form-group">
                                    <label>Year of Study</label>
                                    <select name="study_year">
                                        <option value="">Select</option>
                                        <?php foreach (['First', 'Second', 'Third', 'Final'] as $y): ?><option><?= $y ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group"><label>Duration of Course</label><input type="text" name="course_duration_years" maxlength="4" placeholder="e.g. 4 Year"></div>
                                <div class="form-group"><label>Admission Year</label><input type="text" name="admission_year" maxlength="4"></div>
                                <div class="form-group"><label>SSC Passing Year</label><input type="text" name="ssc_passing_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2018"></div>
                                <div class="form-group"><label>HSC Passing Year</label><input type="text" name="hsc_passing_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2020"></div>
                                <div class="form-group"><label>Diploma Passing Year</label><input type="text" name="diploma_passing_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2022"></div>
                            </div>

                            <div class="section-head"><i class="bi bi-calendar-check"></i> Date &amp; Year of First Admission to</div>
                            <div class="form-grid">
                                <div class="form-group"><label>University / College</label><input type="text" name="first_admission_university_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2023"></div>
                                <div class="form-group"><label>Present Course</label><input type="text" name="first_admission_course_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2023"></div>
                                <div class="form-group"><label>Present Class</label><input type="text" name="first_admission_class_year" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="e.g. 2025"></div>
                            </div>

                            <div class="section-head"><i class="bi bi-signpost-split"></i> Gap / Year Drop</div>
                            <div class="checkbox-row"><input type="checkbox" id="addGapYear" name="has_gap_year" value="1">
                                <label for="addGapYear" style="margin:0;font-weight:600">Had a gap / year drop?</label></div>
                            <div class="form-grid">
                                <div class="form-group"><label>Please Mention Year</label><input type="text" name="gap_year_detail" maxlength="100" placeholder="e.g. 2022"></div>
                            </div>

                            <div class="section-head"><i class="bi bi-bank"></i> Bank Details</div>
                            <div class="form-grid">
                                <div class="form-group"><label>Account Number</label><input type="text" name="bank_account_number" maxlength="30"></div>
                                <div class="form-group"><label>IFSC Code</label><input type="text" name="bank_ifsc" maxlength="15" style="text-transform:uppercase"></div>
                                <div class="form-group"><label>Bank Name</label><input type="text" name="bank_name" maxlength="120"></div>
                                <div class="form-group"><label>Branch</label><input type="text" name="bank_branch" maxlength="120"></div>
                            </div>

                            <div class="section-head"><i class="bi bi-clock-history"></i> Played Before?</div>
                            <div class="checkbox-row"><input type="checkbox" id="addHasPlayed" name="has_played_in_college" value="1">
                                <label for="addHasPlayed" style="margin:0;font-weight:600">Represented a college/institution before?</label></div>
                            <div class="form-grid">
                            <?php foreach ([
                                'zonal' => 'Zonal', 'interzonal' => 'Interzonal', 'all_india' => 'All India',
                                'west_zone' => 'West Zone', 'krida_mahotsav' => 'Krida Mahotsav',
                            ] as $key => $label): ?>
                                <div class="played-block">
                                    <div class="checkbox-row">
                                        <input type="checkbox" id="add<?= $key ?>Played" name="<?= $key ?>_played" value="1">
                                        <label for="add<?= $key ?>Played" style="margin:0;font-weight:600"><?= h($label) ?></label>
                                    </div>
                                    <div class="form-group" style="margin-bottom:0"><label>Year(s)</label><input type="text" name="<?= $key ?>_year" placeholder="e.g. 2024-25"></div>
                                </div>
                            <?php endforeach; ?>
                            </div>

                            <div class="section-head"><i class="bi bi-person-badge"></i> Jersey Details</div>
                            <div class="form-grid">
                                <div class="form-group"><label>Jersey Number</label><input type="text" name="jersey_number" maxlength="10"></div>
                                <div class="form-group"><label>Jersey Size</label><input type="text" name="jersey_size" maxlength="10"></div>
                                <div class="form-group"><label>Shorts Size</label><input type="text" name="shorts_size" maxlength="10"></div>
                                <div class="form-group"><label>Track Size</label><input type="text" name="track_size" maxlength="10"></div>
                            </div>

                            <div style="margin-top:1.25rem;display:flex;gap:.6rem">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus"></i> Add Student</button>
                                <button type="button" class="btn btn-secondary" onclick="document.getElementById('addStudentPanel').style.display='none'">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    <?php if ($studentTotal === 0): ?>
                        <div class="empty-row"><i class="bi bi-inbox"></i> No submissions yet.</div>
                    <?php else: ?>
                    <div style="overflow-x:auto">
                    <table class="data-table">
                        <thead><tr>
                            <th>Name</th><th>Email</th><th>Mobile</th><th>College</th><th>Game</th><th>Verified</th><th>Form</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($students as $s): ?>
                            <tr>
                                <td style="font-weight:600"><?= h($s['full_name']) ?></td>
                                <td><?= h($s['email']) ?></td>
                                <td><?= h((string)($s['mobile'] ?? '')) ?: '—' ?></td>
                                <td><?= h($s['college_name']) ?></td>
                                <td><?= h($s['link_game_name']) ?> <span style="color:var(--medium-gray)">(<?= h($s['link_game_level']) ?>)</span></td>
                                <td><?= !empty($s['email_verified_at']) ? '<span class="badge badge-verified"><i class="bi bi-patch-check-fill"></i> Verified</span>' : '<span class="badge badge-pending">Unverified</span>' ?></td>
                                <td><?= !empty($s['form_submitted_at']) ? '<span class="badge badge-submitted">Submitted</span>' : '<span class="badge badge-pending">In progress (step ' . (int)($s['form_step'] ?? 1) . ')</span>' ?></td>
                                <td>
                                    <form method="post" action="external_entries.php" style="display:inline" onsubmit="return confirm('Delete this entry permanently? This cannot be undone.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_student">
                                        <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" aria-label="Delete entry" title="Delete entry"><i class="bi bi-trash3"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if ($studentPages > 1): ?>
                        <div class="pagination">
                            <div class="info">Page <?= $studentPage ?> of <?= $studentPages ?> · <?= $studentTotal ?> total</div>
                            <div class="pages">
                                <?php
                                $studentBaseQ = http_build_query(array_filter(['link' => $linkFilter > 0 ? $linkFilter : '']));
                                $studentPrev = max(1, $studentPage - 1);
                                $studentNext = min($studentPages, $studentPage + 1);
                                ?>
                                <a class="<?= $studentPage <= 1 ? 'disabled' : '' ?>" aria-label="Previous page" href="?<?= h($studentBaseQ.'&spage='.$studentPrev) ?>"><i class="bi bi-chevron-left"></i></a>
                                <?php for ($i = 1; $i <= $studentPages; $i++): ?>
                                    <a class="<?= $i === $studentPage ? 'active' : '' ?>" href="?<?= h($studentBaseQ.'&spage='.$i) ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <a class="<?= $studentPage >= $studentPages ? 'disabled' : '' ?>" aria-label="Next page" href="?<?= h($studentBaseQ.'&spage='.$studentNext) ?>"><i class="bi bi-chevron-right"></i></a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var level = document.getElementById('fGameLevel');
            var team  = document.getElementById('fTeam');
            var expiry = document.getElementById('fExpiry');
            var preview = document.getElementById('msgPreview');
            if (!preview) return;

            function fmtDate(v) {
                if (!v) return '{expiry date}';
                var d = new Date(v + 'T00:00:00');
                if (isNaN(d.getTime())) return '{expiry date}';
                var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
            }

            function render() {
                var lvl = level.value || '{game level}';
                var tm  = team.value.trim() || '{representing team}';
                var dl  = fmtDate(expiry.value);
                preview.textContent =
                    'Dear Student,\n\n' +
                    'You are hereby informed that you have been provisionally selected in the ' + lvl + ' tournament, representing ' + tm + '.\n\n' +
                    'You are requested to fill your details for confirmation using the link below, before ' + dl + '.\n\n' +
                    '{your link will appear here after generating}';
            }

            [level, team, expiry].forEach(function (el) { el.addEventListener('input', render); el.addEventListener('change', render); });
            render();
        })();

        function toggleExtend(id) {
            var row = document.getElementById('extendRow' + id);
            if (row) row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
        }

        function extCopyPlain(text, btn) {
            function done() {
                var orig = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
                setTimeout(function () { btn.innerHTML = orig; }, 1500);
            }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
            } else {
                fallbackCopy(text, done);
            }
        }
        function extCopyText(elId, btn) {
            var el = document.getElementById(elId);
            if (el) extCopyPlain(el.value, btn);
        }
        function fallbackCopy(text, done) {
            var ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            done();
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        var addDobEl = document.getElementById('addDob');
        if (addDobEl) {
            flatpickr(addDobEl, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'd-m-Y', maxDate: 'today', disableMobile: true });
        }

        // Expiry-date pickers: calendar-only, opened via a dedicated button
        // (not on click/focus) and displayed as dd-mm-yyyy, per the
        // "Extend link date" feature. Underlying value stays Y-m-d for the
        // server; the visible altInput shows d-m-Y.
        var fExpiryEl = document.getElementById('fExpiry');
        if (fExpiryEl) {
            var fExpiryPicker = flatpickr(fExpiryEl, {
                dateFormat: 'Y-m-d', altInput: true, altFormat: 'd-m-Y',
                minDate: 'today', disableMobile: true, clickOpens: false,
                onChange: function () { fExpiryEl.dispatchEvent(new Event('change', { bubbles: true })); }
            });
            var fExpiryBtn = document.getElementById('fExpiryBtn');
            if (fExpiryBtn) fExpiryBtn.addEventListener('click', function () { fExpiryPicker.open(); });
        }

        document.querySelectorAll('.ext-date-picker').forEach(function (el) {
            var fp = flatpickr(el, {
                dateFormat: 'Y-m-d', altInput: true, altFormat: 'd-m-Y',
                minDate: 'today', disableMobile: true, clickOpens: false
            });
            var btn = el.parentElement.querySelector('.ext-date-btn');
            if (btn) btn.addEventListener('click', function () { fp.open(); });
        });
    </script>
</body>
</html>
