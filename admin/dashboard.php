<?php
/**
 * Dashboard. Department-scoped stats for FACULTY; global stats for SUPER_ADMIN.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_department();

$me    = current_faculty();
$dept  = $me['department_id'];
$scope = scope_sql_department('s');
$visible = faculty_visible_student_filter('s');
// Merge the visibility filter (hide wizard drafts) into the dept scope so
// every read query against `students` below excludes un-submitted rows.
$scope[0] .= $visible[0];
$scope[1] = array_merge($scope[1], $visible[1]);
$scope[2] .= $visible[2];
[$where, $p, $t] = $scope;

// Student count (scoped)
$student_count = (int)(db_one(
    "SELECT COUNT(*) AS n FROM students s WHERE 1=1 $where", $p, $t
)['n'] ?? 0);

// Active faculty count
$faculty_count = (int)(db_one(
    "SELECT COUNT(*) AS n FROM faculty WHERE role = 'FACULTY' AND is_active = 1"
)['n'] ?? 0);

// Achievement count (scoped via student)
$ach_count = (int)(db_one(
    "SELECT COUNT(*) AS n FROM achievements a
       JOIN students s ON s.id = a.student_id
      WHERE 1=1 $where", $p, $t
)['n'] ?? 0);

// Notices (global, just count)
$notice_count = (int)(db_one(
    "SELECT COUNT(*) AS n FROM notices WHERE is_published = 1"
)['n'] ?? 0);

// Recently added students (last 8)
$recent = db_select(
    "SELECT s.id, s.enrollment_no, s.full_name, s.sport_1, s.academic_year, s.study_year, d.name AS dept_name
       FROM students s
       JOIN departments d ON d.id = s.department_id
      WHERE 1=1 $where
      ORDER BY s.created_at DESC
      LIMIT 8", $p, $t
);

/* ============== Players by Game (faculty dashboard filter) ============== */
$pbg_dept_id  = effective_department_id();
$pbg_catalog  = [];   // [ [game_code, display_name, display_order] ]
$pbg_picker   = false; // does this dept have a game catalog?
$pbg_filter   = null;  // null = no filter, else [gender_token, game_code]
$pbg_rows     = [];   // filtered students
$pbg_picker_dept_ids = load_picker_dept_ids();
$pbg_games_by_student = [];

if ($pbg_dept_id !== null) {
    $pbg_catalog = db_select(
        'SELECT game_code, display_name, display_order
           FROM dept_game_catalog
          WHERE department_id = ? AND is_active = 1
          ORDER BY display_order, display_name',
        [$pbg_dept_id], 'i'
    );
    $pbg_picker = !empty($pbg_catalog);

    // Whitelist + normalise incoming filter values.
    $pbg_gender_in = (string)($_GET['gender'] ?? '');
    $pbg_game_in   = (string)($_GET['game']   ?? '');
    $pbg_gender_token = null;          // 'men' | 'women'
    $pbg_gender_value = null;          // 'Male' | 'Female'  (what's stored on students.gender)
    if ($pbg_gender_in === 'men')       { $pbg_gender_token = 'men';   $pbg_gender_value = 'Male';   }
    elseif ($pbg_gender_in === 'women') { $pbg_gender_token = 'women'; $pbg_gender_value = 'Female'; }
    $pbg_game_code = null;
    if ($pbg_game_in !== '') {
        foreach ($pbg_catalog as $c) {
            if ((string)$c['game_code'] === $pbg_game_in) { $pbg_game_code = $pbg_game_in; break; }
        }
    }
    if ($pbg_gender_token !== null && $pbg_game_code !== null) {
        $pbg_filter = [$pbg_gender_token, $pbg_game_code];
        $sql = "SELECT s.*, d.name AS dept_name, d.code AS department_code
                  FROM students s
                  JOIN departments d ON d.id = s.department_id
                 WHERE s.department_id = ?
                   AND s.gender = ?
                   AND s.form_submitted_at IS NOT NULL
                   AND EXISTS (
                       SELECT 1 FROM student_selected_games ssg
                        WHERE ssg.student_id = s.id AND ssg.game_code = ?
                   )
                 ORDER BY s.full_name";
        $pbg_rows = db_select($sql, [$pbg_dept_id, $pbg_gender_value, $pbg_game_code], 'iss');
        if (!empty($pbg_rows)) {
            $pbg_games_by_student = load_games_by_student(
                array_map(static fn($r) => (int)$r['id'], $pbg_rows)
            );
        }
    }
}

$flash = flash_get('dashboard_info');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Sports Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">
    <style>
        :root { --primary-navy:#1a365d; --primary-navy-dark:#0f2744; --primary-navy-light:#2c5282;
                --accent-gold:#c9a227; --accent-gold-light:#d4b84a; --accent-maroon:#722f37;
                --white:#fff; --off-white:#f8f9fa; --light-gray:#e9ecef; --medium-gray:#6c757d;
                --dark-gray:#343a40; --text-dark:#212529;
                --font-primary:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
                --sidebar-width:260px; --transition-smooth:all .3s ease-in-out; }
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%;overflow:hidden}
        body{font-family:var(--font-primary);color:var(--text-dark);background:var(--off-white);line-height:1.6}
        .app-wrapper{display:flex;height:100vh}

        /* SIDEBAR */
        .sidebar{width:var(--sidebar-width);background:linear-gradient(180deg,var(--primary-navy-dark),var(--primary-navy));color:var(--white);display:flex;flex-direction:column;flex-shrink:0;overflow:hidden;z-index:100}
        .sidebar-brand{padding:1.25rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:.75rem}
        .sidebar-brand img{width:42px;height:42px;border-radius:8px;object-fit:contain;background:rgba(255,255,255,.1);padding:3px;flex-shrink:0}
        .sidebar-brand-text h2{font-size:.85rem;font-weight:700;color:var(--white);margin:0;white-space:nowrap}
        .sidebar-brand-text span{font-size:.7rem;color:rgba(255,255,255,.5)}
        .sidebar-nav{flex:1;padding:1rem 0;overflow-y:auto}
        .sidebar-nav-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.35);padding:.75rem 1.5rem .4rem}
        .sidebar-nav a{display:flex;align-items:center;gap:.75rem;padding:.7rem 1.5rem;color:rgba(255,255,255,.65);font-size:.88rem;font-weight:500;text-decoration:none;transition:var(--transition-smooth);border-left:3px solid transparent}
        .sidebar-nav a:hover{color:var(--white);background:rgba(255,255,255,.06);border-left-color:rgba(201,162,39,.4)}
        .sidebar-nav a.active{color:var(--white);background:rgba(201,162,39,.12);border-left-color:var(--accent-gold)}
        .sidebar-nav a.active i{color:var(--accent-gold)}
        .sidebar-nav a i{font-size:1.15rem;width:22px;text-align:center;flex-shrink:0}
        .sidebar-nav a .nav-badge{margin-left:auto;background:var(--accent-gold);color:var(--primary-navy-dark);font-size:.65rem;font-weight:700;padding:.15rem .5rem;border-radius:10px}
        .sidebar-footer{padding:1rem 1.25rem;border-top:1px solid rgba(255,255,255,.08)}
        .sidebar-user{display:flex;align-items:center;gap:.75rem}
        .sidebar-user-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent-gold),var(--accent-maroon));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:var(--white);flex-shrink:0}
        .sidebar-user-info h4{font-size:.82rem;font-weight:600;color:var(--white);margin:0}
        .sidebar-user-info span{font-size:.7rem;color:rgba(255,255,255,.5)}
        .btn-logout{margin-left:auto;background:0 0;border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.6);padding:.35rem .5rem;border-radius:6px;cursor:pointer;transition:var(--transition-smooth);font-size:.85rem;text-decoration:none}
        .btn-logout:hover{background:rgba(220,53,69,.2);border-color:rgba(220,53,69,.4);color:#ff8a8a}

        /* MAIN */
        .main-content{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
        .top-bar{background:var(--white);border-bottom:1px solid var(--light-gray);padding:.75rem 2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
        .top-bar-left{display:flex;align-items:center;gap:1rem}
        .sidebar-toggle{background:0 0;border:1px solid var(--light-gray);color:var(--medium-gray);padding:.4rem .55rem;border-radius:6px;cursor:pointer;font-size:1.1rem}
        .sidebar-toggle:hover{background:var(--off-white);color:var(--primary-navy)}
        .breadcrumb-nav{display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:var(--medium-gray)}
        .breadcrumb-nav .current{color:var(--primary-navy);font-weight:600}
        .icon-btn{background:0 0;border:1px solid var(--light-gray);color:var(--medium-gray);width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:var(--transition-smooth)}
        .icon-btn:hover{background:var(--off-white);color:var(--primary-navy)}
        .content-body{flex:1;overflow-y:auto;padding:2rem}

        .page-header{margin-bottom:1.5rem;padding-bottom:.85rem;border-bottom:2px solid var(--primary-navy)}
        .page-header h1{font-size:1.25rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:1.5px}
        .page-header p{color:var(--medium-gray);font-size:.85rem;margin-top:.4rem;text-transform:uppercase;letter-spacing:.6px}

        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;margin-bottom:1.75rem}
        .stat-card{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;padding:1.25rem;display:flex;align-items:center;gap:1rem;transition:var(--transition-smooth)}
        .stat-card:hover{box-shadow:0 6px 18px rgba(26,54,93,.08);transform:translateY(-2px)}
        .stat-icon{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
        .stat-icon.students{background:rgba(26,54,93,.1);color:var(--primary-navy)}
        .stat-icon.ach{background:rgba(201,162,39,.15);color:var(--accent-gold)}
        .stat-icon.notice{background:rgba(114,47,55,.12);color:var(--accent-maroon)}
        .stat-icon.fac{background:rgba(44,82,130,.1);color:var(--primary-navy-light)}
        .stat-info h3{font-size:1.4rem;font-weight:700;color:var(--primary-navy);margin:0;line-height:1.1}
        .stat-info p{font-size:.78rem;color:var(--medium-gray);margin:0;text-transform:uppercase;letter-spacing:.4px}

        .data-card{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;overflow:hidden}
        .data-card-header{padding:.85rem 1.5rem;border-bottom:1.5px solid var(--primary-navy);background:var(--off-white);display:flex;align-items:center;justify-content:space-between;text-transform:uppercase;letter-spacing:1.2px}
        .data-card-header h2{font-size:.78rem;font-weight:700;color:var(--primary-navy);margin:0;display:inline-flex;align-items:center;gap:.5rem}
        .data-card-header .action-link{font-size:.7rem;font-weight:600;color:var(--medium-gray);text-transform:uppercase;letter-spacing:.8px;text-decoration:none}
        .data-card-header .action-link:hover{color:var(--primary-navy)}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.7rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.75rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);color:var(--text-dark)}
        .data-table tr:last-child td{border-bottom:none}
        .student-cell{display:flex;align-items:center;gap:.7rem}
        .student-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-light));color:var(--white);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;flex-shrink:0}
        .student-name{font-weight:600;color:var(--primary-navy)}
        .student-meta{font-size:.75rem;color:var(--medium-gray)}
        .sport-tag{display:inline-block;background:rgba(201,162,39,.12);color:var(--accent-gold);padding:.2rem .6rem;border-radius:4px;font-size:.72rem;font-weight:600}
        .dept-tag{display:inline-block;background:rgba(26,54,93,.08);color:var(--primary-navy);padding:.2rem .6rem;border-radius:4px;font-size:.72rem;font-weight:600}
        .year-tag{font-size:.78rem;color:var(--medium-gray)}
        .action-link{color:var(--primary-navy);text-decoration:none;font-size:.85rem;display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .5rem;border-radius:4px;transition:var(--transition-smooth)}
        .action-link:hover{background:var(--off-white);color:var(--primary-navy-light)}

        .empty-row{text-align:center;color:var(--medium-gray);padding:2.5rem 1rem;font-size:.9rem}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.info{background:rgba(13,202,240,.08);color:#055160;border:1px solid rgba(13,202,240,.2)}

        /* Players by Game card — government-form style */
        .gov-filter{padding:0;background:#fff}
        .gov-filter-bar{display:flex;align-items:flex-end;gap:1.5rem;flex-wrap:wrap;padding:1.4rem 1.5rem 1.6rem;background:#fff;border-bottom:1px solid var(--light-gray)}
        .gov-field{flex:0 0 auto;min-width:230px}
        .gov-field label{display:block;font-size:.72rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:.4rem;padding-bottom:.3rem;border-bottom:1.5px solid var(--primary-navy)}
        .gov-field label .req{color:#b91c1c;margin-left:.2rem}
        .gov-field select{display:block;width:100%;padding:.7rem .8rem;font-family:inherit;font-size:1rem;font-weight:500;color:var(--text-dark);background:#fff;border:1.5px solid var(--primary-navy);border-radius:0;outline:none;cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%231a365d%22%20d%3D%22M6%208L0%200h12z%22%2F%3E%3C%2Fsvg%3E");background-repeat:no-repeat;background-position:right .85rem center;background-size:10px 7px;padding-right:2.1rem;line-height:1.3}
        .gov-field select:focus{box-shadow:inset 0 0 0 2px var(--accent-gold);border-color:var(--primary-navy-dark)}
        .gov-field select:disabled{background-color:var(--off-white);color:var(--medium-gray);border-color:var(--light-gray);cursor:not-allowed;background-image:none}
        .gov-filter-actions{display:flex;gap:.6rem;align-items:flex-end;flex:0 0 auto;padding-bottom:1px}
        .gov-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.78rem 1.4rem;font:inherit;font-size:.9rem;font-weight:700;letter-spacing:.6px;text-transform:uppercase;cursor:pointer;border:1.5px solid var(--primary-navy);border-radius:0;background:var(--primary-navy);color:#fff;text-decoration:none;transition:background .15s ease}
        .gov-btn:hover{background:var(--primary-navy-dark);border-color:var(--primary-navy-dark)}
        .gov-btn-ghost{background:#fff;color:var(--medium-gray);border-color:var(--light-gray)}
        .gov-btn-ghost:hover{background:var(--off-white);color:var(--primary-navy);border-color:var(--primary-navy)}
        .gov-section-head{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.5rem;background:var(--off-white);border-bottom:1.5px solid var(--primary-navy);text-transform:uppercase;letter-spacing:1.5px;font-size:.72rem;font-weight:700;color:var(--primary-navy)}
        .gov-section-head .left{display:inline-flex;align-items:center;gap:.5rem}
        .gov-section-head .right{font-size:.7rem;font-weight:600;color:var(--medium-gray);letter-spacing:.8px}
        .gov-section-head .right a{color:var(--medium-gray);text-decoration:none}
        .gov-section-head .right a:hover{color:var(--primary-navy)}
        .filter-result-line{padding:.85rem 1.5rem;font-size:.9rem;color:var(--medium-gray);background:#fff;border-bottom:1px solid var(--light-gray);font-weight:500}
        .filter-result-line strong{color:var(--primary-navy);font-weight:700}
        .filter-empty{padding:2.6rem 1.5rem;text-align:center;color:var(--medium-gray);font-size:.95rem}
        .filter-empty i{font-size:2.4rem;display:block;margin-bottom:.6rem;color:var(--light-gray)}
        .filter-hint{padding:1.8rem 1.5rem;text-align:center;color:var(--medium-gray);font-size:.92rem;background:#fff;font-style:italic}
        .filter-hint i{color:var(--accent-gold);font-size:1.3rem;margin-right:.4rem;font-style:normal}

        .row-action-btns{display:inline-flex;gap:.3rem}
        .row-action-btn{width:30px;height:30px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;background:var(--off-white);color:var(--primary-navy);text-decoration:none;font-size:.85rem;transition:var(--transition-smooth)}
        .row-action-btn:hover{background:var(--primary-navy);color:var(--white)}
        .row-action-btn.edit{color:var(--accent-gold)}
        .row-action-btn.edit:hover{background:var(--accent-gold);color:var(--white)}

        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .gov-field{min-width: 100%; }
            .gov-filter-actions { width: 100%; }
            .gov-btn { width: 100%; justify-content: center; }
            .data-card { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .data-table { min-width: 600px; }
        }
        @media (max-width: 576px) {
            .content-body { padding: 1rem 0.75rem; }
            .stat-grid { gap: 0.75rem; }
            .stat-card { padding: 1rem; }
            .gov-filter-bar { padding: 1rem; gap: 1rem; }
        }
    </style>
</head>
<body>
    <div class="app-wrapper">

        <aside class="sidebar">
            <div class="sidebar-brand">
                <img src="<?= h(url('images/ytc-logo.png')) ?>" alt="YTC Logo" width="42" height="42">
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
                <a href="dashboard.php" class="active">
                    <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
                </a>
                <a href="../student-search.php">
                    <i class="bi bi-search"></i> <span>Search Students</span>
                </a>
                <a href="../student-profile.php?new=1">
                    <i class="bi bi-person-plus"></i> <span>Add Student</span>
                </a>
                <a href="provisional_list.php">
                    <i class="bi bi-clipboard-check"></i> <span>Provisional Players</span>
                </a>
                <a href="final_list.php">
                    <i class="bi bi-check-all"></i> <span>Final Teams</span>
                </a>
                <a href="eligibility_archive.php">
                    <i class="bi bi-folder2-open"></i> <span>Eligibility Archive</span>
                </a>
                <div class="sidebar-nav-label">External Entries</div>
                <a href="external_entries.php">
                    <i class="bi bi-link-45deg"></i> <span>Links &amp; Entries</span>
                </a>
                <a href="external_final_team.php">
                    <i class="bi bi-people"></i> <span>External Final Team</span>
                </a>
                <a href="external_eligibility_archive.php">
                    <i class="bi bi-folder2"></i> <span>External Archive</span>
                </a>
                <div class="sidebar-nav-label">Other</div>
                <a href="jersey_dashboard.php">
                    <i class="bi bi-person-badge"></i> <span>Jersey Kit</span>
                </a>
                <a href="data_management.php">
                    <i class="bi bi-database-fill-gear"></i> <span>Data Management</span>
                </a>
                <?php if (($me['role'] ?? '') === 'SUPER_ADMIN'): ?>
                    <div class="sidebar-nav-label">Site Content</div>
                    <a href="notices_list.php">
                        <i class="bi bi-megaphone"></i> <span>Notices</span>
                    </a>
                    <a href="achievements_list.php">
                        <i class="bi bi-trophy"></i> <span>Achievements</span>
                    </a>
                    <a href="committee_manage.php">
                        <i class="bi bi-people-fill"></i> <span>Committee</span>
                    </a>
                <?php endif; ?>
                <?php if ($me['role'] === 'SUPER_ADMIN'): ?>
                    <div class="sidebar-nav-label">Admin</div>
                    <a href="faculty_manage.php">
                        <i class="bi bi-people-fill"></i> <span>Faculty Management</span>
                    </a>
                    <a href="document_requirements.php">
                        <i class="bi bi-file-earmark-ruled"></i> <span>Document Requirements</span>
                    </a>
                    <a href="sports_assign.php">
                        <i class="bi bi-trophy-fill"></i> <span>Sports Assignment</span>
                    </a>
                <?php endif; ?>
                <div class="sidebar-nav-label">Site</div>
                <a href="../index.php">
                    <i class="bi bi-globe"></i> <span>View Website</span>
                </a>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar"><?= h(initials($me['full_name'])) ?></div>
                    <div class="sidebar-user-info">
                        <h4><?= h($me['full_name']) ?></h4>
                        <span><?= h($me['department_name'] ?? $me['role']) ?></span>
                    </div>
                    <a href="logout.php?_csrf=<?= h(csrf_token()) ?>" class="btn-logout" title="Logout" aria-label="Logout">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <header class="top-bar">
                <div class="top-bar-left">
                    <div class="breadcrumb-nav">
                        <span class="current">Dashboard</span>
                    </div>
                </div>
                <div class="top-bar-right">
                    <span style="font-size:.85rem;color:var(--medium-gray)">Welcome, <strong><?= h($me['full_name']) ?></strong></span>
                </div>
            </header>

            <div class="content-body">
                <div class="page-header">
                    <h1>Welcome back, <?= h($me['full_name']) ?>!</h1>
                    <p>Here's what's happening with your sports database.</p>
                </div>

                <?php if ($flash): ?>
                    <div class="alert-banner <?= h($flash['level']) ?>" role="alert">
                        <i class="bi bi-info-circle"></i> <?= h($flash['msg']) ?>
                    </div>
                <?php endif; ?>

                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-icon students"><i class="bi bi-people-fill"></i></div>
                        <div class="stat-info">
                            <h3><?= $student_count ?></h3>
                            <p><?= $me['role'] === 'SUPER_ADMIN' ? 'Total Students' : 'Faculty Students' ?></p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon ach"><i class="bi bi-trophy-fill"></i></div>
                        <div class="stat-info">
                            <h3><?= $ach_count ?></h3>
                            <p>Achievements</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon notice"><i class="bi bi-megaphone-fill"></i></div>
                        <div class="stat-info">
                            <h3><?= $notice_count ?></h3>
                            <p>Active Notices</p>
                        </div>
                    </div>
                    <?php if ($me['role'] === 'SUPER_ADMIN'): ?>
                        <div class="stat-card">
                            <div class="stat-icon fac"><i class="bi bi-person-badge"></i></div>
                            <div class="stat-info">
                                <h3><?= $faculty_count ?></h3>
                                <p>Active Faculty</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="data-card" style="margin-bottom:1.5rem">
                    <div class="data-card-header">
                        <h2><i class="bi bi-trophy-fill"></i> Players by Game</h2>
                        <?php if ($pbg_filter !== null || $pbg_dept_id !== null): ?>
                            <a href="dashboard.php" class="action-link" style="font-size:.78rem"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($pbg_dept_id === null): ?>
                        <div class="filter-hint">
                            <i class="bi bi-info-circle"></i>
                            Select a faculty to use this filter. <a href="../faculty-select.php?change=1">Pick a faculty</a>.
                        </div>
                    <?php elseif (!$pbg_picker): ?>
                        <div class="filter-hint">
                            <i class="bi bi-info-circle"></i>
                            Your faculty uses free-text sports. <a href="../student-search.php">Use Search Students</a> to filter by sport.
                        </div>
                    <?php else: ?>
                        <form class="gov-filter" method="get" action="dashboard.php">
                            <div class="gov-section-head">
                                <span class="left"><i class="bi bi-funnel-fill"></i> Filter by Gender &amp; Game</span>
                                <?php if ($pbg_filter !== null): ?>
                                    <span class="right"><a href="dashboard.php"><i class="bi bi-arrow-counterclockwise"></i> Clear Filter</a></span>
                                <?php endif; ?>
                            </div>
                            <div class="gov-filter-bar">
                                <div class="gov-field">
                                    <label for="pbgGender">Gender <span class="req">*</span></label>
                                    <select id="pbgGender" name="gender" required>
                                        <option value="">— Select —</option>
                                        <option value="men"   <?= ($pbg_filter[0] ?? '') === 'men'   ? 'selected' : '' ?>>Men</option>
                                        <option value="women" <?= ($pbg_filter[0] ?? '') === 'women' ? 'selected' : '' ?>>Women</option>
                                    </select>
                                </div>
                                <div class="gov-field">
                                    <label for="pbgGame">Game <span class="req">*</span></label>
                                    <select id="pbgGame" name="game" required>
                                        <option value="">— Select Game —</option>
                                        <?php foreach ($pbg_catalog as $c): ?>
                                            <option value="<?= h($c['game_code']) ?>"
                                                <?= ($pbg_filter[1] ?? '') === $c['game_code'] ? 'selected' : '' ?>>
                                                <?= h($c['display_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="gov-filter-actions">
                                    <button type="submit" class="gov-btn"><i class="bi bi-search"></i> Apply</button>
                                </div>
                            </div>
                        </form>

                        <?php if ($pbg_filter === null): ?>
                            <div class="filter-hint">
                                <i class="bi bi-arrow-down-circle"></i>
                                Pick a gender and a game to see enrolled students.
                            </div>
                        <?php else:
                            $gender_label = $pbg_filter[0] === 'men' ? 'men' : 'women';
                            $game_name = '';
                            foreach ($pbg_catalog as $c) {
                                if ($c['game_code'] === $pbg_filter[1]) { $game_name = $c['display_name']; break; }
                            }
                        ?>
                            <div class="filter-result-line">
                                <strong><?= count($pbg_rows) ?></strong>
                                <?= h($gender_label) ?> student<?= count($pbg_rows) !== 1 ? 's' : '' ?>
                                enrolled in <strong><?= h($game_name ?: $pbg_filter[1]) ?></strong>
                            </div>
                            <?php if (empty($pbg_rows)): ?>
                                <div class="filter-empty">
                                    <i class="bi bi-inbox"></i>
                                    No <?= h($gender_label) ?> students enrolled in <?= h($game_name ?: $pbg_filter[1]) ?> yet.
                                </div>
                            <?php else: ?>
                                <div class="data-table-wrap" style="overflow-x:auto">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Student</th>
                                                <th>Enrollment</th>
                                                <th>Year</th>
                                                <th>Games Enrolled</th>
                                                <th>Submitted</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($pbg_rows as $i => $r): ?>
                                            <tr>
                                                <td><?= $i + 1 ?></td>
                                                <td>
                                                    <div class="student-cell">
                                                        <?php if (!empty($r['photo_path']) && is_file(__DIR__ . '/../' . $r['photo_path'])): ?>
                                                            <img src="<?= h(url($r['photo_path'])) ?>" alt="" class="student-avatar" width="36" height="36" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0">
                                                        <?php else: ?>
                                                            <div class="student-avatar"><?= h(initials($r['full_name'])) ?></div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <div class="student-name"><?= h($r['full_name']) ?></div>
                                                            <div class="student-meta"><?= h($r['mobile']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?= h($r['enrollment_no']) ?></td>
                                                <td><span class="year-tag"><?= h($r['study_year'] ?: '—') ?></span></td>
                                                <td><?= render_sports_cell($r, $pbg_picker_dept_ids, $pbg_games_by_student) ?></td>
                                                <td>
                                                    <?php if (!empty($r['form_submitted_at'])): ?>
                                                        <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .55rem;border-radius:50px;font-size:.7rem;font-weight:600;background:rgba(25,135,84,.12);color:#0a3622">
                                                            <i class="bi bi-check-circle-fill"></i> <?= h(date('d M Y', strtotime((string)$r['form_submitted_at']))) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .55rem;border-radius:50px;font-size:.7rem;font-weight:600;background:rgba(255,193,7,.12);color:#664d03">
                                                            <i class="bi bi-pencil"></i> Draft
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="row-action-btns">
                                                        <a class="row-action-btn" title="View profile" aria-label="View profile — <?= h($r['full_name']) ?>" href="../student-profile.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-eye"></i></a>
                                                        <a class="row-action-btn edit" title="Edit" aria-label="Edit — <?= h($r['full_name']) ?>" href="../student-profile.php?id=<?= (int)$r['id'] ?>#formMode"><i class="bi bi-pencil"></i></a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="data-card">
                    <div class="data-card-header">
                        <h2><i class="bi bi-clock-history"></i> Recently Added Students</h2>
                        <a href="../student-search.php" class="action-link">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (!$recent): ?>
                        <div class="empty-row">
                            <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;color:var(--light-gray)"></i>
                            No students yet. <a href="../student-profile.php?new=1">Add the first one</a>.
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Faculty</th>
                                    <th>Sport</th>
                                    <th>Year</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent as $r): ?>
                                <tr>
                                    <td>
                                        <div class="student-cell">
                                            <div class="student-avatar"><?= h(initials($r['full_name'])) ?></div>
                                            <div>
                                                <div class="student-name"><?= h($r['full_name']) ?></div>
                                                <div class="student-meta"><?= h($r['enrollment_no']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="dept-tag"><?= h($r['dept_name']) ?></span></td>
                                    <td><span class="sport-tag"><?= h($r['sport_1'] ?: '—') ?></span></td>
                                    <td><span class="year-tag"><?= h($r['academic_year']) ?> · <?= h($r['study_year']) ?></span></td>
                                    <td>
                                        <a class="action-link" href="../student-profile.php?id=<?= (int)$r['id'] ?>">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</body>
</html>
