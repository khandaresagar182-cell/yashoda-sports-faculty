<?php
/**
 * Student search: filter the students table and present results.
 * Admin-internal page, mirrors student-search.html.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_department();

$me = current_faculty();
if ($me === null) {
    $prefix = is_file('faculty-login.php') ? '' : '../';
    redirect($prefix . 'faculty-login.php');
    exit;
}

// Whitelist filter values.
$q_raw        = trim((string)($_GET['q'] ?? ''));
$department   = (int)($_GET['department'] ?? 0);
$sport_filter = (string)($_GET['sport'] ?? '');
$year_filter  = (string)($_GET['year'] ?? '');
$view         = (string)($_GET['view'] ?? 'table');
if (!in_array($view, ['table', 'card'], true)) $view = 'table';

[$scope, $p, $t] = scope_sql_department('s');
// Hide wizard drafts (students who registered but haven't hit Final Submit).
$where = ['1=1', 's.form_submitted_at IS NOT NULL']; $params = []; $types = '';
if ($q_raw !== '') {
    $where[] = '(s.full_name LIKE ? OR s.enrollment_no LIKE ? OR s.mobile LIKE ?)';
    $like = '%' . $q_raw . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($department > 0) {
    $where[] = 's.department_id = ?';
    $params[] = $department; $types .= 'i';
}
if ($sport_filter !== '') {
    $where[] = '(s.sport_1 = ? OR s.sport_2 = ?)';
    $params[] = $sport_filter; $params[] = $sport_filter; $types .= 'ss';
}
if ($year_filter !== '') {
    $where[] = 's.study_year = ?';
    $params[] = $year_filter; $types .= 's';
}
$params  = array_merge($params, $p);
$types  .= $t;

$sql = "SELECT s.*, d.name AS dept_name, d.code AS department_code
          FROM students s JOIN departments d ON d.id = s.department_id
         WHERE " . implode(' AND ', $where) . " $scope
         ORDER BY s.full_name
         LIMIT 200";
$rows = db_select($sql, $params, $types !== '' ? $types : null);

/* Bulk-load selected games for any student whose dept is a picker dept.
   Non-picker depts stay on sport_1/sport_2 chip rendering. */
$picker_dept_ids = load_picker_dept_ids();
$games_by_student = [];
if (!empty($rows)) {
    $games_by_student = load_games_by_student(
        array_map(static fn($r) => (int)$r['id'], $rows)
    );
}

function is_selected($value, $current): string {
    return (string)$value === (string)$current ? 'selected' : '';
}

/* render_sports_cell() is now in includes/student_rendering.php and is
   auto-loaded by bootstrap.php — used by both this page and the faculty
   dashboard's "Players by Game" card. */

$departments = db_select('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY display_order, name');
$total       = count($rows);

/**
 * Inner HTML of the results panel (header + table/cards + footer).
 * Shared by the full-page render and the ?ajax=1 live-search endpoint so the
 * markup can never drift between the two.
 */
function render_search_results_inner(array $c): void
{
    $total           = (int)$c['total'];
    $view            = (string)$c['view'];
    $rows            = (array)$c['rows'];
    $picker_dept_ids = $c['picker_dept_ids'];
    $games_by_student = $c['games_by_student'];
    $app_root        = (string)$c['app_root'];
    ?>
    <span id="resultsTotal" data-total="<?= $total ?>" hidden></span>
    <div class="results-header">
        <h2>
            <i class="bi bi-people-fill"></i>
            <?= $total > 0 ? 'Students' : 'No matches' ?>
            <?php if ($total > 0): ?><span class="results-count"><?= number_format($total) ?></span><?php endif; ?>
        </h2>
        <div class="view-toggles" role="group" aria-label="Result view">
            <button type="button" class="view-toggle<?= $view === 'table' ? ' active' : '' ?>" data-view="table" title="Table view" aria-label="Table view">
                <i class="bi bi-list-ul"></i>
            </button>
            <button type="button" class="view-toggle<?= $view === 'card' ? ' active' : '' ?>" data-view="card" title="Card view" aria-label="Card view">
                <i class="bi bi-grid-3x3-gap-fill"></i>
            </button>
        </div>
    </div>

    <?php if ($total === 0): ?>
        <div class="empty-state">
            <i class="bi bi-search"></i>
            <h3>No students found</h3>
            <p>Try a different name, enrollment number or phone &mdash; or adjust the filters.</p>
        </div>
    <?php else: ?>
        <div class="<?= $view === 'table' ? 'data-table-wrap' : 'card-grid' ?>" id="resultsContainer">
            <?php if ($view === 'table'): ?>
            <table class="data-table" id="studentsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Enrollment</th>
                        <th>Department</th>
                        <th>Sport(s)</th>
                        <th>Year</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div class="student-cell">
                                    <?php if (!empty($r['photo_path']) && is_file($app_root . '/' . $r['photo_path'])): ?>
                                        <img src="<?= h(url($r['photo_path'])) ?>" alt="" class="student-avatar">
                                    <?php else: ?>
                                        <div class="student-avatar-placeholder"><?= h(initials($r['full_name'])) ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight:600"><?= h($r['full_name']) ?></div>
                                        <div style="font-size:.78rem;color:var(--medium-gray)"><?= h($r['mobile']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= h($r['enrollment_no']) ?></td>
                            <td><?= h($r['dept_name']) ?></td>
                            <td><?= render_sports_cell($r, $picker_dept_ids, $games_by_student) ?></td>
                            <td><?= h($r['study_year'] ?: '—') ?></td>
                            <td><span class="status-dot active"></span> Active</td>
                            <td>
                                <?php if (!empty($r['form_submitted_at'])): ?>
                                    <span class="chip chip-ok"><i class="bi bi-check-circle-fill"></i> <?= h(date('d M Y', strtotime((string)$r['form_submitted_at']))) ?></span>
                                <?php else: ?>
                                    <span class="chip chip-draft"><i class="bi bi-pencil"></i> Draft</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-action-btns">
                                    <a class="row-action-btn" title="View profile" href="student-profile.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-eye"></i></a>
                                    <a class="row-action-btn edit" title="Edit" href="student-profile.php?id=<?= (int)$r['id'] ?>#formMode"><i class="bi bi-pencil"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="student-cards">
                <?php foreach ($rows as $r): ?>
                    <a class="student-card" href="student-profile.php?id=<?= (int)$r['id'] ?>">
                        <div class="student-card-photo">
                            <?php if (!empty($r['photo_path']) && is_file($app_root . '/' . $r['photo_path'])): ?>
                                <img src="<?= h(url($r['photo_path'])) ?>" alt="">
                            <?php else: ?>
                                <div class="student-avatar-placeholder"><?= h(initials($r['full_name'])) ?></div>
                            <?php endif; ?>
                            <span class="status-dot active"></span>
                        </div>
                        <div class="student-card-body">
                            <h3><?= h($r['full_name']) ?></h3>
                            <div class="enroll"><?= h($r['enrollment_no']) ?></div>
                            <div class="meta"><?= h($r['dept_name']) ?> · <?= h($r['study_year'] ?: '—') ?> Year</div>
                            <div class="sports"><?= render_sports_cell($r, $picker_dept_ids, $games_by_student) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="results-footer">
            <div class="results-info">
                Showing <strong><?= number_format($total) ?></strong> result<?= $total !== 1 ? 's' : '' ?><?= $total >= 200 ? ' · first 200 shown' : '' ?>
            </div>
        </div>
    <?php endif; ?>
    <?php
}

// Live-search endpoint: same query above, results markup only, no page chrome.
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    render_search_results_inner([
        'total'           => $total,
        'view'            => $view,
        'rows'            => $rows,
        'picker_dept_ids' => $picker_dept_ids,
        'games_by_student' => $games_by_student,
        'app_root'        => __DIR__,
    ]);
    exit;
}

/* Game-wise Student Count card: the department filter (if picked) wins,
   otherwise fall back to the faculty's own effective department. NULL
   for a SUPER_ADMIN who hasn't picked a faculty — the card shows a hint.
   A "Game" dropdown then lets them drill into one game's Male/Female/
   Total count instead of dumping the whole catalog as a table. */
$gw_department_id = $department > 0 ? $department : effective_department_id();
$gw_dept_name = null;
$gw_catalog   = [];
$gw_totals    = ['male' => 0, 'female' => 0, 'other' => 0, 'total' => 0];
if ($gw_department_id !== null) {
    $gw_dept_row = db_one('SELECT name FROM departments WHERE id = ?', [$gw_department_id], 'i');
    $gw_dept_name = $gw_dept_row['name'] ?? null;
    $gw_catalog   = load_gamewise_gender_counts($gw_department_id);
    $gw_totals    = load_department_gender_totals($gw_department_id);
}

$gw_selected_game = null;
if (isset($_GET['gw_game']) && $_GET['gw_game'] !== '') {
    foreach ($gw_catalog as $g) {
        if ($g['game_code'] === $_GET['gw_game']) { $gw_selected_game = $g; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Students | Sports Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">
    <style>
        /* Game-wise Student Count — Faculty picker */
        .gw-filter-bar { display:flex; align-items:flex-end; gap:1rem; flex-wrap:wrap; padding:1rem 1.5rem; background:#fff; border-bottom:1px solid var(--light-gray); }
        .gw-field { flex:0 0 auto; min-width:240px; }
        .gw-field label { display:block; font-size:.7rem; font-weight:700; color:var(--primary-navy); text-transform:uppercase; letter-spacing:1px; margin-bottom:.35rem; padding-bottom:.25rem; border-bottom:1.5px solid var(--primary-navy); }
        .gw-field select {
            display:block; width:100%; padding:.65rem .8rem; font-family:inherit; font-size:.95rem; font-weight:500;
            color:var(--text-dark); background:#fff; border:1.5px solid var(--primary-navy); border-radius:6px; outline:none; cursor:pointer;
            appearance:none; -webkit-appearance:none;
            background-image:url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%231a365d%22%20d%3D%22M6%208L0%200h12z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat:no-repeat; background-position:right .85rem center; background-size:10px 7px; padding-right:2.1rem;
        }
        .gw-field select:focus { box-shadow:0 0 0 3px rgba(201,162,39,.25); border-color:var(--primary-navy-dark); }
        .gw-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.68rem 1.2rem; font:inherit; font-size:.85rem; font-weight:700; letter-spacing:.4px; text-transform:uppercase; cursor:pointer; border:1.5px solid var(--primary-navy); border-radius:6px; background:var(--primary-navy); color:#fff; }
        .gw-btn:hover { background:var(--primary-navy-dark); }
        .filter-hint { padding:1.8rem 1.5rem; text-align:center; color:var(--medium-gray); font-size:.92rem; font-style:italic; }
        .filter-hint i { color:var(--accent-gold); margin-right:.4rem; font-style:normal; }
        .filter-result-line { padding:.85rem 1.5rem; font-size:.9rem; color:var(--medium-gray); background:#fff; border-bottom:1px solid var(--light-gray); font-weight:500; }
        .filter-result-line strong { color:var(--primary-navy); font-weight:700; }
        @media (max-width:576px) { .gw-field { min-width:100%; } }

        /* ---------- Search page polish (scoped to this page) ---------- */
        /* The .html original used .search-header-left; this PHP page uses
           .search-header-content / -stats, which admin.css never styled. */
        .search-header-content h1 { display:flex; align-items:center; gap:.55rem; font-size:1.5rem; font-weight:800; color:var(--primary-navy); margin:0 0 .15rem; }
        .search-header-content h1 i { font-size:1.1rem; color:var(--accent-gold); }
        .search-header-content p { font-size:.9rem; color:var(--medium-gray); margin:0; }
        .search-header-stats { display:flex; gap:.75rem; }
        .search-stat { min-width:104px; padding:.6rem 1.1rem; background:var(--white); border:1px solid var(--light-gray); border-radius:12px; text-align:center; box-shadow:0 2px 10px rgba(0,0,0,.04); }
        .search-stat h3 { font-size:1.55rem; font-weight:800; color:var(--primary-navy); line-height:1; margin:0; }
        .search-stat span { font-size:.64rem; font-weight:700; letter-spacing:.7px; text-transform:uppercase; color:var(--medium-gray); }

        /* Segmented mode switch (General / Advanced) */
        .search-modes { display:inline-flex; gap:.25rem; padding:.25rem; background:var(--off-white); border:1px solid var(--light-gray); border-radius:10px; margin-bottom:1.1rem; }
        .search-mode-btn { border:none; background:transparent; padding:.45rem .95rem; border-radius:8px; font-family:var(--font-primary); font-size:.82rem; font-weight:600; color:var(--medium-gray); cursor:pointer; display:inline-flex; align-items:center; gap:.4rem; transition:color .18s ease, background .18s ease, box-shadow .18s ease; }
        .search-mode-btn:hover { color:var(--primary-navy); }
        .search-mode-btn.active { background:var(--white); color:var(--primary-navy); box-shadow:0 1px 4px rgba(0,0,0,.12); }

        /* Inputs + icons (page markup omitted the .search-icon class, so the
           absolute-positioned icon rule in admin.css never applied) */
        .search-input-row { display:flex; gap:.75rem; align-items:stretch; flex-wrap:wrap; }
        .search-input-wrapper { flex:1 1 220px; position:relative; min-width:0; }
        .search-input-wrapper > i.search-icon { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--medium-gray); font-size:1rem; pointer-events:none; transition:color .2s; z-index:1; }
        .search-input-wrapper input,
        .search-input-wrapper select { width:100%; height:44px; border:1.5px solid var(--light-gray); border-radius:10px; font-family:var(--font-primary); font-size:.92rem; color:var(--text-dark); background:var(--white); outline:none; transition:border-color .18s ease, box-shadow .18s ease; }
        .search-input-wrapper input { padding:0 2.4rem 0 2.6rem; }
        .search-input-wrapper select { padding:0 .9rem 0 2.6rem; cursor:pointer; }
        .search-input-wrapper input:focus,
        .search-input-wrapper select:focus { border-color:var(--primary-navy); box-shadow:0 0 0 3px rgba(26,54,93,.1); }
        .search-input-wrapper input:focus ~ i.search-icon { color:var(--primary-navy); }
        .search-input-wrapper input::placeholder { color:#adb5bd; }
        .search-input-wrapper .field-clear { position:absolute; right:8px; top:50%; transform:translateY(-50%); width:26px; height:26px; padding:0; border:none; border-radius:50%; background:var(--off-white); color:var(--medium-gray); cursor:pointer; display:none; align-items:center; justify-content:center; font-size:.72rem; line-height:1; }
        .search-input-wrapper .field-clear:hover { background:var(--light-gray); color:var(--primary-navy); }
        .search-input-wrapper.has-value .field-clear { display:inline-flex; }

        /* Primary search button — was a washed-out gold gradient */
        .btn-search { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; height:44px; padding:0 1.5rem; background:var(--primary-navy); color:var(--white); border:none; border-radius:10px; font-family:var(--font-primary); font-size:.88rem; font-weight:700; letter-spacing:.2px; cursor:pointer; white-space:nowrap; transition:background .18s ease, transform .18s ease, box-shadow .18s ease; }
        .btn-search:hover { background:var(--primary-navy-dark); transform:translateY(-1px); box-shadow:0 6px 16px rgba(26,54,93,.22); }
        .btn-search:active { transform:translateY(0); }
        .btn-search .spinner { width:15px; height:15px; border:2px solid rgba(255,255,255,.4); border-top-color:#fff; border-radius:50%; animation:btnspin .6s linear infinite; display:none; }
        .btn-search.is-busy .spinner { display:inline-block; }
        .btn-search.is-busy > i { display:none; }
        @keyframes btnspin { to { transform:rotate(360deg); } }

        /* View toggles as a segmented control */
        .results-header .view-toggles { display:inline-flex; gap:.15rem; padding:.2rem; background:var(--off-white); border:1px solid var(--light-gray); border-radius:9px; }
        .view-toggle { width:32px; height:30px; border:none; background:transparent; color:var(--medium-gray); border-radius:6px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:.9rem; transition:background .16s ease, color .16s ease; }
        .view-toggle:hover { color:var(--primary-navy); }
        .view-toggle.active { background:var(--primary-navy); color:var(--white); }

        /* Results header/footer — admin.css only styles <h3> here, page uses <h2> */
        .results-header h2 { display:flex; align-items:center; gap:.55rem; font-size:1rem; font-weight:700; color:var(--primary-navy); margin:0; }
        .results-header h2 i { color:var(--accent-gold); }
        .results-footer { display:flex; align-items:center; justify-content:space-between; padding:.8rem 1.5rem; border-top:1px solid var(--light-gray); background:var(--off-white); }
        .results-info { font-size:.82rem; color:var(--medium-gray); }
        .results-info strong { color:var(--primary-navy); font-weight:700; }

        /* Live-search loading state */
        #resultsPanel { position:relative; transition:opacity .15s ease; }
        #resultsPanel.is-loading { opacity:.5; pointer-events:none; }
        #resultsPanel.is-loading::after { content:""; position:absolute; left:0; right:0; top:0; height:3px; background:linear-gradient(90deg, transparent, var(--accent-gold), transparent); background-size:40% 100%; background-repeat:no-repeat; animation:barslide 1s linear infinite; }
        @keyframes barslide { 0% { background-position:-40% 0; } 100% { background-position:140% 0; } }

        /* Status chips */
        .chip { display:inline-flex; align-items:center; gap:.3rem; padding:.15rem .55rem; border-radius:50px; font-size:.7rem; font-weight:600; }
        .chip-ok { background:rgba(25,135,84,.12); color:#0a3622; }
        .chip-draft { background:rgba(255,193,7,.14); color:#664d03; }

        @media (max-width:768px) {
            .search-header-stats { width:100%; }
            .search-stat { flex:1; }
            .btn-search { width:100%; }
            .search-input-wrapper { flex-basis:100%; }
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
                <a href="student-search.php" class="active"><i class="bi bi-search"></i> <span>Search Students</span></a>
                <a href="student-profile.php?new=1"><i class="bi bi-person-plus"></i> <span>Add Student</span></a>
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
                        <span class="current">Search Students</span>
                    </nav>
                </div>
                <div class="top-bar-right">
                    <a href="student-profile.php?new=1" class="btn-act edit" style="text-decoration:none"><i class="bi bi-person-plus"></i> Add Student</a>
                </div>
            </header>

            <div class="content-body">
                <!-- Search Header -->
                <div class="search-header">
                    <div class="search-header-content">
                        <h1><i class="bi bi-search"></i> Search Students</h1>
                        <p>Find any student in the sports department database</p>
                    </div>
                    <div class="search-header-stats">
                        <div class="search-stat">
                            <h3><?= number_format($total) ?></h3>
                            <span>Results</span>
                        </div>
                    </div>
                </div>

                <!-- Game-wise Student Count -->
                <div class="results-panel" style="margin-bottom:1.5rem">
                    <div class="results-header">
                        <h2>
                            <i class="bi bi-trophy-fill"></i>
                            Game-wise Student Count<?= $gw_dept_name !== null ? ' — ' . h($gw_dept_name) : '' ?>
                        </h2>
                        <?php if ($gw_department_id !== null && !empty($gw_catalog)): ?>
                            <a href="admin/gamewise_report_xlsx.php?department=<?= (int)$gw_department_id ?>"
                               class="btn-act edit" style="text-decoration:none">
                                <i class="bi bi-download"></i> Download Report
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($gw_department_id === null): ?>
                        <div class="filter-hint">
                            <i class="bi bi-info-circle"></i>
                            Pick a Faculty in Advanced search to see its game-wise student count.
                        </div>
                    <?php elseif (empty($gw_catalog)): ?>
                        <div class="filter-hint">
                            <i class="bi bi-info-circle"></i>
                            No games are configured for this faculty yet.
                        </div>
                    <?php else: ?>
                        <div style="display:flex;gap:.75rem;flex-wrap:wrap;padding:1rem 1.5rem;border-bottom:1px solid var(--light-gray)">
                            <div style="flex:1;min-width:120px;padding:.7rem 1rem;border-radius:10px;background:rgba(26,54,93,.05);text-align:center">
                                <div style="font-size:1.3rem;font-weight:700;color:var(--primary-navy)"><?= number_format($gw_totals['total']) ?></div>
                                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--medium-gray)">Total Students</div>
                            </div>
                            <div style="flex:1;min-width:120px;padding:.7rem 1rem;border-radius:10px;background:rgba(13,110,253,.06);text-align:center">
                                <div style="font-size:1.3rem;font-weight:700;color:#0d47a1"><?= number_format($gw_totals['male']) ?></div>
                                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--medium-gray)">Male</div>
                            </div>
                            <div style="flex:1;min-width:120px;padding:.7rem 1rem;border-radius:10px;background:rgba(190,24,93,.06);text-align:center">
                                <div style="font-size:1.3rem;font-weight:700;color:#ad1457"><?= number_format($gw_totals['female']) ?></div>
                                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--medium-gray)">Female</div>
                            </div>
                            <?php if ($gw_totals['other'] > 0): ?>
                                <div style="flex:1;min-width:120px;padding:.7rem 1rem;border-radius:10px;background:var(--off-white);text-align:center">
                                    <div style="font-size:1.3rem;font-weight:700;color:var(--medium-gray)"><?= number_format($gw_totals['other']) ?></div>
                                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--medium-gray)">Other</div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <form method="get" action="student-search.php" class="gw-filter-bar">
                            <?php foreach (['q' => $q_raw, 'department' => ($department > 0 ? $department : ''), 'sport' => $sport_filter, 'year' => $year_filter, 'view' => $view] as $k => $v): ?>
                                <?php if ($v !== '' && $v !== 'table'): ?>
                                    <input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <div class="gw-field">
                                <label for="gwGameSelect">Game</label>
                                <select id="gwGameSelect" name="gw_game" onchange="this.form.submit()">
                                    <option value="">— Select Game —</option>
                                    <?php foreach ($gw_catalog as $g): ?>
                                        <option value="<?= h($g['game_code']) ?>" <?= ($gw_selected_game && $gw_selected_game['game_code'] === $g['game_code']) ? 'selected' : '' ?>>
                                            <?= h($g['display_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <noscript><button type="submit" class="gw-btn"><i class="bi bi-search"></i> View</button></noscript>
                        </form>

                        <?php if ($gw_selected_game === null): ?>
                            <div class="filter-hint">
                                <i class="bi bi-arrow-up-circle"></i>
                                Pick a game above to see its Male / Female / Total count.
                            </div>
                        <?php else: ?>
                            <div class="filter-result-line">
                                <strong><?= number_format($gw_selected_game['total']) ?></strong>
                                student<?= $gw_selected_game['total'] !== 1 ? 's' : '' ?>
                                enrolled in <strong><?= h($gw_selected_game['display_name']) ?></strong>
                            </div>
                            <div style="display:flex;gap:.75rem;flex-wrap:wrap;padding:1rem 1.5rem">
                                <div style="flex:1;min-width:120px;padding:.7rem 1rem;border-radius:10px;background:rgba(13,110,253,.06);text-align:center">
                                    <div style="font-size:1.3rem;font-weight:700;color:#0d47a1"><?= number_format($gw_selected_game['male']) ?></div>
                                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--medium-gray)">Male</div>
                                </div>
                                <div style="flex:1;min-width:120px;padding:.7rem 1rem;border-radius:10px;background:rgba(190,24,93,.06);text-align:center">
                                    <div style="font-size:1.3rem;font-weight:700;color:#ad1457"><?= number_format($gw_selected_game['female']) ?></div>
                                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--medium-gray)">Female</div>
                                </div>
                                <?php if ($gw_selected_game['other'] > 0): ?>
                                    <div style="flex:1;min-width:120px;padding:.7rem 1rem;border-radius:10px;background:var(--off-white);text-align:center">
                                        <div style="font-size:1.3rem;font-weight:700;color:var(--medium-gray)"><?= number_format($gw_selected_game['other']) ?></div>
                                        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--medium-gray)">Other</div>
                                    </div>
                                <?php endif; ?>
                                <div style="flex:1;min-width:120px;padding:.7rem 1rem;border-radius:10px;background:rgba(26,54,93,.05);text-align:center">
                                    <div style="font-size:1.3rem;font-weight:700;color:var(--primary-navy)"><?= number_format($gw_selected_game['total']) ?></div>
                                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--medium-gray)">Total</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Search Panel -->
                <div class="search-panel">
                    <div class="search-modes" role="tablist" aria-label="Search mode">
                        <button type="button" class="search-mode-btn active" data-mode="general">
                            <i class="bi bi-search"></i> General
                        </button>
                        <button type="button" class="search-mode-btn" data-mode="advanced">
                            <i class="bi bi-sliders"></i> Advanced
                        </button>
                    </div>

                    <form id="searchForm" method="get" action="student-search.php" autocomplete="off">
                        <!-- General mode -->
                        <div class="search-input-row" data-mode="general">
                            <div class="search-input-wrapper" id="qWrapper">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" name="q" id="searchInput" placeholder="Search by name, enrollment number, or phone…" value="<?= h($q_raw) ?>" autocomplete="off" spellcheck="false">
                                <button type="button" class="field-clear" id="qClear" title="Clear" aria-label="Clear search"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <button type="submit" class="btn-search" id="searchBtn">
                                <span class="spinner" aria-hidden="true"></span>
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>

                        <!-- Advanced mode -->
                        <div class="search-input-row" data-mode="advanced" style="display:none">
                            <div class="search-input-wrapper">
                                <i class="bi bi-building search-icon"></i>
                                <select name="department" id="filterDepartment">
                                    <option value="0">All Faculties</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>" <?= $department === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="search-input-wrapper">
                                <i class="bi bi-trophy search-icon"></i>
                                <input type="text" name="sport" list="sportList" placeholder="Filter by sport" value="<?= h($sport_filter) ?>" autocomplete="off">
                                <datalist id="sportList">
                                    <?php foreach (sport_options() as $sp): ?>
                                        <option value="<?= h($sp) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="search-input-wrapper">
                                <i class="bi bi-mortarboard search-icon"></i>
                                <select name="year">
                                    <option value="">Any Year</option>
                                    <?php foreach (year_options() as $y): ?>
                                        <option value="<?= h($y) ?>" <?= $year_filter === (string)$y ? 'selected' : '' ?>><?= h($y) ?> Year</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn-search">
                                <i class="bi bi-funnel"></i> Apply
                            </button>
                        </div>

                        <input type="hidden" name="view" id="viewField" value="<?= h($view) ?>">
                    </form>
                </div>

                <!-- Results Panel -->
                <div class="results-panel" id="resultsPanel" aria-live="polite">
                    <?php render_search_results_inner([
                        'total'           => $total,
                        'view'            => $view,
                        'rows'            => $rows,
                        'picker_dept_ids' => $picker_dept_ids,
                        'games_by_student' => $games_by_student,
                        'app_root'        => __DIR__,
                    ]); ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // --- Sidebar ---
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

            var form      = document.getElementById('searchForm');
            var input     = document.getElementById('searchInput');
            var qWrap     = document.getElementById('qWrapper');
            var qClear    = document.getElementById('qClear');
            var panel     = document.getElementById('resultsPanel');
            var btn       = document.getElementById('searchBtn');
            var viewField = document.getElementById('viewField');
            var statEl    = document.querySelector('.search-stat h3');

            // --- Mode switch (segmented) ---
            var modeBtns = document.querySelectorAll('.search-mode-btn');
            var modeRows = document.querySelectorAll('.search-input-row');
            modeBtns.forEach(function (b) {
                b.addEventListener('click', function () {
                    var mode = this.dataset.mode;
                    modeBtns.forEach(function (x) { x.classList.toggle('active', x === b); });
                    modeRows.forEach(function (r) { r.style.display = r.dataset.mode === mode ? 'flex' : 'none'; });
                });
            });
            var showAdvanced = <?= ($department > 0 || $sport_filter !== '' || $year_filter !== '') ? 'true' : 'false' ?>;
            if (showAdvanced && modeBtns[1]) { modeBtns[1].click(); }

            // --- Live (runtime) search ---
            var timer = null, seq = 0;

            function buildParams() {
                var p = new URLSearchParams();
                var q = (input.value || '').trim();
                if (q) { p.set('q', q); }
                var dept = form.querySelector('[name=department]');
                if (dept && dept.value && dept.value !== '0') { p.set('department', dept.value); }
                var sport = form.querySelector('[name=sport]');
                if (sport && sport.value.trim()) { p.set('sport', sport.value.trim()); }
                var year = form.querySelector('[name=year]');
                if (year && year.value) { p.set('year', year.value); }
                if (viewField && viewField.value && viewField.value !== 'table') { p.set('view', viewField.value); }
                return p;
            }

            function afterRender() {
                var tot = panel.querySelector('#resultsTotal');
                if (tot && statEl) { statEl.textContent = Number(tot.dataset.total || 0).toLocaleString(); }
                bindViewToggles();
            }

            function bindViewToggles() {
                panel.querySelectorAll('.view-toggle').forEach(function (vb) {
                    vb.addEventListener('click', function () {
                        if (this.classList.contains('active')) { return; }
                        if (viewField) { viewField.value = this.dataset.view; }
                        runSearch();
                    });
                });
            }

            function runSearch() {
                if (!panel) { return; }
                var mySeq = ++seq;
                var p = buildParams();
                panel.classList.add('is-loading');
                if (btn) { btn.classList.add('is-busy'); }
                fetch('student-search.php?ajax=1' + (p.toString() ? '&' + p.toString() : ''), {
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin'
                })
                .then(function (r) {
                    if (r.redirected) { window.location.reload(); return null; }
                    return r.text();
                })
                .then(function (html) {
                    if (html === null || mySeq !== seq) { return; }
                    panel.innerHTML = html;
                    afterRender();
                    var qs = p.toString();
                    window.history.replaceState(null, '', 'student-search.php' + (qs ? '?' + qs : ''));
                })
                .catch(function () { /* network error: keep the current results */ })
                .finally(function () {
                    if (mySeq === seq) {
                        panel.classList.remove('is-loading');
                        if (btn) { btn.classList.remove('is-busy'); }
                    }
                });
            }

            function debounced() { clearTimeout(timer); timer = setTimeout(runSearch, 250); }

            if (input) {
                var syncClear = function () { qWrap.classList.toggle('has-value', input.value.length > 0); };
                input.addEventListener('input', function () { syncClear(); debounced(); });
                syncClear();
            }
            if (qClear) {
                qClear.addEventListener('click', function () {
                    input.value = '';
                    qWrap.classList.remove('has-value');
                    input.focus();
                    runSearch();
                });
            }
            ['department', 'sport', 'year'].forEach(function (n) {
                var el = form.querySelector('[name=' + n + ']');
                if (el) { el.addEventListener('change', runSearch); }
            });
            if (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    clearTimeout(timer);
                    runSearch();
                });
            }

            bindViewToggles(); // wire the server-rendered toggles present on first load
        });
    </script>
</body>
</html>
