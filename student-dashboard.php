<?php
/**
 * Student profile wizard.
 *
 * GET (no params)        -> redirect to ?step=<form_step or 1>
 * GET ?step=N (1..6)     -> render that step of the wizard
 * GET ?new=1             -> ignored (legacy entry point); redirects to step 1
 *
 * Steps:
 *   1 — Personal Information
 *   2 — Academic Details
 *   3 — Sports Information
 *   4 — Played History
 *   5 — Documents (department-specific uploads)
 *   6 — Preview & Submit (read-only review)
 *
 * Per-step saves POST to student_dashboard_process.php?step=N.
 * Step 5 (documents) uses fetch() against the same endpoint with JSON responses.
 * Step 6 Submit POSTs to student_dashboard_process.php?finalize=1.
 *
 * State is stored on the students row in form_step (1..6) and
 * form_submitted_at (NULL until first submit). Refresh always resumes
 * at the last step the student reached.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_student();

$me   = current_student();
$meId = (int)$me['id'];

$flash_ok  = flash_get('student_dashboard_ok');
$flash_err = flash_get('student_dashboard_err');

/* Always re-read the latest record */
$student = db_one(
    'SELECT s.*, d.name AS dept_name, d.code AS department_code
       FROM students s
       JOIN departments d ON d.id = s.department_id
      WHERE s.id = ?',
    [$meId], 'i'
);

if (!$student) {
    http_response_code(404);
    exit('Student record not found.');
}

/* Locked profile: once submitted, the wizard is read-only and this
 * page shows the "Submitted" confirmation screen instead — until
 * faculty grants edit access (edit_unlocked = 1) on student-profile.php.
 * Re-submitting resets the flag back to 0, re-locking the form. */
$formLocked = student_form_locked($student);

/* -----------------------------------------------------------------
 * Determine the active step
 * ----------------------------------------------------------------- */
$requestedStep = isset($_GET['step']) ? (int)$_GET['step'] : 0;
$persistedStep = (int)($student['form_step'] ?? 0);
if ($persistedStep < 1 || $persistedStep > 6) $persistedStep = 1;

if ($requestedStep < 1 || $requestedStep > 6) {
    /* Land at last-completed step (or 1 if never started) */
    redirect('student-dashboard.php?step=' . $persistedStep);
}
$step = $requestedStep;

/* -----------------------------------------------------------------
 * Per-dept form fields (matches student-profile.php logic)
 * ----------------------------------------------------------------- */
$dept_code                  = (string)($student['department_code'] ?? '');
$uses_father_first_name     = in_array($dept_code, ['engineering', 'pharmacy'], true);
$is_pharm_faculty_department = in_array($dept_code, ['pharmacy', 'ytc_pharmacy', 'management', 'architecture'], true);
$stores_roll_no             = in_array($dept_code, ['engineering', 'polytechnic', 'dpharm', 'pharmacy', 'ytc_pharmacy', 'management', 'architecture'], true);
$shows_mother_name_field    = $is_pharm_faculty_department || $uses_father_first_name;
$parent_name_label          = $uses_father_first_name ? "Father's First Name" : 'Mother Name';
$parent_name_required       = $uses_father_first_name;
// Every department except Polytechnic uses the Shivaji University eligibility
// proforma for the Final Team PDF export, which needs a "Duration of Course"
// value that can't be reliably guessed from the free-text Program field.
$needs_course_duration      = $dept_code !== 'polytechnic';
// The Shivaji proforma also has an "Aadhar Number" column; Polytechnic's own
// form does not, so Aadhar is only required off the polytechnic path.
$aadhar_required            = $dept_code !== 'polytechnic';

/* -----------------------------------------------------------------
 * Game-checkbox picker (Step 3) — loaded only for picker depts.
 * A dept is a "picker dept" iff dept_game_catalog has at least one
 * active row for it. polytechnic + dpharm are seeded in
 * migration-v22-game-catalog.sql; future depts can opt in by
 * adding rows.
 * ----------------------------------------------------------------- */
$game_catalog = db_select(
    'SELECT game_code, display_name, max_picks
       FROM dept_game_catalog
      WHERE department_id = ? AND is_active = 1
      ORDER BY display_order, display_name',
    [(int)$student['department_id']], 'i'
);
$uses_game_picker = !empty($game_catalog);
$game_total_available = count($game_catalog);

$selected_games = [];
if ($uses_game_picker) {
    $rows = db_select(
        'SELECT game_code FROM student_selected_games WHERE student_id = ?',
        [$meId], 'i'
    );
    foreach ($rows as $r) $selected_games[] = (string)$r['game_code'];
    $selected_games = array_values(array_intersect(
        $selected_games,
        array_column($game_catalog, 'game_code')
    ));
}

/* -----------------------------------------------------------------
 * Documents + their requirements (for step 5 and the preview on step 6)
 * ----------------------------------------------------------------- */
$documents = db_select(
    'SELECT dr.id AS req_id, dr.document_name, dr.is_required, dr.allowed_mime_types, sd.file_path, sd.uploaded_at, sd.id AS doc_id
       FROM dept_document_requirements dr
       LEFT JOIN student_documents sd ON sd.requirement_id = dr.id AND sd.student_id = ?
      WHERE dr.department_id = ?
      ORDER BY (dr.document_name = "Passport-size Photo") DESC, dr.id',
    [$meId, (int)$student['department_id']], 'ii'
);

// The Gap certificate is only mandatory for students who answered "Yes"
// to "Gap / Year Drop?" in Step 2 — everyone else sees it as optional.
$hasGapYear = (int)($student['has_gap_year'] ?? 0) === 1;
foreach ($documents as &$d) {
    if (stripos((string)$d['document_name'], 'gap certificate') === 0) {
        $d['is_required'] = $hasGapYear ? 1 : 0;
    }
}
unset($d);

$required_total   = 0;
$required_uploaded = 0;
$has_bank_passbook = false;
foreach ($documents as $d) {
    if (strcasecmp(trim((string)$d['document_name']), 'Bank passbook') === 0) {
        $has_bank_passbook = true;
    }
    if ((int)($d['is_required'] ?? 0) === 1) {
        $required_total++;
        if (!empty($d['file_path'])) $required_uploaded++;
    }
}

/* -----------------------------------------------------------------
 * Helpers
 * ----------------------------------------------------------------- */
function is_selected($value, $current): string {
    return (string)$value === (string)$current ? 'selected' : '';
}

/* Best-effort Font Awesome 6 glyph for a game_code.
   Pure decoration — if no match, returns the trophy fallback.
   Returned string is a complete <i> class list (e.g. "fa-solid fa-futbol"). */
function game_icon_for(string $code): string {
    static $map = [
        // 12-game picker (polytechnic + dpharm)
        'kabaddi'      => 'fa-solid fa-shield-halved',
        'kho_kho'      => 'fa-solid fa-person-running',
        'volleyball'   => 'fa-solid fa-volleyball',
        'basketball'   => 'fa-solid fa-basketball',
        'hockey'       => 'fa-solid fa-hockey-puck',
        'football'     => 'fa-solid fa-futbol',
        'badminton'    => 'fa-solid fa-table-tennis-paddle-ball',
        'table_tennis' => 'fa-solid fa-table-tennis-paddle-ball',
        'cricket'      => 'fa-solid fa-baseball-bat-ball',
        'carrom'       => 'fa-solid fa-circle-dot',
        'relay_4x100m' => 'fa-solid fa-arrows-left-right',
        'chess'        => 'fa-solid fa-chess-king',
        // 9-game picker (engineering + pharmacy + ytc_pharmacy)
        'athletics'    => 'fa-solid fa-stopwatch',
        // 16-game picker (management + architecture) — extra codes
        'handball'     => 'fa-solid fa-hand-fist',
        'swimming'     => 'fa-solid fa-person-swimming',
        'wrestling'    => 'fa-solid fa-person-burst',
        'softball'     => 'fa-solid fa-baseball',
        'netball'      => 'fa-solid fa-bullseye',
        'boxing'       => 'fa-solid fa-boxing-glove',
        'taekwondo'    => 'fa-solid fa-user-ninja',
        'baseball'     => 'fa-solid fa-baseball',
        'shooting_ball' => 'fa-solid fa-crosshairs',
        // v43 unified catalogue — remaining codes
        'archery'          => 'fa-solid fa-bullseye',
        'body_building'    => 'fa-solid fa-dumbbell',
        'canoeing_kayaking' => 'fa-solid fa-sailboat',
        'cross_country'    => 'fa-solid fa-person-running',
        'cycling'          => 'fa-solid fa-bicycle',
        'fencing'          => 'fa-solid fa-khanda',
        'gymnastics'       => 'fa-solid fa-person-falling',
        'judo'             => 'fa-solid fa-hand-fist',
        'karate'           => 'fa-solid fa-hand-fist',
        'lawn_tennis'      => 'fa-solid fa-table-tennis-paddle-ball',
        'mallakhamb'       => 'fa-solid fa-person-rays',
        'power_lifting'    => 'fa-solid fa-dumbbell',
        'rope_mallakhamb'  => 'fa-solid fa-person-rays',
        'rowing'           => 'fa-solid fa-sailboat',
        'rugby'            => 'fa-solid fa-football',
        'shooting'         => 'fa-solid fa-crosshairs',
        'track_cycling'    => 'fa-solid fa-bicycle',
        'weight_lifting'   => 'fa-solid fa-dumbbell',
        'wushu'            => 'fa-solid fa-hand-fist',
        'yoga'             => 'fa-solid fa-spa',
    ];
    return $map[$code] ?? 'fa-solid fa-trophy';
}

/* Wizard step definitions */
$wizard_steps = [
    1 => ['label' => 'Personal',     'sub' => 'Name & Contact',   'icon' => 'bi-person-vcard'],
    2 => ['label' => 'Academic',     'sub' => 'College Details',  'icon' => 'bi-mortarboard'],
    3 => ['label' => 'Sports',       'sub' => 'Your Sports',      'icon' => 'bi-trophy'],
    4 => ['label' => 'Played',       'sub' => 'Match History',    'icon' => 'bi-clock-history'],
    5 => ['label' => 'Documents',    'sub' => 'Upload Files',     'icon' => 'bi-file-earmark-text'],
    6 => ['label' => 'Preview',      'sub' => 'Review & Submit',  'icon' => 'bi-eye'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Sports Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">
    <?php if ($step === 1): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <?php endif; ?>
    <?php if ($step === 5): ?>
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
    <?php endif; ?>
    <style>
        /* Date of Birth picker (flatpickr) — brand colors, quick year jump */
        .flatpickr-calendar { border-radius: 10px; box-shadow: 0 12px 30px rgba(0,0,0,.18); }
        .flatpickr-months .flatpickr-month, .flatpickr-current-month .flatpickr-monthDropdown-months,
        .flatpickr-current-month input.cur-year { color: var(--primary-navy); fill: var(--primary-navy); }
        .flatpickr-current-month input.cur-year { font-weight: 600; }
        span.flatpickr-weekday { color: var(--primary-navy); font-weight: 600; }
        .flatpickr-day.selected, .flatpickr-day.selected:hover { background: var(--primary-navy); border-color: var(--primary-navy); }
        .flatpickr-day.today { border-color: var(--accent-gold); }
        .flatpickr-day.today:hover { background: var(--accent-gold); border-color: var(--accent-gold); }
        .flatpickr-day:hover { background: var(--off-white); }
        .student-topbar { background:#fff; border-bottom:1px solid var(--light-gray); padding:.7rem 1.25rem; display:flex; align-items:center; justify-content:space-between; box-shadow: 0 2px 6px rgba(0,0,0,.04); position:sticky; top:0; z-index:50; }
        .student-topbar .brand { display:flex; align-items:center; gap:.7rem; font-weight:700; color: var(--primary-navy); }
        .student-topbar .brand img { width:34px; height:34px; object-fit:contain; }
        .student-topbar .user-pill { display:flex; align-items:center; gap:.55rem; background: var(--off-white); padding:.35rem .75rem .35rem .35rem; border-radius:50px; font-size:.85rem; color: var(--text-dark); }
        .student-topbar .user-pill .avatar { width:30px; height:30px; border-radius:50%; background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-light)); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.78rem; }
        .student-topbar a.logout { color: var(--accent-maroon); margin-left:.5rem; font-size:1rem; }
        .content-body { padding: 1.5rem 1.25rem 3rem; max-width:1100px; margin:0 auto; }
        .welcome-banner { background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); color:#fff; padding:1.1rem 1.4rem; border-radius:12px; margin-bottom:1.25rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .welcome-banner h1 { font-size:1.15rem; font-weight:700; margin:0 0 .2rem; }
        .welcome-banner p { font-size:.85rem; color: rgba(255,255,255,.78); margin:0; }
        .welcome-banner .gold { color: var(--accent-gold); }

        /* ============================================================
           GOVERNMENT-STYLE STEP WIZARD — Large & Attractive
           ============================================================ */
        .wizard-card { background:#fff; border-radius:16px; box-shadow: 0 4px 24px rgba(26,54,93,.08), 0 1px 3px rgba(0,0,0,.04); padding:0; overflow:hidden; border:1px solid rgba(26,54,93,.06); }
        .wizard-head { padding:1.6rem 2rem 1.1rem; border-bottom:1px solid var(--light-gray); background: linear-gradient(135deg, rgba(26,54,93,.02), rgba(26,54,93,.04)); }
        .wizard-head h2 { font-size:1.15rem; font-weight:700; color: var(--primary-navy); margin:0 0 .35rem; display:flex; align-items:center; gap:.5rem; }
        .wizard-head p  { font-size:.88rem; color: var(--medium-gray); margin:0; line-height:1.5; }
        .wizard-body { padding:1.6rem 2rem 2rem; }

        /* -- Pipeline Container -- */
        .pipeline {
            display:flex; align-items:flex-start; justify-content:center;
            gap:0; padding:2rem 2rem 1.8rem;
            background: linear-gradient(180deg, #f0f4f8 0%, #f8fafc 100%);
            border-bottom:2px solid var(--light-gray);
            overflow-x:auto;
            position:relative;
        }

        /* -- Step Item -- */
        .pipeline-step {
            display:flex; flex-direction:column; align-items:center; gap:.5rem;
            padding:0; border:none; background:transparent;
            font-size:.82rem; font-weight:600; color: var(--medium-gray);
            white-space:nowrap; flex-shrink:0;
            transition: all .3s cubic-bezier(.4,0,.2,1);
            text-decoration:none; position:relative; min-width:90px;
            cursor:default;
        }
        .pipeline-step:hover { transform: translateY(-2px); }

        /* -- Step Circle (Number) -- */
        .pipeline-step .num {
            width:48px; height:48px; border-radius:50%;
            background:#fff; color: var(--medium-gray);
            display:flex; align-items:center; justify-content:center;
            font-size:1rem; font-weight:800;
            border:3px solid #d1d5db;
            transition: all .3s cubic-bezier(.4,0,.2,1);
            position:relative; z-index:2;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        /* -- Step Label (below circle) -- */
        .pipeline-step .step-label {
            font-size:.82rem; font-weight:600; color: var(--medium-gray);
            text-align:center; line-height:1.3;
            transition: all .3s ease;
        }
        .pipeline-step .step-sublabel {
            font-size:.68rem; font-weight:400; color: #9ca3af;
            text-align:center; margin-top:-.1rem;
        }

        /* -- Step Icon (inside circle for done) -- */
        .pipeline-step .num i { font-size:1.2rem; }

        /* ---- DONE state ---- */
        .pipeline-step.done .num {
            background: linear-gradient(135deg, #059669, #10b981);
            color:#fff; border-color:#059669;
            box-shadow: 0 4px 12px rgba(5,150,105,.3);
        }
        .pipeline-step.done .step-label { color:#059669; }
        .pipeline-step.done .step-sublabel { color:#6ee7b7; }

        /* ---- CURRENT state ---- */
        .pipeline-step.current .num {
            background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark));
            color:#fff; border-color: var(--primary-navy);
            box-shadow: 0 6px 20px rgba(26,54,93,.35), 0 0 0 4px rgba(26,54,93,.1);
            animation: pulse-step 2s ease-in-out infinite;
        }
        .pipeline-step.current .step-label {
            color: var(--primary-navy); font-weight:700;
        }
        .pipeline-step.current .step-sublabel { color: var(--primary-navy-light); }

        @keyframes pulse-step {
            0%, 100% { box-shadow: 0 6px 20px rgba(26,54,93,.35), 0 0 0 4px rgba(26,54,93,.1); }
            50%      { box-shadow: 0 6px 20px rgba(26,54,93,.35), 0 0 0 8px rgba(26,54,93,.06); }
        }

        /* -- Connector Line -- */
        .pipeline-conn {
            width:60px; height:4px; flex-shrink:0;
            background: #d1d5db;
            border-radius:2px;
            margin-top:22px; /* align to center of circle */
            position:relative;
            overflow:hidden;
        }
        .pipeline-conn.done {
            background: linear-gradient(90deg, #059669, #10b981);
            box-shadow: 0 1px 4px rgba(5,150,105,.25);
        }
        .pipeline-conn.done::after {
            content:''; position:absolute; top:0; left:0;
            width:100%; height:100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.4), transparent);
            animation: shimmer 2.5s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%   { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* -- Progress Summary Bar -- */
        .pipeline-progress-bar {
            display:flex; align-items:center; justify-content:center;
            gap:.5rem; padding:.55rem 1.2rem;
            background: linear-gradient(135deg, rgba(26,54,93,.05), rgba(26,54,93,.02));
            border-bottom:1px solid var(--light-gray);
            font-size:.78rem; color: var(--medium-gray);
        }
        .pipeline-progress-bar .progress-track {
            flex:1; max-width:300px; height:6px;
            background:#e5e7eb; border-radius:3px; overflow:hidden;
        }
        .pipeline-progress-bar .progress-fill {
            height:100%; border-radius:3px;
            background: linear-gradient(90deg, #059669, #10b981);
            transition: width .5s cubic-bezier(.4,0,.2,1);
        }
        .pipeline-progress-bar strong { color: var(--primary-navy); }

        /* -- Mobile Responsive -- */
        @media (max-width: 720px) {
            .pipeline { padding:1.4rem 1rem 1.2rem; gap:0; justify-content:flex-start; }
            .pipeline-step { min-width:70px; }
            .pipeline-step .num { width:38px; height:38px; font-size:.88rem; border-width:2px; }
            .pipeline-step .step-label { font-size:.72rem; }
            .pipeline-step .step-sublabel { display:none; }
            .pipeline-conn { width:30px; height:3px; margin-top:17px; }
            .wizard-head { padding:1.2rem 1.2rem .8rem; }
            .wizard-body { padding:1.2rem 1.2rem 1.4rem; }
        }
        @media (max-width: 480px) {
            .pipeline-step .num { width:32px; height:32px; font-size:.78rem; }
            .pipeline-step .step-label { font-size:.65rem; }
            .pipeline-conn { width:18px; margin-top:14px; }
        }

        .form-grid { display:grid; grid-template-columns: 1fr 1fr; gap:.9rem 1.1rem; }
        @media (max-width: 720px) { .form-grid { grid-template-columns: 1fr; } }
        .form-group label { display:block; font-size:.78rem; font-weight:600; color: var(--primary-navy); margin-bottom:.4rem; text-transform:uppercase; letter-spacing:.3px; }
        .form-group input, .form-group select, .form-group textarea { width:100%; padding:.65rem .75rem; border:2px solid var(--light-gray); border-radius:8px; font-family:inherit; font-size:.92rem; color: var(--text-dark); background:#fff; transition: var(--transition-smooth); outline:none; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--primary-navy); box-shadow: 0 0 0 3px rgba(26,54,93,.1); }
        .form-group input[readonly] { background: var(--off-white); color: var(--medium-gray); cursor:not-allowed; }
        .form-group textarea { min-height:80px; resize:vertical; }
        .form-group .hint { font-size:.72rem; color: var(--medium-gray); margin-top:.3rem; }
        .section-head { font-size:.88rem; font-weight:700; color: var(--primary-navy); margin:1.4rem 0 .9rem; padding-bottom:.5rem; border-bottom:1px solid var(--light-gray); display:flex; align-items:center; gap:.4rem; }
        .section-head i { color: var(--accent-gold); }

        .btn-save { background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); color:#fff; border:none; border-radius:8px; padding:.75rem 1.6rem; font-family:inherit; font-size:.92rem; font-weight:600; letter-spacing:.5px; text-transform:uppercase; cursor:pointer; transition: var(--transition-smooth); display:inline-flex; align-items:center; gap:.5rem; }
        .btn-save:hover { background: linear-gradient(135deg, var(--primary-navy-light), var(--primary-navy)); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(26,54,93,.3); }
        .btn-cancel { background:#fff; color: var(--primary-navy); border:1px solid var(--light-gray); border-radius:8px; padding:.75rem 1.4rem; font-family:inherit; font-size:.92rem; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:.5rem; }
        .btn-next  { background: linear-gradient(135deg, #198754, #146c43); }
        .btn-next:hover  { background: linear-gradient(135deg, #20c997, #198754); box-shadow: 0 4px 14px rgba(25,135,84,.3); }
        .btn-back  { background:#fff; color: var(--primary-navy); border:1px solid var(--light-gray); }

        .alert-banner { padding:.8rem 1rem; border-radius:8px; margin-bottom:1.25rem; font-size:.9rem; display:flex; align-items:center; gap:.5rem; }
        .alert-banner.success { background: rgba(25,135,84,.1); color:#0a3622; border:1px solid rgba(25,135,84,.2); }
        .alert-banner.error { background: rgba(220,53,69,.1); color:#842029; border:1px solid rgba(220,53,69,.2); }
        .alert-banner.warn { background: rgba(255,193,7,.1); color:#664d03; border:1px solid rgba(255,193,7,.2); }
        .alert-banner.info { background: rgba(13,110,253,.08); color:#052c65; border:1px solid rgba(13,110,253,.18); }

        /* Official-style file upload rules panel (Step 5 — Documents) */
        .upload-rules {
            margin-top: 1rem;
            border: 1px solid #d8dee6;
            border-radius: 4px;
            background: #fff;
            overflow: hidden;
        }
        .upload-rules-head {
            display: flex;
            align-items: center;
            gap: .45rem;
            padding: .45rem .9rem;
            border-bottom: 1px solid #e5e9ef;
            color: #4a5568;
            font-size: .78rem;
            font-weight: 600;
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .upload-rules-head i { color: #6b7a8a; font-size: .9rem; }
        .upload-rules-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        .upload-rule {
            padding: .75rem .9rem;
            border-right: 1px solid #e5e9ef;
            display: flex;
            flex-direction: column;
            gap: .25rem;
        }
        .upload-rule:last-child { border-right: 0; }
        .upload-rule-label {
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #6b7a8a;
        }
        .upload-rule-body {
            display: flex;
            align-items: baseline;
            gap: .6rem;
            flex-wrap: wrap;
        }
        .upload-rule-format {
            font-size: .95rem;
            font-weight: 700;
            color: #1a365d;
        }
        .upload-rule-size {
            font-size: .78rem;
            color: #6b7a8a;
        }
        .upload-rules-foot {
            padding: .5rem .9rem;
            border-top: 1px solid #e5e9ef;
            color: #6b7a8a;
            font-size: .76rem;
        }
        @media (max-width: 600px) {
            .upload-rules-grid { grid-template-columns: 1fr; }
            .upload-rule { border-right: 0; border-bottom: 1px solid #e5e9ef; }
            .upload-rule:last-child { border-bottom: 0; }
        }

        .photo-upload { display:flex; align-items:center; gap:1.2rem; }
        .photo-preview { width:90px; height:90px; border-radius:8px; border:2px dashed var(--light-gray); background: var(--off-white); display:flex; align-items:center; justify-content:center; overflow:hidden; color: var(--medium-gray); font-size:1.6rem; }
        .photo-preview img { width:100%; height:100%; object-fit:cover; }
        .photo-upload-btn { display:inline-block; padding:.5rem .9rem; background: var(--primary-navy); color:#fff; border-radius:6px; cursor:pointer; font-size:.82rem; font-weight:600; }
        .photo-upload-btn:hover { background: var(--primary-navy-light); }

        /* ---------------- Documents step ---------------- */
        .doc-card { border:1px solid var(--light-gray); border-radius:10px; padding:1rem 1.1rem; margin-bottom:.7rem; background:#fff; transition: var(--transition-smooth); }
        .doc-card.uploaded { border-color: rgba(25,135,84,.35); background: rgba(25,135,84,.04); }
        .doc-card .doc-head { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:.5rem; flex-wrap:wrap; }
        .doc-card .doc-head-main { display:flex; align-items:center; gap:.7rem; min-width:0; }
        .doc-thumb { width:56px; height:56px; flex-shrink:0; border-radius:8px; border:1px solid var(--light-gray); background: var(--off-white); display:flex; align-items:center; justify-content:center; overflow:hidden; color: var(--medium-gray); font-size:1.4rem; }
        .doc-thumb img { width:100%; height:100%; object-fit:cover; }
        .doc-thumb canvas.pdf-thumb-canvas { width:100%; height:100%; object-fit:cover; pointer-events:none; }
        .doc-thumb:hover { border-color: var(--primary-navy); }
        .doc-card .doc-title { font-weight:600; color: var(--primary-navy); font-size:.92rem; display:flex; align-items:center; gap:.5rem; }
        .doc-card .doc-title .badge-required { font-size:.65rem; background: var(--accent-maroon); color:#fff; padding:.1rem .4rem; border-radius:50px; }
        .doc-card .doc-title .badge-ok { font-size:.65rem; background:#198754; color:#fff; padding:.1rem .4rem; border-radius:50px; }
        .doc-card .doc-actions { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
        .doc-card .doc-file { font-size:.8rem; color: var(--medium-gray); display:flex; align-items:center; gap:.4rem; }
        .doc-card .doc-actions a, .doc-card .doc-actions button { font-size:.78rem; padding:.3rem .65rem; border-radius:6px; cursor:pointer; border:1px solid var(--light-gray); background:#fff; color: var(--primary-navy); text-decoration:none; font-weight:600; }
        .doc-card .doc-actions a:hover { background: var(--off-white); }
        .doc-card .doc-actions .doc-del { color: var(--accent-maroon); border-color: rgba(220,53,69,.3); }
        .doc-card .doc-actions .doc-del:hover { background: rgba(220,53,69,.08); }
        .doc-card .doc-status { font-size:.75rem; color: var(--medium-gray); }
        .doc-card input[type=file] { display:none; }
        .doc-card .doc-pick { display:inline-block; padding:.4rem .8rem; background: var(--primary-navy); color:#fff; border-radius:6px; cursor:pointer; font-size:.78rem; font-weight:600; }
        .doc-card .doc-pick:hover { background: var(--primary-navy-light); }
        /* Bank account details — shown inside the "Bank passbook" doc-card only */
        .bank-details { margin-top:.85rem; padding-top:.85rem; border-top:1px dashed var(--light-gray); }
        .bank-details-title { font-size:.8rem; font-weight:700; color: var(--primary-navy); text-transform:uppercase; letter-spacing:.4px; display:flex; align-items:center; gap:.45rem; margin-bottom:.7rem; }
        .bank-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:.5rem .9rem; }
        .bank-grid .form-group { margin-bottom:.35rem; }
        .bank-grid input { text-transform:none; }
        .bank-grid input#bank_ifsc { text-transform:uppercase; }
        .bank-details-actions { display:flex; align-items:center; gap:.8rem; flex-wrap:wrap; margin-top:.4rem; }
        .bank-details-status { font-size:.78rem; font-weight:600; }
        .bank-field-error { font-size:.74rem; font-weight:600; color:#b91c1c; margin-top:.3rem; min-height:1em; }
        .bank-grid input.mismatch { border-color:#b91c1c; box-shadow:0 0 0 3px rgba(185,28,28,.12); }
        .bank-grid input.match-ok { border-color:#198754; }

        /* ---------------- Preview step ---------------- */
        .preview-summary { display:flex; flex-direction:column; gap:1.15rem; }
        .preview-card { border:1px solid var(--light-gray); border-radius:12px; background:#fff; overflow:hidden; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
        .preview-card-head { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:.85rem 1.1rem; background: linear-gradient(135deg, rgba(26,54,93,.05), rgba(26,54,93,.02)); border-bottom:1px solid var(--light-gray); flex-wrap:wrap; }
        .preview-card-title { display:flex; align-items:center; gap:.65rem; font-weight:700; color: var(--primary-navy); font-size:.82rem; text-transform:uppercase; letter-spacing:.5px; }
        .preview-card-icon { width:28px; height:28px; border-radius:8px; background: rgba(26,54,93,.1); color: var(--primary-navy); display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
        .preview-edit-link { display:inline-flex; align-items:center; gap:.35rem; font-size:.75rem; font-weight:600; color: var(--primary-navy); text-decoration:none; padding:.32rem .75rem; border:1px solid var(--light-gray); border-radius:50px; background:#fff; transition: var(--transition-smooth); flex-shrink:0; }
        .preview-edit-link:hover { background: var(--primary-navy); color:#fff; border-color: var(--primary-navy); }
        .preview-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
        .preview-field { padding:.85rem 1.1rem; border-bottom:1px solid #f0f2f5; }
        .preview-field-label { display:block; font-size:.7rem; font-weight:600; color: var(--medium-gray); text-transform:uppercase; letter-spacing:.4px; margin-bottom:.32rem; }
        .preview-field-value { display:block; font-size:.9rem; color: var(--text-dark); font-weight:600; word-break:break-word; line-height:1.4; }
        .preview-field-value a { color: var(--primary-navy); font-weight:600; }
        .preview-card em.empty { color: var(--medium-gray); font-style:normal; font-weight:500; }
        .preview-field--full { grid-column: 1 / -1; }

        .preview-doc-list { display:flex; flex-direction:column; }
        .preview-doc-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:.8rem 1.1rem; border-bottom:1px solid #f0f2f5; flex-wrap:wrap; }
        .preview-doc-row:last-child { border-bottom:none; }
        .preview-doc-name { font-size:.87rem; font-weight:600; color: var(--text-dark); }
        .preview-doc-status { display:flex; align-items:center; gap:.55rem; font-size:.85rem; }

        .badge-ok, .badge-required { display:inline-flex; align-items:center; gap:.3rem; font-size:.7rem; font-weight:700; padding:.2rem .55rem; border-radius:50px; letter-spacing:.2px; }
        .badge-ok { background: rgba(25,135,84,.12); color:#146c43; }
        .badge-required { background: rgba(220,53,69,.12); color:#b91c1c; }

        /* ---------------- Submitted / locked screen ---------------- */
        @keyframes submittedPopIn { 0% { transform: scale(.6); opacity:0; } 60% { transform: scale(1.05); opacity:1; } 100% { transform: scale(1); opacity:1; } }
        @keyframes submittedCircleDraw { to { stroke-dashoffset: 0; } }
        @keyframes submittedCheckDraw { to { stroke-dashoffset: 0; } }
        @keyframes submittedFadeUp { from { opacity:0; transform: translateY(10px); } to { opacity:1; transform: translateY(0); } }
        @keyframes submittedRingPing { 0% { transform: scale(.7); opacity:.5; } 100% { transform: scale(1.9); opacity:0; } }
        @keyframes submittedBounce { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }

        .submitted-card { background:#fff; border-radius:16px; box-shadow: 0 4px 24px rgba(26,54,93,.08), 0 1px 3px rgba(0,0,0,.04); border:1px solid rgba(26,54,93,.06); overflow:hidden; }
        .submitted-hero { text-align:center; padding:2.6rem 2rem 2rem; background: linear-gradient(135deg, rgba(25,135,84,.05), rgba(26,54,93,.03)); }
        .submitted-check-wrap { position:relative; width:92px; height:92px; margin:0 auto 1.3rem; display:flex; align-items:center; justify-content:center; }
        .submitted-ring { position:absolute; inset:0; border-radius:50%; border:2px solid rgba(25,135,84,.4); animation: submittedRingPing 1.8s ease-out 1; animation-fill-mode: forwards; }
        .submitted-ring--2 { animation-delay:.35s; }
        .submitted-check { width:92px; height:92px; position:relative; z-index:1; animation: submittedPopIn .5s cubic-bezier(.34,1.56,.64,1) both; }
        .submitted-check-circle { stroke:#198754; stroke-width:2.5; stroke-dasharray:151; stroke-dashoffset:151; animation: submittedCircleDraw .6s ease-out .15s forwards; }
        .submitted-check-mark { stroke:#198754; stroke-width:3.5; stroke-linecap:round; stroke-linejoin:round; stroke-dasharray:40; stroke-dashoffset:40; animation: submittedCheckDraw .4s ease-out .65s forwards; }
        .submitted-title { font-size:1.35rem; font-weight:800; color: var(--primary-navy); margin:0 0 .55rem; opacity:0; animation: submittedFadeUp .5s ease-out .8s forwards; }
        .submitted-sub { font-size:.94rem; color: var(--medium-gray); margin:0 auto 1rem; max-width:480px; line-height:1.6; opacity:0; animation: submittedFadeUp .5s ease-out .92s forwards; }
        .submitted-sub strong { color: var(--text-dark); }
        .submitted-meta { display:inline-flex; align-items:center; gap:.45rem; font-size:.8rem; font-weight:600; color:#146c43; background: rgba(25,135,84,.1); border:1px solid rgba(25,135,84,.25); padding:.4rem .9rem; border-radius:50px; opacity:0; animation: submittedFadeUp .5s ease-out 1.02s forwards; }

        .submitted-next { display:flex; align-items:flex-start; gap:1rem; padding:1.4rem 2rem; border-top:1px solid var(--light-gray); background: var(--off-white); opacity:0; animation: submittedFadeUp .5s ease-out 1.15s forwards; }
        .submitted-next-icon { flex-shrink:0; width:44px; height:44px; border-radius:50%; background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.05rem; animation: submittedBounce 2.2s ease-in-out 1.6s infinite; }
        .submitted-next-body { flex:1; min-width:0; }
        .submitted-next-title { font-weight:700; color: var(--primary-navy); font-size:.92rem; margin-bottom:.3rem; }
        .submitted-next-text { font-size:.86rem; color: var(--medium-gray); line-height:1.6; margin:0; }

        .submitted-footnote { display:flex; align-items:center; gap:.5rem; justify-content:center; padding:.9rem 1.5rem; font-size:.78rem; color: var(--medium-gray); border-top:1px solid var(--light-gray); }

        @media (prefers-reduced-motion: reduce) {
            .submitted-ring, .submitted-check, .submitted-check-circle, .submitted-check-mark,
            .submitted-title, .submitted-sub, .submitted-meta, .submitted-next, .submitted-next-icon {
                animation: none !important; opacity:1 !important; stroke-dashoffset:0 !important; transform:none !important;
            }
        }

        /* Yes/No pill radios (Step 4) */
        .yesno-group { display:flex; gap:.6rem; flex-wrap:wrap; }
        .yesno-opt { display:inline-flex; align-items:center; gap:.5rem; padding:.5rem 1rem; border:2px solid var(--light-gray); border-radius:999px; cursor:pointer; font-size:.92rem; color: var(--text-dark); background:#fff; transition: var(--transition-smooth); user-select:none; }
        .yesno-opt:hover { border-color: var(--primary-navy); }
        .yesno-opt input[type=radio] { position:absolute; opacity:0; pointer-events:none; }
        .yesno-circle { width:18px; height:18px; border:2px solid var(--medium-gray); border-radius:50%; display:inline-block; position:relative; flex-shrink:0; transition: var(--transition-smooth); }
        .yesno-opt.selected { border-color: var(--primary-navy); background: rgba(26,54,93,.05); font-weight:600; }
        .yesno-opt.selected .yesno-circle { border-color: var(--primary-navy); }
        .yesno-opt.selected .yesno-circle::after { content:''; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:8px; height:8px; border-radius:50%; background: var(--primary-navy); }
        .req-star { color:#c53030; margin-left:.15rem; font-weight:700; }
        .form-group .hint.yesno-error { margin-top:.45rem; }

        /* Smaller Yes/No pills for the per-level participation questions (Step 4) */
        .yesno-group--sm .yesno-opt { padding:.32rem .75rem; font-size:.85rem; }
        .yesno-group--sm .yesno-circle { width:14px; height:14px; }
        .yesno-group--sm .yesno-opt.selected .yesno-circle::after { width:6px; height:6px; }
        .participation-level { padding:.9rem 1rem; border:1px solid var(--light-gray); border-radius:10px; margin-bottom:.9rem; }
        .participation-level .form-group:last-child { margin-bottom:0; }
        .participation-level-label { font-weight:600; font-size:.92rem; color: var(--text-dark); margin-bottom:.5rem; display:block; }

        /* ---------------- Game-checkbox picker (Step 3) ----------------
           Government-form style: one game per row, compact, with a
           thin vertical accent on the selected item. */
        .game-picker-head { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:.6rem; flex-wrap:wrap; }
        .game-picker-counter { font-size:.78rem; color: var(--medium-gray); letter-spacing:.3px; text-transform:uppercase; font-weight:600; }
        .game-picker-counter strong { color: var(--primary-navy); font-size:.95rem; }
        .game-picker-counter.complete strong { color:#198754; }
        .game-picker-bar { height:4px; background:#e5e7eb; border-radius:2px; overflow:hidden; margin-bottom:.85rem; }
        .game-picker-bar > div { height:100%; background: linear-gradient(90deg, #1a365d, #2c5282); transition: width .2s ease; width:0; }
        .game-picker-search { position:relative; margin-bottom:.7rem; }
        .game-picker-search i { position:absolute; left:.8rem; top:50%; transform:translateY(-50%); color: var(--medium-gray); font-size:.82rem; pointer-events:none; }
        .game-picker-search input { width:100%; padding:.55rem .8rem .55rem 2.1rem; border:1px solid var(--light-gray); border-radius:6px; font-size:.88rem; font-family: var(--font-primary); color: var(--text-dark); outline:none; transition: border-color .12s ease, box-shadow .12s ease; }
        .game-picker-search input:focus { border-color: var(--primary-navy); box-shadow: 0 0 0 3px rgba(26,54,93,.1); }
        .game-row.filtered-out { display:none; }
        .game-list-empty { display:none; padding:.9rem .85rem; font-size:.85rem; color: var(--medium-gray); font-style:italic; text-align:center; }
        .game-list-empty.show { display:block; }
        .game-list { display:flex; flex-direction:column; border:1px solid var(--light-gray); border-radius:6px; overflow:hidden; background:#fff; }
        .game-row { display:flex; align-items:center; gap:.7rem; padding:.45rem .85rem; border-bottom:1px solid #edf0f3; cursor:pointer; user-select:none; transition: background .12s ease; position:relative; font-size:.88rem; color: var(--text-dark); background:#fff; }
        .game-row:last-child { border-bottom:none; }
        .game-row:hover { background: var(--off-white); }
        .game-row .game-icon { color: var(--medium-gray); font-size:.95rem; flex-shrink:0; width:1.1rem; text-align:center; transition: color .12s ease; }
        .game-row .game-name { font-weight:500; flex:1; }
        /* The checkbox is a real, visible input — sits in the leftmost slot. */
        .game-row .game-check {
            flex-shrink:0; width:16px; height:16px;
            border:1.5px solid #94a3b8; border-radius:3px;
            background:#fff; cursor:pointer; padding:0; margin:0;
            appearance:none; -webkit-appearance:none;
            display:inline-flex; align-items:center; justify-content:center;
            transition: all .12s ease;
        }
        .game-row .game-check:checked { background: var(--primary-navy); border-color: var(--primary-navy); }
        .game-row .game-check:checked::after { content:''; width:9px; height:5px; border-left:2px solid #fff; border-bottom:2px solid #fff; transform: rotate(-45deg) translate(1px,-1px); }
        .game-row .game-check:focus { outline:2px solid rgba(26,54,93,.3); outline-offset:1px; }
        .game-row .game-check:disabled { cursor:not-allowed; opacity:.6; }
        .game-row.selected { background: rgba(26,54,93,.05); color:#0a1f3d; }
        .game-row.selected::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background: var(--primary-navy); }
        .game-row.selected .game-icon { color: var(--primary-navy); }
        .game-row.disabled { opacity:.55; cursor:not-allowed; background:#fafbfc; }
        .game-row.disabled:hover { background:#fafbfc; }
        .game-row.disabled .game-check { cursor:not-allowed; }
        .game-picker-error { color:#b91c1c; font-weight:600; font-size:.78rem; margin-top:.5rem; min-height:1.1em; }

        /* Game chips (read-only display on preview + faculty profile) */
        .game-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.2rem .55rem; background: rgba(26,54,93,.08); color:#0a1f3d; border:1px solid rgba(26,54,93,.18); border-radius:3px; font-size:.78rem; font-weight:600; margin:.1rem .2rem .1rem 0; }
        .game-chip i { color: var(--primary-navy); }
        .game-chip.empty { background: transparent; color: var(--medium-gray); border:1px dashed var(--light-gray); font-weight:500; font-style:italic; }

        /* ---- Student Dashboard Mobile ---- */
        @media (max-width: 767px) {
            .student-topbar { padding: .55rem .85rem; }
            .student-topbar .brand img { width: 28px; height: 28px; }
            .student-topbar .brand div:first-of-type { font-size: .85rem !important; }
            .student-topbar .brand div:last-of-type { display: none; }
            .student-topbar .user-pill { font-size: .78rem; padding: .3rem .6rem .3rem .3rem; gap: .4rem; }
            .student-topbar .user-pill .avatar { width: 26px; height: 26px; font-size: .7rem; }
            .content-body { padding: 1rem .85rem 2rem; }
            .welcome-banner { padding: .85rem 1rem; border-radius: 10px; flex-direction: column; align-items: flex-start; }
            .welcome-banner h1 { font-size: 1rem; }
            .welcome-banner p { font-size: .8rem; }
            .wizard-card { border-radius: 12px; }
            .preview-grid { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
            .submitted-hero { padding: 2rem 1.25rem 1.6rem; }
            .submitted-next { padding: 1.2rem 1.25rem; }
        }
        @media (max-width: 480px) {
            .student-topbar .user-pill span { display: none; }
            .student-topbar .user-pill { padding: .3rem .4rem; }
            .content-body { padding: .75rem .65rem 1.5rem; }
            .welcome-banner { padding: .75rem .85rem; gap: .5rem; }
            .welcome-banner h1 { font-size: .92rem; }
            .alert-banner { font-size: .82rem; padding: .65rem .75rem; }
            /* Preview cards responsive */
            .preview-grid { grid-template-columns: 1fr; }
            .preview-field { padding: .7rem .9rem; }
            .preview-card-head { padding: .7rem .9rem; }
            .preview-doc-row { padding: .7rem .9rem; }
            /* Submitted screen responsive */
            .submitted-check-wrap, .submitted-check { width: 76px; height: 76px; }
            .submitted-title { font-size: 1.12rem; }
            .submitted-next { flex-direction: column; align-items: flex-start; }
            /* Action buttons full width */
            .btn-save, .btn-cancel, .btn-next, .btn-back { width: 100%; justify-content: center; }
            .form-actions { flex-direction: column; gap: .5rem; }
        }
    </style>
</head>
<body>
    <div class="student-topbar">
        <div class="brand">
            <img src="<?= h(url('images/ytc-logo.png')) ?>" alt="YTC">
            <div>
                <div style="font-size:.95rem">Sports Portal</div>
                <div style="font-size:.7rem; color: var(--medium-gray); font-weight:500">Yashoda Technical Campus</div>
            </div>
        </div>
        <div class="user-pill">
            <div class="avatar"><?= h(initials($student['full_name'])) ?></div>
            <span><?= h($student['full_name']) ?></span>
            <a class="logout" href="student-logout.php" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>

    <div class="content-body">
        <div class="welcome-banner">
            <div>
                <h1>Welcome, <span class="gold"><?= h($student['full_name']) ?></span></h1>
                <p>Faculty: <?= h($student['dept_name']) ?> · <?= h($student['enrollment_no']) ?>
                <?php if (!empty($student['form_submitted_at'])): ?>
                    · <i class="bi bi-check-circle-fill" style="color: var(--accent-gold)"></i> Submitted on <?= h(date('d M Y', strtotime((string)$student['form_submitted_at']))) ?>
                <?php endif; ?>
                </p>
            </div>
            <div style="font-size:.8rem; color: rgba(255,255,255,.7); text-align:right">
                <i class="bi bi-shield-check"></i> Logged in as Student
            </div>
        </div>

        <?php if ($flash_ok): ?>
            <div class="alert-banner success"><i class="bi bi-check-circle"></i> <?= h($flash_ok['msg']) ?></div>
        <?php endif; ?>
        <?php if ($flash_err): ?>
            <div class="alert-banner error"><i class="bi bi-exclamation-circle"></i> <?= h($flash_err['msg']) ?></div>
        <?php endif; ?>
        <?php if (!$formLocked && (int)($student['is_self_registered'] ?? 0) === 1
                  && (empty($student['sport_1']) || empty($student['enrollment_no']) || str_starts_with((string)$student['enrollment_no'], 'SELF-'))): ?>
            <div class="alert-banner warn">
                <i class="bi bi-info-circle"></i>
                Please complete your profile using the wizard below. On Step 2 (Academic Details) you'll need to enter your official college PRN/Enrollment number.
            </div>
        <?php endif; ?>

        <?php if ($formLocked): ?>
        <div class="submitted-card">
            <div class="submitted-hero">
                <div class="submitted-check-wrap">
                    <span class="submitted-ring"></span>
                    <span class="submitted-ring submitted-ring--2"></span>
                    <svg class="submitted-check" viewBox="0 0 52 52">
                        <circle class="submitted-check-circle" cx="26" cy="26" r="24" fill="none"/>
                        <path class="submitted-check-mark" fill="none" d="M14 27l7 7 17-17"/>
                    </svg>
                </div>
                <h2 class="submitted-title">Profile Submitted Successfully!</h2>
                <p class="submitted-sub">
                    Your profile has been sent to the <strong>Faculty of Sports, <?= h($student['dept_name']) ?></strong> for review.
                </p>
                <?php if (!empty($student['form_submitted_at'])): ?>
                    <div class="submitted-meta">
                        <i class="bi bi-calendar-check"></i>
                        Submitted on <?= h(date('d M Y \a\t h:i A', strtotime((string)$student['form_submitted_at']))) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="submitted-next">
                <div class="submitted-next-icon"><i class="bi bi-telephone-fill"></i></div>
                <div class="submitted-next-body">
                    <div class="submitted-next-title">What happens next?</div>
                    <p class="submitted-next-text">
                        The Faculty of Sports will review your details and documents. If anything
                        needs to be corrected or added, they'll reach out to you directly — or
                        enable editing on your profile so you can update it yourself.
                    </p>
                </div>
            </div>

            <div class="submitted-footnote">
                <i class="bi bi-lock-fill"></i>
                Your profile is locked for editing until your faculty re-opens it.
            </div>
        </div>
        <?php else: ?>
        <div class="wizard-card">
            <!-- ============== Step Wizard Pipeline ============== -->
            <div class="pipeline">
                <?php foreach ($wizard_steps as $i => $ws):
                    $state = 'pending';
                    if ($i < $step) $state = 'done';
                    elseif ($i === $step) $state = 'current';
                    $editable = $i < $step;
                ?>
                    <a href="<?= $editable ? 'student-dashboard.php?step=' . $i : '#' ?>"
                       class="pipeline-step <?= h($state) ?>"
                       <?= $editable ? '' : 'onclick="return false"' ?>
                       title="Step <?= $i ?>: <?= h($ws['label']) ?>">
                        <span class="num">
                            <?php if ($state === 'done'): ?><i class="bi bi-check-lg"></i>
                            <?php else: ?><?= $i ?><?php endif; ?>
                        </span>
                        <span class="step-label"><i class="bi <?= h($ws['icon']) ?>"></i> <?= h($ws['label']) ?></span>
                        <span class="step-sublabel"><?= h($ws['sub']) ?></span>
                    </a>
                    <?php if ($i < 6): ?>
                        <span class="pipeline-conn <?= $i < $step ? 'done' : '' ?>"></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="pipeline-progress-bar">
                <span>Step <strong><?= $step ?></strong> of <strong>6</strong></span>
                <div class="progress-track">
                    <div class="progress-fill" style="width:<?= round(($step / 6) * 100) ?>%"></div>
                </div>
                <span><?= round(($step / 6) * 100) ?>% Complete</span>
            </div>

            <!-- ============== Step body ============== -->
            <?php if ($step === 1): ?>
                <?php $name_parts = split_full_name_sf($student['full_name'] ?? ''); ?>
                <div class="wizard-head">
                    <h2><i class="bi bi-person-vcard" style="color: var(--accent-gold)"></i> Step 1 — Personal Information</h2>
                    <p>Your name, date of birth and contact details. The Faculty of Sports uses these to identify you.</p>
                </div>
                <div class="wizard-body">
                    <form method="post" action="student_dashboard_process.php?step=1" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="full_name" id="full_name_field" value="">

                        <div class="section-head"><i class="bi bi-person-vcard"></i> Name &amp; Demographics</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="surname">Surname *</label>
                                <input type="text" id="surname" name="surname" required maxlength="60"
                                       value="<?= h($name_parts['surname']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="first_name">First Name *</label>
                                <input type="text" id="first_name" name="first_name" required maxlength="60"
                                       value="<?= h($name_parts['first_name']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="middle_name"><?= $uses_father_first_name ? "Middle Name (Father's First Name)" : 'Middle Name' ?> *</label>
                                <input type="text" id="middle_name" name="middle_name" required maxlength="60"
                                       value="<?= h($name_parts['middle_name']) ?>"
<?php if ($uses_father_first_name): ?>
                                       placeholder="Enter your father's first name"
<?php endif; ?>
                                >
                                <?php if ($uses_father_first_name): ?>
                                    <div class="hint">Engineering students: enter your father's first name here. This is also stored as the father field on your record.</div>
                                <?php endif; ?>
                            </div>
                            <?php if ($shows_mother_name_field): ?>
                            <div class="form-group">
                                <label for="mother_name">Mother's Name</label>
                                <input type="text" id="mother_name" name="mother_name"
                                       value="<?= h($student['mother_name'] ?? '') ?>"
                                       placeholder="Enter your mother's name">
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="dob">Date of Birth *</label>
                                <input type="text" id="dob" name="dob" required autocomplete="off"
                                       placeholder="dd-mm-yyyy"
                                       value="<?= h($student['dob'] ?? '') ?>">
                                <div class="hint">If you change this, your login password also changes (to the new DOB in DDMMYYYY).</div>
                            </div>
                            <div class="form-group">
                                <label for="aadhar_number">Aadhar Number<?= $aadhar_required ? ' *' : '' ?></label>
                                <input type="text" id="aadhar_number" name="aadhar_number"<?= $aadhar_required ? ' required' : '' ?>
                                       pattern="[0-9]{12}" maxlength="12" inputmode="numeric" autocomplete="off"
                                       placeholder="12-digit Aadhar number"
                                       value="<?= h($student['aadhar_number'] ?? '') ?>">
                                <div class="hint">Exactly 12 digits, no spaces. Printed on the Shivaji University eligibility proforma.</div>
                            </div>
                            <div class="form-group">
                                <label for="gender">Gender *</label>
                                <select id="gender" name="gender" required>
                                    <option value="">Select</option>
                                    <?php foreach (gender_options() as $g): ?>
                                        <option value="<?= h($g) ?>" <?= is_selected($g, $student['gender'] ?? '') ?>><?= h($g) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="blood_group">Blood Group</label>
                                <select id="blood_group" name="blood_group">
                                    <option value="">Select</option>
                                    <?php foreach (blood_options() as $b): ?>
                                        <option value="<?= h($b) ?>" <?= is_selected($b, $student['blood_group'] ?? '') ?>><?= h($b) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="section-head"><i class="bi bi-telephone"></i> Contact Details</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="email">Email (Username)</label>
                                <input type="email" id="email" name="email" readonly
                                       value="<?= h($student['email'] ?? '') ?>">
                                <div class="hint">Email is your username and cannot be changed here. Contact the Faculty of Sports if you need to update it.</div>
                            </div>
                            <div class="form-group">
                                <label for="mobile">Mobile No. *</label>
                                <input type="tel" id="mobile" name="mobile" required
                                       pattern="[0-9]{10}" maxlength="10" inputmode="numeric"
                                       value="<?= h($student['mobile'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem">
                                    <label for="whatsapp_no" style="margin:0">WhatsApp No.</label>
                                    <label for="same_as_mobile" style="display:inline-flex;align-items:center;gap:.4rem;margin:0;text-transform:none;letter-spacing:normal;font-weight:700;cursor:pointer">
                                        <input type="checkbox" id="same_as_mobile" name="same_as_mobile" value="1" style="width:auto"
                                               <?php $waNo = $student['whatsapp_no'] ?? ''; $mobNo = $student['mobile'] ?? ''; ?>
                                               <?= ($waNo !== '' && $waNo === $mobNo) ? 'checked' : '' ?>>
                                        WhatsApp number same as Mobile No.
                                    </label>
                                </div>
                                <input type="tel" id="whatsapp_no" name="whatsapp_no"
                                       pattern="[0-9]{10}" maxlength="10" inputmode="numeric"
                                       placeholder="Leave blank if same as Mobile No."
                                       value="<?= h($student['whatsapp_no'] ?? '') ?>">
                            </div>
                            <div class="form-group" style="grid-column:1/-1">
                                <label for="permanent_address">Permanent Address *</label>
                                <input type="text" id="permanent_address" name="permanent_address" required maxlength="500"
                                       placeholder="House no / street, area, city, state, pincode"
                                       value="<?= h($student['permanent_address'] ?? $student['address'] ?? '') ?>">
                            </div>
                            <div class="form-group" style="grid-column:1/-1;margin-bottom:.3rem">
                                <label style="display:flex;align-items:center;gap:.5rem;text-transform:none;letter-spacing:normal;font-weight:700;cursor:pointer">
                                    <input type="checkbox" id="same_as_permanent" name="same_as_permanent" value="1"
                                           style="width:auto"
                                           <?php $curAddr = $student['current_address'] ?? ''; $permAddr = $student['permanent_address'] ?? $student['address'] ?? ''; ?>
                                           <?= ($curAddr !== '' && $curAddr === $permAddr) ? 'checked' : '' ?>>
                                    Current address same as permanent address
                                </label>
                            </div>
                            <div class="form-group" style="grid-column:1/-1" id="currentAddressGroup">
                                <label for="current_address">Current Address *</label>
                                <input type="text" id="current_address" name="current_address" maxlength="500"
                                       placeholder="House no / street, area, city, state, pincode"
                                       value="<?= h($student['current_address'] ?? '') ?>">
                            </div>
                        </div>

                        <div style="margin-top:1.4rem; display:flex; gap:.7rem; align-items:center; flex-wrap:wrap">
                            <button type="submit" class="btn-save btn-next"><i class="bi bi-arrow-right-circle"></i> Save &amp; Continue</button>
                        </div>
                    </form>
                </div>
                <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
                <script>
                    flatpickr('#dob', {
                        dateFormat: 'Y-m-d',
                        altInput: true,
                        altFormat: 'd-m-Y',
                        minDate: '1995-01-01',
                        maxDate: 'today',
                        disableMobile: true
                    });
                </script>

            <?php elseif ($step === 2): ?>
                <div class="wizard-head">
                    <h2><i class="bi bi-mortarboard" style="color: var(--accent-gold)"></i> Step 2 — Academic Details</h2>
                    <p>Your faculty, PRN/Enrollment number, roll number, program, and year of study.</p>
                </div>
                <div class="wizard-body">
                    <?php
                        $gapYear = $student['has_gap_year'] ?? null;
                        $gapYearPersisted = ($gapYear === 0 || $gapYear === 1);
                        if ($gapYear === null) $gapYear = 0;
                    ?>
                    <form method="post" action="student_dashboard_process.php?step=2" id="academicForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="has_gap_year" id="hasGapYearHidden" value="<?= $gapYearPersisted ? (int)$gapYear : '' ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="enrollment_no">PRN/Enrollment No. *</label>
                                <input type="text" id="enrollment_no" name="enrollment_no" required maxlength="40"
                                       value="<?= h((string)($student['enrollment_no'] ?? '')) ?>"
                                       placeholder="e.g. EN2025001">
                                <?php if (empty($student['enrollment_no'])): ?>
                                    <div class="hint">Please enter your official college PRN/Enrollment number. (If you don't have one yet, ask the Faculty of Sports.)</div>
                                <?php else: ?>
                                    <div class="hint">Your official college PRN/Enrollment number. Must be unique across all students.</div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="department_id">Faculty</label>
                                <input type="text" readonly value="<?= h($student['dept_name']) ?>">
                                <input type="hidden" name="department_id" value="<?= (int)$student['department_id'] ?>">
                                <div class="hint">Set at registration. Contact the Faculty of Sports if you need to change it.</div>
                            </div>
                            <?php if ($stores_roll_no): ?>
                            <div class="form-group">
                                <label for="roll_no">Roll No.</label>
                                <input type="text" id="roll_no" name="roll_no"
                                       value="<?= h($student['roll_no'] ?? '') ?>"
                                       placeholder="Enter your class roll number">
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="program">Program *</label>
                                <input type="text" id="program" name="program" required maxlength="120"
                                       placeholder="e.g. B.Tech, M.Tech"
                                       value="<?= h($student['program'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="department_name">Department / Branch *</label>
                                <input type="text" id="department_name" name="department_name" required maxlength="120"
                                       placeholder="e.g. Computer Engineering"
                                       value="<?= h($student['department_name'] ?? '') ?>">
                            </div>
                            <?php if ($needs_course_duration): ?>
                            <div class="form-group">
                                <label for="course_duration_years">Duration of Course *</label>
                                <select id="course_duration_years" name="course_duration_years" required>
                                    <option value="">— Select —</option>
                                    <?php foreach (['2 Year', '3 Year', '4 Year', '5 Year', '6 Year'] as $dur): ?>
                                        <option value="<?= h($dur) ?>" <?= is_selected($dur, $student['course_duration_years'] ?? '') ?>><?= h($dur) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hint">Total length of your program (e.g. B.Arch = 5 Year, MBA = 2 Year). Used on the Final Team eligibility form.</div>
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="study_year">Current Year in which studying *</label>
                                <select id="study_year" name="study_year" required
                                        data-saved-year="<?= h((string)($student['study_year'] ?? '')) ?>">
                                    <option value="">— Select —</option>
                                    <?php foreach (year_options() as $y): ?>
                                        <option value="<?= h($y) ?>" <?= is_selected($y, $student['study_year'] ?? '') ?>><?= h($y) ?> Year</option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($needs_course_duration): ?>
                                <div class="hint">Options follow the course duration selected above.</div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="ssc_passing_year">SSC Passing Year *</label>
                                <input type="text" id="ssc_passing_year" name="ssc_passing_year" required
                                       maxlength="4" pattern="[0-9]{4}" inputmode="numeric"
                                       placeholder="e.g. 2018"
                                       value="<?= h((string)($student['ssc_passing_year'] ?? '')) ?>">
                            </div>
                            <div class="form-group">
                                <label for="hsc_passing_year">HSC Passing Year</label>
                                <input type="text" id="hsc_passing_year" name="hsc_passing_year"
                                       maxlength="4" pattern="[0-9]{4}" inputmode="numeric"
                                       placeholder="e.g. 2020"
                                       value="<?= h((string)($student['hsc_passing_year'] ?? '')) ?>">
                            </div>
                            <div class="form-group">
                                <label for="diploma_passing_year">Diploma Passing Year</label>
                                <input type="text" id="diploma_passing_year" name="diploma_passing_year"
                                       maxlength="4" pattern="[0-9]{4}" inputmode="numeric"
                                       placeholder="e.g. 2022"
                                       value="<?= h((string)($student['diploma_passing_year'] ?? '')) ?>">
                            </div>
                        </div>

                        <div class="section-head"><i class="bi bi-calendar-check"></i> Date &amp; Year of First Admission to</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="first_admission_university_year">University / College *</label>
                                <input type="text" id="first_admission_university_year" name="first_admission_university_year" required
                                       maxlength="4" pattern="[0-9]{4}" inputmode="numeric"
                                       placeholder="e.g. 2023"
                                       value="<?= h((string)($student['first_admission_university_year'] ?? '')) ?>">
                            </div>
                            <div class="form-group">
                                <label for="first_admission_course_year">Present Course *</label>
                                <input type="text" id="first_admission_course_year" name="first_admission_course_year" required
                                       maxlength="4" pattern="[0-9]{4}" inputmode="numeric"
                                       placeholder="e.g. 2023"
                                       value="<?= h((string)($student['first_admission_course_year'] ?? '')) ?>">
                            </div>
                            <div class="form-group">
                                <label for="first_admission_class_year">Present Class *</label>
                                <input type="text" id="first_admission_class_year" name="first_admission_class_year" required
                                       maxlength="4" pattern="[0-9]{4}" inputmode="numeric"
                                       placeholder="e.g. 2025"
                                       value="<?= h((string)($student['first_admission_class_year'] ?? '')) ?>">
                            </div>
                        </div>

                        <div class="section-head"><i class="bi bi-signpost-split"></i> Gap / Year Drop</div>
                        <div class="form-group" data-required-group>
                            <label>Gap / Year Drop? <span class="req-star" aria-hidden="true">*</span></label>
                            <div class="yesno-group" role="radiogroup" aria-label="Gap / Year Drop?" aria-required="true">
                                <label class="yesno-opt <?= (int)$gapYear === 1 ? 'selected' : '' ?>" data-val="1">
                                    <input type="radio" name="has_gap_year_radio" value="1" <?= (int)$gapYear === 1 ? 'checked' : '' ?>>
                                    <span class="yesno-circle"></span>
                                    <span class="yesno-text">Yes</span>
                                </label>
                                <label class="yesno-opt <?= (int)$gapYear === 0 ? 'selected' : '' ?>" data-val="0">
                                    <input type="radio" name="has_gap_year_radio" value="0" <?= (int)$gapYear === 0 ? 'checked' : '' ?>>
                                    <span class="yesno-circle"></span>
                                    <span class="yesno-text">No</span>
                                </label>
                            </div>
                            <div class="hint yesno-error" id="gapYearError" style="display:none;color:#c53030;font-weight:600">Please choose Yes or No before continuing.</div>
                        </div>
                        <div class="form-group" id="gapYearDetailGroup" style="<?= (int)$gapYear === 1 ? '' : 'display:none' ?>">
                            <label for="gap_year_detail">Please Mention Year</label>
                            <input type="text" id="gap_year_detail" name="gap_year_detail" maxlength="100"
                                   placeholder="e.g. 2022"
                                   value="<?= h((string)($student['gap_year_detail'] ?? '')) ?>">
                        </div>

                        <div style="margin-top:1.4rem; display:flex; gap:.7rem; align-items:center; flex-wrap:wrap">
                            <a href="student-dashboard.php?step=1" class="btn-cancel btn-back"><i class="bi bi-arrow-left"></i> Back</a>
                            <button type="submit" class="btn-save btn-next"><i class="bi bi-arrow-right-circle"></i> Save &amp; Continue</button>
                        </div>
                    </form>
                    <script>
                    (function () {
                        var form   = document.getElementById('academicForm');
                        if (!form) return;
                        var opts   = form.querySelectorAll('.yesno-opt');
                        var group  = document.getElementById('gapYearDetailGroup');
                        var hidden = document.getElementById('hasGapYearHidden');
                        var detail = document.getElementById('gap_year_detail');
                        var errBox = document.getElementById('gapYearError');
                        var radios = form.querySelectorAll('input[name="has_gap_year_radio"]');
                        function getChosen() {
                            for (var i = 0; i < radios.length; i++) {
                                if (radios[i].checked) return radios[i].value;
                            }
                            return null;
                        }
                        function sync() {
                            var chosen = getChosen();
                            if (chosen === null) {
                                if (errBox) errBox.style.display = 'none';
                                return;
                            }
                            hidden.value = chosen;
                            opts.forEach(function (o) {
                                o.classList.toggle('selected', o.getAttribute('data-val') === chosen);
                            });
                            if (errBox) errBox.style.display = 'none';
                            if (chosen === '1') {
                                group.style.display = '';
                            } else {
                                group.style.display = 'none';
                                detail.value = '';
                            }
                        }
                        opts.forEach(function (o) {
                            o.addEventListener('click', function () {
                                var r = o.querySelector('input[type=radio]');
                                r.checked = true;
                                sync();
                            });
                        });

                        // "Current Year in which studying" follows "Duration of Course":
                        // a 2-Year course offers First/Second, a 3-Year adds Third, and
                        // 4-Year and longer add "Final" (resolved to the real ordinal on
                        // the eligibility form). study_year itself stays a 4-bucket field.
                        var durSel  = document.getElementById('course_duration_years');
                        var yearSel = document.getElementById('study_year');
                        if (yearSel) {
                            var yearSaved = yearSel.getAttribute('data-saved-year') || '';
                            var yearDefs  = [
                                { v: 'First',  min: 1 },
                                { v: 'Second', min: 2 },
                                { v: 'Third',  min: 3 },
                                { v: 'Final',  min: 4 }
                            ];
                            var ordinal = function (x) {
                                var s = ['th', 'st', 'nd', 'rd'], m = x % 100;
                                return x + (s[(m - 20) % 10] || s[m] || s[0]);
                            };
                            var syncStudyYears = function () {
                                var n = durSel ? parseInt((durSel.value || '').replace(/\D/g, ''), 10) : 0;
                                if (!n || isNaN(n)) { n = 99; } // no duration chosen yet -> show all
                                var html = '<option value="">— Select —</option>';
                                var keepSaved = false;
                                yearDefs.forEach(function (d) {
                                    if (n < d.min) { return; }
                                    var label = (d.v === 'Final' && n >= 4 && n <= 6)
                                        ? 'Final Year (' + ordinal(n) + ')'
                                        : d.v + ' Year';
                                    var sel = d.v === yearSaved ? ' selected' : '';
                                    if (sel) { keepSaved = true; }
                                    html += '<option value="' + d.v + '"' + sel + '>' + label + '</option>';
                                });
                                yearSel.innerHTML = html;
                                if (!keepSaved) { yearSel.value = ''; }
                            };
                            if (durSel) { durSel.addEventListener('change', syncStudyYears); }
                            syncStudyYears();
                        }

                        form.addEventListener('submit', function (e) {
                            if (getChosen() === null) {
                                e.preventDefault();
                                if (errBox) {
                                    errBox.style.display = '';
                                    errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                }
                                var g = form.querySelector('.yesno-group');
                                g.style.boxShadow = '0 0 0 3px rgba(197,48,48,.25)';
                                setTimeout(function () { g.style.boxShadow = ''; }, 1500);
                            }
                        });
                    })();
                    </script>
                </div>

            <?php elseif ($step === 3): ?>
                <div class="wizard-head">
                    <h2><i class="bi bi-trophy" style="color: var(--accent-gold)"></i> Step 3 — Sports Information</h2>
                    <p>
                        <?php if ($uses_game_picker): ?>
                            Pick the games you want to enroll in for <?= h($student['dept_name']) ?> &mdash; select as many as you like.
                            Faculty will assign you to events based on your choices.
                        <?php else: ?>
                            Primary sport and secondary sport.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="wizard-body">
                    <?php if ($uses_game_picker): ?>
                        <form method="post" action="student_dashboard_process.php?step=3" id="gamePickerForm" data-total-games="<?= (int)$game_total_available ?>">
                            <?= csrf_field() ?>
                            <div class="game-picker-head">
                                <div class="game-picker-counter" id="gameCounter">
                                    <strong id="gameCountNum"><?= count($selected_games) ?></strong>
                                    /
                                    <strong><?= (int)$game_total_available ?></strong>
                                    selected
                                </div>
                                <div class="hint" style="margin:0">Check as many games as you want to play.</div>
                            </div>
                            <div class="game-picker-bar"><div id="gameBarFill" style="width: <?= (int)round(count($selected_games) / max(1, $game_total_available) * 100) ?>%"></div></div>

                            <?php if ($game_total_available > 8): ?>
                            <div class="game-picker-search">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input type="text" id="gameSearch" placeholder="Search games&hellip;" autocomplete="off" aria-label="Search games">
                            </div>
                            <?php endif; ?>

                            <div class="game-list" id="gameGrid" role="list">
                                <?php foreach ($game_catalog as $g):
                                    $code  = (string)$g['game_code'];
                                    $name  = (string)$g['display_name'];
                                    $icon  = game_icon_for($code);
                                    $picked = in_array($code, $selected_games, true);
                                ?>
                                    <label class="game-row <?= $picked ? 'selected' : '' ?>" data-code="<?= h($code) ?>" role="listitem">
                                        <input type="checkbox" name="games[]" value="<?= h($code) ?>" <?= $picked ? 'checked' : '' ?> class="game-check">
                                        <i class="<?= h($icon) ?> game-icon"></i>
                                        <span class="game-name"><?= h($name) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <div class="game-list-empty" id="gameListEmpty">No games match your search.</div>
                            </div>
                            <div class="game-picker-error" id="gamePickerError" role="alert" aria-live="polite"></div>

                            <div style="margin-top:1.4rem; display:flex; gap:.7rem; align-items:center; flex-wrap:wrap">
                                <a href="student-dashboard.php?step=2" class="btn-cancel btn-back"><i class="bi bi-arrow-left"></i> Back</a>
                                <button type="submit" class="btn-save btn-next" id="gamePickerSubmit"><i class="bi bi-arrow-right-circle"></i> Save &amp; Continue</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="post" action="student_dashboard_process.php?step=3">
                            <?= csrf_field() ?>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="sport_1">Primary Sport *</label>
                                    <input type="text" id="sport_1" name="sport_1" required
                                           value="<?= h($student['sport_1'] ?? '') ?>"
                                           placeholder="e.g. Cricket">
                                </div>
                                <div class="form-group">
                                    <label for="sport_2">Secondary Sport</label>
                                    <input type="text" id="sport_2" name="sport_2"
                                           value="<?= h($student['sport_2'] ?? '') ?>"
                                           placeholder="e.g. Athletics">
                                </div>
                            </div>
                            <div style="margin-top:1.4rem; display:flex; gap:.7rem; align-items:center; flex-wrap:wrap">
                                <a href="student-dashboard.php?step=2" class="btn-cancel btn-back"><i class="bi bi-arrow-left"></i> Back</a>
                                <button type="submit" class="btn-save btn-next"><i class="bi bi-arrow-right-circle"></i> Save &amp; Continue</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

            <?php elseif ($step === 4): ?>
                <div class="wizard-head">
                    <h2><i class="bi bi-clock-history" style="color: var(--accent-gold)"></i> Step 4 — Played History</h2>
                    <p>Tell us whether you've represented this college in any sport, and if so, list the events.</p>
                </div>
                <div class="wizard-body">
                    <?php
                        // $hpic is the *current* value used for pre-selecting a radio.
        // $hpic_persisted is whether the column has a real stored value:
        //   true  -> student already answered; respect their choice
        //   false -> no answer yet; force them to pick before saving
        $hpic = $student['has_played_in_college'] ?? null;
        $hpic_persisted = ($hpic === 0 || $hpic === 1);
        if ($hpic === null) {
            $hpic = !empty($student['sports_history']) ? 1 : 0;
        }
    ?>
    <form method="post" action="student_dashboard_process.php?step=4" id="playedForm">
        <?= csrf_field() ?>
        <input type="hidden" name="has_played_in_college" id="hasPlayedHidden" value="<?= $hpic_persisted ? (int)$hpic : '' ?>">
                        <div class="form-group" data-required-group>
                            <label>Have you played any sports representing this college? <span class="req-star" aria-hidden="true">*</span></label>
                            <div class="yesno-group" id="mainPlayedGroup" role="radiogroup" aria-label="Have you played any sports representing this college?" aria-required="true">
                                <label class="yesno-opt <?= (int)$hpic === 1 ? 'selected' : '' ?>" data-val="1">
                                    <input type="radio" name="has_played_in_college_radio" value="1" <?= (int)$hpic === 1 ? 'checked' : '' ?>>
                                    <span class="yesno-circle"></span>
                                    <span class="yesno-text">Yes</span>
                                </label>
                                <label class="yesno-opt <?= (int)$hpic === 0 ? 'selected' : '' ?>" data-val="0">
                                    <input type="radio" name="has_played_in_college_radio" value="0" <?= (int)$hpic === 0 ? 'checked' : '' ?>>
                                    <span class="yesno-circle"></span>
                                    <span class="yesno-text">No</span>
                                </label>
                            </div>
                            <div class="hint yesno-error" id="yesnoError" style="display:none;color:#c53030;font-weight:600">Please choose Yes or No before continuing.</div>
                            <div class="hint">If yes, tell us the tournament levels you've taken part in below.</div>
                        </div>
                        <div class="form-group" id="historyGroup" style="<?= (int)$hpic === 1 ? '' : 'display:none' ?>">
                            <label>Levels of Participation</label>
                            <?php foreach (participation_levels() as $lvl):
                                $lvlVal = $student[$lvl['played_col']] ?? null;
                                $lvlPersisted = ($lvlVal === 0 || $lvlVal === 1);
                                if ($lvlVal === null) $lvlVal = 0;
                            ?>
                            <div class="participation-level" data-level="<?= h($lvl['slug']) ?>">
                                <input type="hidden" name="<?= h($lvl['slug']) ?>_played" id="<?= h($lvl['slug']) ?>PlayedHidden" value="<?= $lvlPersisted ? (int)$lvlVal : '' ?>">
                                <span class="participation-level-label"><?= h($lvl['label']) ?></span>
                                <div class="form-group" style="margin-bottom:0">
                                    <div class="yesno-group yesno-group--sm" role="radiogroup" aria-label="<?= h($lvl['label']) ?> participation" data-level-group="<?= h($lvl['slug']) ?>">
                                        <label class="yesno-opt <?= (int)$lvlVal === 1 ? 'selected' : '' ?>" data-val="1">
                                            <input type="radio" name="<?= h($lvl['slug']) ?>_played_radio" value="1" <?= (int)$lvlVal === 1 ? 'checked' : '' ?>>
                                            <span class="yesno-circle"></span>
                                            <span class="yesno-text">Yes</span>
                                        </label>
                                        <label class="yesno-opt <?= (int)$lvlVal === 0 ? 'selected' : '' ?>" data-val="0">
                                            <input type="radio" name="<?= h($lvl['slug']) ?>_played_radio" value="0" <?= (int)$lvlVal === 0 ? 'checked' : '' ?>>
                                            <span class="yesno-circle"></span>
                                            <span class="yesno-text">No</span>
                                        </label>
                                    </div>
                                    <div class="hint yesno-error" id="<?= h($lvl['slug']) ?>Error" style="display:none;color:#c53030;font-weight:600">Please choose Yes or No.</div>
                                    <div class="form-group" id="<?= h($lvl['slug']) ?>YearGroup" style="margin:.6rem 0 0; <?= (int)$lvlVal === 1 ? '' : 'display:none' ?>">
                                        <label for="<?= h($lvl['slug']) ?>_year">Please mention year of participation
                                            <span style="text-transform:none;letter-spacing:normal;font-weight:400;color:var(--medium-gray)">(for more than one year, separate with commas &mdash; e.g. 2024, 2025)</span>
                                        </label>
                                        <input type="text" id="<?= h($lvl['slug']) ?>_year" name="<?= h($lvl['slug']) ?>_year" maxlength="100"
                                               placeholder="e.g. 2024, 2025"
                                               value="<?= h((string)($student[$lvl['year_col']] ?? '')) ?>">
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:1.4rem; display:flex; gap:.7rem; align-items:center; flex-wrap:wrap">
                            <a href="student-dashboard.php?step=3" class="btn-cancel btn-back"><i class="bi bi-arrow-left"></i> Back</a>
                            <button type="submit" class="btn-save btn-next"><i class="bi bi-arrow-right-circle"></i> Save &amp; Continue</button>
                        </div>
                    </form>
                    <script>
                    (function () {
                        var form    = document.getElementById('playedForm');
                        if (!form) return;
                        var mainGroupEl = document.getElementById('mainPlayedGroup');
                        var opts    = mainGroupEl.querySelectorAll('.yesno-opt');
                        var group   = form.querySelector('#historyGroup');
                        var hidden  = form.querySelector('#hasPlayedHidden');
                        var errBox  = form.querySelector('#yesnoError');
                        var radios  = mainGroupEl.querySelectorAll('input[name="has_played_in_college_radio"]');
                        function getChosen() {
                            for (var i = 0; i < radios.length; i++) {
                                if (radios[i].checked) return radios[i].value;
                            }
                            return null;
                        }
                        function sync() {
                            var chosen = getChosen();
                            if (chosen === null) {
                                if (errBox) errBox.style.display = 'none';
                                return;
                            }
                            hidden.value = chosen;
                            opts.forEach(function (o) {
                                o.classList.toggle('selected', o.getAttribute('data-val') === chosen);
                            });
                            if (errBox) errBox.style.display = 'none';
                            group.style.display = (chosen === '1') ? '' : 'none';
                        }
                        opts.forEach(function (o) {
                            o.addEventListener('click', function (e) {
                                var r = o.querySelector('input[type=radio]');
                                r.checked = true;
                                sync();
                            });
                        });

                        // --- Per-level Yes/No + Year sub-questions ------------------
                        var levels = <?= json_encode(array_column(participation_levels(), 'slug')) ?>;
                        var levelState = {};
                        levels.forEach(function (slug) {
                            var groupEl  = form.querySelector('[data-level-group="' + slug + '"]');
                            var subOpts  = groupEl.querySelectorAll('.yesno-opt');
                            var subRadios = groupEl.querySelectorAll('input[type=radio]');
                            var subHidden = document.getElementById(slug + 'PlayedHidden');
                            var yearGroup = document.getElementById(slug + 'YearGroup');
                            var yearInput = document.getElementById(slug + '_year');
                            var subErr    = document.getElementById(slug + 'Error');
                            function getSubChosen() {
                                for (var i = 0; i < subRadios.length; i++) {
                                    if (subRadios[i].checked) return subRadios[i].value;
                                }
                                return null;
                            }
                            function syncSub() {
                                var chosen = getSubChosen();
                                if (chosen === null) return;
                                subHidden.value = chosen;
                                subOpts.forEach(function (o) {
                                    o.classList.toggle('selected', o.getAttribute('data-val') === chosen);
                                });
                                if (subErr) subErr.style.display = 'none';
                                if (chosen === '1') {
                                    yearGroup.style.display = '';
                                } else {
                                    yearGroup.style.display = 'none';
                                    yearInput.value = '';
                                }
                            }
                            subOpts.forEach(function (o) {
                                o.addEventListener('click', function () {
                                    var r = o.querySelector('input[type=radio]');
                                    r.checked = true;
                                    syncSub();
                                });
                            });
                            levelState[slug] = { getChosen: getSubChosen, errBox: subErr, groupEl: groupEl };
                            syncSub();
                        });

                        // Block submit until the main choice — and, if Yes, every
                        // per-level choice — has been made.
                        form.addEventListener('submit', function (e) {
                            var blocked = false;
                            if (getChosen() === null) {
                                blocked = true;
                                if (errBox) {
                                    errBox.style.display = '';
                                    errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                }
                                mainGroupEl.style.boxShadow = '0 0 0 3px rgba(197,48,48,.25)';
                                setTimeout(function () { mainGroupEl.style.boxShadow = ''; }, 1500);
                            } else if (getChosen() === '1') {
                                levels.forEach(function (slug) {
                                    var st = levelState[slug];
                                    if (st.getChosen() === null) {
                                        blocked = true;
                                        if (st.errBox) st.errBox.style.display = '';
                                        st.groupEl.style.boxShadow = '0 0 0 3px rgba(197,48,48,.25)';
                                        setTimeout(function (el) {
                                            return function () { el.style.boxShadow = ''; };
                                        }(st.groupEl), 1500);
                                    }
                                });
                            }
                            if (blocked) e.preventDefault();
                        });

                        // Initial state — if column was NULL, show the level
                        // group off but don't pre-check the main radio.
                        sync();
                    })();
                    </script>
                </div>

            <?php elseif ($step === 5): ?>
                <div class="wizard-head">
                    <h2><i class="bi bi-file-earmark-text" style="color: var(--accent-gold)"></i> Step 5 — Documents</h2>
                    <p>
                        Upload the documents required for <?= h($student['dept_name']) ?>.
                        You have uploaded <strong><?= (int)$required_uploaded ?> / <?= (int)$required_total ?></strong> required documents.
                        Required documents can be added later, but uploading them now speeds up approval.
                    </p>
                    <div class="upload-rules" role="note" aria-label="File upload rules">
                        <div class="upload-rules-head">
                            <i class="bi bi-info-circle-fill"></i>
                            <span>File Upload Guidelines</span>
                        </div>
                        <div class="upload-rules-grid">
                            <div class="upload-rule">
                                <div class="upload-rule-label">Passport-size Photo</div>
                                <div class="upload-rule-body">
                                    <span class="upload-rule-format">JPEG / JPG</span>
                                    <span class="upload-rule-size">Target 500&nbsp;KB &middot; Max 1&nbsp;MB</span>
                                </div>
                            </div>
                            <div class="upload-rule">
                                <div class="upload-rule-label">Other Documents</div>
                                <div class="upload-rule-body">
                                    <span class="upload-rule-format">PDF</span>
                                    <span class="upload-rule-size">Target 500&nbsp;KB &middot; Max 1&nbsp;MB</span>
                                </div>
                            </div>
                        </div>
                        <div class="upload-rules-foot">
                            Files exceeding 1&nbsp;MB or uploaded in the wrong format will be rejected.
                        </div>
                    </div>
                </div>
                <div class="wizard-body">
                    <?php if (!$documents): ?>
                        <div class="alert-banner info">
                            <i class="bi bi-info-circle"></i>
                            No documents are required for <?= h($student['dept_name']) ?>. You can proceed to the preview.
                        </div>
                    <?php else: ?>
                        <div id="docList">
                        <?php foreach ($documents as $doc):
                            $req_id = (int)$doc['req_id'];
                            $uploaded = !empty($doc['file_path']);
                            $requiredFlag = (int)($doc['is_required'] ?? 0) === 1;
                            // Per-row MIME list (default: PDF). Used for accept + JS guard.
                            $rowMimes = array_values(array_filter(array_map('trim', explode(',', (string)($doc['allowed_mime_types'] ?? 'application/pdf')))));
                            $isPhoto  = in_array('image/jpeg', $rowMimes, true) && !in_array('application/pdf', $rowMimes, true);
                            $acceptAttr = $isPhoto ? 'image/jpeg,.jpg,.jpeg' : 'application/pdf,.pdf';
                            $isBankPassbook = strcasecmp(trim((string)$doc['document_name']), 'Bank passbook') === 0;
                        ?>
                            <div class="doc-card <?= $uploaded ? 'uploaded' : '' ?>" id="doc-<?= $req_id ?>">
                                <div class="doc-head">
                                    <div class="doc-head-main">
                                        <?php if ($uploaded): ?>
                                            <a class="doc-thumb" href="<?= h(url((string)$doc['file_path'])) ?>" target="_blank" rel="noopener" title="View <?= h($doc['document_name']) ?>">
                                                <?php if ($isPhoto): ?>
                                                    <img src="<?= h(url((string)$doc['file_path'])) ?>" alt="">
                                                <?php else: ?>
                                                    <i class="bi bi-file-earmark-pdf pdf-thumb-fallback"></i>
                                                    <canvas class="pdf-thumb-canvas" data-pdf-url="<?= h(url((string)$doc['file_path'])) ?>" style="display:none"></canvas>
                                                <?php endif; ?>
                                            </a>
                                        <?php endif; ?>
                                        <div class="doc-title">
                                            <i class="bi bi-file-earmark-<?= $uploaded ? 'check' : 'arrow-up' ?>"></i>
                                            <?= h($doc['document_name']) ?>
                                            <?php if ($requiredFlag): ?>
                                                <span class="badge-required">REQUIRED</span>
                                            <?php endif; ?>
                                            <?php if ($uploaded): ?>
                                                <span class="badge-ok"><i class="bi bi-check-circle"></i> Uploaded</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="doc-actions">
                                        <?php if ($uploaded): ?>
                                            <span class="doc-file">
                                                <i class="bi bi-paperclip"></i>
                                                <?= h(basename((string)$doc['file_path'])) ?>
                                            </span>
                                            <a href="<?= h(url((string)$doc['file_path'])) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> View</a>
                                            <button type="button" class="doc-del" data-req="<?= $req_id ?>"><i class="bi bi-trash"></i> Remove</button>
                                        <?php else: ?>
                                            <label class="doc-pick">
                                                <i class="bi bi-upload"></i> Choose file
                                                <input type="file" data-req="<?= $req_id ?>" accept="<?= h($acceptAttr) ?>">
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="doc-status" id="doc-status-<?= $req_id ?>">
                                    <?php if ($uploaded): ?>
                                        Uploaded <?= h(date('d M Y H:i', strtotime((string)$doc['uploaded_at']))) ?>
                                    <?php else: ?>
                                        No file uploaded yet.
                                    <?php endif; ?>
                                </div>
                                <?php if ($isBankPassbook): ?>
                                <div class="bank-details" id="bankDetails">
                                    <div class="bank-details-title"><i class="bi bi-bank"></i> Bank account details</div>
                                    <div class="bank-grid">
                                        <div class="form-group">
                                            <label for="bank_account_number">Account Number *</label>
                                            <input type="text" id="bank_account_number" name="bank_account_number" maxlength="30"
                                                   inputmode="numeric" autocomplete="off"
                                                   value="<?= h((string)($student['bank_account_number'] ?? '')) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="bank_account_number_confirm">Confirm Account Number *</label>
                                            <input type="text" id="bank_account_number_confirm" name="bank_account_number_confirm" maxlength="30"
                                                   inputmode="numeric" autocomplete="off" onpaste="return false"
                                                   aria-describedby="bankConfirmError"
                                                   value="<?= h((string)($student['bank_account_number'] ?? '')) ?>">
                                            <div class="bank-field-error" id="bankConfirmError" role="alert" aria-live="polite"></div>
                                        </div>
                                        <div class="form-group">
                                            <label for="bank_name">Name of Bank *</label>
                                            <input type="text" id="bank_name" name="bank_name" maxlength="120" autocomplete="off"
                                                   value="<?= h((string)($student['bank_name'] ?? '')) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="bank_branch">Name of Branch *</label>
                                            <input type="text" id="bank_branch" name="bank_branch" maxlength="120" autocomplete="off"
                                                   value="<?= h((string)($student['bank_branch'] ?? '')) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="bank_ifsc">IFSC Code *</label>
                                            <input type="text" id="bank_ifsc" name="bank_ifsc" maxlength="11" autocomplete="off"
                                                   placeholder="e.g. SBIN0001234"
                                                   value="<?= h((string)($student['bank_ifsc'] ?? '')) ?>">
                                        </div>
                                    </div>
                                    <div class="bank-details-actions">
                                        <button type="button" class="btn-save" id="bankDetailsSave"><i class="bi bi-check-circle"></i> Save bank details</button>
                                        <span class="bank-details-status" id="bankDetailsStatus"></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:1.4rem; display:flex; gap:.7rem; align-items:center; flex-wrap:wrap">
                        <a href="student-dashboard.php?step=4" class="btn-cancel btn-back"><i class="bi bi-arrow-left"></i> Back</a>
                        <a href="student-dashboard.php?step=6" class="btn-save btn-next"><i class="bi bi-arrow-right-circle"></i> Continue to Preview</a>
                    </div>
                </div>

            <?php elseif ($step === 6): ?>
                <div class="wizard-head">
                    <h2><i class="bi bi-eye" style="color: var(--accent-gold)"></i> Step 6 — Preview &amp; Submit</h2>
                    <p>Review everything below. Use the Edit links to jump back to any section. When you're satisfied, click Submit to send your profile to the Faculty of Sports.</p>
                </div>
                <div class="wizard-body">
                    <?php
                        $fmt_doc = function(array $doc): string {
                            if (empty($doc['file_path'])) return '<em class="empty">— not uploaded —</em>';
                            $href = h(url((string)$doc['file_path']));
                            $name = h(basename((string)$doc['file_path']));
                            return '<a href="' . $href . '" target="_blank" rel="noopener">' . $name . '</a>';
                        };
                        $yes = function(string $v): string {
                            return $v !== '' ? h($v) : '<em class="empty">—</em>';
                        };
                        $reqDocBadge = function(array $doc): string {
                            if (!empty($doc['file_path'])) return ' <span class="badge-ok">Uploaded</span>';
                            if ((int)($doc['is_required'] ?? 0) === 1) return ' <span class="badge-required">REQUIRED — missing</span>';
                            return '';
                        };
                    ?>

                    <div class="preview-summary">

                        <div class="preview-card">
                            <div class="preview-card-head">
                                <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-person-vcard"></i></span> Personal Information</div>
                                <a class="preview-edit-link" href="student-dashboard.php?step=1"><i class="bi bi-pencil"></i> Edit</a>
                            </div>
                            <div class="preview-grid">
                                <div class="preview-field"><span class="preview-field-label">Full Name</span><span class="preview-field-value"><?= h($student['full_name'] ?? '') ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">Date of Birth</span><span class="preview-field-value"><?= $yes((string)($student['dob'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">Aadhar Number</span><span class="preview-field-value"><?= $yes((string)($student['aadhar_number'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">Gender</span><span class="preview-field-value"><?= $yes((string)($student['gender'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">Blood Group</span><span class="preview-field-value"><?= $yes((string)($student['blood_group'] ?? '')) ?></span></div>
                                <?php if ($uses_father_first_name): ?>
                                    <div class="preview-field"><span class="preview-field-label">Father's First Name</span><span class="preview-field-value"><?= $yes((string)($student['father_name'] ?? '')) ?></span></div>
                                <?php endif; ?>
                                <?php if ($shows_mother_name_field): ?>
                                    <div class="preview-field"><span class="preview-field-label">Mother's Name</span><span class="preview-field-value"><?= $yes((string)($student['mother_name'] ?? '')) ?></span></div>
                                <?php endif; ?>
                                <div class="preview-field"><span class="preview-field-label">Email</span><span class="preview-field-value"><?= $yes((string)($student['email'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">Mobile</span><span class="preview-field-value"><?= $yes((string)($student['mobile'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">WhatsApp No.</span><span class="preview-field-value"><?= $yes((string)($student['whatsapp_no'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">Permanent Address</span><span class="preview-field-value"><?= $yes((string)($student['permanent_address'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">Current Address</span><span class="preview-field-value"><?= $yes((string)($student['current_address'] ?? '')) ?></span></div>
                                <?php if (!empty($student['photo_path'])): ?>
                                    <div class="preview-field"><span class="preview-field-label">Photo</span><span class="preview-field-value"><a href="<?= h(url((string)$student['photo_path'])) ?>" target="_blank" rel="noopener">View <i class="bi bi-box-arrow-up-right"></i></a></span></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="preview-card">
                            <div class="preview-card-head">
                                <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-mortarboard"></i></span> Academic Details</div>
                                <a class="preview-edit-link" href="student-dashboard.php?step=2"><i class="bi bi-pencil"></i> Edit</a>
                            </div>
                            <div class="preview-grid">
                                <div class="preview-field"><span class="preview-field-label">Faculty</span><span class="preview-field-value"><?= h($student['dept_name']) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">PRN/Enrollment No.</span><span class="preview-field-value"><?= $yes((string)($student['enrollment_no'] ?? '')) ?></span></div>
                                <?php if ($stores_roll_no): ?>
                                    <div class="preview-field"><span class="preview-field-label">Roll No.</span><span class="preview-field-value"><?= $yes((string)($student['roll_no'] ?? '')) ?></span></div>
                                <?php endif; ?>
                                <div class="preview-field"><span class="preview-field-label">Program</span><span class="preview-field-value"><?= $yes((string)($student['program'] ?? '')) ?></span></div>
                                <?php if ($needs_course_duration): ?>
                                    <div class="preview-field"><span class="preview-field-label">Duration of Course</span><span class="preview-field-value"><?= $yes((string)($student['course_duration_years'] ?? '')) ?></span></div>
                                <?php endif; ?>
                                <div class="preview-field"><span class="preview-field-label">Department / Branch</span><span class="preview-field-value"><?= $yes((string)($student['department_name'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">Current Year in which studying</span><span class="preview-field-value"><?= $yes((string)($student['study_year'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">SSC Passing Year</span><span class="preview-field-value"><?= $yes((string)($student['ssc_passing_year'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">HSC Passing Year</span><span class="preview-field-value"><?= $yes((string)($student['hsc_passing_year'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">Diploma Passing Year</span><span class="preview-field-value"><?= $yes((string)($student['diploma_passing_year'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">First Admission to University / College</span><span class="preview-field-value"><?= $yes((string)($student['first_admission_university_year'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">First Admission to Present Course</span><span class="preview-field-value"><?= $yes((string)($student['first_admission_course_year'] ?? '')) ?></span></div>
                                <div class="preview-field"><span class="preview-field-label">First Admission to Present Class</span><span class="preview-field-value"><?= $yes((string)($student['first_admission_class_year'] ?? '')) ?></span></div>
                                <?php $gapYearVal = $student['has_gap_year'] ?? null; ?>
                                <div class="preview-field"><span class="preview-field-label">Gap / Year Drop</span><span class="preview-field-value"><?= $gapYearVal === null ? '<em class="empty">—</em>' : ((int)$gapYearVal === 1 ? 'Yes' : 'No') ?></span></div>
                                <?php if ((int)($gapYearVal ?? 0) === 1): ?>
                                    <div class="preview-field"><span class="preview-field-label">Please Mention Year</span><span class="preview-field-value"><?= $yes((string)($student['gap_year_detail'] ?? '')) ?></span></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="preview-card">
                            <div class="preview-card-head">
                                <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-trophy"></i></span> Sports Information</div>
                                <a class="preview-edit-link" href="student-dashboard.php?step=3"><i class="bi bi-pencil"></i> Edit</a>
                            </div>
                            <div class="preview-grid">
                                <?php if ($uses_game_picker): ?>
                                    <?php
                                        // Build a code -> display_name lookup for preview chips.
                                        $game_name_by_code = [];
                                        foreach ($game_catalog as $g) {
                                            $game_name_by_code[(string)$g['game_code']] = (string)$g['display_name'];
                                        }
                                    ?>
                                    <div class="preview-field preview-field--full">
                                        <span class="preview-field-label">Enrolled Games</span>
                                        <span class="preview-field-value">
                                            <?php if (!empty($selected_games)): ?>
                                                <?php foreach ($selected_games as $code): ?>
                                                    <span class="game-chip">
                                                        <i class="<?= h(game_icon_for((string)$code)) ?>"></i>
                                                        <?= h($game_name_by_code[(string)$code] ?? $code) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <em class="empty">No games selected yet.</em>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="preview-field"><span class="preview-field-label">Primary Sport</span><span class="preview-field-value"><?= $yes((string)($student['sport_1'] ?? '')) ?></span></div>
                                    <div class="preview-field"><span class="preview-field-label">Secondary Sport</span><span class="preview-field-value"><?= $yes((string)($student['sport_2'] ?? '')) ?></span></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="preview-card">
                            <div class="preview-card-head">
                                <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-clock-history"></i></span> Played History</div>
                                <a class="preview-edit-link" href="student-dashboard.php?step=4"><i class="bi bi-pencil"></i> Edit</a>
                            </div>
                            <div class="preview-grid">
                                <?php
                                    $hpic_val = $student['has_played_in_college'] ?? null;
                                    if ($hpic_val === null) {
                                        $hpic_val = !empty($student['sports_history']) ? 1 : 0;
                                    }
                                ?>
                                <div class="preview-field">
                                    <span class="preview-field-label">Played in this college?</span>
                                    <span class="preview-field-value">
                                        <?php if ((int)$hpic_val === 1): ?>
                                            <span class="badge-ok"><i class="bi bi-check-circle"></i> Yes</span>
                                        <?php else: ?>
                                            <em class="empty">No</em>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ((int)$hpic_val === 1): foreach (participation_levels() as $lvl):
                                    $lvlVal = $student[$lvl['played_col']] ?? null;
                                ?>
                                <div class="preview-field">
                                    <span class="preview-field-label"><?= h($lvl['label']) ?></span>
                                    <span class="preview-field-value">
                                        <?php if ((int)$lvlVal === 1): ?>
                                            <span class="badge-ok"><i class="bi bi-check-circle"></i> Yes<?= !empty($student[$lvl['year_col']]) ? ' — ' . h((string)$student[$lvl['year_col']]) : '' ?></span>
                                        <?php else: ?>
                                            <em class="empty">No</em>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>

                        <div class="preview-card">
                            <div class="preview-card-head">
                                <div class="preview-card-title"><span class="preview-card-icon"><i class="bi bi-file-earmark-text"></i></span> Documents</div>
                                <a class="preview-edit-link" href="student-dashboard.php?step=5"><i class="bi bi-pencil"></i> Edit</a>
                            </div>
                            <?php if (!$documents): ?>
                                <div class="preview-doc-list">
                                    <div class="preview-doc-row"><em class="empty">No documents required for your department.</em></div>
                                </div>
                            <?php else: ?>
                                <div class="preview-doc-list">
                                    <?php foreach ($documents as $doc): ?>
                                        <div class="preview-doc-row">
                                            <span class="preview-doc-name"><?= h($doc['document_name']) ?><?= (int)($doc['is_required'] ?? 0) === 1 ? ' <span class="req-star">*</span>' : '' ?></span>
                                            <span class="preview-doc-status"><?= $fmt_doc($doc) ?> <?= $reqDocBadge($doc) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($has_bank_passbook): ?>
                                <div class="preview-grid" style="border-top:1px solid var(--light-gray)">
                                    <div class="preview-field"><span class="preview-field-label">Account Number</span><span class="preview-field-value"><?= $yes((string)($student['bank_account_number'] ?? '')) ?></span></div>
                                    <div class="preview-field"><span class="preview-field-label">Name of Bank</span><span class="preview-field-value"><?= $yes((string)($student['bank_name'] ?? '')) ?></span></div>
                                    <div class="preview-field"><span class="preview-field-label">Name of Branch</span><span class="preview-field-value"><?= $yes((string)($student['bank_branch'] ?? '')) ?></span></div>
                                    <div class="preview-field"><span class="preview-field-label">IFSC Code</span><span class="preview-field-value"><?= $yes((string)($student['bank_ifsc'] ?? '')) ?></span></div>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>

                    <?php if ($required_total > 0 && $required_uploaded < $required_total): ?>
                        <div class="alert-banner warn" style="margin-top:1.2rem">
                            <i class="bi bi-exclamation-triangle"></i>
                            <?= (int)($required_total - $required_uploaded) ?> required document(s) still missing. You can submit anyway — the Faculty of Sports will follow up.
                        </div>
                    <?php endif; ?>

                    <form method="post" action="student_dashboard_process.php?finalize=1" style="margin-top:1.4rem">
                        <?= csrf_field() ?>
                        <div style="display:flex; gap:.7rem; align-items:center; flex-wrap:wrap">
                            <a href="student-dashboard.php?step=5" class="btn-cancel btn-back"><i class="bi bi-arrow-left"></i> Back to Documents</a>
                            <?php if (!empty($student['form_submitted_at'])): ?>
                                <button type="submit" class="btn-save"><i class="bi bi-check-circle"></i> Re-submit Profile</button>
                                <span style="font-size:.8rem; color: var(--medium-gray)">
                                    (Submitted on <?= h(date('d M Y H:i', strtotime((string)$student['form_submitted_at']))) ?>)
                                </span>
                            <?php else: ?>
                                <button type="submit" class="btn-save"><i class="bi bi-check-circle"></i> Submit Profile</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        /* Combine Surname + First + Middle into hidden full_name on step 1 submit */
        var form1 = document.querySelector('form[action*="student_dashboard_process.php?step=1"]');
        if (form1) {
            form1.addEventListener('submit', function () {
                var sn = (document.getElementById('surname')     || {}).value || '';
                var fn = (document.getElementById('first_name')  || {}).value || '';
                var mn = (document.getElementById('middle_name') || {}).value || '';
                var parts = [sn, fn, mn].map(function (s) { return s.trim(); }).filter(Boolean);
                document.getElementById('full_name_field').value = parts.join(' ');
            });

            /* "Same as Permanent Address" checkbox */
            var sameCb    = document.getElementById('same_as_permanent');
            var permInput = document.getElementById('permanent_address');
            var curInput  = document.getElementById('current_address');
            function syncCurrentAddress() {
                if (!sameCb || !permInput || !curInput) return;
                if (sameCb.checked) {
                    curInput.value = permInput.value;
                    curInput.readOnly = true;
                } else {
                    curInput.readOnly = false;
                }
            }
            if (sameCb) {
                syncCurrentAddress();
                sameCb.addEventListener('change', syncCurrentAddress);
                permInput.addEventListener('input', function () {
                    if (sameCb.checked) curInput.value = permInput.value;
                });
            }

            /* "WhatsApp number same as Mobile No." checkbox */
            var waCb    = document.getElementById('same_as_mobile');
            var mobInput = document.getElementById('mobile');
            var waInput  = document.getElementById('whatsapp_no');
            if (waCb && mobInput && waInput) {
                /* On load: only lock the field if already ticked — never wipe a
                   saved number that legitimately differs from the mobile no. */
                if (waCb.checked) {
                    waInput.value = mobInput.value;
                    waInput.readOnly = true;
                }
                waCb.addEventListener('change', function () {
                    if (waCb.checked) {
                        waInput.value = mobInput.value;
                        waInput.readOnly = true;
                    } else {
                        waInput.value = '';
                        waInput.readOnly = false;
                        waInput.focus();
                    }
                });
                mobInput.addEventListener('input', function () {
                    if (waCb.checked) waInput.value = mobInput.value;
                });
            }
        }

        /* Documents step (5) — per-file upload + delete via fetch */
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfValue = csrfMeta ? csrfMeta.getAttribute('content') : '';

        function postDocUpload(reqId, file, onOk, onErr) {
            var fd = new FormData();
            fd.append('_csrf', csrfValue);
            fd.append('doc_' + reqId, file);
            fetch('student_dashboard_process.php?step=5', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.ok) onOk(j);
                    else onErr(j && j.message ? j.message : (j && j.error ? j.error : 'upload_failed'));
                })
                .catch(function () { onErr('network_error'); });
        }
        function postDocDelete(reqId, onOk, onErr) {
            var fd = new FormData();
            fd.append('_csrf', csrfValue);
            fetch('student_dashboard_process.php?step=5&delete=' + reqId, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.ok) onOk(j);
                    else onErr(j && j.error ? j.error : 'delete_failed');
                })
                .catch(function () { onErr('network_error'); });
        }

        document.querySelectorAll('.doc-pick input[type=file]').forEach(function (input) {
            input.addEventListener('change', function () {
                var reqId = input.getAttribute('data-req');
                var file  = input.files && input.files[0];
                if (!file) return;
                var status = document.getElementById('doc-status-' + reqId);

                // Per-row rules: photo rows accept JPEG only, document rows accept PDF only.
                var accept = (input.getAttribute('accept') || '').toLowerCase();
                var isPhoto = accept.indexOf('image/jpeg') !== -1;
                var MAX_BYTES = 1024 * 1024; // 1 MB
                var nameOk = isPhoto ? /\.jpe?g$/i.test(file.name || '') : /\.pdf$/i.test(file.name || '');
                var typeOk = isPhoto ? (file.type === 'image/jpeg' || file.type === '') : (file.type === 'application/pdf' || file.type === '');

                if (!isPhoto) {
                    // Strict PDF
                    if (!(nameOk && (file.type === 'application/pdf' || /\.pdf$/i.test(file.name || '')))) {
                        var pdfMsg = 'Only PDF files are accepted for this document. "' + (file.name || '') +
                            '" is not a PDF. Please convert and try again.';
                        if (status) { status.textContent = pdfMsg; status.style.color = '#b91c1c'; }
                        alert(pdfMsg);
                        input.value = '';
                        return;
                    }
                } else {
                    // Strict JPEG/JPG
                    if (!(/\.jpe?g$/i.test(file.name || '') && (file.type === 'image/jpeg' || file.type === ''))) {
                        var jpgMsg = 'Only JPEG/JPG files are accepted for the photo. "' + (file.name || '') +
                            '" is not a JPEG. Please convert and try again.';
                        if (status) { status.textContent = jpgMsg; status.style.color = '#b91c1c'; }
                        alert(jpgMsg);
                        input.value = '';
                        return;
                    }
                }

                if (file.size > MAX_BYTES) {
                    var sizeMsg = 'File is too large: "' + file.name + '" is ' +
                        (file.size / 1024 / 1024).toFixed(2) + ' MB. Maximum allowed size is 1 MB. Please compress and try again.';
                    if (status) { status.textContent = sizeMsg; status.style.color = '#b91c1c'; }
                    alert(sizeMsg);
                    input.value = '';
                    return;
                }

                if (status) status.textContent = 'Uploading…';
                postDocUpload(reqId, file,
                    function () { location.reload(); },
                    function (err) {
                        // postDocUpload already prefers j.message over j.error.
                        if (status) { status.textContent = 'Upload failed: ' + err; status.style.color = '#b91c1c'; }
                        input.value = '';
                    }
                );
            });
        });
        document.querySelectorAll('.doc-del').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var reqId = btn.getAttribute('data-req');
                if (!confirm('Remove this uploaded document?')) return;
                btn.disabled = true;
                postDocDelete(reqId,
                    function () { location.reload(); },
                    function (err) {
                        alert('Delete failed: ' + err);
                        btn.disabled = false;
                    }
                );
            });
        });

        /* Bank account details (inside the "Bank passbook" doc-card) — fetch save */
        var bankSave = document.getElementById('bankDetailsSave');
        if (bankSave) {
            var bankStatus = document.getElementById('bankDetailsStatus');
            var bankVal = function (id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
            var bankFail = function (msg) { if (bankStatus) { bankStatus.textContent = msg; bankStatus.style.color = '#b91c1c'; } };

            /* Live "confirm account number" match check — runs as the user types,
               before Save. Returns true when the two fields agree. */
            var accEl     = document.getElementById('bank_account_number');
            var acc2El    = document.getElementById('bank_account_number_confirm');
            var confirmErr = document.getElementById('bankConfirmError');
            function checkAccountMatch() {
                if (!accEl || !acc2El) return true;
                var a = accEl.value.trim(), b = acc2El.value.trim();
                if (b === '') {
                    acc2El.classList.remove('mismatch', 'match-ok');
                    if (confirmErr) confirmErr.textContent = '';
                    return a === '';
                }
                if (a === b) {
                    acc2El.classList.remove('mismatch');
                    acc2El.classList.add('match-ok');
                    if (confirmErr) confirmErr.textContent = '';
                    return true;
                }
                acc2El.classList.add('mismatch');
                acc2El.classList.remove('match-ok');
                if (confirmErr) confirmErr.textContent = 'Account numbers do not match.';
                return false;
            }
            if (accEl)  accEl.addEventListener('input', checkAccountMatch);
            if (acc2El) acc2El.addEventListener('input', checkAccountMatch);

            bankSave.addEventListener('click', function () {
                var acc    = bankVal('bank_account_number');
                var acc2   = bankVal('bank_account_number_confirm');
                var bank   = bankVal('bank_name');
                var branch = bankVal('bank_branch');
                var ifsc   = bankVal('bank_ifsc').toUpperCase();
                if (!acc || !acc2 || !bank || !branch || !ifsc) { bankFail('Please fill all five bank fields.'); return; }
                if (!/^[0-9]{6,20}$/.test(acc)) { bankFail('Account number must be 6–20 digits.'); return; }
                if (acc !== acc2) {
                    checkAccountMatch();
                    if (acc2El) acc2El.focus();
                    bankFail('Account numbers do not match.');
                    return;
                }
                if (!/^[A-Z]{4}0[A-Z0-9]{6}$/.test(ifsc)) { bankFail('IFSC code format looks wrong (e.g. SBIN0001234).'); return; }
                bankSave.disabled = true;
                if (bankStatus) { bankStatus.textContent = 'Saving…'; bankStatus.style.color = ''; }
                var fd = new FormData();
                fd.append('_csrf', csrfValue);
                fd.append('bank_details', '1');
                fd.append('bank_account_number', acc);
                fd.append('bank_account_number_confirm', acc2);
                fd.append('bank_name', bank);
                fd.append('bank_branch', branch);
                fd.append('bank_ifsc', ifsc);
                fetch('student_dashboard_process.php?step=5', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        bankSave.disabled = false;
                        if (j && j.ok) {
                            if (bankStatus) { bankStatus.textContent = 'Saved.'; bankStatus.style.color = '#198754'; }
                            var ifscEl = document.getElementById('bank_ifsc');
                            if (ifscEl) ifscEl.value = ifsc;
                        } else {
                            bankFail(j && j.message ? j.message : 'Save failed.');
                        }
                    })
                    .catch(function () { bankSave.disabled = false; bankFail('Network error. Please try again.'); });
            });
        }

        /* Render a real thumbnail of page 1 for each uploaded PDF (falls back to the icon if pdf.js can't load/render) */
        var pdfCanvases = document.querySelectorAll('.pdf-thumb-canvas[data-pdf-url]');
        if (pdfCanvases.length && window.pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
            pdfCanvases.forEach(function (canvas) {
                var url = canvas.getAttribute('data-pdf-url');
                var thumbSize = canvas.parentElement.clientWidth || 56;
                var dpr = window.devicePixelRatio || 1;
                pdfjsLib.getDocument(url).promise
                    .then(function (pdf) { return pdf.getPage(1); })
                    .then(function (page) {
                        var baseViewport = page.getViewport({ scale: 1 });
                        var scale = (thumbSize * dpr) / Math.min(baseViewport.width, baseViewport.height);
                        var viewport = page.getViewport({ scale: scale });
                        canvas.width = viewport.width;
                        canvas.height = viewport.height;
                        var ctx = canvas.getContext('2d');
                        return page.render({ canvasContext: ctx, viewport: viewport }).promise;
                    })
                    .then(function () {
                        canvas.style.display = 'block';
                        var fallback = canvas.parentElement.querySelector('.pdf-thumb-fallback');
                        if (fallback) fallback.style.display = 'none';
                    })
                    .catch(function () { /* leave the fallback icon showing */ });
            });
        }

        /* ---------------- Game-picker (Step 3) — no cap, at least 1 required ---------------- */
        var pickerForm = document.getElementById('gamePickerForm');
        if (pickerForm) {
            var totalGames = parseInt(pickerForm.getAttribute('data-total-games') || '0', 10) || 0;
            var counterNum = document.getElementById('gameCountNum');
            var counter    = document.getElementById('gameCounter');
            var bar        = document.getElementById('gameBarFill');
            var err        = document.getElementById('gamePickerError');
            var submit     = document.getElementById('gamePickerSubmit');

            function refresh() {
                var checked = pickerForm.querySelectorAll('.game-row.selected').length;
                if (counterNum) counterNum.textContent = String(checked);
                if (counter) counter.classList.toggle('complete', checked === totalGames && totalGames > 0);
                if (bar) bar.style.width = Math.min(100, Math.round(checked / Math.max(1, totalGames) * 100)) + '%';
                if (submit) submit.disabled = false;
            }

            pickerForm.querySelectorAll('.game-row').forEach(function (row) {
                var cb = row.querySelector('input[type=checkbox]');
                if (!cb) return;
                cb.addEventListener('change', function () {
                    row.classList.toggle('selected', cb.checked);
                    refresh();
                });
            });

            /* Search / filter — hides non-matching rows; ticked rows stay
               ticked and still submit even while hidden. */
            var search = document.getElementById('gameSearch');
            if (search) {
                var emptyMsg = document.getElementById('gameListEmpty');
                var searchRows = pickerForm.querySelectorAll('.game-row');
                search.addEventListener('input', function () {
                    var q = search.value.trim().toLowerCase();
                    var shown = 0;
                    searchRows.forEach(function (row) {
                        var nameEl = row.querySelector('.game-name');
                        var name = (nameEl ? nameEl.textContent : '').toLowerCase();
                        var match = q === '' || name.indexOf(q) !== -1;
                        row.classList.toggle('filtered-out', !match);
                        if (match) shown++;
                    });
                    if (emptyMsg) emptyMsg.classList.toggle('show', shown === 0);
                });
            }

            pickerForm.addEventListener('submit', function (e) {
                var checked = pickerForm.querySelectorAll('.game-row.selected').length;
                if (checked < 1) {
                    e.preventDefault();
                    if (err) {
                        err.textContent = 'Please select at least 1 game before continuing.';
                    }
                    var grid = document.getElementById('gameGrid');
                    if (grid) {
                        grid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        grid.style.boxShadow = '0 0 0 3px rgba(185,28,28,.3)';
                        setTimeout(function () { grid.style.boxShadow = ''; }, 1500);
                    }
                }
            });

            refresh();
        }
    })();
    </script>
</body>
</html>
