<?php
/**
 * Add/Edit form for a single notice.
 *   ?new=1   → empty form, INSERT on submit
 *   ?id=N    → pre-fill, UPDATE on submit
 *
 * Posts to notice_save.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('SUPER_ADMIN');

$me = current_faculty();

$err = flash_get('notice_error');
$is_new = isset($_GET['new']);
$id     = (int)($_GET['id'] ?? 0);

$notice = [
    'id'           => 0,
    'title'        => '',
    'category'     => '',
    'summary'      => '',
    'body'         => '',
    'notice_date'  => date('Y-m-d'),
    'is_published' => 1,
    'attachment'   => '',
];

if (!$is_new && $id > 0) {
    $row = db_one('SELECT * FROM notices WHERE id = ?', [$id], 'i');
    if (!$row) {
        http_response_code(404);
        exit('Notice not found.');
    }
    $notice = array_merge($notice, $row);
    $is_new = false;
} elseif (!$is_new && $id === 0) {
    // No id and no ?new=1 → default to add form
    $is_new = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_new ? 'Add' : 'Edit' ?> Notice | Sports Portal</title>
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
        .btn{padding:.55rem 1.1rem;border-radius:6px;font-size:.88rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem}
        .btn-primary{background:var(--primary-navy);color:#fff}.btn-primary:hover{background:var(--primary-navy-dark)}
        .btn-secondary{background:var(--off-white);color:var(--primary-navy);border:1px solid var(--light-gray)}.btn-secondary:hover{background:var(--light-gray)}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .form-card{background:#fff;border:1px solid var(--light-gray);border-radius:10px;padding:1.75rem;max-width:780px}
        .form-section{margin-bottom:1.5rem;padding-bottom:1.25rem;border-bottom:1px solid var(--light-gray)}
        .form-section:last-of-type{border-bottom:none;padding-bottom:0;margin-bottom:0}
        .form-section h3{font-size:.95rem;font-weight:600;color:var(--primary-navy);margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem}
        .form-group{display:flex;flex-direction:column;gap:.3rem}
        .form-group label{font-size:.78rem;font-weight:600;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.3px}
        .form-group input,.form-group select,.form-group textarea{padding:.6rem .8rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.92rem;background:#fff;color:var(--text-dark)}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--primary-navy);box-shadow:0 0 0 3px rgba(26,54,93,.08)}
        .form-group textarea{min-height:90px;resize:vertical}
        .form-group .hint{font-size:.75rem;color:var(--medium-gray);margin-top:.2rem}
        .form-group .req{color:#c53030}
        .form-actions{display:flex;gap:.75rem;margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--light-gray)}
        .checkbox-group{display:flex;align-items:center;gap:.5rem;padding:.65rem .8rem;background:var(--off-white);border:1px solid var(--light-gray);border-radius:6px}
        .checkbox-group label{font-weight:500;text-transform:none;letter-spacing:0;font-size:.92rem;color:var(--text-dark);cursor:pointer}
        .checkbox-group input{width:18px;height:18px;cursor:pointer}
        .current-file{display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:var(--off-white);border:1px solid var(--light-gray);border-radius:6px;margin-bottom:.5rem;font-size:.85rem}
        .current-file a{color:var(--primary-navy);text-decoration:none;font-weight:500}
        .current-file a:hover{text-decoration:underline}
        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .form-grid { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
        @media(max-width:576px){
            .content-body { padding: 1rem 0.75rem; }
            .form-card { padding: 1rem; }
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
                <div class="sidebar-nav-label">Site Content</div>
                <a href="notices_list.php" class="active"><i class="bi bi-megaphone"></i> <span>Notices</span></a>
                <a href="achievements_list.php"><i class="bi bi-trophy"></i> <span>Achievements</span></a>
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
                <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0"><?= $is_new ? 'Add Notice' : 'Edit Notice' ?></h2>
                <a href="notices_list.php" class="top-back-btn" title="Back to notices" aria-label="Back to notices">
                    <i class="bi bi-arrow-left"></i>
                </a>
            </header>

            <div class="content-body">
                <?php if ($err): ?><div class="alert-banner error"><i class="bi bi-exclamation-circle"></i> <?= h($err['msg']) ?></div><?php endif; ?>

                <div class="page-header">
                    <h1><?= $is_new ? 'Add Notice' : 'Edit Notice' ?></h1>
                </div>

                <form method="post" action="notice_save.php" enctype="multipart/form-data" class="form-card">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$notice['id'] ?>">

                    <div class="form-section">
                        <h3><i class="bi bi-card-text"></i> Notice Content</h3>
                        <div class="form-group" style="margin-bottom:1rem">
                            <label>Title <span class="req">*</span></label>
                            <input type="text" name="title" required maxlength="255" value="<?= h($notice['title']) ?>" placeholder="e.g. Annual Sports Meet 2026 — Registration Open">
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Category</label>
                                <input type="text" name="category" maxlength="60" value="<?= h($notice['category']) ?>" placeholder="e.g. Urgent, New, General, Tournament">
                                <span class="hint">Drives the badge color on the public site.</span>
                            </div>
                            <div class="form-group">
                                <label>Notice Date <span class="req">*</span></label>
                                <input type="date" name="notice_date" required value="<?= h($notice['notice_date']) ?>">
                            </div>
                        </div>
                        <div class="form-group" style="margin-top:1rem">
                            <label>Summary</label>
                            <input type="text" name="summary" maxlength="500" value="<?= h($notice['summary']) ?>" placeholder="Short one-liner shown in the meta line">
                        </div>
                        <div class="form-group" style="margin-top:1rem">
                            <label>Full Body</label>
                            <textarea name="body" rows="6" placeholder="Full notice content…"><?= h($notice['body']) ?></textarea>
                            <span class="hint">Optional. Used for the full notice detail page (if linked).</span>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="bi bi-paperclip"></i> PDF Attachment</h3>
                        <?php if (!empty($notice['attachment'])): ?>
                            <div class="current-file">
                                <i class="bi bi-file-earmark-pdf-fill" style="color:#c53030"></i>
                                <a href="<?= h(url('uploads/notices/' . $notice['attachment'])) ?>" target="_blank" rel="noopener">
                                    <?= h($notice['attachment']) ?>
                                </a>
                                <span style="color:var(--medium-gray);font-size:.78rem;margin-left:auto">Current file</span>
                            </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label><?= !empty($notice['attachment']) ? 'Replace PDF (optional)' : 'Upload PDF (optional)' ?></label>
                            <input type="file" name="attachment" accept="application/pdf,.pdf">
                            <span class="hint">Max 5 MB. Only PDF files. Replaces the existing attachment.</span>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="bi bi-toggles"></i> Publishing</h3>
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_published" id="is_published" value="1" <?= (int)$notice['is_published'] === 1 ? 'checked' : '' ?>>
                            <label for="is_published">Published — visible on the public Notice Board and News Ticker</label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> <?= $is_new ? 'Create Notice' : 'Save Changes' ?></button>
                        <a href="notices_list.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
