<?php
/**
 * Final Team List Management.
 *
 * GET params:
 *   game    string  the game name
 *   event   string  the event label
 *   ay      string  the academic year
 *   q       string  search filter for add-pane
 *   page    int     pagination
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_department();

$me = current_faculty();
if ($me === null) {
    redirect('../faculty-login.php');
    exit;
}

$game     = trim((string)($_GET['game'] ?? ''));
$new_game = trim((string)($_GET['new_game'] ?? ''));
if ($game === '' && $new_game !== '') {
    $game = $new_game;
}
$event    = trim((string)($_GET['event'] ?? ''));
$ay       = trim((string)($_GET['ay'] ?? ''));
$gender   = trim((string)($_GET['gender'] ?? ''));
if (!array_key_exists($gender, gender_list_options())) $gender = '';
$q        = trim((string)($_GET['q'] ?? ''));
$page     = max(1, (int)($_GET['page'] ?? 1));
$per      = 8;
$has_list = ($game !== '' && $event !== '' && $gender !== '');

// ---- distinct game names (from final_teams) ----
[$gscope, $gp, $gt] = scope_sql_department('s');
// Hide wizard drafts (students who registered but haven't hit Final Submit).
$visible = faculty_visible_student_filter('s');
$gscope .= $visible[0];
$gp      = array_merge($gp, $visible[1]);
$gt     .= $visible[2];
$existing_games = db_select(
    "SELECT DISTINCT ft.game_name
       FROM final_teams ft
       JOIN students s ON s.id = ft.student_id
      WHERE 1=1 $gscope
      ORDER BY ft.game_name",
    $gp, $gt
);

// Games "belonging to" this faculty: the department's catalog
// (dept_game_catalog). These populate the picker dropdown even before any
// list exists. Any game already used in a saved list but missing from the
// catalog is appended so historical lists stay reachable.
$game_options = load_department_game_names(effective_department_id());
foreach ($existing_games as $g) {
    if (!in_array($g['game_name'], $game_options, true)) {
        $game_options[] = $g['game_name'];
    }
}

// ---- distinct saved final lists ----
$saved_lists = db_select(
    "SELECT ft.game_name, ft.event_label, ft.academic_year, ft.gender,
            COUNT(*) AS player_count,
            MAX(ft.created_at) AS last_added
       FROM final_teams ft
       JOIN students s ON s.id = ft.student_id
      WHERE 1=1 $gscope
      GROUP BY ft.game_name, ft.event_label, ft.academic_year, ft.gender
      ORDER BY last_added DESC",
    $gp, $gt
);

// ---- current final list rows ----
$list_rows  = [];
$list_total = 0;
$lpage      = max(1, (int)($_GET['lpage'] ?? 1));
$lper       = 50;
$lpages     = 1;
if ($has_list) {
    [$lscope, $lp, $lt] = scope_sql_department('s');
    // Hide wizard drafts (students who registered but haven't hit Final Submit).
    $visible = faculty_visible_student_filter('s');
    $lscope .= $visible[0];
    $lp      = array_merge($lp, $visible[1]);
    $lt     .= $visible[2];

    $list_where_sql = "ft.game_name = ?
            AND ft.event_label = ?
            AND ft.academic_year <=> ?
            AND ft.gender <=> ?
            $lscope";
    $list_where_params = array_merge([$game, $event, $ay === '' ? null : $ay, $gender], $lp);
    $list_where_types  = 'ssss' . $lt;

    $list_total = (int)(db_one(
        "SELECT COUNT(*) AS n
           FROM final_teams ft
           JOIN students s ON s.id = ft.student_id
          WHERE $list_where_sql",
        $list_where_params, $list_where_types
    )['n'] ?? 0);
    $lpages = max(1, (int)ceil($list_total / $lper));
    if ($lpage > $lpages) $lpage = $lpages;
    $loffset = ($lpage - 1) * $lper;

    $list_rows = db_select(
        "SELECT ft.id AS entry_id, ft.roll_no, ft.created_at,
                s.id, s.enrollment_no, s.full_name,
                s.sport_1, s.sport_2, s.gender, s.program,
                s.academic_year, s.study_year, s.photo_path,
                d.name AS dept_name
           FROM final_teams ft
           JOIN students s    ON s.id = ft.student_id
           JOIN departments d ON d.id = s.department_id
          WHERE $list_where_sql
          ORDER BY ft.created_at ASC, s.enrollment_no ASC
          LIMIT $lper OFFSET $loffset",
        $list_where_params, $list_where_types
    );
}

// ---- available students to add ----
$search_rows = [];
$search_total = 0;
$search_pages = 1;
if ($has_list) {
    [$sscope, $sp, $st] = scope_sql_department('s');
    // Hide wizard drafts (students who registered but haven't hit Final Submit).
    $visible = faculty_visible_student_filter('s');
    $sscope .= $visible[0];
    $sp      = array_merge($sp, $visible[1]);
    $st     .= $visible[2];
    // Only students matching the team's gender can be added to it.
    $where    = "1=1 $sscope AND s.gender = ?";
    $params   = array_merge($sp, [$gender]);
    $types    = $st . 's';

    // Restrict the add-pane to students who actually picked THIS game in
    // their Step 3 sports selection. Only applies when the team's game is a
    // catalogued game for this department — a free-text custom game has no
    // picker data, so it keeps the old "show everyone" behaviour.
    $picker_game_code = resolve_department_game_code(effective_department_id(), $game);
    if ($picker_game_code !== null) {
        $where   .= ' AND EXISTS (SELECT 1 FROM student_selected_games ssg
                                   WHERE ssg.student_id = s.id AND ssg.game_code = ?) ';
        $params[] = $picker_game_code;
        $types   .= 's';
    }

    if ($q !== '') {
        $where .= ' AND (s.enrollment_no LIKE ? OR s.full_name LIKE ?) ';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $types   .= 'ss';
    }
    $where .= ' AND s.id NOT IN (
        SELECT ft2.student_id
          FROM final_teams ft2
         WHERE ft2.game_name = ?
           AND ft2.event_label = ?
           AND ft2.academic_year <=> ?
           AND ft2.gender <=> ?
    ) ';
    $params[] = $game;
    $params[] = $event;
    $params[] = $ay === '' ? null : $ay;
    $params[] = $gender;
    $types   .= 'ssss';

    $search_total = (int)(db_one(
        "SELECT COUNT(*) AS n FROM students s WHERE $where", $params, $types
    )['n'] ?? 0);
    $search_pages = max(1, (int)ceil($search_total / $per));
    if ($page > $search_pages) $page = $search_pages;
    $offset = ($page - 1) * $per;

    $search_rows = db_select(
        "SELECT s.id, s.enrollment_no, s.full_name,
                s.sport_1, s.sport_2, s.gender, s.program,
                s.academic_year, s.study_year, s.photo_path,
                d.name AS dept_name
           FROM students s
           JOIN departments d ON d.id = s.department_id
          WHERE $where
          ORDER BY s.enrollment_no
          LIMIT $per OFFSET $offset",
        $params, $types
    );
}

$list_query = http_build_query(array_filter(['game' => $game, 'event' => $event, 'ay' => $ay, 'gender' => $gender]));
$search_query = http_build_query(array_filter(['game' => $game, 'event' => $event, 'ay' => $ay, 'gender' => $gender, 'q' => $q]));
$gender_label = gender_list_options()[$gender] ?? '';

$flash_ok  = flash_get('final_saved');
$flash_err = flash_get('final_error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Final Team List | Sports Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">
    <style>
        :root{--primary-navy:#1a365d;--primary-navy-dark:#0f2744;--primary-navy-light:#2c5282;--accent-gold:#c9a227;--accent-gold-light:#d4b84a;--accent-maroon:#722f37;--white:#fff;--off-white:#f8f9fa;--light-gray:#e9ecef;--medium-gray:#6c757d;--dark-gray:#343a40;--text-dark:#212529;--font-primary:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;--sidebar-width:260px;--transition-smooth:all .3s ease-in-out}
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
        .sidebar-nav a i{font-size:1.15rem;width:22px;text-align:center}
        .sidebar-nav a .nav-badge{margin-left:auto;background:var(--accent-gold);color:var(--primary-navy-dark);font-size:.65rem;font-weight:700;padding:.15rem .5rem;border-radius:10px}
        .sidebar-footer{padding:1rem 1.25rem;border-top:1px solid rgba(255,255,255,.08)}
        .sidebar-user{display:flex;align-items:center;gap:.75rem}
        .sidebar-user-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent-gold),var(--accent-maroon));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:var(--white);flex-shrink:0}
        .sidebar-user-info h4{font-size:.82rem;font-weight:600;color:var(--white);margin:0}
        .sidebar-user-info span{font-size:.7rem;color:rgba(255,255,255,.5)}
        .btn-logout{margin-left:auto;background:0 0;border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.6);padding:.35rem .5rem;border-radius:6px;cursor:pointer;font-size:.85rem;text-decoration:none}
        .btn-logout:hover{background:rgba(220,53,69,.2);border-color:rgba(220,53,69,.4);color:#ff8a8a}
        .main-content{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
        .top-bar{background:var(--white);border-bottom:1px solid var(--light-gray);padding:.75rem 2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
        .top-bar-left{display:flex;align-items:center;gap:1rem}
        .icon-btn{background:0 0;border:1px solid var(--light-gray);color:var(--medium-gray);width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer}
        .icon-btn:hover{background:var(--off-white);color:var(--primary-navy)}
        .content-body{flex:1;overflow-y:auto;padding:2rem}
        .page-header{margin-bottom:1.25rem}
        .page-header h1{font-size:1.4rem;font-weight:700;color:var(--primary-navy)}
        .page-header p{color:var(--medium-gray);font-size:.9rem;margin-top:.25rem}
        .search-form{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end}
        .search-form .form-group{display:flex;flex-direction:column;gap:.3rem;flex:1;min-width:160px}
        .search-form label{font-size:.75rem;font-weight:600;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.3px}
        .search-form input,.search-form select{padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.9rem;background:var(--white);color:var(--text-dark)}
        .search-form input:focus,.search-form select:focus{outline:none;border-color:var(--primary-navy)}
        .btn{padding:.55rem 1.1rem;border-radius:6px;font-size:.88rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;transition:var(--transition-smooth)}
        .btn-primary{background:var(--primary-navy);color:var(--white)}.btn-primary:hover{background:var(--primary-navy-dark)}
        .btn-secondary{background:var(--off-white);color:var(--primary-navy);border:1px solid var(--light-gray)}.btn-secondary:hover{background:var(--light-gray)}
        .btn-success{background:#198754;color:var(--white)}.btn-success:hover{background:#157347}
        .data-card{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;overflow:hidden}
        .data-card-header{padding:1rem 1.25rem;border-bottom:1px solid var(--light-gray);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
        .data-card-header h2{font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.7rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.75rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);color:var(--text-dark)}
        .data-table tr:last-child td{border-bottom:none}
        .data-table tr:hover{background:var(--off-white)}
        .student-cell{display:flex;align-items:center;gap:.7rem}
        .student-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-light));color:var(--white);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;flex-shrink:0;overflow:hidden}
        .student-avatar img{width:100%;height:100%;object-fit:cover}
        .student-name{font-weight:600;color:var(--primary-navy)}
        .student-meta{font-size:.75rem;color:var(--medium-gray)}
        .sport-tag{display:inline-block;background:rgba(201,162,39,.12);color:var(--accent-gold);padding:.2rem .6rem;border-radius:4px;font-size:.72rem;font-weight:600;margin-right:.3rem}
        .pagination{padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;border-top:1px solid var(--light-gray)}
        .pagination .info{font-size:.85rem;color:var(--medium-gray)}
        .pagination .pages{display:flex;gap:.25rem}
        .pagination .pages a{padding:.35rem .65rem;border:1px solid var(--light-gray);border-radius:4px;font-size:.85rem;color:var(--primary-navy);text-decoration:none;background:var(--white)}
        .pagination .pages a:hover{background:var(--off-white)}
        .pagination .pages a.active{background:var(--primary-navy);color:var(--white);border-color:var(--primary-navy)}
        .pagination .pages a.disabled{opacity:.4;pointer-events:none}
        .empty-row{text-align:center;color:var(--medium-gray);padding:3rem 1rem;font-size:.9rem}
        .empty-row i{font-size:2.5rem;display:block;margin-bottom:.5rem;color:var(--light-gray)}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#8420 own;border:1px solid rgba(220,53,69,.2)}
        .alert-banner.info{background:rgba(13,110,253,.1);color:#052c65;border:1px solid rgba(13,110,253,.2)}
        .list-summary{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .list-summary .label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--medium-gray);margin-right:.4rem}
        .list-summary .value{font-weight:600;color:var(--primary-navy)}
        .list-summary .group{display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
        .two-col{display:grid;grid-template-columns:1.5fr 1fr;gap:1.25rem;align-items:start}
        @media (max-width: 1100px){.two-col{grid-template-columns:1fr}}
        .list-row{display:flex;align-items:center;gap:.7rem;padding:.65rem 1.1rem;border-bottom:1px solid var(--light-gray)}
        .list-row:last-child{border-bottom:none}
        .list-row .info{flex:1;min-width:0}
        .list-row .info .name{font-weight:600;color:var(--primary-navy);font-size:.9rem}
        .list-row .info .meta{font-size:.75rem;color:var(--medium-gray);margin-top:.1rem}
        .list-row .info .roll-input{font-size:.78rem;color:var(--primary-navy);border:1px solid var(--light-gray);border-radius:4px;padding:.1rem .4rem;width:120px;margin-right:.5rem}
        .icon-remove{background:0 0;border:1px solid var(--light-gray);color:var(--medium-gray);width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;flex-shrink:0;text-decoration:none;transition:var(--transition-smooth)}
        .icon-remove:hover{background:rgba(220,53,69,.1);border-color:rgba(220,53,69,.3);color:#dc3545}
        .count-pill{display:inline-block;background:rgba(201,162,39,.15);color:var(--accent-gold);font-size:.72rem;font-weight:700;padding:.15rem .55rem;border-radius:10px;margin-left:.4rem}
        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .search-form { flex-direction: column; align-items: stretch; gap: 0.75rem; }
            .search-form .form-group { width: 100%; flex: none; }
            .btn { width: 100%; justify-content: center; }
            .data-card { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .data-table { min-width: 600px; }
            .list-summary { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .list-summary .group { width: 100%; justify-content: space-between; }
        }
        @media(max-width:576px){
            .content-body { padding: 1rem 0.75rem; }
            .search-form { padding: 1rem; }
        }
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
            </div >
            <nav class="sidebar-nav">
                <div class="sidebar-nav-label">Main</div>
                <?php if (has_multiple_departments()): ?>
                    <a href="../faculty-select.php?change=1">
                        <i class="bi bi-building"></i> <span>Select Faculty</span>
                    </a>
                <?php endif; ?>
                <a href="dashboard.php"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a>
                <a href="../student-search.php"><i class="bi bi-search"></i> <span>Search Students</span></a>
                <a href="../student-profile.php?new=1"><i class="bi bi-person-plus"></i> <span>Add Student</span></a>
                <a href="provisional_list.php"><i class="bi bi-clipboard-check"></i> <span>Provisional Players</span></a>
                <a href="final_list.php" class="active"><i class="bi bi-check-all"></i> <span>Final Teams</span></a>
                <a href="eligibility_archive.php"><i class="bi bi-folder2-open"></i> <span>Eligibility Archive</span></a>
                <a href="jersey_dashboard.php"><i class="bi bi-person-badge"></i> <span>Jersey Kit</span></a>
                <a href="data_management.php"><i class="bi bi-database-fill-gear"></i> <span>Data Management</span></a>
                <?php if (($me['role'] ?? '') === 'SUPER_ADMIN'): ?>
                    <div class="sidebar-nav-label">Site Content</div>
                    <a href="notices_list.php"><i class="bi bi-megaphone"></i> <span>Notices</span></a>
                    <a href="achievements_list.php"><i class="bi bi-trophy"></i> <span>Achievements</span></a>
                    <a href="committee_manage.php"><i class="bi bi-people-fill"></i> <span>Committee</span></a>
                <?php endif; ?>
                <?php if ($me['role'] === 'SUPER_ADMIN'): ?>
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
                <div class="top-bar-left">
                    <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">Final Team Management</h2>
                </div>
            </header>

            <div class="content-body">
                <div class="page-header">
                    <h1>Final Team Lists</h1>
                    <p>Confirm your final roster from the provisional shortlist. You can add/remove players and customize roll numbers for the official export.</p>
                </div>

                <?php if ($flash_ok): ?>
                    <div class="alert-banner success" role="alert"><i class="bi bi-check-circle"></i> <?= h($flash_ok['msg']) ?></div>
                <?php endif; ?>
                <?php if ($flash_err): ?>
                    <div class="alert-banner <?= h($flash_err['level'] === 'info' ? 'info' : 'error') ?>" role="alert">
                        <i class="bi bi-<?= $flash_err['level'] === 'info' ? 'info-circle' : 'exclamation-circle' ?>"></i>
                        <?= h($flash_err['msg']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($has_list): ?>
                    <div class="list-summary">
                        <div class="group">
                            <span><span class="label">Game</span><span class="value"><?= h($game) ?></span></span>
                            <span><span class="label">Event</span><span class="value"><?= h($event) ?></span></span>
                            <span><span class="label">AY</span><span class="value"><?= h($ay !== '' ? $ay : '—') ?></span></span>
                            <span><span class="label">Gender</span><span class="value"><?= h($gender_label) ?></span></span>
                        </div>
                        <div class="group">
                            <a href="final_import.php?<?= h($list_query) ?>" class="btn btn-success">
                                <i class="bi bi-arrow-down-circle"></i> Import from Provisional
                            </a>
                            <a href="final_list.php" class="btn btn-secondary"><i class="bi bi-arrow-left-right"></i> Change list</a>
                        </div>
                    </div>

                    <div class="two-col">
                        <!-- LEFT: add students -->
                        <div class="data-card">
                            <div class="data-card-header">
                                <h2><i class="bi bi-search"></i> &nbsp;Add <?= h($gender_label) ?> students to this team</h2>
                            </div>
                            <form method="get" action="final_list.php" class="search-form" style="margin:0;border-radius:0;border-left:none;border-right:none">
                                <input type="hidden" name="game" value="<?= h($game) ?>">
                                <input type="hidden" name="event" value="<?= h($event) ?>">
                                <input type="hidden" name="ay" value="<?= h($ay) ?>">
                                <input type="hidden" name="gender" value="<?= h($gender) ?>">
                                <div class="form-group" style="flex:2">
                                    <label for="q">Search</label>
                                    <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Enrollment number or name">
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                                <?php if ($q !== ''): ?>
                                    <a href="final_list.php?<?= h($list_query) ?>" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Reset</a>
                                <?php endif; ?>
                            </form>

                            <?php if (!$search_rows): ?>
                                <div class="empty-row">
                                    <i class="bi bi-inbox"></i>
                                    <?php if (empty($search_rows)) echo "No available students found matching your criteria."; ?>
                                </div>
                            <?php else: ?>
                                <form method="post" action="final_save.php" id="bulkAddForm">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="game_name" value="<?= h($game) ?>">
                                    <input type="hidden" name="event_label" value="<?= h($event) ?>">
                                    <input type="hidden" name="academic_year" value="<?= h($ay) ?>">
                                    <input type="hidden" name="gender" value="<?= h($gender) ?>">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th style="width:36px"><input type="checkbox" id="selectAllStudents" title="Select all on this page" aria-label="Select all on this page"></th>
                                                <th>Student</th>
                                                <th>Enrollment</th>
                                                <th>Sports</th>
                                                <th>Year</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($search_rows as $r): ?>
                                            <tr>
                                                <td><input type="checkbox" class="student-check" name="student_id[]" value="<?= (int)$r['id'] ?>" aria-label="Select <?= h($r['full_name']) ?>"></td>
                                                <td>
                                                    <div class="student-cell">
                                                        <div class="student-avatar">
                                                            <?php if (!empty($r['photo_path']) && is_file(__DIR__ . '/../' . $r['photo_path'])): ?>
                                                                <img src="<?= h(url($r['photo_path'])) ?>" alt="">
                                                            <?php else: ?>
                                                                <?= h(initials($r['full_name'])) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="student-name"><?= h($r['full_name']) ?></div>
                                                    </div>
                                                </td>
                                                <td><?= h($r['enrollment_no']) ?></td>
                                                <td>
                                                    <?php if ($r['sport_1']): ?><span class="sport-tag"><?= h($r['sport_1']) ?></span><?php endif; ?>
                                                    <?php if ($r['sport_2']): ?><span class="sport-tag"><?= h($r['sport_2']) ?></span><?php endif; ?>
                                                </td>
                                                <td><?= h($r['academic_year']) ?> · <?= h($r['study_year']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div style="display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.1rem;border-top:1px solid var(--light-gray);background:var(--off-white)">
                                        <span id="selectedCount" style="font-size:.85rem;color:var(--medium-gray);font-weight:600">0 selected</span>
                                        <button type="submit" class="btn btn-primary" id="addSelectedBtn" disabled>
                                            <i class="bi bi-plus-circle"></i> Add Selected
                                        </button>
                                    </div>
                                </form>
                                <div class="pagination">
                                    <div class="info">Page <?= $page ?> of <?= $search_pages ?> · <?= $search_total ?> available</div>
                                    <div class="pages">
                                        <?php
                                        $prev = max(1, $page - 1);
                                        $next = min($search_pages, $page + 1);
                                        ?>
                                        <a class="<?= $page <= 1 ? 'disabled' : '' ?>" aria-label="Previous page" href="?<?= h($search_query.'&page='.$prev) ?>"><i class="bi bi-chevron-left"></i></a>
                                        <?php for ($i = 1; $i <= $search_pages; $i++): ?>
                                            <a class="<?= $i === $page ? 'active' : '' ?>" href="?<?= h($search_query.'&page='.$i) ?>"><?= $i ?></a>
                                        <?php endfor; ?>
                                        <a class="<?= $page >= $search_pages ? 'disabled' : '' ?>" aria-label="Next page" href="?<?= h($search_query.'&page='.$next) ?>"><i class="bi bi-chevron-right"></i></a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- RIGHT: current final team -->
                        <div class="data-card">
                            <div class="data-card-header">
                                <h2>
                                    <i class="bi bi-check-circle-fill"></i> &nbsp;Final Roster — <?= h($gender_label) ?>
                                    <span class="count-pill"><?= $list_total ?> player<?= $list_total === 1 ? '' : 's' ?></span>
                                </h2>
                                <?php if ($list_total > 0): ?>
                                    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                                        <a href="final_export_pdf.php?<?= h($list_query) ?>" class="btn btn-primary">
                                            <i class="bi bi-file-earmark-pdf"></i> Export PDF
                                        </a>
                                        <?php if (in_array(($me['department_code'] ?? ''), ['polytechnic', 'engineering', 'pharmacy', 'ytc_pharmacy', 'dpharm', 'management', 'architecture'], true)): ?>
                                            <a href="final_export_docx.php?<?= h($list_query) ?>" class="btn btn-secondary">
                                                <i class="bi bi-file-earmark-word"></i> Export Word
                                            </a>
                                        <?php endif; ?>
                                        <a href="final_export_xlsx.php?<?= h($list_query) ?>" class="btn btn-secondary">
                                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($list_total === 0): ?>
                                <div class="empty-row">
                                    <i class="bi bi-person-x"></i>
                                    No players confirmed for this team yet.<br>
                                    <span style="font-size:.82rem">Add students or import from a provisional list.</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($list_rows as $r): ?>
                                    <div class="list-row">
                                        <div class="student-avatar" style="width:34px;height:34px;font-size:.72rem">
                                            <?php if (!empty($r['photo_path']) && is_file(__DIR__ . '/../' . $r['photo_path'])): ?>
                                                <img src="<?= h(url($r['photo_path'])) ?>" alt="">
                                            <?php else: ?>
                                                <?= h(initials($r['full_name'])) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="info">
                                            <div class="name"><?= h($r['full_name']) ?></div>
                                            <div class="meta">
                                                <?php if ($r['sport_1']): ?><span class="sport-tag"><?= h($r['sport_1']) ?></span><?php endif; ?>
                                                <?php if ($r['sport_2']): ?><span class="sport-tag"><?= h($r['sport_2']) ?></span><?php endif; ?>
                                            </div>
                                        </div>
                                        <form method="post" action="final_remove.php" onsubmit="return confirm('Remove <?= h(addslashes($r['full_name'])) ?> from final team?');" style="margin:0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="entry_id" value="<?= (int)$r['entry_id'] ?>">
                                            <input type="hidden" name="game" value="<?= h($game) ?>">
                                            <input type="hidden" name="event" value="<?= h($event) ?>">
                                            <input type="hidden" name="ay" value="<?= h($ay) ?>">
                                            <input type="hidden" name="gender" value="<?= h($gender) ?>">
                                            <button type="submit" class="icon-remove" title="Remove" aria-label="Remove <?= h($r['full_name']) ?> from final team">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($lpages > 1): ?>
                                    <div class="pagination">
                                        <div class="info">Page <?= $lpage ?> of <?= $lpages ?> · <?= $list_total ?> total</div>
                                        <div class="pages">
                                            <?php
                                            $lbase_q = http_build_query(array_filter(['game'=>$game,'event'=>$event,'ay'=>$ay,'gender'=>$gender]));
                                            $lprev = max(1, $lpage - 1);
                                            $lnext = min($lpages, $lpage + 1);
                                            ?>
                                            <a class="<?= $lpage <= 1 ? 'disabled' : '' ?>" aria-label="Previous page" href="?<?= h($lbase_q.'&lpage='.$lprev) ?>"><i class="bi bi-chevron-left"></i></a>
                                            <?php for ($i = 1; $i <= $lpages; $i++): ?>
                                                <a class="<?= $i === $lpage ? 'active' : '' ?>" href="?<?= h($lbase_q.'&lpage='.$i) ?>"><?= $i ?></a>
                                            <?php endfor; ?>
                                            <a class="<?= $lpage >= $lpages ? 'disabled' : '' ?>" aria-label="Next page" href="?<?= h($lbase_q.'&lpage='.$lnext) ?>"><i class="bi bi-chevron-right"></i></a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="two-col" style="grid-template-columns: 1.2fr 1fr;">
                        <div class="data-card">
                            <div class="data-card-header">
                                <h2><i class="bi bi-folder2-open"></i> &nbsp;Open or create a final list</h2>
                            </div>
                            <form method="get" action="final_list.php" style="padding:1.25rem">
                                <div class="form-group" style="margin-bottom:1rem">
                                    <label for="gender">Gender</label>
                                    <select id="gender" name="gender" required style="padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.9rem;background:var(--white);width:100%">
                                        <option value="">— Select —</option>
                                        <?php foreach (gender_list_options() as $gVal => $gLabel): ?>
                                            <option value="<?= h($gVal) ?>" <?= $gender === $gVal ? 'selected' : '' ?>><?= h($gLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div style="font-size:.78rem;color:var(--medium-gray);margin-top:.4rem">
                                        Men and Women get separate final teams, even for the same game/event.
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:1rem">
                                    <label for="game">Game</label>
                                    <?php if (!empty($game_options)): ?>
                                        <select id="game" name="game" style="padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.9rem;background:var(--white);width:100%">
                                            <option value="">— Select a game —</option>
                                            <?php foreach ($game_options as $gname): ?>
                                                <option value="<?= h($gname) ?>" <?= $game === $gname ? 'selected' : '' ?>><?= h($gname) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div style="font-size:.78rem;color:var(--medium-gray);margin-top:.4rem">
                                            Games for your department. Not listed? Type it below.
                                        </div>
                                    <?php endif; ?>
                                    <input type="text" name="new_game" placeholder="<?= !empty($game_options) ? 'Or type another game name' : 'Game name (e.g. Cricket, Kho-Kho)' ?>" style="margin-top:.5rem;padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.9rem;background:var(--white);width:100%">
                                </div>
                                <div class="form-group" style="margin-bottom:1rem">
                                    <label for="event">Event</label>
                                    <select id="event" name="event" required style="padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.9rem;background:var(--white);width:100%">
                                        <option value="">— Select —</option>
                                        <?php foreach (['Zonal', 'Interzonal'] as $evOpt): ?>
                                            <option value="<?= h($evOpt) ?>" <?= $event === $evOpt ? 'selected' : '' ?>><?= h($evOpt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom:1rem">
                                    <label for="ay">Academic year</label>
                                    <select id="ay" name="ay" style="padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.9rem;background:var(--white);width:100%">
                                        <option value="">— Any —</option>
                                        <?php foreach (academic_year_options() as $y): ?>
                                            <option value="<?= h($y) ?>" <?= $y === current_academic_year() ? 'selected' : '' ?>><?= h($y) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-arrow-right-circle"></i> Open list
                                </button>
                            </form>
                        </div>

                        <div class="data-card">
                            <div class="data-card-header">
                                <h2><i class="bi bi-collection"></i> &nbsp;Saved Final Lists</h2>
                                <?php if ($saved_lists): ?>
                                    <span class="count-pill"><?= count($saved_lists) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!$saved_lists): ?>
                                <div class="empty-row">
                                    <i class="bi bi-collection"></i>
                                    No final teams saved yet.
                                </div>
                            <?php else: ?>
                                <?php foreach ($saved_lists as $sl): ?>
                                    <?php
                                        $sl_gender = $sl['gender'] ?? '';
                                        $sl_gender_label = gender_list_options()[$sl_gender] ?? 'Unspecified';
                                        $open_url = 'final_list.php?' . http_build_query([
                                            'game'   => $sl['game_name'],
                                            'event'  => $sl['event_label'],
                                            'ay'     => $sl['academic_year'] ?? '',
                                            'gender' => $sl_gender,
                                        ]);
                                        $count = (int)$sl['player_count'];
                                    ?>
                                    <div class="list-row">
                                        <div class="info">
                                            <div class="name"><?= h($sl['game_name']) ?> <span class="count-pill" style="background:rgba(26,54,93,.1);color:var(--primary-navy)"><?= h($sl_gender_label) ?></span></div>
                                            <div class="meta">
                                                <?= h($sl['event_label']) ?>
                                                · <?= h($sl['academic_year'] ?? '— Any —') ?>
                                                <span class="count-pill"><?= $count ?> player<?= $count === 1 ? '' : 's' ?></span>
                                            </div>
                                        </div>
                                        <a class="btn btn-secondary" style="padding:.3rem .65rem;font-size:.78rem" href="<?= h($open_url) ?>" title="Edit this team">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </a>
                                        <form method="post" action="final_list_delete.php"
                                              onsubmit="return confirm('Delete this entire final team (<?= $count ?> player<?= $count === 1 ? '' : 's' ?>)? This cannot be undone.');"
                                              style="margin:0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="game" value="<?= h($sl['game_name']) ?>">
                                            <input type="hidden" name="event" value="<?= h($sl['event_label']) ?>">
                                            <input type="hidden" name="ay" value="<?= h($sl['academic_year'] ?? '') ?>">
                                            <input type="hidden" name="gender" value="<?= h($sl_gender) ?>">
                                            <button type="submit" class="icon-remove" title="Delete final team" aria-label="Delete final team — <?= h($sl['game_name']) ?> <?= h($sl_gender_label) ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var form = document.getElementById('bulkAddForm');
        if (!form) return;
        var selectAll = document.getElementById('selectAllStudents');
        var checks    = Array.prototype.slice.call(form.querySelectorAll('.student-check'));
        var countEl   = document.getElementById('selectedCount');
        var addBtn    = document.getElementById('addSelectedBtn');

        function refresh() {
            var n = checks.filter(function (c) { return c.checked; }).length;
            countEl.textContent = n + (n === 1 ? ' selected' : ' selected');
            addBtn.disabled = n === 0;
            if (selectAll) selectAll.checked = n > 0 && n === checks.length;
        }

        checks.forEach(function (c) { c.addEventListener('change', refresh); });
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checks.forEach(function (c) { c.checked = selectAll.checked; });
                refresh();
            });
        }
        refresh();
    })();
    </script>
</body>
</html>
