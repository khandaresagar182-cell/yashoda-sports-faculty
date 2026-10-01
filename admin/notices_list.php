<?php
/**
 * Notice Board management. Any logged-in admin can list, edit, delete,
 * and toggle publish state. Add/edit lives in notice_edit.php; this file
 * handles the inline delete action.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('SUPER_ADMIN');

$me = current_faculty();

/* ---------------- POST: delete notice ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do  = $_POST['do']  ?? '';
    $id  = (int)($_POST['id'] ?? 0);

    if ($do === 'delete' && $id > 0) {
        $existing = db_one('SELECT id, attachment FROM notices WHERE id = ?', [$id], 'i');
        if ($existing) {
            // Best-effort: remove the attached PDF from disk
            if (!empty($existing['attachment'])) {
                $abs = __DIR__ . '/../uploads/notices/' . basename($existing['attachment']);
                if (is_file($abs)) @unlink($abs);
            }
            db_execute('DELETE FROM notices WHERE id = ?', [$id], 'i');
            flash_set('notice_saved', 'Notice deleted.', 'success');
        } else {
            flash_set('notice_error', 'Notice not found.', 'error');
        }
        redirect('notices_list.php');
    }

    http_response_code(400);
    exit('Bad request.');
}

/* ---------------- GET: list ---------------- */

$ok  = flash_get('notice_saved');
$err = flash_get('notice_error');

$q    = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;

$where  = '1=1';
$params = [];
$types  = '';
if ($q !== '') {
    $where   .= ' AND (n.title LIKE ? OR n.category LIKE ? OR n.summary LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $types   .= 'sss';
}

$total = (int)(db_one("SELECT COUNT(*) AS n FROM notices n WHERE $where", $params, $types)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per;

$notices = db_select(
    "SELECT n.*, f.full_name AS poster_name
       FROM notices n
       LEFT JOIN faculty f ON f.id = n.posted_by
      WHERE $where
      ORDER BY n.notice_date DESC, n.id DESC
      LIMIT $per OFFSET $offset",
    $params, $types
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notices | Sports Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">
    <style>
        :root{--primary-navy:#1a365d;--primary-navy-dark:#0f2744;--primary-navy-light:#2c5282;--accent-gold:#c9a227;--accent-gold-light:#d4b84a;--accent-maroon:#722f37;--white:#fff;--off-white:#f8f9fa;--light-gray:#e9ecef;--medium-gray:#6c757d;--text-dark:#212529;--font-primary:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;--sidebar-width:260px;--transition-smooth:all .3s ease-in-out}
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
        .pagination{padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;border-top:1px solid var(--light-gray)}
        .pagination .info{font-size:.85rem;color:var(--medium-gray)}
        .pagination .pages{display:flex;gap:.25rem}
        .pagination .pages a{padding:.35rem .65rem;border:1px solid var(--light-gray);border-radius:4px;font-size:.85rem;color:var(--primary-navy);text-decoration:none;background:var(--white)}
        .pagination .pages a:hover{background:var(--off-white)}
        .pagination .pages a.active{background:var(--primary-navy);color:var(--white);border-color:var(--primary-navy)}
        .pagination .pages a.disabled{opacity:.4;pointer-events:none}
        .badge{display:inline-block;padding:.2rem .6rem;border-radius:4px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
        .badge-published{background:rgba(25,135,84,.12);color:#0a3622}
        .badge-draft{background:rgba(108,117,125,.15);color:#495057}
        .badge-cat{background:rgba(26,54,93,.08);color:var(--primary-navy)}
        .badge-urgent{background:#fee;color:#c33}
        .badge-new{background:#e8f5e9;color:#2e7d32}
        .badge-general{background:#e3f2fd;color:#1976d2}
        .cell-title{font-weight:600;color:var(--primary-navy);max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .cell-meta{font-size:.75rem;color:var(--medium-gray);margin-top:.2rem}
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
            .data-card { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .data-table { min-width: 600px; }
            .search-form { width: 100%; max-width: none; }
            .btn { width: 100%; justify-content: center; }
            .data-card-header { flex-direction: column; align-items: stretch; }
        }
        @media(max-width:576px){
            .content-body { padding: 1rem 0.75rem; }
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
                <a href="notices_list.php" class="active"><i class="bi bi-megaphone"></i> <span>Notices</span></a>
                <a href="achievements_list.php"><i class="bi bi-trophy"></i> <span>Achievements</span></a>
                <a href="committee_manage.php"><i class="bi bi-people-fill"></i> <span>Committee</span></a>
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
                <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">Notice Board</h2>
            </header>

            <div class="content-body">
                <?php if ($ok):  ?><div class="alert-banner success" role="alert"><i class="bi bi-check-circle"></i> <?= h($ok['msg']) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert-banner error" role="alert"><i class="bi bi-exclamation-circle"></i> <?= h($err['msg']) ?></div><?php endif; ?>

                <div class="page-header">
                    <div>
                        <h1>Notices</h1>
                        <p>Manage announcements shown on the public Notice Board and News Ticker.</p>
                    </div>
                    <a href="notice_edit.php?new=1" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Notice</a>
                </div>

                <div class="data-card">
                    <div class="data-card-header">
                        <h2><i class="bi bi-pin-angle"></i> All Notices (<?= $total ?>)</h2>
                        <form method="get" class="search-form" action="notices_list.php">
                            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search title, category, summary…">
                            <button type="submit" class="btn btn-secondary" style="padding:.5rem .75rem" aria-label="Search"><i class="bi bi-search"></i></button>
                        </form>
                    </div>

                    <?php if (!$notices): ?>
                        <div class="empty-row">
                            <i class="bi bi-inbox"></i>
                            No notices found. <a href="notice_edit.php?new=1">Add the first one</a>.
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Posted By</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($notices as $n):
                                $cat = strtolower((string)$n['category']);
                                $cat_class = 'badge-general';
                                if (str_contains($cat, 'urgent') || str_contains($cat, 'important')) $cat_class = 'badge-urgent';
                                elseif (str_contains($cat, 'new') || str_contains($cat, 'latest')) $cat_class = 'badge-new';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= h(date('d M Y', strtotime($n['notice_date']))) ?></strong>
                                        <div class="cell-meta"><?= h(date('D', strtotime($n['notice_date']))) ?></div>
                                    </td>
                                    <td>
                                        <div class="cell-title"><?= h($n['title']) ?></div>
                                        <?php if (!empty($n['attachment'])): ?>
                                            <div class="cell-meta"><i class="bi bi-paperclip"></i> PDF attached</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($n['category'])): ?>
                                            <span class="badge <?= h($cat_class) ?>"><?= h($n['category']) ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--medium-gray)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$n['is_published'] === 1): ?>
                                            <span class="badge badge-published">Published</span>
                                        <?php else: ?>
                                            <span class="badge badge-draft">Draft</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:.85rem;color:var(--medium-gray)">
                                        <?= h($n['poster_name'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <a class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.78rem" href="notice_edit.php?id=<?= (int)$n['id'] ?>">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                            <form method="post" action="notices_list.php" style="display:inline" onsubmit="return confirm('Delete this notice? This cannot be undone.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="do" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                                <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.78rem" aria-label="Delete notice — <?= h($n['title']) ?>"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="pagination">
                            <div class="info">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> total</div>
                            <div class="pages">
                                <?php
                                $base_q = http_build_query(array_filter(['q' => $q]));
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
            </div>
        </div>
    </div>
</body>
</html>
