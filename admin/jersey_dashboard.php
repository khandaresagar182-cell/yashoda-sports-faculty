<?php
/**
 * Jersey Kit overview for all authenticated faculty and administrators.
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

[$scope, $params, $types] = scope_sql_department('s');
// Hide wizard drafts (students who registered but haven't hit Final Submit).
$visible = faculty_visible_student_filter('s');
$scope  .= $visible[0];
$params  = array_merge($params, $visible[1]);
$types  .= $visible[2];
$dept_id = jersey_request_department_id();
$jersey_dept_join   = jersey_forms_has_department_id() ? ' AND jf.department_id = s.department_id' : '';
$jersey_gender_join = jersey_forms_has_gender() ? ' AND jf.gender <=> ft.gender' : '';

$teams = db_select(
    "SELECT ft.game_name, ft.event_label, ft.academic_year, ft.gender,
            COUNT(DISTINCT ft.student_id) AS player_count,
            jf.id AS form_id,
            COALESCE(jf.is_open, 0) AS is_open,
            COUNT(DISTINCT jr.id) AS request_count,
            COUNT(DISTINCT CASE WHEN jr.status = 'Pending' THEN jr.id END) AS pending_count,
            COUNT(DISTINCT CASE WHEN jr.status = 'Approved' THEN jr.id END) AS approved_count,
            COUNT(DISTINCT CASE WHEN jr.status = 'Rejected' THEN jr.id END) AS rejected_count,
            MAX(ft.created_at) AS last_added
       FROM final_teams ft
       JOIN students s ON s.id = ft.student_id
       LEFT JOIN jersey_forms jf
         ON jf.game_name = ft.game_name
        AND jf.event_label = ft.event_label
        AND jf.academic_year <=> ft.academic_year
        $jersey_dept_join
        $jersey_gender_join
       LEFT JOIN jersey_requests jr
         ON jr.jersey_form_id = jf.id
        AND jr.student_id = s.id
      WHERE 1=1 $scope
      GROUP BY ft.game_name, ft.event_label, ft.academic_year, ft.gender, jf.id, jf.is_open
      ORDER BY last_added DESC, ft.game_name, ft.event_label",
    $params,
    $types
);

$summary = [
    'teams' => count($teams),
    'open' => 0,
    'requests' => 0,
    'pending' => 0,
];

foreach ($teams as $team) {
    $summary['open'] += (int)$team['is_open'];
    $summary['requests'] += (int)$team['request_count'];
    $summary['pending'] += (int)$team['pending_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jersey Kit Dashboard | Sports Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">
    <style>
        :root{--primary-navy:#1a365d;--primary-navy-dark:#0f2744;--primary-navy-light:#2c5282;--accent-gold:#c9a227;--accent-maroon:#722f37;--white:#fff;--off-white:#f8f9fa;--light-gray:#e9ecef;--medium-gray:#6c757d;--text-dark:#212529;--font-primary:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;--sidebar-width:260px;--transition-smooth:all .3s ease-in-out}
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
        .btn-logout{margin-left:auto;background:transparent;border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.6);padding:.35rem .5rem;border-radius:6px;text-decoration:none}
        .main-content{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
        .top-bar{background:var(--white);border-bottom:1px solid var(--light-gray);padding:.75rem 2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
        .top-bar h2{font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0}
        .content-body{flex:1;overflow-y:auto;padding:2rem}
        .page-header{margin-bottom:1.5rem}
        .page-header h1{font-size:1.5rem;font-weight:700;color:var(--primary-navy);margin:0}
        .page-header p{color:var(--medium-gray);font-size:.92rem;margin:.25rem 0 0}
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat-card{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;padding:1rem 1.15rem;display:flex;align-items:center;gap:.85rem}
        .stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;background:rgba(26,54,93,.09);color:var(--primary-navy)}
        .stat-card:nth-child(2) .stat-icon{background:rgba(25,135,84,.1);color:#157347}
        .stat-card:nth-child(3) .stat-icon{background:rgba(201,162,39,.15);color:#8a6d08}
        .stat-card:nth-child(4) .stat-icon{background:rgba(255,193,7,.14);color:#664d03}
        .stat-info h3{font-size:1.35rem;font-weight:700;color:var(--primary-navy);margin:0;line-height:1.1}
        .stat-info p{font-size:.72rem;color:var(--medium-gray);margin:0;text-transform:uppercase;letter-spacing:.4px}
        .data-card{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;overflow:hidden}
        .data-card-header{padding:1rem 1.25rem;border-bottom:1px solid var(--light-gray);display:flex;align-items:center;justify-content:space-between;gap:1rem}
        .data-card-header h2{font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.7rem 1rem;font-size:.72rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.45px;text-align:left;border-bottom:1px solid var(--light-gray);white-space:nowrap}
        .data-table td{padding:.8rem 1rem;font-size:.86rem;border-bottom:1px solid var(--light-gray);vertical-align:middle}
        .data-table tr:last-child td{border-bottom:none}
        .team-name{font-weight:650;color:var(--primary-navy)}
        .team-meta{font-size:.75rem;color:var(--medium-gray)}
        .status-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .55rem;border-radius:4px;font-size:.7rem;font-weight:700;text-transform:uppercase}
        .status-open{background:rgba(25,135,84,.1);color:#0a3622}
        .status-closed{background:rgba(220,53,69,.1);color:#842029}
        .status-none{background:rgba(108,117,125,.1);color:var(--medium-gray)}
        .request-counts{display:flex;gap:.35rem;flex-wrap:wrap}
        .count-pill{padding:.16rem .45rem;border-radius:4px;font-size:.7rem;font-weight:650;background:var(--off-white);color:var(--medium-gray);border:1px solid var(--light-gray)}
        .count-pill.pending{color:#664d03}.count-pill.approved{color:#0a3622}.count-pill.rejected{color:#842029}
        .btn-manage{background:var(--primary-navy);color:#fff;padding:.38rem .7rem;border-radius:6px;font-size:.78rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap}
        .btn-manage:hover{background:var(--primary-navy-dark);color:#fff}
        .empty-row{text-align:center;color:var(--medium-gray);padding:3rem 1rem;font-size:.9rem}
        .empty-row i{font-size:2.4rem;display:block;margin-bottom:.5rem;color:var(--light-gray)}
        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .stat-grid { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
            .data-card { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .data-table { min-width: 600px; }
        }
        @media(max-width:576px){
            .content-body { padding: 1rem 0.75rem; }
            .stat-grid { gap: 0.75rem; }
            .stat-card { padding: 0.85rem 1rem; }
            .data-card-header { padding: 0.75rem 1rem; }
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
                <a href="../faculty-select.php?change=1"><i class="bi bi-building"></i> <span>Select Faculty</span></a>
            <?php endif; ?>
            <a href="dashboard.php"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a>
            <a href="../student-search.php"><i class="bi bi-search"></i> <span>Search Students</span></a>
            <a href="../student-profile.php?new=1"><i class="bi bi-person-plus"></i> <span>Add Student</span></a>
            <a href="provisional_list.php"><i class="bi bi-clipboard-check"></i> <span>Provisional Players</span></a>
            <a href="final_list.php"><i class="bi bi-check-all"></i> <span>Final Teams</span></a>
            <a href="eligibility_archive.php"><i class="bi bi-folder2-open"></i> <span>Eligibility Archive</span></a>
            <a href="jersey_dashboard.php" class="active"><i class="bi bi-person-badge"></i> <span>Jersey Kit</span></a>
            <?php if (($me['role'] ?? '') === 'SUPER_ADMIN'): ?>
                <div class="sidebar-nav-label">Site Content</div>
                <a href="notices_list.php"><i class="bi bi-megaphone"></i> <span>Notices</span></a>
                <a href="achievements_list.php"><i class="bi bi-trophy"></i> <span>Achievements</span></a>
                <div class="sidebar-nav-label">Admin</div>
                <a href="faculty_manage.php"><i class="bi bi-people-fill"></i> <span>Faculty Management</span></a>
                <a href="document_requirements.php"><i class="bi bi-file-earmark-ruled"></i> <span>Document Requirements</span></a>
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
                <a href="logout.php?_csrf=<?= h(csrf_token()) ?>" class="btn-logout" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-bar">
            <h2>Jersey Kit Dashboard</h2>
            <span style="font-size:.82rem;color:var(--medium-gray)"><?= h($me['department_name'] ?? 'All Faculties') ?></span>
        </header>

        <div class="content-body">
            <div class="page-header">
                <h1>Jersey Kit Overview</h1>
                <p>Open forms, share student links, and review jersey requests for every saved final team.</p>
            </div>

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-collection"></i></div>
                    <div class="stat-info"><h3><?= $summary['teams'] ?></h3><p>Final Teams</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-unlock"></i></div>
                    <div class="stat-info"><h3><?= $summary['open'] ?></h3><p>Open Forms</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-person-badge"></i></div>
                    <div class="stat-info"><h3><?= $summary['requests'] ?></h3><p>Total Requests</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div class="stat-info"><h3><?= $summary['pending'] ?></h3><p>Pending Review</p></div>
                </div>
            </div>

            <div class="data-card">
                <div class="data-card-header">
                    <h2><i class="bi bi-list-check"></i> Jersey Forms by Final Team</h2>
                </div>
                <?php if (!$teams): ?>
                    <div class="empty-row">
                        <i class="bi bi-inbox"></i>
                        No final teams are available yet. Create a final team first.
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>Team</th>
                                <th>Players</th>
                                <th>Form Status</th>
                                <th>Requests</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($teams as $team): ?>
                                <?php
                                $team_gender = $team['gender'] ?? '';
                                $team_gender_label = gender_list_options()[$team_gender] ?? 'Unspecified';
                                $query = http_build_query(array_filter([
                                    'game' => $team['game_name'],
                                    'event' => $team['event_label'],
                                    'ay' => $team['academic_year'] ?? '',
                                    'gender' => $team_gender,
                                    'dept' => $dept_id,
                                ], static fn($value) => $value !== null && $value !== ''));
                                ?>
                                <tr>
                                    <td>
                                        <div class="team-name"><?= h($team['game_name']) ?> <span class="count-pill" style="background:rgba(26,54,93,.1);color:var(--primary-navy)"><?= h($team_gender_label) ?></span></div>
                                        <div class="team-meta">
                                            <?= h($team['event_label']) ?>
                                            <?= !empty($team['academic_year']) ? ' · ' . h($team['academic_year']) : '' ?>
                                        </div>
                                    </td>
                                    <td><strong><?= (int)$team['player_count'] ?></strong></td>
                                    <td>
                                        <?php if (!$team['form_id']): ?>
                                            <span class="status-badge status-none"><i class="bi bi-dash-circle"></i> Not Created</span>
                                        <?php elseif ((int)$team['is_open']): ?>
                                            <span class="status-badge status-open"><i class="bi bi-unlock"></i> Open</span>
                                        <?php else: ?>
                                            <span class="status-badge status-closed"><i class="bi bi-lock"></i> Closed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="request-counts">
                                            <span class="count-pill"><?= (int)$team['request_count'] ?> total</span>
                                            <span class="count-pill pending"><?= (int)$team['pending_count'] ?> pending</span>
                                            <span class="count-pill approved"><?= (int)$team['approved_count'] ?> approved</span>
                                            <span class="count-pill rejected"><?= (int)$team['rejected_count'] ?> rejected</span>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="jersey_manage.php?<?= h($query) ?>" class="btn-manage">
                                            <i class="bi bi-sliders"></i> Manage
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>
