<?php
/**
 * Student profile: view, add, or edit a single student.
 * URL:  ?id=123  -> view/edit
 *       ?new=1   -> blank add form
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_department();

$me = current_faculty();
if ($me === null) {
    $prefix = is_file('faculty-login.php') ? '' : '../';
    redirect($prefix . 'faculty-login.php');
}

$is_new = isset($_GET['new']);
$id     = (int)($_GET['id'] ?? 0);

$student = null;
$achievements = [];
if (!$is_new && $id > 0) {
    [$scope, $p, $t] = scope_sql_department('s');
    // Hide wizard drafts (students who registered but haven't hit Final Submit).
    $visible = faculty_visible_student_filter('s');
    $scope  .= $visible[0];
    $p       = array_merge($p, $visible[1]);
    $t      .= $visible[2];
    $student = db_one(
        "SELECT s.*, d.name AS dept_name, d.code AS department_code FROM students s
           JOIN departments d ON d.id = s.department_id
          WHERE s.id = ? $scope",
        array_merge([$id], $p), 'i' . $t
    );
    if (!$student) {
        http_response_code(404);
        exit('Student not found.');
    }

    // Pull achievements from the dedicated achievements table
    $achievements = db_select(
        'SELECT id, title, event_name, level, position, event_date, description
           FROM achievements
          WHERE student_id = ? AND is_published = 1
          ORDER BY event_date DESC, id DESC',
        [$id], 'i'
    );

    // Pull all required documents for this department and check if student has uploaded them
    $documents = db_select(
        'SELECT dr.id AS req_id, dr.document_name, sd.file_path
         FROM dept_document_requirements dr
         LEFT JOIN student_documents sd ON sd.requirement_id = dr.id AND sd.student_id = ?
         WHERE dr.department_id = ?
         ORDER BY dr.id',
        [$id, $student['department_id']], 'ii'
    );
}

$departments = db_select('SELECT id, code, name FROM departments WHERE is_active = 1 ORDER BY display_order, name');

// Departments the current user is allowed to save to.
$allowed_dept_ids = ($me['role'] === 'SUPER_ADMIN')
    ? array_map(fn($r) => (int)$r['id'], $departments)
    : [(int)$me['department_id']];

$ok_flash  = flash_get('student_saved');
$err_flash = flash_get('student_error');

function is_selected($value, $current): string {
    return (string)$value === (string)$current ? 'selected' : '';
}

// Map position text to CSS class + icon for the medal chip.
function medal_class(string $position): string {
    $l = strtolower($position);
    if (str_contains($l, 'gold') || $l === '1st')   return 'gold';
    if (str_contains($l, 'silver') || $l === '2nd')  return 'silver';
    if (str_contains($l, 'bronze') || $l === '3rd')  return 'bronze';
    if (str_contains($l, 'participat'))               return 'participation';
    return 'gold';
}
function medal_icon(string $class): string {
    return match($class) {
        'silver'        => 'bi-award-fill',
        'participation' => 'bi-flag-fill',
        default         => 'bi-trophy-fill',
    };
}

/* Best-effort Bootstrap-Icon glyph for a game_code. Pure decoration. */
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
    ];
    return $map[$code] ?? 'fa-solid fa-trophy';
}

/* Game-picker state for this student. Used by both view + edit modes. */
$game_catalog = [];
$selected_games = [];
$game_total_available = 0;
$uses_game_picker = false;
if (!$is_new && $student) {
    $game_catalog = db_select(
        'SELECT game_code, display_name, max_picks
           FROM dept_game_catalog
          WHERE department_id = ? AND is_active = 1
          ORDER BY display_order, display_name',
        [(int)$student['department_id']], 'i'
    );
    $uses_game_picker = !empty($game_catalog);
    if ($uses_game_picker) {
        $game_total_available = count($game_catalog);
        $rows = db_select(
            'SELECT game_code FROM student_selected_games WHERE student_id = ?',
            [$id], 'i'
        );
        foreach ($rows as $r) $selected_games[] = (string)$r['game_code'];
        $selected_games = array_values(array_intersect(
            $selected_games,
            array_column($game_catalog, 'game_code')
        ));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_new ? 'Add' : 'Edit' ?> Student | Sports Portal</title>
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
        .yesno-group { display:flex; gap:.6rem; flex-wrap:wrap; }

        /* Official-style file upload rules panel (Required Documents section) */
        .upload-rules {
            margin: 0 0 1rem;
            border: 1px solid #d8dee6;
            border-radius: 4px;
            background: #fff;
            overflow: hidden;
        }
        .upload-rules-head {
            display: flex; align-items: center; gap: .45rem;
            padding: .4rem .85rem;
            border-bottom: 1px solid #e5e9ef;
            color: #4a5568;
            font-size: .75rem; font-weight: 600;
            letter-spacing: .03em; text-transform: uppercase;
        }
        .upload-rules-head i { color: #6b7a8a; font-size: .88rem; }
        .upload-rules-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 0;
        }
        .upload-rule {
            padding: .65rem .85rem;
            border-right: 1px solid #e5e9ef;
            display: flex; flex-direction: column; gap: .2rem;
        }
        .upload-rule:last-child { border-right: 0; }
        .upload-rule-label {
            font-size: .68rem; font-weight: 600;
            letter-spacing: .04em; text-transform: uppercase;
            color: #6b7a8a;
        }
        .upload-rule-body { display: flex; align-items: baseline; gap: .5rem; flex-wrap: wrap; }
        .upload-rule-format {
            font-size: .92rem; font-weight: 700; color: #1a365d;
        }
        .upload-rule-size { font-size: .75rem; color: #6b7a8a; }
        .upload-rules-foot {
            padding: .45rem .85rem;
            border-top: 1px solid #e5e9ef;
            color: #6b7a8a;
            font-size: .74rem;
        }
        @media (max-width: 600px) {
            .upload-rules-grid { grid-template-columns: 1fr; }
            .upload-rule { border-right: 0; border-bottom: 1px solid #e5e9ef; }
            .upload-rule:last-child { border-bottom: 0; }
        }
        .yesno-opt { display:inline-flex; align-items:center; gap:.5rem; padding:.5rem 1rem; border:2px solid var(--light-gray); border-radius:999px; cursor:pointer; font-size:.92rem; color: var(--text-dark); background:#fff; transition: all .15s ease; user-select:none; }
        .yesno-opt:hover { border-color: var(--primary-navy); }
        .yesno-opt input[type=radio] { position:absolute; opacity:0; pointer-events:none; }
        .yesno-circle { width:18px; height:18px; border:2px solid var(--medium-gray); border-radius:50%; display:inline-block; position:relative; flex-shrink:0; transition: all .15s ease; }
        .yesno-opt.selected { border-color: var(--primary-navy); background: rgba(26,54,93,.05); font-weight:600; }
        .yesno-opt.selected .yesno-circle { border-color: var(--primary-navy); }
        .yesno-opt.selected .yesno-circle::after { content:''; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:8px; height:8px; border-radius:50%; background: var(--primary-navy); }
        .form-group .hint { font-size:.72rem; color: var(--medium-gray); margin-top:.3rem; }
        .yesno-group--sm .yesno-opt { padding:.32rem .75rem; font-size:.85rem; }
        .yesno-group--sm .yesno-circle { width:14px; height:14px; }
        .yesno-group--sm .yesno-opt.selected .yesno-circle::after { width:6px; height:6px; }
        .participation-level { padding:.9rem 1rem; border:1px solid var(--light-gray); border-radius:10px; margin-bottom:.9rem; }
        .participation-level .form-group:last-child { margin-bottom:0; }
        .participation-level-label { font-weight:600; font-size:.92rem; color: var(--text-dark); margin-bottom:.5rem; display:block; }

        /* Game-checkbox picker (Step 3) — same look as student-dashboard */
        .game-picker-head { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:.7rem; flex-wrap:wrap; }
        .game-picker-counter { font-size:.85rem; color: var(--medium-gray); }
        .game-picker-counter strong { color: var(--primary-navy); font-size:1rem; }
        .game-picker-counter.complete strong { color:#198754; }
        .game-picker-bar { height:6px; background:#e5e7eb; border-radius:3px; overflow:hidden; margin-bottom:1rem; }
        .game-picker-bar > div { height:100%; background: linear-gradient(90deg, #059669, #10b981); transition: width .25s ease; width:0; }
        .game-list { display:flex; flex-direction:column; border:1px solid var(--light-gray); border-radius:6px; overflow:hidden; background:#fff; }
        .game-row { display:flex; align-items:center; gap:.7rem; padding:.4rem .85rem; border-bottom:1px solid #edf0f3; cursor:pointer; user-select:none; transition: background .12s ease; position:relative; font-size:.88rem; color: var(--text-dark); background:#fff; }
        .game-row:last-child { border-bottom:none; }
        .game-row:hover { background: var(--off-white); }
        .game-row .game-icon { color: var(--medium-gray); font-size:.95rem; flex-shrink:0; width:1.1rem; text-align:center; transition: color .12s ease; }
        .game-row .game-name { font-weight:500; flex:1; }
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
        .game-row .game-check:disabled { opacity:.4; cursor:not-allowed; }
        .game-row.selected { background: rgba(26,54,93,.05); color:#0a1f3d; }
        .game-row.selected::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background: var(--primary-navy); }
        .game-row.selected .game-icon { color: var(--primary-navy); }
        .game-row.disabled { opacity:.55; cursor:not-allowed; background:#fafbfc; }
        .game-row.disabled:hover { background:#fafbfc; }
        .game-row.disabled .game-name { color: var(--medium-gray); }
        .game-picker-bar { height:4px; background:#e5e7eb; border-radius:2px; overflow:hidden; margin-bottom:.85rem; }
        .game-picker-bar > div { height:100%; background: linear-gradient(90deg, #1a365d, #2c5282); transition: width .2s ease; width:0; }
        .game-picker-error { color:#c53030; font-weight:600; font-size:.78rem; margin-top:.5rem; min-height:1.1em; }

        /* Game chips (read-only display in record card) */
        .game-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.2rem .55rem; background: rgba(26,54,93,.08); color:#0a1f3d; border:1px solid rgba(26,54,93,.18); border-radius:3px; font-size:.78rem; font-weight:600; margin:.1rem .2rem .1rem 0; }
        .game-chip i { color: var(--primary-navy); }
        .game-chip.empty { background: transparent; color: var(--medium-gray); border:1px dashed var(--light-gray); font-weight:500; font-style:italic; }

        @media (max-width: 768px) {
            .game-picker-head { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .game-row { padding: 0.5rem; font-size: 0.82rem; }
            .yesno-group { gap: 0.5rem; }
            .yesno-opt { padding: 0.4rem 0.8rem; font-size: 0.85rem; }
        }
        @media (max-width: 576px) {
            .upload-rules-head { font-size: 0.7rem; padding: 0.35rem 0.65rem; }
            .upload-rules-foot { font-size: 0.68rem; padding: 0.35rem 0.65rem; }
            .upload-rule { padding: 0.5rem 0.65rem; }
            .btn-act { width: 100%; justify-content: center; }
            .profile-actions { flex-direction: column; gap: 0.5rem; }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="app-wrapper">

        <aside class="sidebar" id="sidebar">
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
                    <a href="faculty-select.php?change=1"><i class="bi bi-building"></i> <span>Select Faculty</span></a>
                <?php endif; ?>
                <a href="admin/dashboard.php"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a>
                <a href="student-search.php"><i class="bi bi-search"></i> <span>Search Students</span></a>
                <a href="student-profile.php?new=1" class="active"><i class="bi bi-person-plus"></i> <span><?= $is_new ? 'Add Student' : 'Student Profile' ?></span></a>
                <a href="admin/provisional_list.php"><i class="bi bi-clipboard-check"></i> <span>Provisional Players</span></a>
                <?php if ($me['role'] === 'SUPER_ADMIN'): ?>
                    <div class="sidebar-nav-label">Admin</div>
                    <a href="admin/faculty_manage.php"><i class="bi bi-people-fill"></i> <span>Faculty Management</span></a>
                <?php endif; ?>
                <div class="sidebar-nav-label">Site</div>
                <a href="index.php"><i class="bi bi-globe"></i> <span>View Website</span></a>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar"><?= h(initials($me['full_name'])) ?></div>
                    <div class="sidebar-user-info">
                        <h4><?= h($me['full_name']) ?></h4>
                        <span><?= h($me['department_name'] ?? $me['role']) ?></span>
                    </div>
                    <a href="admin/logout.php?_csrf=<?= h(csrf_token()) ?>" class="btn-logout" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <header class="top-bar">
                <div class="top-bar-left">
                    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="bi bi-list"></i></button>
                    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
                        <a href="admin/dashboard.php"><i class="bi bi-house-fill"></i> Dashboard</a><span class="sep">/</span>
                        <a href="student-search.php">Search</a><span class="sep">/</span>
                        <span class="current"><?= $is_new ? 'Add New Student' : 'Student Profile' ?></span>
                    </nav>
                </div>
                <div class="top-bar-right">
                    <a href="student-search.php" class="top-back-btn" title="Back to student search" aria-label="Back to student search">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                </div>
            </header>

            <div class="content-body">
                <?php if ($ok_flash): ?>
                    <div class="alert-banner success" style="padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem;background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)">
                        <i class="bi bi-check-circle"></i> <?= h($ok_flash['msg']) ?>
                    </div>
                <?php endif; ?>
                <?php if ($err_flash): ?>
                    <div class="alert-banner error" style="padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem;background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)">
                        <i class="bi bi-exclamation-circle"></i> <?= h($err_flash['msg']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!$is_new && $student): ?>
                    <!-- ======== VIEW MODE ======== -->
                    <div id="viewMode">
                        <!-- Profile Banner -->
                        <div class="profile-banner">
                            <div class="profile-photo-wrap">
                                <?php if (!empty($student['photo_path']) && is_file(__DIR__ . '/' . $student['photo_path'])): ?>
                                    <img src="<?= h(url($student['photo_path'])) ?>" alt="<?= h($student['full_name']) ?>" class="profile-photo">
                                <?php else: ?>
                                    <div class="profile-photo-placeholder"><?= h(initials($student['full_name'])) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="profile-info">
                                <h1><?= h($student['full_name']) ?></h1>
                                <div class="enrollment"><i class="bi bi-upc-scan"></i> <?= h($student['enrollment_no']) ?></div>
                                <div class="profile-meta-chips">
                                    <span class="meta-chip"><i class="bi bi-building"></i> <?= h($student['dept_name']) ?></span>
                                    <span class="meta-chip"><i class="bi bi-calendar3"></i> <?= h($student['academic_year'] ?: '—') ?></span>
                                    <span class="meta-chip"><i class="bi bi-mortarboard"></i> <?= h($student['study_year'] ?: '—') ?> Year</span>
                                    <?php if (!empty($student['form_submitted_at'])): ?>
                                        <span class="meta-chip" style="background: rgba(25,135,84,.12); color:#0a3622; border-color: rgba(25,135,84,.3)">
                                            <i class="bi bi-check-circle-fill"></i> Student submitted on <?= h(date('d M Y', strtotime((string)$student['form_submitted_at']))) ?>
                                        </span>
                                        <?php if ((int)($student['edit_unlocked'] ?? 0) === 1): ?>
                                            <span class="meta-chip" style="background: rgba(25,135,84,.12); color:#146c43; border-color: rgba(25,135,84,.3)">
                                                <i class="bi bi-unlock-fill"></i> Edit access granted — awaiting re-submission
                                            </span>
                                        <?php else: ?>
                                            <span class="meta-chip" style="background: var(--off-white); color: var(--medium-gray); border-color: var(--light-gray)">
                                                <i class="bi bi-lock-fill"></i> Student form locked
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="meta-chip" style="background: rgba(255,193,7,.12); color:#664d03; border-color: rgba(255,193,7,.3)">
                                            <i class="bi bi-pencil"></i> Profile draft (not submitted)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="profile-actions">
                                <button class="btn-act edit" onclick="document.getElementById('viewMode').style.display='none';document.getElementById('formMode').style.display='block';window.scrollTo({top:0,behavior:'smooth'})">
                                    <i class="bi bi-pencil-square"></i> Edit
                                </button>
                                <a class="btn-act export" href="admin/export_xlsx.php?id=<?= (int)$student['id'] ?>">
                                    <i class="bi bi-download"></i> Export
                                </a>
                                <?php if (!empty($student['form_submitted_at'])): ?>
                                    <form method="post" action="admin/student_toggle_edit.php" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$student['id'] ?>">
                                        <?php if ((int)($student['edit_unlocked'] ?? 0) === 1): ?>
                                            <input type="hidden" name="action" value="lock">
                                            <button type="submit" class="btn-act lock" title="Revoke the student's edit access and re-lock their form">
                                                <i class="bi bi-lock-fill"></i> Revoke Edit Access
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="unlock">
                                            <button type="submit" class="btn-act unlock" title="Let the student edit their submitted profile and re-submit">
                                                <i class="bi bi-unlock-fill"></i> Allow Student to Edit
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Official Student Record Document -->
                        <div class="record-document">
                            <div class="record-doc-header"><i class="bi bi-file-earmark-text"></i> Student Sports Record — Official Copy</div>
                            <table class="record-table">
                                <tr class="record-section-row"><td colspan="4"><i class="bi bi-person-vcard"></i> Personal Information</td></tr>
                                <tr>
                                    <td class="rec-label">Full Name</td>
                                    <td class="rec-value"><?= h($student['full_name']) ?></td>
                                    <?php if (in_array($student['department_code'] ?? '', ['engineering', 'pharmacy'], true)): ?>
                                        <td class="rec-label">Father's First Name</td>
                                        <td class="rec-value"><?= h($student['father_name'] ?: '—') ?></td>
                                    <?php elseif (in_array($student['department_code'] ?? '', ['management', 'architecture'], true)): ?>
                                        <td class="rec-label">Mother Name</td>
                                        <td class="rec-value"><?= h($student['mother_name'] ?: '-') ?></td>
                                    <?php else: ?>
                                        <td class="rec-label">Enrollment No.</td>
                                        <td class="rec-value"><?= h($student['enrollment_no']) ?></td>
                                    <?php endif; ?>
                                </tr>
                                <?php if (in_array($student['department_code'] ?? '', ['engineering', 'pharmacy'], true)): ?>
                                    <tr>
                                        <td class="rec-label">Mother's Name</td>
                                        <td class="rec-value"><?= h($student['mother_name'] ?: '—') ?></td>
                                        <td class="rec-label"></td>
                                        <td class="rec-value"></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (in_array($student['department_code'] ?? '', ['polytechnic', 'dpharm', 'engineering', 'pharmacy', 'ytc_pharmacy', 'management', 'architecture'], true)): ?>
                                    <tr>
                                        <td class="rec-label">Roll No.</td>
                                        <td class="rec-value"><?= h($student['roll_no'] ?: '-') ?></td>
                                        <?php if (in_array($student['department_code'] ?? '', ['engineering', 'pharmacy', 'ytc_pharmacy', 'management', 'architecture'], true)): ?>
                                            <td class="rec-label">Enrollment No.</td>
                                            <td class="rec-value"><?= h($student['enrollment_no']) ?></td>
                                        <?php else: ?>
                                            <td class="rec-label"></td>
                                            <td class="rec-value"></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td class="rec-label">Date of Birth</td>
                                    <td class="rec-value"><?= h(format_date($student['dob'] ?: null) ?: '—') ?></td>
                                    <td class="rec-label">Gender</td>
                                    <td class="rec-value"><?= h($student['gender'] ?: '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="rec-label">Aadhar Number</td>
                                    <td class="rec-value"><?= h($student['aadhar_number'] ?: '—') ?></td>
                                    <td class="rec-label">Blood Group</td>
                                    <td class="rec-value"><?= h($student['blood_group'] ?: '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="rec-label">Status</td>
                                    <td class="rec-value" style="color:#38a169;font-weight:700">● Active</td>
                                    <td class="rec-label"></td>
                                    <td class="rec-value"></td>
                                </tr>

                                <tr class="record-section-row"><td colspan="4"><i class="bi bi-telephone"></i> Contact Details</td></tr>
                                <tr>
                                    <td class="rec-label">Email</td>
                                    <td class="rec-value"><?= h($student['email'] ?: '—') ?></td>
                                    <td class="rec-label">Mobile No.</td>
                                    <td class="rec-value"><?= h($student['mobile'] ?: '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="rec-label">Address</td>
                                    <td class="rec-value" colspan="3"><?= h($student['address'] ?: '—') ?></td>
                                </tr>

                                <?php
                                    $needs_course_duration_view = ($student['department_code'] ?? '') !== 'polytechnic';
                                    $gapYearRaw = $student['has_gap_year'] ?? null;
                                    if ($gapYearRaw === null) {
                                        $gapYearDisplay = '—';
                                    } elseif ((int)$gapYearRaw === 1) {
                                        $gapYearDisplay = 'Yes' . (!empty($student['gap_year_detail']) ? ' (' . $student['gap_year_detail'] . ')' : '');
                                    } else {
                                        $gapYearDisplay = 'No';
                                    }
                                ?>
                                <tr class="record-section-row"><td colspan="4"><i class="bi bi-mortarboard"></i> Academic Details</td></tr>
                                <tr>
                                    <td class="rec-label">Department</td>
                                    <td class="rec-value"><?= h($student['dept_name']) ?></td>
                                    <td class="rec-label">Faculty / Course</td>
                                    <td class="rec-value"><?= h($student['program'] ?: '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="rec-label">Department / Branch</td>
                                    <td class="rec-value"><?= h($student['department_name'] ?: '—') ?></td>
                                    <?php if ($needs_course_duration_view): ?>
                                        <td class="rec-label">Duration of Course</td>
                                        <td class="rec-value"><?= h($student['course_duration_years'] ?: '—') ?></td>
                                    <?php else: ?>
                                        <td class="rec-label"></td>
                                        <td class="rec-value"></td>
                                    <?php endif; ?>
                                </tr>
                                <tr>
                                    <td class="rec-label">Academic Year</td>
                                    <td class="rec-value"><?= h($student['academic_year'] ?: '—') ?></td>
                                    <td class="rec-label">Year of Study</td>
                                    <td class="rec-value"><?= h($student['study_year'] ?: '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="rec-label">SSC Passing Year</td>
                                    <td class="rec-value"><?= h($student['ssc_passing_year'] ?: '—') ?></td>
                                    <td class="rec-label">HSC Passing Year</td>
                                    <td class="rec-value"><?= h($student['hsc_passing_year'] ?: '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="rec-label">Diploma Passing Year</td>
                                    <td class="rec-value"><?= h($student['diploma_passing_year'] ?: '—') ?></td>
                                    <td class="rec-label">Gap / Year Drop</td>
                                    <td class="rec-value"><?= h($gapYearDisplay) ?></td>
                                </tr>
                                <tr>
                                    <td class="rec-label">First Admission — University/College</td>
                                    <td class="rec-value"><?= h($student['first_admission_university_year'] ?: '—') ?></td>
                                    <td class="rec-label">First Admission — Present Course</td>
                                    <td class="rec-value"><?= h($student['first_admission_course_year'] ?: '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="rec-label">First Admission — Present Class</td>
                                    <td class="rec-value"><?= h($student['first_admission_class_year'] ?: '—') ?></td>
                                    <td class="rec-label"></td>
                                    <td class="rec-value"></td>
                                </tr>

                                <tr class="record-section-row"><td colspan="4"><i class="bi bi-trophy"></i> Sports Information</td></tr>
                                <?php if ($uses_game_picker): ?>
                                    <?php
                                        $game_name_by_code = [];
                                        foreach ($game_catalog as $g) {
                                            $game_name_by_code[(string)$g['game_code']] = (string)$g['display_name'];
                                        }
                                    ?>
                                    <tr>
                                        <td class="rec-label">Enrolled Games</td>
                                        <td class="rec-value" colspan="3">
                                            <?php if (!empty($selected_games)): ?>
                                                <?php foreach ($selected_games as $code): ?>
                                                    <span class="game-chip">
                                                        <i class="<?= h(game_icon_for((string)$code)) ?>"></i>
                                                        <?= h($game_name_by_code[(string)$code] ?? $code) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="game-chip empty"><i class="bi bi-dash-circle"></i> No games selected yet</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td class="rec-label">Primary Sport</td>
                                        <td class="rec-value"><?= h($student['sport_1'] ?: '—') ?></td>
                                        <td class="rec-label">Secondary Sport</td>
                                        <td class="rec-value"><?= h($student['sport_2'] ?: '—') ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php
                                    $hpic_prev = $student['has_played_in_college'] ?? null;
                                    if ($hpic_prev === null) {
                                        $hpic_prev = !empty($student['sports_history']) ? 1 : 0;
                                    }
                                    $playedItems = [[
                                        'label' => 'Played in this college?',
                                        'html'  => (int)$hpic_prev === 1
                                            ? '<span class="badge-ok"><i class="bi bi-check-circle"></i> Yes</span>'
                                            : '<em class="empty">No</em>',
                                    ]];
                                    if ((int)$hpic_prev === 1) {
                                        foreach (participation_levels() as $lvl) {
                                            $lvlVal  = $student[$lvl['played_col']] ?? null;
                                            $yearVal = $student[$lvl['year_col']] ?? null;
                                            $playedItems[] = [
                                                'label' => $lvl['label'],
                                                'html'  => (int)$lvlVal === 1
                                                    ? '<span class="badge-ok"><i class="bi bi-check-circle"></i> Yes' . (!empty($yearVal) ? ' — ' . h((string)$yearVal) : '') . '</span>'
                                                    : '<em class="empty">No</em>',
                                            ];
                                        }
                                    }
                                ?>
                                <?php foreach (array_chunk($playedItems, 2) as $pair): ?>
                                <tr>
                                    <td class="rec-label"><?= h($pair[0]['label']) ?></td>
                                    <td class="rec-value"><?= $pair[0]['html'] ?></td>
                                    <td class="rec-label"><?= isset($pair[1]) ? h($pair[1]['label']) : '' ?></td>
                                    <td class="rec-value"><?= isset($pair[1]) ? $pair[1]['html'] : '' ?></td>
                                </tr>
                                <?php endforeach; ?>

                                <tr class="record-section-row"><td colspan="4"><i class="bi bi-file-earmark-pdf"></i> Uploaded Documents</td></tr>
                                <?php if (!empty($documents)): ?>
                                    <?php foreach ($documents as $doc): ?>
                                        <tr style="border-bottom: 1px solid var(--light-gray)">
                                            <td class="rec-label"><?= h($doc['document_name']) ?></td>
                                            <td class="rec-value" colspan="3">
                                                <?php if ($doc['file_path']): ?>
                                                    <a href="<?= h(url($doc['file_path'])) ?>" target="_blank" class="text-primary" style="text-decoration:none;font-weight:600">
                                                        <i class="bi bi-download"></i> View Document
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color:var(--medium-gray);font-style:italic;font-size:.85rem">No document uploaded yet</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td class="rec-label" colspan="4" style="text-align:center;color:var(--medium-gray);font-style:italic">No document requirements for this faculty.</td>
                                    </tr>
                                <?php endif; ?>
                            </table>
                        </div>

                        <!-- Achievements Table -->
                        <div class="achievements-section">
                            <table class="ach-table">
                                <thead>
                                    <tr>
                                        <th style="width:40px">#</th>
                                        <th>Achievement / Event</th>
                                        <th>Level</th>
                                        <th>Medal / Position</th>
                                        <th>Year</th>
                                        <th>Remarks</th>
                                        <th style="width:50px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($achievements): ?>
                                        <?php foreach ($achievements as $i => $ach):
                                            $mc = medal_class($ach['position'] ?: '');
                                            $mi = medal_icon($mc);
                                        ?>
                                            <tr>
                                                <td><?= $i + 1 ?></td>
                                                <td><?= h($ach['title'] ?: $ach['event_name'] ?: '—') ?></td>
                                                <td><?= h($ach['level'] ?: '—') ?></td>
                                                <td><span class="ach-medal <?= h($mc) ?>"><i class="bi <?= $mi ?>"></i> <?= h($ach['position'] ?: '—') ?></span></td>
                                                <td><?= h($ach['event_date'] ? substr($ach['event_date'], 0, 4) : '—') ?></td>
                                                <td><?= h($ach['description'] ?: '—') ?></td>
                                                <td>
                                                    <form method="post" action="admin/achievement_delete.php" style="display:inline" onsubmit="return confirm('Delete this achievement?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="ach_id" value="<?= (int)$ach['id'] ?>">
                                                        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                                        <button type="submit" style="background:none;border:none;color:#c53030;cursor:pointer;font-size:.85rem" title="Delete"><i class="bi bi-trash3"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" style="text-align:center;color:var(--medium-gray);padding:1.5rem">No achievements recorded yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ======== ADD / EDIT MODE ======== -->
                <div id="formMode" style="<?= $is_new ? '' : 'display:none' ?>">
                    <div class="form-section">
                        <h2 id="formTitle">
                            <i class="bi bi-<?= $is_new ? 'person-plus-fill' : 'pencil-square' ?>"></i>
                            <?= $is_new ? 'Add New Student' : 'Edit Student Profile' ?>
                        </h2>
                        <p id="formSubtitle"><?= $is_new ? 'Fill in student details to add them to the sports database.' : 'Update the student details below.' ?></p>

                        <form method="post" action="admin/student_save.php" enctype="multipart/form-data" id="studentForm">
                            <?= csrf_field() ?>
                            <?php if (!$is_new && $student): ?>
                                <input type="hidden" name="id" value="<?= (int)$student['id'] ?>">
                            <?php endif; ?>

                            <!-- Photo Upload -->
                            <div style="margin-bottom:1.5rem">
                                <label style="font-size:.78rem;font-weight:600;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.4px;margin-bottom:.5rem;display:block">Passport Photo</label>
                                <div class="photo-upload">
                                    <div class="photo-preview" id="photoPreview">
                                        <?php if (!$is_new && $student && !empty($student['photo_path']) && is_file(__DIR__ . '/' . $student['photo_path'])): ?>
                                            <img src="<?= h(url($student['photo_path'])) ?>" alt="">
                                        <?php else: ?>
                                            <i class="bi bi-camera"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <label class="photo-upload-btn"><i class="bi bi-upload"></i> Upload Photo <input type="file" id="photo" name="photo" accept="image/*" style="display:none"></label>
                                        <p style="font-size:.72rem;color:var(--medium-gray);margin-top:.35rem">JPG/PNG, max 2MB, passport size</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Personal Info -->
                            <h3 style="font-size:.88rem;font-weight:700;color:var(--primary-navy);margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--light-gray)">
                                <i class="bi bi-person-vcard" style="color:var(--accent-gold);margin-right:.4rem"></i> Personal Information
                            </h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="full_name">Full Name *</label>
                                    <input type="text" id="full_name" name="full_name" placeholder="Enter full name" required value="<?= h($student['full_name'] ?? '') ?>">
                                </div>
                                <div class="form-group" id="fatherNameField" style="display:none">
                                    <label for="father_name">Father's First Name *</label>
                                    <input type="text" id="father_name" name="father_name" placeholder="Enter father's first name" value="<?= h($student['father_name'] ?? '') ?>">
                                </div>
                                <div class="form-group" id="motherNameField" style="display:none">
                                    <label for="mother_name">Mother's Name</label>
                                    <input type="text" id="mother_name" name="mother_name" placeholder="Enter mother's name" value="<?= h($student['mother_name'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="enrollment_no">Enrollment No. *</label>
                                    <input type="text" id="enrollment_no" name="enrollment_no" placeholder="e.g. EN2025001" required value="<?= h($student['enrollment_no'] ?? '') ?>">
                                </div>
                                <div class="form-group" id="rollNumberField" style="display:none">
                                    <label for="roll_no">Roll No.</label>
                                    <input type="text" id="roll_no" name="roll_no" placeholder="Enter roll number" value="<?= h($student['roll_no'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="dob">Date of Birth *</label>
                                    <input type="text" id="dob" name="dob" required autocomplete="off"
                                           placeholder="dd-mm-yyyy"
                                           value="<?= h($student['dob'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="aadhar_number">Aadhar Number *</label>
                                    <input type="text" id="aadhar_number" name="aadhar_number"
                                           pattern="[0-9]{12}" maxlength="12" inputmode="numeric" autocomplete="off"
                                           placeholder="12-digit Aadhar number" value="<?= h($student['aadhar_number'] ?? '') ?>">
                                    <small style="color:var(--medium-gray)">12 digits. Required for all faculties except Polytechnic (Shivaji eligibility proforma).</small>
                                </div>
                                <div class="form-group">
                                    <label for="gender">Gender</label>
                                    <select id="gender" name="gender">
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

                            <!-- Contact -->
                            <h3 style="font-size:.88rem;font-weight:700;color:var(--primary-navy);margin:1.5rem 0 1rem;padding-bottom:.5rem;border-bottom:1px solid var(--light-gray)">
                                <i class="bi bi-telephone" style="color:var(--accent-gold);margin-right:.4rem"></i> Contact Details
                            </h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="email">Email *</label>
                                    <input type="email" id="email" name="email" placeholder="student@email.com" required value="<?= h($student['email'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="mobile">Mobile No. *</label>
                                    <input type="tel" id="mobile" name="mobile" placeholder="10-digit mobile number" required pattern="[0-9]{10}" maxlength="10" inputmode="numeric" value="<?= h($student['mobile'] ?? '') ?>">
                                </div>
                                <div class="form-group" style="grid-column:1/-1">
                                    <label for="address">Address *</label>
                                    <input type="text" id="address" name="address" required maxlength="500" placeholder="House no / street, area, city, state, pincode" value="<?= h($student['address'] ?? '') ?>">
                                </div>
                            </div>

                            <!-- Academic -->
                            <h3 style="font-size:.88rem;font-weight:700;color:var(--primary-navy);margin:1.5rem 0 1rem;padding-bottom:.5rem;border-bottom:1px solid var(--light-gray)">
                                <i class="bi bi-mortarboard" style="color:var(--accent-gold);margin-right:.4rem"></i> Academic Details
                            </h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="department_id">Department *</label>
                                    <select id="department_id" name="department_id" required>
                                        <?php foreach ($departments as $d): ?>
                                            <?php if (!in_array((int)$d['id'], $allowed_dept_ids, true) && (int)$d['id'] !== (int)($student['department_id'] ?? 0)) continue; ?>
                                            <option value="<?= (int)$d['id'] ?>" data-code="<?= h($d['code']) ?>" <?= is_selected($d['id'], $student['department_id'] ?? $me['department_id'] ?? '') ?>>
                                                <?= h($d['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($me['role'] === 'FACULTY'): ?>
                                        <small style="color:var(--medium-gray);font-size:.78rem">You can only save students to your assigned department.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="form-group">
                                    <label for="program">Program / Branch</label>
                                    <input type="text" id="program" name="program" placeholder="e.g. B.E. Computer Engg." value="<?= h($student['program'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="academic_year">Academic Year *</label>
                                    <select id="academic_year" name="academic_year" required>
                                        <option value="">—</option>
                                        <?php $selectedAcademicYear = $student['academic_year'] ?? current_academic_year(); ?>
                                        <?php foreach (academic_year_options() as $y): ?>
                                            <option value="<?= h($y) ?>" <?= is_selected($y, $selectedAcademicYear) ?>><?= h($y) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="study_year">Year of Study *</label>
                                    <select id="study_year" name="study_year" required>
                                        <option value="" disabled hidden>Select</option>
                                        <?php foreach (year_options() as $y): ?>
                                            <option value="<?= h($y) ?>" data-study-year="<?= h(strtolower($y)) ?>" <?= is_selected($y, $student['study_year'] ?? '') ?>><?= h($y) ?> Year</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Documents -->
                            <div id="dynamic-docs-container" style="margin-bottom:1.5rem">
                                <h3 style="font-size:.88rem;font-weight:700;color:var(--primary-navy);margin-bottom:.5rem;padding-bottom:.5rem;border-bottom:1px solid var(--light-gray)">
                                    <i class="bi bi-file-earmark-arrow-up" style="color:var(--accent-gold);margin-right:.4rem"></i> Required Documents
                                </h3>
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
                                <div id="docs-fields-grid" class="form-grid">
                                    <p style="color:var(--medium-gray);font-size:.8rem;grid-column:1/-1">Please select a department to see required documents.</p>
                                </div>
                            </div>

                            <!-- Sports -->
                            <h3 style="font-size:.88rem;font-weight:700;color:var(--primary-navy);margin:1.5rem 0 1rem;padding-bottom:.5rem;border-bottom:1px solid var(--light-gray)">
                                <i class="bi bi-trophy" style="color:var(--accent-gold);margin-right:.4rem"></i> Sports Information
                            </h3>
                            <?php if ($is_new): ?>
                                <p style="color:var(--medium-gray);font-size:.85rem">Game selection becomes available after the student is created.</p>
                            <?php elseif ($uses_game_picker): ?>
                                <div class="game-picker-head">
                                    <div class="game-picker-counter" id="gameCounter">
                                        <strong id="gameCountNum"><?= count($selected_games) ?></strong>
                                        /
                                        <strong id="gameTotalAvailable"><?= (int)$game_total_available ?></strong>
                                        selected
                                    </div>
                                    <div class="hint" style="margin:0">Pick the games this student wants to enroll in (any number).</div>
                                </div>
                                <div class="game-picker-bar"><div id="gameBarFill" style="width: <?= (int)round(count($selected_games) / max(1, $game_total_available) * 100) ?>%"></div></div>
                                <div class="game-list" id="gameGrid" role="list">
                                    <?php foreach ($game_catalog as $g):
                                        $code = (string)$g['game_code'];
                                        $name = (string)$g['display_name'];
                                        $icon = game_icon_for($code);
                                        $picked = in_array($code, $selected_games, true);
                                    ?>
                                        <label class="game-row <?= $picked ? 'selected' : '' ?>" data-code="<?= h($code) ?>" role="listitem">
                                            <input type="checkbox" name="games[]" value="<?= h($code) ?>" <?= $picked ? 'checked' : '' ?> class="game-check">
                                            <i class="<?= h($icon) ?> game-icon"></i>
                                            <span class="game-name"><?= h($name) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="game-picker-error" id="gamePickerError" role="alert" aria-live="polite"></div>
                            <?php else: ?>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="sport_1">Primary Sport *</label>
                                        <input type="text" id="sport_1" name="sport_1" placeholder="e.g. Cricket" required value="<?= h($student['sport_1'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="sport_2">Secondary Sport</label>
                                        <input type="text" id="sport_2" name="sport_2" placeholder="e.g. Athletics" value="<?= h($student['sport_2'] ?? '') ?>">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="form-grid">
                                <div class="form-group" style="grid-column:1/-1">
                                    <label for="achievements">Achievements / Notes</label>
                                    <textarea id="achievements" name="achievements" placeholder="One per line. E.g. 'Gold - Inter-College Cricket 2025'"><?= h($student['achievements'] ?? '') ?></textarea>
                                </div>
                                <div class="form-group" style="grid-column:1/-1">
                                    <label>Played any sports representing this college?</label>
                                    <?php
                                        $hpic_fac = $student['has_played_in_college'] ?? null;
                                        if ($hpic_fac === null) {
                                            $hpic_fac = !empty($student['sports_history']) ? 1 : 0;
                                        }
                                    ?>
                                    <div class="yesno-group" role="radiogroup" aria-label="Played any sports representing this college?">
                                        <label class="yesno-opt <?= (int)$hpic_fac === 1 ? 'selected' : '' ?>" data-val="1">
                                            <input type="radio" name="has_played_in_college" value="1" <?= (int)$hpic_fac === 1 ? 'checked' : '' ?>>
                                            <span class="yesno-circle"></span>
                                            <span class="yesno-text">Yes</span>
                                        </label>
                                        <label class="yesno-opt <?= (int)$hpic_fac === 0 ? 'selected' : '' ?>" data-val="0">
                                            <input type="radio" name="has_played_in_college" value="0" <?= (int)$hpic_fac === 0 ? 'checked' : '' ?>>
                                            <span class="yesno-circle"></span>
                                            <span class="yesno-text">No</span>
                                        </label>
                                    </div>
                                    <div class="hint">If yes, fill in the levels of participation below. If no, leave them blank.</div>
                                </div>
                                <div class="form-group" style="grid-column:1/-1">
                                    <label>Levels of Participation</label>
                                    <?php foreach (participation_levels() as $lvl):
                                        $lvlValFac = $student[$lvl['played_col']] ?? null;
                                    ?>
                                    <div class="participation-level">
                                        <span class="participation-level-label"><?= h($lvl['label']) ?></span>
                                        <div class="yesno-group yesno-group--sm" role="radiogroup" aria-label="<?= h($lvl['label']) ?> participation">
                                            <label class="yesno-opt <?= (int)$lvlValFac === 1 ? 'selected' : '' ?>" data-val="1">
                                                <input type="radio" name="<?= h($lvl['slug']) ?>_played" value="1" <?= (int)$lvlValFac === 1 ? 'checked' : '' ?>>
                                                <span class="yesno-circle"></span>
                                                <span class="yesno-text">Yes</span>
                                            </label>
                                            <label class="yesno-opt <?= (int)$lvlValFac === 0 ? 'selected' : '' ?>" data-val="0">
                                                <input type="radio" name="<?= h($lvl['slug']) ?>_played" value="0" <?= (int)$lvlValFac === 0 ? 'checked' : '' ?>>
                                                <span class="yesno-circle"></span>
                                                <span class="yesno-text">No</span>
                                            </label>
                                        </div>
                                        <div class="form-group" style="margin:.6rem 0 0">
                                            <label for="<?= h($lvl['slug']) ?>_year">Year of participation</label>
                                            <input type="text" id="<?= h($lvl['slug']) ?>_year" name="<?= h($lvl['slug']) ?>_year" maxlength="100"
                                                   placeholder="e.g. 2024"
                                                   value="<?= h((string)($student[$lvl['year_col']] ?? '')) ?>">
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn-save">
                                    <i class="bi bi-check-circle"></i> <?= $is_new ? 'Save Student' : 'Update Profile' ?>
                                </button>
                                <a href="<?= $is_new ? 'student-search.php' : 'student-profile.php?id=' . (int)$student['id'] ?>" class="btn-cancel">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </a>
                            </div>
                        </form>
                        <?php if (!$is_new && $student): ?>
                            <!--
                                Delete form must live OUTSIDE the main <form>:
                                HTML forbids nested forms, and browsers silently
                                drop the inner form's submit, which made the
                                Delete button appear to do nothing. Rendered as a
                                small right-aligned row directly below the action
                                bar; see .form-actions-delete in css/admin.css.
                            -->
                            <div class="form-actions-delete">
                                <form method="post" action="admin/student_delete.php" onsubmit="return confirm('Are you sure you want to permanently delete this student record? This action cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$student['id'] ?>">
                                    <button type="submit" class="btn-cancel" style="background:#fff5f5;color:#c53030;border-color:#fed7d7">
                                        <i class="bi bi-trash3"></i> Delete Student
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
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
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebarOverlay');
            var toggle  = document.getElementById('sidebarToggle');
            if (toggle) {
                toggle.addEventListener('click', function () {
                    sidebar.classList.toggle('open');
                    overlay.classList.toggle('show');
                });
                overlay.addEventListener('click', function () {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('show');
                });
            }

            // Dynamic Document Requirements
            var deptSelect = document.getElementById('department_id');
            var docsGrid = document.getElementById('docs-fields-grid');
            var fatherNameField = document.getElementById('fatherNameField');
            var fatherNameInput = document.getElementById('father_name');
            var motherNameField = document.getElementById('motherNameField');
            var motherNameInput = document.getElementById('mother_name');
            var rollField = document.getElementById('rollNumberField');
            var rollInput = document.getElementById('roll_no');
            var studyYearSelect = document.getElementById('study_year');

            function updateDepartmentFields() {
                if (!deptSelect) return;
                var option = deptSelect.options[deptSelect.selectedIndex];
                var usesFatherFirstName = option && ['engineering', 'pharmacy'].includes(option.dataset.code);
                var isPolytechnic = option && (option.dataset.code === 'polytechnic' || option.dataset.code === 'dpharm');
                var isPharmFacultyDepartment = option && ['pharmacy', 'ytc_pharmacy', 'management', 'architecture'].includes(option.dataset.code);
                var isEngineering = option && option.dataset.code === 'engineering';
                var isDiploma = option && option.dataset.code === 'polytechnic';
                if (fatherNameField && fatherNameInput) {
                    fatherNameField.style.display = usesFatherFirstName ? '' : 'none';
                    fatherNameInput.required = usesFatherFirstName;
                    if (!usesFatherFirstName) fatherNameInput.value = '';
                }
                if (motherNameField && motherNameInput) {
                    var showMotherName = usesFatherFirstName || isPharmFacultyDepartment;
                    motherNameField.style.display = showMotherName ? '' : 'none';
                    if (!showMotherName) motherNameInput.value = '';
                }
                if (rollField && rollInput) {
                    var showRollNumber = isPolytechnic || isPharmFacultyDepartment || isEngineering;
                    rollField.style.display = showRollNumber ? '' : 'none';
                    rollInput.required = false;
                    if (!showRollNumber) rollInput.value = '';
                }
                if (studyYearSelect) {
                    Array.from(studyYearSelect.options).forEach(function(yearOption) {
                        var hideFinal = isDiploma && yearOption.dataset.studyYear === 'final';
                        yearOption.hidden = hideFinal;
                        yearOption.disabled = hideFinal;
                    });
                    if (isDiploma && studyYearSelect.value === 'Final') {
                        studyYearSelect.value = '';
                    }
                }
            }
            if (deptSelect) {
                deptSelect.addEventListener('change', updateDepartmentFields);
                updateDepartmentFields();
            }
            if (deptSelect && docsGrid) {
                async function updateRequirements() {
                    var deptId = deptSelect.value;
                    if (!deptId) {
                        docsGrid.innerHTML = '<p style=\"color:var(--medium-gray);font-size:.8rem;grid-column:1/-1\">Please select a department to see required documents.</p>';
                        return;
                    }
                    try {
                        var response = await fetch('api/get_dept_requirements.php?dept_id=' + deptId);
                        var reqs = await response.json();
                        if (!Array.isArray(reqs) || reqs.length === 0) {
                            docsGrid.innerHTML = '<p style=\"color:var(--medium-gray);font-size:.8rem;grid-column:1/-1\">No specific documents required for this faculty.</p>';
                            return;
                        }
                        var html = '';
                        reqs.forEach(function(req) {
                            var requiredAttr = ''; // Removed mandatory requirement
                            // Per-row MIME: photo rows accept JPEG, others accept PDF.
                            var mimes = String(req.allowed_mime_types || 'application/pdf')
                                .split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
                            var isPhoto = mimes.indexOf('image/jpeg') !== -1 && mimes.indexOf('application/pdf') === -1;
                            var accept = isPhoto ? 'image/jpeg,.jpg,.jpeg' : 'application/pdf,.pdf';
                            html += '<div class=\"form-group\">' +
                                    '<label for=\"doc_' + req.id + '\">' + req.document_name + '</label>' +
                                    '<input type=\"file\" id=\"doc_' + req.id + '\" name=\"doc_' + req.id + '\" ' + requiredAttr + ' accept=\"' + accept + '\">' +
                                    '</div>';
                        });
                        docsGrid.innerHTML = html;
                    } catch (e) {
                        docsGrid.innerHTML = '<p style=\"color:red;font-size:.8rem;grid-column:1/-1\">Error loading requirements.</p>';
                    }
                }
                deptSelect.addEventListener('change', updateRequirements);
                updateRequirements(); // Initial load
            }

            // Document size + type guard: enforce 1 MB cap; JPEG-only for photo rows, PDF-only otherwise.
            // Server-side enforces the same rules; this is a friendlier early warning.
            (function () {
                var MAX_BYTES = 1024 * 1024; // 1 MB
                function reject(input, msg) {
                    alert(msg);
                    input.value = '';
                }
                function attach(input) {
                    input.addEventListener('change', function (e) {
                        var f = e.target.files && e.target.files[0];
                        if (!f) return;
                        var accept = (input.getAttribute('accept') || '').toLowerCase();
                        var isPhoto = accept.indexOf('image/jpeg') !== -1;
                        if (isPhoto) {
                            var isJpg = /\.jpe?g$/i.test(f.name || '') && (f.type === 'image/jpeg' || f.type === '');
                            if (!isJpg) {
                                reject(input, 'Only JPEG/JPG files are accepted for the photo. "' + (f.name || '') +
                                    '" is not a JPEG. Please convert and try again.');
                                return;
                            }
                        } else {
                            var isPdf = /\.pdf$/i.test(f.name || '') && (f.type === 'application/pdf' || f.type === '');
                            if (!isPdf) {
                                reject(input, 'Only PDF files are accepted for this document. "' + (f.name || '') +
                                    '" is not a PDF. Please convert and try again.');
                                return;
                            }
                        }
                        if (f.size > MAX_BYTES) {
                            reject(input, 'File is too large: "' + f.name + '" is ' +
                                (f.size / 1024 / 1024).toFixed(2) + ' MB. Maximum allowed size is 1 MB. Please compress and try again.');
                        }
                    });
                }
                document.querySelectorAll('#docs-fields-grid input[type="file"][name^="doc_"]').forEach(attach);
                var grid = document.getElementById('docs-fields-grid');
                if (grid && window.MutationObserver) {
                    new MutationObserver(function (mutations) {
                        mutations.forEach(function (m) {
                            m.addedNodes.forEach(function (n) {
                                if (!(n instanceof HTMLElement)) return;
                                if (n.matches && n.matches('input[type="file"][name^="doc_"]')) attach(n);
                                var nested = n.querySelectorAll && n.querySelectorAll('input[type="file"][name^="doc_"]');
                                if (nested) nested.forEach(attach);
                            });
                        });
                    }).observe(grid, { childList: true, subtree: true });
                }
            })();
        });

        // Photo preview
        (function () {
            var input = document.getElementById('photo');
            var preview = document.getElementById('photoPreview');
            if (!input || !preview) return;
            input.addEventListener('change', function (e) {
                var f = e.target.files[0];
                if (!f) return;
                var reader = new FileReader();
                reader.onload = function (ev) {
                    preview.innerHTML = '<img src="' + ev.target.result + '" alt="">';
                };
                reader.readAsDataURL(f);
            });
        })();

        // Yes/No pill radios — sync the .selected class on click.
        document.querySelectorAll('.yesno-group').forEach(function (group) {
            group.querySelectorAll('.yesno-opt').forEach(function (opt) {
                opt.addEventListener('click', function (e) {
                    var r = opt.querySelector('input[type=radio]');
                    r.checked = true;
                    group.querySelectorAll('.yesno-opt').forEach(function (o) {
                        o.classList.toggle('selected', o === opt);
                    });
                });
            });
        });

        // Game-picker (faculty edit) — no cap, at least 1 required.
        (function () {
            var grid = document.getElementById('gameGrid');
            if (!grid) return;
            var cards = grid.querySelectorAll('.game-row');
            var totalGames = parseInt(
                ((document.getElementById('gameTotalAvailable') || {}).textContent || '0'), 10
            ) || 0;
            var counterNum = document.getElementById('gameCountNum');
            var counter    = document.getElementById('gameCounter');
            var bar        = document.getElementById('gameBarFill');
            var err        = document.getElementById('gamePickerError');
            var form       = grid.closest('form');

            function refresh() {
                var checked = grid.querySelectorAll('.game-row.selected').length;
                if (counterNum) counterNum.textContent = String(checked);
                if (counter) counter.classList.toggle('complete', checked === totalGames && totalGames > 0);
                if (bar) bar.style.width = Math.min(100, Math.round(checked / Math.max(1, totalGames) * 100)) + '%';
            }
            cards.forEach(function (card) {
                var cb = card.querySelector('input[type=checkbox]');
                if (!cb) return;
                cb.addEventListener('change', function () {
                    card.classList.toggle('selected', cb.checked);
                    refresh();
                });
            });
            if (form) {
                form.addEventListener('submit', function (e) {
                    var checked = grid.querySelectorAll('.game-row.selected').length;
                    if (checked < 1) {
                        e.preventDefault();
                        if (err) err.textContent = 'Please select at least 1 game.';
                        grid.style.boxShadow = '0 0 0 3px rgba(185,28,28,.3)';
                        setTimeout(function () { grid.style.boxShadow = ''; }, 1500);
                    }
                });
            }
            refresh();
        })();
    </script>
</body>
</html>
