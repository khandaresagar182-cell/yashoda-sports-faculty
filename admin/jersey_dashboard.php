<?php
/**
 * Jersey Kit — simple lookup of a student's stored jersey number/size.
 *
 * Replaces the old link/QR/approval workflow (jersey_forms, jersey_requests,
 * public jersey-form.php). Jersey number + size are now plain fields on the
 * student's own record (filled in during the wizard, Step 6), so this page
 * is just a search: pick a sport and/or type a name, see the results.
 *
 * Scoped like every other faculty page: FACULTY see their own department,
 * SUPER_ADMIN picks one via ?dept=.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/student_rendering.php';

require_login();
require_department();

$me     = current_faculty();
$deptId = effective_department_id();

$gameCode = trim((string)($_GET['game'] ?? ''));
$q        = trim((string)($_GET['q'] ?? ''));
$page     = max(1, (int)($_GET['page'] ?? 1));
$per      = 50;

$catalog = [];
$rows    = [];
$total   = 0;
$pages   = 1;
if ($deptId !== null && $deptId > 0) {
    $catalog = db_select(
        'SELECT game_code, display_name
           FROM dept_game_catalog
          WHERE department_id = ? AND is_active = 1
          ORDER BY display_name',
        [$deptId], 'i'
    );

    // Only accept a game code that's actually in this department's catalog.
    $validGame = null;
    foreach ($catalog as $c) {
        if ((string)$c['game_code'] === $gameCode) { $validGame = $gameCode; break; }
    }

    $where  = 's.department_id = ? AND s.form_submitted_at IS NOT NULL';
    $params = [$deptId];
    $types  = 'i';

    if ($validGame !== null) {
        $where   .= ' AND EXISTS (SELECT 1 FROM student_selected_games ssg WHERE ssg.student_id = s.id AND ssg.game_code = ?)';
        $params[] = $validGame;
        $types   .= 's';
    }
    if ($q !== '') {
        $where   .= ' AND (s.full_name LIKE ? OR s.enrollment_no LIKE ?)';
        $like     = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $types   .= 'ss';
    }

    $total = (int)(db_one("SELECT COUNT(*) AS n FROM students s WHERE $where", $params, $types)['n'] ?? 0);
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $per;

    $rows = db_select(
        "SELECT s.id, s.full_name, s.enrollment_no, s.gender, s.department_id,
                s.sport_1, s.sport_2, s.jersey_number, s.jersey_size, s.shorts_size, s.track_size
           FROM students s
          WHERE $where
          ORDER BY s.full_name
          LIMIT $per OFFSET $offset",
        $params, $types
    );
}

$pickerDeptIds  = load_picker_dept_ids();
$gamesByStudent = $rows ? load_games_by_student(array_map(static fn($r) => (int)$r['id'], $rows)) : [];
$sizeLabels     = jersey_size_options();
$shortsLabels   = shorts_size_options();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jersey Kit | Sports Portal</title>
    <?= csrf_meta() ?>
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
        .sidebar{width:var(--sidebar-width);background:linear-gradient(180deg,var(--primary-navy-dark),var(--primary-navy));color:#fff;display:flex;flex-direction:column;flex-shrink:0;overflow:hidden}
        .sidebar-brand{padding:1.25rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:.75rem}
        .sidebar-brand img{width:42px;height:42px;border-radius:8px;object-fit:contain;background:rgba(255,255,255,.1);padding:3px}
        .sidebar-brand-text h2{font-size:.85rem;font-weight:700;color:#fff;margin:0}
        .sidebar-brand-text span{font-size:.7rem;color:rgba(255,255,255,.5)}
        .sidebar-nav{flex:1;padding:1rem 0;overflow-y:auto}
        .sidebar-nav-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.35);padding:.75rem 1.5rem .4rem}
        .sidebar-nav a{display:flex;align-items:center;gap:.75rem;padding:.7rem 1.5rem;color:rgba(255,255,255,.65);font-size:.88rem;font-weight:500;text-decoration:none;transition:var(--transition-smooth);border-left:3px solid transparent}
        .sidebar-nav a:hover{color:#fff;background:rgba(255,255,255,.06);border-left-color:rgba(201,162,39,.4)}
        .sidebar-nav a.active{color:#fff;background:rgba(201,162,39,.12);border-left-color:var(--accent-gold)}
        .sidebar-nav a i{font-size:1.15rem;width:22px;text-align:center}
        .sidebar-footer{padding:1rem 1.25rem;border-top:1px solid rgba(255,255,255,.08)}
        .sidebar-user{display:flex;align-items:center;gap:.75rem}
        .sidebar-user-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent-gold),var(--accent-maroon));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:#fff;flex-shrink:0}
        .sidebar-user-info h4{font-size:.82rem;font-weight:600;color:#fff;margin:0}
        .sidebar-user-info span{font-size:.7rem;color:rgba(255,255,255,.5)}
        .btn-logout{margin-left:auto;background:0 0;border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.6);padding:.35rem .5rem;border-radius:6px;cursor:pointer;font-size:.85rem;text-decoration:none}
        .btn-logout:hover{background:rgba(220,53,69,.2);border-color:rgba(220,53,69,.4);color:#ff8a8a}
        .main-content{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
        .top-bar{background:#fff;border-bottom:1px solid var(--light-gray);padding:.75rem 2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
        .content-body{flex:1;overflow-y:auto;padding:2rem}
        .page-header{margin-bottom:1.25rem}
        .page-header h1{font-size:1.4rem;font-weight:700;color:var(--primary-navy)}
        .page-header p{color:var(--medium-gray);font-size:.9rem;margin-top:.25rem}
        .toolbar{display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;background:#fff;border:1px solid var(--light-gray);border-radius:10px;padding:1.1rem 1.25rem}
        .form-group{display:flex;flex-direction:column;gap:.3rem}
        .form-group label{font-size:.78rem;font-weight:600;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.3px}
        .form-group input,.form-group select{padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.92rem;background:#fff;min-width:200px}
        .form-group input:focus,.form-group select:focus{outline:none;border-color:var(--primary-navy)}
        .btn{padding:.55rem 1.1rem;border-radius:6px;font-size:.88rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem}
        .btn-primary{background:var(--primary-navy);color:#fff}.btn-primary:hover{background:var(--primary-navy-dark)}
        .btn-secondary{background:var(--off-white);color:var(--primary-navy);border:1px solid var(--light-gray)}.btn-secondary:hover{background:var(--light-gray)}
        .btn-sm{padding:.35rem .7rem;font-size:.8rem}
        .data-card{background:#fff;border:1px solid var(--light-gray);border-radius:10px;overflow:hidden}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.75rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.75rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);color:var(--text-dark);vertical-align:middle}
        .data-table tr:last-child td{border-bottom:none}
        .data-table tr:hover{background:var(--off-white)}
        .pagination{padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;border-top:1px solid var(--light-gray)}
        .pagination .info{font-size:.85rem;color:var(--medium-gray)}
        .pagination .pages{display:flex;gap:.25rem}
        .pagination .pages a{padding:.35rem .65rem;border:1px solid var(--light-gray);border-radius:4px;font-size:.85rem;color:var(--primary-navy);text-decoration:none;background:var(--white)}
        .pagination .pages a:hover{background:var(--off-white)}
        .pagination .pages a.active{background:var(--primary-navy);color:var(--white);border-color:var(--primary-navy)}
        .pagination .pages a.disabled{opacity:.4;pointer-events:none}
        .sport-tag{display:inline-block;background:rgba(201,162,39,.12);color:#9c7a12;padding:.2rem .6rem;border-radius:4px;font-size:.72rem;font-weight:600;margin-right:.3rem}
        .jersey-chip{display:inline-flex;align-items:center;gap:.3rem;background:rgba(26,54,93,.08);color:var(--primary-navy);padding:.2rem .6rem;border-radius:50px;font-size:.82rem;font-weight:700}
        .missing{color:var(--medium-gray);font-style:italic;font-size:.85rem}
        .empty-row{text-align:center;color:var(--medium-gray);padding:3rem 1rem;font-size:.9rem}
        .empty-row i{font-size:2.5rem;display:block;margin-bottom:.5rem;color:var(--light-gray)}
        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .toolbar{flex-direction:column;align-items:stretch}
            .form-group input,.form-group select{min-width:0}
            .data-card{overflow-x:auto;-webkit-overflow-scrolling:touch}
            .data-table{min-width:600px}
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
                <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">Jersey Kit</h2>
            </header>

            <div class="content-body">
                <div class="page-header">
                    <h1>Jersey Kit</h1>
                    <p>Look up a student's jersey number and size — stored on their own profile, filled in during the wizard.</p>
                </div>

                <?php if ($deptId === null || $deptId <= 0): ?>
                    <div class="data-card">
                        <div class="empty-row">
                            <i class="bi bi-building"></i>
                            Select a faculty to search its jersey details.<br><br>
                            <a href="../faculty-select.php?change=1" class="btn btn-secondary btn-sm"><i class="bi bi-building"></i> Select Faculty</a>
                        </div>
                    </div>
                <?php else: ?>
                    <form method="get" action="jersey_dashboard.php" class="toolbar">
                        <div class="form-group">
                            <label for="game">Sport</label>
                            <select id="game" name="game">
                                <option value="">All sports</option>
                                <?php foreach ($catalog as $c): ?>
                                    <option value="<?= h($c['game_code']) ?>" <?= $gameCode === $c['game_code'] ? 'selected' : '' ?>>
                                        <?= h($c['display_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="q">Student name / enrollment no.</label>
                            <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Type to search&hellip;">
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                        <?php if ($gameCode !== '' || $q !== ''): ?>
                            <a href="jersey_dashboard.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Clear</a>
                        <?php endif; ?>
                    </form>

                    <div class="data-card">
                        <?php if (!$rows): ?>
                            <div class="empty-row">
                                <i class="bi bi-person-badge"></i>
                                No matching students.
                            </div>
                        <?php else: ?>
                            <div style="overflow-x:auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Enrollment No.</th>
                                        <th>Sports</th>
                                        <th>Jersey Size</th>
                                        <th>Jersey Number</th>
                                        <th>Shorts Size</th>
                                        <th>Track Pant Size</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td style="font-weight:600;color:var(--primary-navy)"><?= h($r['full_name']) ?></td>
                                            <td><?= h($r['enrollment_no'] ?: '—') ?></td>
                                            <td><?= render_sports_cell($r, $pickerDeptIds, $gamesByStudent) ?></td>
                                            <td>
                                                <?php if (trim((string)$r['jersey_size']) !== ''): ?>
                                                    <span class="jersey-chip"><?= h($sizeLabels[$r['jersey_size']] ?? $r['jersey_size']) ?></span>
                                                <?php else: ?>
                                                    <span class="missing">Not set</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (trim((string)$r['jersey_number']) !== ''): ?>
                                                    <span class="jersey-chip"><i class="bi bi-hash"></i><?= h($r['jersey_number']) ?></span>
                                                <?php else: ?>
                                                    <span class="missing">Not set</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (trim((string)$r['shorts_size']) !== ''): ?>
                                                    <span class="jersey-chip"><?= h($shortsLabels[$r['shorts_size']] ?? $r['shorts_size']) ?></span>
                                                <?php else: ?>
                                                    <span class="missing">Not set</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (trim((string)$r['track_size']) !== ''): ?>
                                                    <span class="jersey-chip"><?= h($shortsLabels[$r['track_size']] ?? $r['track_size']) ?></span>
                                                <?php else: ?>
                                                    <span class="missing">Not set</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                            <div class="pagination">
                                <div class="info">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> total</div>
                                <div class="pages">
                                    <?php
                                    $base_q = http_build_query(array_filter(['game' => $gameCode, 'q' => $q]));
                                    $prev = max(1, $page - 1);
                                    $next = min($pages, $page + 1);
                                    ?>
                                    <a class="<?= $page <= 1 ? 'disabled' : '' ?>" aria-label="Previous page" href="?<?= h($base_q . '&page=' . $prev) ?>"><i class="bi bi-chevron-left"></i></a>
                                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                                        <a class="<?= $i === $page ? 'active' : '' ?>" href="?<?= h($base_q . '&page=' . $i) ?>"><?= $i ?></a>
                                    <?php endfor; ?>
                                    <a class="<?= $page >= $pages ? 'disabled' : '' ?>" aria-label="Next page" href="?<?= h($base_q . '&page=' . $next) ?>"><i class="bi bi-chevron-right"></i></a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
