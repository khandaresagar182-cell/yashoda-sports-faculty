<?php
/**
 * Provisional Player List.
 *
 * GET params:
 *   game    string  the game name (e.g. "Cricket")
 *   event   string  the event label (e.g. "Zonal 2025-26")
 *   ay      string  the academic year (e.g. "2025-26")
 *   q       string  optional search filter for the add-pane
 *   page    int     pagination for the add-pane
 *
 * When game + event + ay are all set, the right-pane "Current list" is
 * rendered alongside the left-pane "Add students" search. When any is
 * missing, the page shows the "Open or create a list" picker card only.
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

// ---- read & sanitize input ----
$game     = trim((string)($_GET['game'] ?? ''));
$new_game = trim((string)($_GET['new_game'] ?? ''));
if ($game === '' && $new_game !== '') {
    $game = $new_game;
}
// Provisional lists no longer carry a user-entered event label — a
// provisional shortlist is just "candidates for <game> <gender> <year>".
// A fixed sentinel keeps the (game, event_label, ay, gender) list identity
// intact for storage/exports. An incoming ?event= is still honoured so the
// "Saved lists" panel can re-open older lists that had a real label.
const PROVISIONAL_EVENT = 'Provisional';
$event    = trim((string)($_GET['event'] ?? ''));
if ($event === '') $event = PROVISIONAL_EVENT;
$ay       = trim((string)($_GET['ay'] ?? ''));
$gender   = trim((string)($_GET['gender'] ?? ''));
if (!array_key_exists($gender, gender_list_options())) $gender = '';
$q        = trim((string)($_GET['q'] ?? ''));
$page     = max(1, (int)($_GET['page'] ?? 1));
$per     = 8;
$has_list = ($game !== '' && $gender !== '');

// ---- distinct game names for the picker dropdown (scoped) ----
[$gscope, $gp, $gt] = scope_sql_department('s');
// Hide wizard drafts (students who registered but haven't hit Final Submit).
$visible = faculty_visible_student_filter('s');
$gscope .= $visible[0];
$gp      = array_merge($gp, $visible[1]);
$gt     .= $visible[2];
$existing_games = db_select(
    "SELECT DISTINCT pe.game_name
       FROM provisional_entries pe
       JOIN students s ON s.id = pe.student_id
      WHERE 1=1 $gscope
      ORDER BY pe.game_name",
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

// ---- distinct saved lists (game + event + AY + gender) with player count, for the right-side panel ----
$saved_lists = db_select(
    "SELECT pe.game_name, pe.event_label, pe.academic_year, pe.gender,
            COUNT(*) AS player_count,
            MAX(pe.created_at) AS last_added
       FROM provisional_entries pe
       JOIN students s ON s.id = pe.student_id
      WHERE 1=1 $gscope
      GROUP BY pe.game_name, pe.event_label, pe.academic_year, pe.gender
      ORDER BY last_added DESC",
    $gp, $gt
);

// ---- existing provisional events for the active game (for the event-label hint) ----
$existing_events = [];
if ($game !== '') {
    $rows = db_select(
        "SELECT DISTINCT pe.event_label, pe.academic_year
           FROM provisional_entries pe
           JOIN students s ON s.id = pe.student_id
          WHERE pe.game_name = ? $gscope
          ORDER BY pe.event_label",
        array_merge([$game], $gp), 's' . $gt
    );
    foreach ($rows as $r) {
        $existing_events[] = $r;
    }
}

// ---- current list rows (right pane) ----
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

    $list_where_sql = "pe.game_name = ?
            AND pe.event_label = ?
            AND pe.academic_year <=> ?
            AND pe.gender <=> ?
            $lscope";
    $list_where_params = array_merge([$game, $event, $ay === '' ? null : $ay, $gender], $lp);
    $list_where_types  = 'ssss' . $lt;

    $list_total = (int)(db_one(
        "SELECT COUNT(*) AS n
           FROM provisional_entries pe
           JOIN students s ON s.id = pe.student_id
          WHERE $list_where_sql",
        $list_where_params, $list_where_types
    )['n'] ?? 0);
    $lpages = max(1, (int)ceil($list_total / $lper));
    if ($lpage > $lpages) $lpage = $lpages;
    $loffset = ($lpage - 1) * $lper;

    $list_rows = db_select(
        "SELECT pe.id AS entry_id, pe.notes, pe.created_at,
                s.id, s.enrollment_no, s.full_name,
                s.sport_1, s.sport_2, s.gender, s.program,
                s.academic_year, s.study_year, s.photo_path,
                d.name AS dept_name
           FROM provisional_entries pe
           JOIN students s    ON s.id = pe.student_id
           JOIN departments d ON d.id = s.department_id
          WHERE $list_where_sql
          ORDER BY pe.created_at ASC, s.enrollment_no ASC
          LIMIT $lper OFFSET $loffset",
        $list_where_params, $list_where_types
    );
}

// ---- available students to add (left pane) ----
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

    // Only students matching the list's gender can be added to it.
    $where    = "1=1 $sscope AND s.gender = ?";
    $params   = array_merge($sp, [$gender]);
    $types    = $st . 's';

    // Restrict the add-pane to students who actually picked THIS game in
    // their Step 3 sports selection. Only applies when the list's game is a
    // catalogued game for this department — a free-text custom game has no
    // picker data, so it keeps the old "show everyone" behaviour.
    $picker_game_code = resolve_department_game_code(effective_department_id(), $game);
    $game_scoped = ($picker_game_code !== null);
    if ($game_scoped) {
        $game_exists_sql = ' AND EXISTS (SELECT 1 FROM student_selected_games ssg
                                          WHERE ssg.student_id = s.id AND ssg.game_code = ?) ';
        $where   .= $game_exists_sql;
        $params[] = $picker_game_code;
        $types   .= 's';
    }

    if ($q !== '') {
        $where .= ' AND (s.enrollment_no LIKE ? OR s.full_name LIKE ?) ';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $types   .= 'ss';
    }
    // Exclude students already on the active (game, event, ay, gender) list
    $where .= ' AND s.id NOT IN (
        SELECT pe2.student_id
          FROM provisional_entries pe2
         WHERE pe2.game_name = ?
           AND pe2.event_label = ?
           AND pe2.academic_year <=> ?
           AND pe2.gender <=> ?
    ) ';
    $params[] = $game;
    $params[] = $event;
    $params[] = $ay === '' ? null : $ay;
    $params[] = $gender;
    $types   .= 'ssss';

    // Total students of this gender in department (used for the empty-state hint)
    $dept_total = (int)(db_one(
        "SELECT COUNT(*) AS n FROM students s WHERE 1=1 $sscope AND s.gender = ?",
        array_merge($sp, [$gender]), $st . 's'
    )['n'] ?? 0);

    // Total matching students (this gender + game + search query) before list exclusion
    $pre_where = "1=1 $sscope AND s.gender = ?";
    $pre_params = array_merge($sp, [$gender]);
    $pre_types = $st . 's';
    if ($game_scoped) {
        $pre_where   .= $game_exists_sql;
        $pre_params[] = $picker_game_code;
        $pre_types   .= 's';
    }
    if ($q !== '') {
        $pre_where .= ' AND (s.enrollment_no LIKE ? OR s.full_name LIKE ?) ';
        $pre_params[] = '%' . $q . '%';
        $pre_params[] = '%' . $q . '%';
        $pre_types   .= 'ss';
    }
    $search_pre_exclude_total = (int)(db_one(
        "SELECT COUNT(*) AS n FROM students s WHERE $pre_where", $pre_params, $pre_types
    )['n'] ?? 0);

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

// ---- base URL builder for pagination / "Change" links ----
$list_query = http_build_query(array_filter([
    'game'   => $game,
    'event'  => $event,
    'ay'     => $ay,
    'gender' => $gender,
]));
$search_query = http_build_query(array_filter([
    'game'   => $game,
    'event'  => $event,
    'ay'     => $ay,
    'gender' => $gender,
    'q'      => $q,
]));
$gender_label = gender_list_options()[$gender] ?? '';

$flash_ok  = flash_get('prov_saved');
$flash_err = flash_get('prov_error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provisional Players | Sports Portal</title>
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
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .alert-banner.info{background:rgba(13,110,253,.1);color:#052c65;border:1px solid rgba(13,110,253,.2)}

        /* New: list-summary strip + two-pane grid + remove button */
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
        .list-row .info .notes{font-size:.78rem;color:var(--dark-gray);background:var(--off-white);border-left:2px solid var(--accent-gold);padding:.15rem .5rem;margin-top:.3rem;border-radius:0 4px 4px 0}
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
            </div>
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
                <a href="provisional_list.php" class="active"><i class="bi bi-clipboard-check"></i> <span>Provisional Players</span></a>
                <a href="final_list.php"><i class="bi bi-check-all"></i> <span>Final Teams</span></a>
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
                    <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">Provisional Players</h2>
                </div>
            </header>

            <div class="content-body">
                <div class="page-header">
                    <h1>Provisional Player Lists</h1>
                    <p>Build a shortlist of students being considered for an upcoming game or event. Lists are saved and re-exportable — not deleted after the event.</p>
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
                            <?php if ($event !== PROVISIONAL_EVENT): ?>
                            <span><span class="label">Event</span><span class="value"><?= h($event) ?></span></span>
                            <?php endif; ?>
                            <span><span class="label">AY</span><span class="value"><?= h($ay !== '' ? $ay : '—') ?></span></span>
                            <span><span class="label">Gender</span><span class="value"><?= h($gender_label) ?></span></span>
                        </div>
                        <a href="provisional_list.php" class="btn btn-secondary"><i class="bi bi-arrow-left-right"></i> Change list</a>
                    </div>

                    <div class="two-col">
                        <!-- LEFT: add students -->
                        <div class="data-card">
                            <div class="data-card-header">
                                <h2><i class="bi bi-search"></i> &nbsp;Add <?= h($gender_label) ?> students to this list</h2>
                            </div>
                            <form method="get" action="provisional_list.php" class="search-form" style="margin:0;border-radius:0;border-left:none;border-right:none">
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
                                    <a href="provisional_list.php?<?= h($list_query) ?>" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Reset</a>
                                <?php endif; ?>
                            </form>

                            <?php if (!$search_rows): ?>
                                <div class="empty-row">
                                    <i class="bi bi-inbox"></i>
                                    <?php if ($dept_total === 0): ?>
                                        No <?= h($gender_label) ?> students registered in this department yet.
                                    <?php elseif ($search_pre_exclude_total === 0 && $game_scoped && $q === ''): ?>
                                        No <?= h($gender_label) ?> students have selected <strong><?= h($game) ?></strong> in their profile yet.
                                    <?php elseif ($search_pre_exclude_total === 0): ?>
                                        No students match your search query.
                                    <?php else: ?>
                                        All matching students are already on this list.
                                    <?php endif; ?>
                                    <?php if ($dept_total === 0 || $search_pre_exclude_total === 0): ?>
                                        <br><br>
                                        <a href="../student-profile.php?new=1" class="btn btn-secondary" style="font-size:.85rem">
                                            <i class="bi bi-person-plus"></i> Student not found? Add new
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Enrollment</th>
                                            <th>Sports</th>
                                            <th>Year</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($search_rows as $r): ?>
                                        <tr>
                                            <td>
                                                <div class="student-cell">
                                                    <div class="student-avatar">
                                                        <?php if (!empty($r['photo_path']) && is_file(__DIR__ . '/../' . $r['photo_path'])): ?>
                                                            <img src="<?= h(url($r['photo_path'])) ?>" alt="">
                                                        <?php else: ?>
                                                            <?= h(initials($r['full_name'])) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="student-name"><?= h($r['full_name']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= h($r['enrollment_no']) ?></td>
                                            <td>
                                                <?php if ($r['sport_1']): ?><span class="sport-tag"><?= h($r['sport_1']) ?></span><?php endif; ?>
                                                <?php if ($r['sport_2']): ?><span class="sport-tag"><?= h($r['sport_2']) ?></span><?php endif; ?>
                                            </td>
                                            <td><?= h($r['academic_year']) ?> · <?= h($r['study_year']) ?></td>
                                            <td>
                                                <form method="post" action="provisional_save.php" style="display:inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>">
                                                    <input type="hidden" name="game_name" value="<?= h($game) ?>">
                                                    <input type="hidden" name="event_label" value="<?= h($event) ?>">
                                                    <input type="hidden" name="academic_year" value="<?= h($ay) ?>">
                                                    <input type="hidden" name="gender" value="<?= h($gender) ?>">
                                                    <button type="submit" class="btn btn-primary" style="padding:.3rem .6rem;font-size:.78rem">
                                                        <i class="bi bi-plus-circle"></i> Add
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
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

                        <!-- RIGHT: current list -->
                        <div class="data-card">
                            <div class="data-card-header">
                                <h2>
                                    <i class="bi bi-clipboard-check"></i> &nbsp;Current list — <?= h($gender_label) ?>
                                    <span class="count-pill"><?= $list_total ?> player<?= $list_total === 1 ? '' : 's' ?></span>
                                </h2>
                                <?php if ($list_total > 0): ?>
                                    <a href="provisional_export.php?<?= h($list_query) ?>" class="btn btn-primary">
                                        <i class="bi bi-file-earmark-excel"></i> Export to Excel
                                    </a>
                                <?php endif; ?>
                            </div>
                            <?php if ($list_total === 0): ?>
                                <div class="empty-row">
                                    <i class="bi bi-clipboard"></i>
                                    No students on this list yet.<br>
                                    <span style="font-size:.82rem">Use the search on the left to add some.</span>
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
                                                <?= h($r['enrollment_no']) ?>
                                                <?php if ($r['sport_1']): ?> · <span class="sport-tag"><?= h($r['sport_1']) ?></span><?php endif; ?>
                                                <?php if ($r['sport_2']): ?><span class="sport-tag"><?= h($r['sport_2']) ?></span><?php endif; ?>
                                            </div>
                                            <?php if (!empty($r['notes'])): ?>
                                                <div class="notes"><?= h($r['notes']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <form method="post" action="provisional_remove.php" onsubmit="return confirm('Remove <?= h(addslashes($r['full_name'])) ?> from this list?');" style="margin:0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="entry_id" value="<?= (int)$r['entry_id'] ?>">
                                            <input type="hidden" name="game" value="<?= h($game) ?>">
                                            <input type="hidden" name="event" value="<?= h($event) ?>">
                                            <input type="hidden" name="ay" value="<?= h($ay) ?>">
                                            <input type="hidden" name="gender" value="<?= h($gender) ?>">
                                            <button type="submit" class="icon-remove" title="Remove" aria-label="Remove <?= h($r['full_name']) ?> from this list">
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
                        <!-- LEFT: Picker card -->
                        <div class="data-card">
                            <div class="data-card-header">
                                <h2><i class="bi bi-folder2-open"></i> &nbsp;Open or create a list</h2>
                            </div>
                            <form method="get" action="provisional_list.php" style="padding:1.25rem">
                                <div class="form-group" style="margin-bottom:1rem">
                                    <label for="gender">Gender</label>
                                    <select id="gender" name="gender" required style="padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.9rem;background:var(--white);width:100%">
                                        <option value="">— Select —</option>
                                        <?php foreach (gender_list_options() as $gVal => $gLabel): ?>
                                            <option value="<?= h($gVal) ?>" <?= $gender === $gVal ? 'selected' : '' ?>><?= h($gLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div style="font-size:.78rem;color:var(--medium-gray);margin-top:.4rem">
                                        Men and Women get separate lists, even for the same game/event.
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
                                    <input type="text" name="new_game" placeholder="<?= !empty($game_options) ? 'Or type another game name' : 'Game name (e.g. Cricket, Kho-Kho, Kabaddi)' ?>" style="margin-top:.5rem;padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.9rem;background:var(--white);width:100%">
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

                        <!-- RIGHT: Saved lists panel -->
                        <div class="data-card">
                            <div class="data-card-header">
                                <h2>
                                    <i class="bi bi-collection"></i> &nbsp;Saved lists
                                    <?php if ($saved_lists): ?>
                                        <span class="count-pill"><?= count($saved_lists) ?></span>
                                    <?php endif; ?>
                                </h2>
                            </div>
                            <?php if (!$saved_lists): ?>
                                <div class="empty-row">
                                    <i class="bi bi-collection"></i>
                                    No saved lists yet.<br>
                                    <span style="font-size:.82rem">Use the form on the left to open a list and add students.</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($saved_lists as $sl): ?>
                                    <?php
                                        $sl_gender = $sl['gender'] ?? '';
                                        $sl_gender_label = gender_list_options()[$sl_gender] ?? 'Unspecified';
                                        $open_url = 'provisional_list.php?' . http_build_query([
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
                                                <?php if ($sl['event_label'] !== PROVISIONAL_EVENT): ?><?= h($sl['event_label']) ?> · <?php endif; ?>
                                                <?= h($sl['academic_year'] ?? '— Any —') ?>
                                                <span class="count-pill"><?= $count ?> player<?= $count === 1 ? '' : 's' ?></span>
                                            </div>
                                        </div>
                                        <a class="btn btn-secondary" style="padding:.3rem .65rem;font-size:.78rem" href="<?= h($open_url) ?>" title="Open &amp; edit this list">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </a>
                                        <form method="post" action="provisional_list_delete.php"
                                              onsubmit="return confirm('Delete this entire list (<?= $count ?> player<?= $count === 1 ? '' : 's' ?>)? This cannot be undone.');"
                                              style="margin:0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="game" value="<?= h($sl['game_name']) ?>">
                                            <input type="hidden" name="event" value="<?= h($sl['event_label']) ?>">
                                            <input type="hidden" name="ay" value="<?= h($sl['academic_year'] ?? '') ?>">
                                            <input type="hidden" name="gender" value="<?= h($sl_gender) ?>">
                                            <button type="submit" class="icon-remove" title="Delete list" aria-label="Delete list — <?= h($sl['game_name']) ?> <?= h($sl_gender_label) ?>">
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
</body>
</html>
