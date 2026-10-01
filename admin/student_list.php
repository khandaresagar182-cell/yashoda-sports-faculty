<?php
/**
 * Student search/list. Department-scoped, with search by enrollment or name.
 * Used as the read endpoint of the search page (server-side render of student-search.html).
 *
 * GET params: q, page, academic_year, study_year
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_department();

$me = current_faculty();
$q  = trim((string)($_GET['q'] ?? ''));
$ay = trim((string)($_GET['academic_year'] ?? ''));
$sy = trim((string)($_GET['study_year'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 12;

[$scope, $params, $types] = scope_sql_department('s');
// Hide wizard drafts (students who registered but haven't hit Final Submit).
$visible = faculty_visible_student_filter('s');
$scope  .= $visible[0];
$params  = array_merge($params, $visible[1]);
$types  .= $visible[2];

$where = '1=1 ' . $scope;
if ($q !== '') {
    $where .= ' AND (s.enrollment_no LIKE ? OR s.full_name LIKE ?) ';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $types   .= 'ss';
}
if ($ay !== '') { $where .= ' AND s.academic_year = ? '; $params[] = $ay; $types .= 's'; }
if ($sy !== '') { $where .= ' AND s.study_year = ? ';    $params[] = $sy; $types .= 's'; }

$total = (int)(db_one("SELECT COUNT(*) AS n FROM students s WHERE $where", $params, $types)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per;

$rows = db_select(
    "SELECT s.id, s.enrollment_no, s.full_name, s.sport_1, s.sport_2,
            s.academic_year, s.study_year, s.photo_path, d.name AS dept_name
       FROM students s
       JOIN departments d ON d.id = s.department_id
      WHERE $where
      ORDER BY s.created_at DESC
      LIMIT $per OFFSET $offset",
    $params, $types
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Students | Sports Portal</title>
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
                <a href="../student-search.php" class="active"><i class="bi bi-search"></i> <span>Search Students</span></a>
                <a href="../student-profile.php?new=1"><i class="bi bi-person-plus"></i> <span>Add Student</span></a>
                <a href="provisional_list.php"><i class="bi bi-clipboard-check"></i> <span>Provisional Players</span></a>
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
                    <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">Search Students</h2>
                </div>
            </header>

            <div class="content-body">
                <div class="page-header">
                    <h1><?= $me['role'] === 'SUPER_ADMIN' ? 'All Students' : h($me['department_name'] ?? 'Students') ?></h1>
                    <p>Search by enrollment number or name. Showing <strong><?= (int)$total ?></strong> record<?= $total === 1 ? '' : 's' ?>.</p>
                </div>

                <?php
                $ok_flash  = flash_get('student_saved');
                $err_flash = flash_get('student_error');
                if ($ok_flash): ?>
                    <div class="alert-banner success" role="alert"><i class="bi bi-check-circle"></i> <?= h($ok_flash['msg']) ?></div>
                <?php endif; if ($err_flash): ?>
                    <div class="alert-banner error" role="alert"><i class="bi bi-exclamation-circle"></i> <?= h($err_flash['msg']) ?></div>
                <?php endif; ?>

                <form method="get" action="student_list.php" class="search-form">
                    <div class="form-group" style="flex:2">
                        <label for="q">Search</label>
                        <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Enrollment number or name">
                    </div>
                    <div class="form-group">
                        <label for="academic_year">Academic Year</label>
                        <select id="academic_year" name="academic_year">
                            <option value="">All years</option>
                            <?php foreach (academic_year_options() as $y): ?>
                                <option value="<?= h($y) ?>" <?= $ay === $y ? 'selected' : '' ?>><?= h($y) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="study_year">Study Year</label>
                        <select id="study_year" name="study_year">
                            <option value="">All</option>
                            <?php foreach (year_options() as $y): ?>
                                <option value="<?= h($y) ?>" <?= $sy === $y ? 'selected' : '' ?>><?= h($y) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                    <a href="student_list.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Reset</a>
                </form>

                <div class="data-card">
                    <div class="data-card-header">
                        <h2>Results</h2>
                        <a href="../student-profile.php?new=1" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Student</a>
                    </div>
                    <?php if (!$rows): ?>
                        <div class="empty-row">
                            <i class="bi bi-inbox"></i>
                            No students found.
                            <?php if ($q !== ''): ?>
                                <br><a href="student_list.php" class="btn btn-secondary" style="margin-top:.5rem">Clear search</a>
                            <?php else: ?>
                                <br><a href="../student-profile.php?new=1" class="btn btn-primary" style="margin-top:.5rem">Add the first one</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Enrollment</th>
                                    <th>Faculty</th>
                                    <th>Sports</th>
                                    <th>Year</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
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
                                    <td><?= h($r['dept_name']) ?></td>
                                    <td>
                                        <?php if ($r['sport_1']): ?><span class="sport-tag"><?= h($r['sport_1']) ?></span><?php endif; ?>
                                        <?php if ($r['sport_2']): ?><span class="sport-tag"><?= h($r['sport_2']) ?></span><?php endif; ?>
                                    </td>
                                    <td><?= h($r['academic_year']) ?> · <?= h($r['study_year']) ?></td>
                                    <td>
                                        <a class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.78rem" href="../student-profile.php?id=<?= (int)$r['id'] ?>">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="pagination">
                            <div class="info">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> total</div>
                            <div class="pages">
                                <?php
                                $base_q = http_build_query(array_filter(['q'=>$q,'academic_year'=>$ay,'study_year'=>$sy]));
                                $prev = max(1, $page - 1);
                                $next = min($pages, $page + 1);
                                ?>
                                <a class="<?= $page <= 1 ? 'disabled' : '' ?>" aria-label="Previous page" href="?<?= h($base_q.'&page='.$prev) ?>"><i class="bi bi-chevron-left"></i></a>
                                <?php for ($i = 1; $i <= $pages; $i++): ?>
                                    <a class="<?= $i === $page ? 'active' : '' ?>" href="?<?= h($base_q.'&page='.$i) ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <a class="<?= $page >= $pages ? 'disabled' : '' ?>" aria-label="Next page" href="?<?= h($base_q.'&page='.$next) ?>"><i class="bi bi-chevron-right"></i></a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
