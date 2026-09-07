<?php
/**
 * Achievements management. Any logged-in admin can list, edit, and delete
 * achievements shown in the public homepage carousel.
 * Add/edit lives in achievement_edit.php; this file handles inline delete.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('SUPER_ADMIN');

$me = current_faculty();

/* ---------------- POST: delete achievement ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($do === 'delete' && $id > 0) {
        $existing = db_one('SELECT id, image_path FROM achievements WHERE id = ?', [$id], 'i');
        if ($existing) {
            if (!empty($existing['image_path'])) {
                $abs = __DIR__ . '/../' . ltrim($existing['image_path'], '/');
                if (is_file($abs)) @unlink($abs);
            }
            db_execute('DELETE FROM achievements WHERE id = ?', [$id], 'i');
            flash_set('ach_saved', 'Achievement deleted.', 'success');
        } else {
            flash_set('ach_error', 'Achievement not found.', 'error');
        }
        redirect('achievements_list.php');
    }

    http_response_code(400);
    exit('Bad request.');
}

/* ---------------- GET: list ---------------- */

$ok  = flash_get('ach_saved');
$err = flash_get('ach_error');

$achievements = db_select(
    "SELECT a.*, s.full_name AS student_name, s.enrollment_no, d.name AS dept_name
       FROM achievements a
       LEFT JOIN students  s ON s.id = a.student_id
       LEFT JOIN departments d ON d.id = s.department_id
      WHERE s.form_submitted_at IS NOT NULL OR s.id IS NULL
      ORDER BY a.event_date DESC, a.id DESC"
);

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $achievements = array_values(array_filter($achievements, function ($a) use ($q) {
        return stripos($a['title'], $q) !== false
            || stripos((string)$a['student_name'], $q) !== false
            || stripos((string)$a['event_name'], $q) !== false
            || stripos((string)$a['description'], $q) !== false;
    }));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achievements | Sports Portal</title>
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
        .btn-logout{margin-left:auto;background:0 0;border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.6);padding:.35rem .5rem;border-radius:6px;cursor:pointer;transition:var(--transition-smooth);font-size:.85rem;text-decoration:none}
        .btn-logout:hover{background:rgba(220,53,69,.2);border-color:rgba(220,53,69,.4);color:#ff8a8a}
        .main-content{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
        .top-bar{background:#fff;border-bottom:1px solid var(--light-gray);padding:.75rem 2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
        .content-body{flex:1;overflow-y:auto;padding:2rem}
        .page-header{margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
        .page-header h1{font-size:1.4rem;font-weight:700;color:var(--primary-navy);margin:0}
        .page-header p{color:var(--medium-gray);font-size:.9rem;margin-top:.25rem;width:100%}
        .btn{padding:.55rem 1.1rem;border-radius:6px;font-size:.88rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem}
        .btn-primary{background:var(--primary-navy);color:#fff}.btn-primary:hover{background:var(--primary-navy-dark)}
        .btn-secondary{background:var(--off-white);color:var(--primary-navy);border:1px solid var(--light-gray)}.btn-secondary:hover{background:var(--light-gray)}
        .btn-danger{background:#fff5f5;color:#c53030;border:1px solid #fed7d7}.btn-danger:hover{background:#fed7d7}
        .data-card{background:#fff;border:1px solid var(--light-gray);border-radius:10px;overflow:hidden;margin-bottom:1.25rem}
        .data-card-header{padding:1rem 1.25rem;border-bottom:1px solid var(--light-gray);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .data-card-header h2{font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0}
        .search-form{display:flex;align-items:center;gap:.5rem;flex:1;max-width:360px;min-width:220px}
        .search-form input{padding:.5rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font:inherit;font-size:.88rem;width:100%;background:#fff}
        .search-form input:focus{outline:none;border-color:var(--primary-navy)}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.75rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.75rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);color:var(--text-dark);vertical-align:middle}
        .data-table tr:last-child td{border-bottom:none}
        .data-table tr:hover{background:var(--off-white)}
        .badge{display:inline-block;padding:.2rem .6rem;border-radius:4px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
        .badge-published{background:rgba(25,135,84,.12);color:#0a3622}
        .badge-draft{background:rgba(108,117,125,.15);color:#495057}
        .badge-gold{background:#fef3c7;color:#854d0e}
        .badge-silver{background:#e5e7eb;color:#374151}
        .badge-bronze{background:#fed7aa;color:#9a3412}
        .badge-level{background:rgba(26,54,93,.08);color:var(--primary-navy)}
        .cell-title{font-weight:600;color:var(--primary-navy);max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .cell-meta{font-size:.75rem;color:var(--medium-gray);margin-top:.2rem}
        .thumb{width:54px;height:40px;object-fit:cover;border-radius:4px;border:1px solid var(--light-gray);background:var(--off-white);display:block}
        .thumb-fallback{width:54px;height:40px;border-radius:4px;background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-light));color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem}
        .row-actions{display:flex;gap:.4rem;flex-wrap:nowrap;justify-content:flex-end}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .empty-row{text-align:center;color:var(--medium-gray);padding:2.5rem 1rem;font-size:.9rem}
        .empty-row i{font-size:2rem;display:block;margin-bottom:.5rem;color:var(--light-gray)}
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
                    <a href="../faculty-select.php?change=1"><i class="bi bi-building"></i> <span>Select Faculty</span></a>
                <?php endif; ?>
                <a href="dashboard.php"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a>
                <a href="../student-search.php"><i class="bi bi-search"></i> <span>Search Students</span></a>
                <a href="../student-profile.php?new=1"><i class="bi bi-person-plus"></i> <span>Add Student</span></a>
                <a href="provisional_list.php"><i class="bi bi-clipboard-check"></i> <span>Provisional Players</span></a>
                <a href="final_list.php"><i class="bi bi-check-all"></i> <span>Final Teams</span></a>
                <a href="eligibility_archive.php"><i class="bi bi-folder2-open"></i> <span>Eligibility Archive</span></a>
                <a href="jersey_dashboard.php"><i class="bi bi-person-badge"></i> <span>Jersey Kit</span></a>
                <a href="data_management.php"><i class="bi bi-database-fill-gear"></i> <span>Data Management</span></a>
                <div class="sidebar-nav-label">Site Content</div>
                <a href="notices_list.php"><i class="bi bi-megaphone"></i> <span>Notices</span></a>
                <a href="achievements_list.php" class="active"><i class="bi bi-trophy"></i> <span>Achievements</span></a>
                <?php if ($me['role'] === 'SUPER_ADMIN'): ?>
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

        <div class="main-content">
            <header class="top-bar">
                <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">Achievements</h2>
            </header>

            <div class="content-body">
                <?php if ($ok):  ?><div class="alert-banner success"><i class="bi bi-check-circle"></i> <?= h($ok['msg']) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert-banner error"><i class="bi bi-exclamation-circle"></i> <?= h($err['msg']) ?></div><?php endif; ?>

                <div class="page-header">
                    <div>
                        <h1>Achievements</h1>
                        <p>Manage the carousel shown on the public homepage.</p>
                    </div>
                    <a href="achievement_edit.php?new=1" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Achievement</a>
                </div>

                <div class="data-card">
                    <div class="data-card-header">
                        <h2><i class="bi bi-trophy"></i> All Achievements (<?= count($achievements) ?>)</h2>
                        <form method="get" class="search-form" action="achievements_list.php">
                            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search title, student, event…">
                            <button type="submit" class="btn btn-secondary" style="padding:.5rem .75rem"><i class="bi bi-search"></i></button>
                        </form>
                    </div>

                    <?php if (!$achievements): ?>
                        <div class="empty-row">
                            <i class="bi bi-trophy"></i>
                            No achievements found. <a href="achievement_edit.php?new=1">Add the first one</a>.
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:60px"></th>
                                    <th>Date</th>
                                    <th>Title</th>
                                    <th>Student</th>
                                    <th>Level / Position</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($achievements as $a):
                                $pos = strtolower((string)$a['position']);
                                $pos_class = 'badge-level';
                                if (str_contains($pos, 'gold'))   $pos_class = 'badge-gold';
                                if (str_contains($pos, 'silver')) $pos_class = 'badge-silver';
                                if (str_contains($pos, 'bronze')) $pos_class = 'badge-bronze';
                                $has_image = !empty($a['image_path']) && is_file(__DIR__ . '/../' . $a['image_path']);
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($has_image): ?>
                                            <img class="thumb" src="<?= h(url($a['image_path'])) ?>" alt="">
                                        <?php else: ?>
                                            <div class="thumb-fallback"><i class="bi bi-trophy"></i></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= $a['event_date'] ? h(date('d M Y', strtotime($a['event_date']))) : '—' ?></strong>
                                    </td>
                                    <td>
                                        <div class="cell-title"><?= h($a['title']) ?></div>
                                        <?php if (!empty($a['event_name']) && $a['event_name'] !== $a['title']): ?>
                                            <div class="cell-meta"><?= h($a['event_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($a['student_name'])): ?>
                                            <strong><?= h($a['student_name']) ?></strong>
                                            <div class="cell-meta"><?= h($a['enrollment_no'] ?? '') ?></div>
                                        <?php else: ?>
                                            <span style="color:var(--medium-gray)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($a['level'])): ?>
                                            <span class="badge badge-level"><?= h($a['level']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($a['position'])): ?>
                                            <span class="badge <?= h($pos_class) ?>"><?= h($a['position']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$a['is_published'] === 1): ?>
                                            <span class="badge badge-published">Published</span>
                                        <?php else: ?>
                                            <span class="badge badge-draft">Draft</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <a class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.78rem" href="achievement_edit.php?id=<?= (int)$a['id'] ?>">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                            <form method="post" action="achievements_list.php" style="display:inline" onsubmit="return confirm('Delete this achievement? This cannot be undone.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="do" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                                <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.78rem"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
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
