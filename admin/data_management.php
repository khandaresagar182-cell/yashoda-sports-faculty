<?php
/**
 * Data Management — its own screen (moved out of the Dashboard).
 * Department-scoped destructive tools for FACULTY; global for SUPER_ADMIN
 * when no faculty is currently selected.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_department();

$me = current_faculty();

$purgeIsSuperGlobal = ($me['role'] === 'SUPER_ADMIN') && (effective_department_id() === null);
$purgeScopeLabel = $purgeIsSuperGlobal
    ? 'every department'
    : ('the ' . ($me['department_name'] ?? 'current') . ' department');

/* ============== Individual student management ============== */
// Deliberately NOT using faculty_visible_student_filter() here — faculty
// need to see (and be able to clear/delete) draft rows too, not just
// submitted ones.
$dm_dept_id  = effective_department_id();
$dm_students = [];
if ($dm_dept_id !== null) {
    $dm_students = db_select(
        "SELECT id, full_name, enrollment_no, email, form_submitted_at
           FROM students
          WHERE department_id = ?
          ORDER BY full_name",
        [$dm_dept_id], 'i'
    );
}

$flash = flash_get('data_management_info');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Management | Sports Portal</title>
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
        .breadcrumb-nav{display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:var(--medium-gray)}
        .breadcrumb-nav .current{color:var(--primary-navy);font-weight:600}
        .content-body{flex:1;overflow-y:auto;padding:2rem}

        .page-header{margin-bottom:1.5rem;padding-bottom:.85rem;border-bottom:2px solid var(--primary-navy)}
        .page-header h1{font-size:1.25rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:1.5px}
        .page-header p{color:var(--medium-gray);font-size:.85rem;margin-top:.4rem;text-transform:uppercase;letter-spacing:.6px}

        .data-card{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;overflow:hidden}
        .data-card-header{padding:.85rem 1.5rem;border-bottom:1.5px solid var(--primary-navy);background:var(--off-white);display:flex;align-items:center;justify-content:space-between;text-transform:uppercase;letter-spacing:1.2px}
        .data-card-header h2{font-size:.78rem;font-weight:700;color:var(--primary-navy);margin:0;display:inline-flex;align-items:center;gap:.5rem}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.7rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.75rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);color:var(--text-dark)}
        .data-table tr:last-child td{border-bottom:none}
        .student-name{font-weight:600;color:var(--primary-navy)}
        .student-meta{font-size:.75rem;color:var(--medium-gray)}

        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.info{background:rgba(13,202,240,.08);color:#055160;border:1px solid rgba(13,202,240,.2)}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}

        .filter-hint{padding:1.8rem 1.5rem;text-align:center;color:var(--medium-gray);font-size:.92rem;background:#fff;font-style:italic}
        .filter-hint i{color:var(--accent-gold);font-size:1.3rem;margin-right:.4rem;font-style:normal}

        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .data-card { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .data-table { min-width: 600px; }
        }
        @media (max-width: 576px) {
            .content-body { padding: 1rem 0.75rem; }
        }

        /* ---- Data Management ---- */
        .dm-card .data-card-header h2 i { color: var(--medium-gray); }
        .dm-body { padding: 1.35rem 1.5rem; }
        .dm-row { display: flex; gap: 1.75rem; align-items: center; flex-wrap: wrap; justify-content: space-between; }
        .dm-info { flex: 1; min-width: 280px; }
        .dm-info h3 { font-size: 0.92rem; font-weight: 700; color: var(--primary-navy); margin: 0 0 0.3rem; }
        .dm-info p { font-size: 0.82rem; color: var(--medium-gray); margin: 0; max-width: 660px; line-height: 1.55; }
        .dm-info p .keep { color: #146c43; font-weight: 600; }
        .dm-info p .warn { color: #c53030; font-weight: 600; }
        .btn-danger-solid {
            display: inline-flex; align-items: center; gap: 0.45rem; white-space: nowrap;
            padding: 0.62rem 1.2rem; border: none; border-radius: 8px;
            background: #c53030; color: #fff; font: inherit; font-size: 0.85rem; font-weight: 600;
            cursor: pointer; transition: var(--transition-smooth);
        }
        .btn-danger-solid:hover:not(:disabled) { background: #9b2c2c; box-shadow: 0 6px 18px rgba(197,48,48,.28); transform: translateY(-1px); }
        .btn-danger-solid:disabled { opacity: 0.5; cursor: not-allowed; }

        .dm-modal { position: fixed; inset: 0; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; z-index: 2000; padding: 1rem; }
        .dm-modal[hidden] { display: none; }
        .dm-modal-box { background: #fff; border-radius: 12px; max-width: 460px; width: 100%; box-shadow: 0 24px 60px rgba(0,0,0,.35); overflow: hidden; animation: dmPop .14s ease-out; }
        @keyframes dmPop { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }
        .dm-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 0.95rem 1.25rem; background: #fff5f5; border-bottom: 1px solid #fed7d7; }
        .dm-modal-head h3 { margin: 0; font-size: 0.98rem; font-weight: 700; color: #c53030; display: flex; align-items: center; gap: 0.5rem; }
        .dm-modal-x { background: 0 0; border: none; font-size: 1.5rem; line-height: 1; color: var(--medium-gray); cursor: pointer; padding: 0 0.25rem; }
        .dm-modal-x:hover { color: var(--dark-gray); }
        .dm-modal-body { padding: 1.25rem; }
        .dm-modal-body > p { font-size: 0.86rem; color: var(--dark-gray); margin: 0 0 0.5rem; line-height: 1.55; }
        .dm-modal-body label { display: block; font-size: 0.78rem; font-weight: 600; color: var(--primary-navy); margin: 0.9rem 0 0.3rem; }
        .dm-modal-body code { background: var(--off-white); border: 1px solid var(--light-gray); border-radius: 4px; padding: 0.05rem 0.35rem; font-size: 0.8rem; color: #c53030; font-weight: 700; }
        .dm-modal-body input { width: 100%; padding: 0.55rem 0.75rem; border: 1px solid var(--light-gray); border-radius: 6px; font: inherit; font-size: 0.9rem; letter-spacing: 0.5px; }
        .dm-modal-body input:focus { outline: none; border-color: #c53030; box-shadow: 0 0 0 3px rgba(197,48,48,.12); }
        .dm-modal-actions { display: flex; gap: 0.6rem; justify-content: flex-end; margin-top: 1.4rem; }
        .btn-ghost { padding: 0.55rem 1rem; border: 1px solid var(--light-gray); border-radius: 8px; background: #fff; color: var(--dark-gray); font: inherit; font-size: 0.85rem; font-weight: 600; cursor: pointer; }
        .btn-ghost:hover { background: var(--off-white); }
        @media (max-width: 576px) {
            .dm-row { flex-direction: column; align-items: stretch; }
            .btn-danger-solid { justify-content: center; }
        }

        /* ---- Individual student management ---- */
        .dm-divider { border: none; border-top: 1px solid var(--light-gray); margin: 1.5rem 0 1.35rem; }
        .dm-individual h3 { font-size: 0.92rem; font-weight: 700; color: var(--primary-navy); margin: 0 0 0.3rem; }
        .dm-individual > p { font-size: 0.82rem; color: var(--medium-gray); margin: 0 0 0.9rem; max-width: 720px; line-height: 1.55; }
        .dm-search { width: 100%; max-width: 340px; padding: 0.55rem 0.8rem; border: 1px solid var(--light-gray); border-radius: 8px; font: inherit; font-size: 0.86rem; margin-bottom: 0.9rem; }
        .dm-search:focus { outline: none; border-color: var(--primary-navy); box-shadow: 0 0 0 3px rgba(26,54,93,.1); }
        .dm-table-wrap { max-height: 420px; overflow-y: auto; border: 1px solid var(--light-gray); border-radius: 8px; }
        .dm-table-wrap table { margin: 0; }
        .dm-table-wrap thead th { position: sticky; top: 0; z-index: 1; }
        .dm-bulk-actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem; }
        .dm-bulk-actions .dm-sel-count { font-size: 0.82rem; color: var(--medium-gray); margin-right: auto; }
        .btn-reset-outline {
            display: inline-flex; align-items: center; gap: 0.45rem; white-space: nowrap;
            padding: 0.6rem 1.1rem; border: 1.5px solid var(--primary-navy); border-radius: 8px;
            background: #fff; color: var(--primary-navy); font: inherit; font-size: 0.85rem; font-weight: 600;
            cursor: pointer; transition: var(--transition-smooth);
        }
        .btn-reset-outline:hover:not(:disabled) { background: var(--off-white); }
        .btn-reset-outline:disabled { opacity: 0.5; cursor: not-allowed; }
        @media (max-width: 576px) {
            .dm-bulk-actions { flex-direction: column; align-items: stretch; }
            .dm-bulk-actions .dm-sel-count { margin-right: 0; text-align: center; }
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
                <a href="dashboard.php">
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
                <a href="jersey_dashboard.php">
                    <i class="bi bi-person-badge"></i> <span>Jersey Kit</span>
                </a>
                <a href="data_management.php" class="active">
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
                    <a href="logout.php?_csrf=<?= h(csrf_token()) ?>" class="btn-logout" title="Logout">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <header class="top-bar">
                <div class="top-bar-left">
                    <div class="breadcrumb-nav">
                        <span class="current">Data Management</span>
                    </div>
                </div>
                <div class="top-bar-right">
                    <span style="font-size:.85rem;color:var(--medium-gray)">Welcome, <strong><?= h($me['full_name']) ?></strong></span>
                </div>
            </header>

            <div class="content-body">
                <div class="page-header">
                    <h1><i class="bi bi-database-fill-gear"></i> Data Management</h1>
                    <p>Reset or permanently delete student data.</p>
                </div>

                <?php if ($flash): ?>
                    <div class="alert-banner <?= h($flash['level']) ?>" role="alert">
                        <i class="bi bi-info-circle"></i> <?= h($flash['msg']) ?>
                    </div>
                <?php endif; ?>

                <div class="data-card dm-card">
                    <div class="data-card-header">
                        <h2><i class="bi bi-database-fill-gear"></i> Data Management</h2>
                    </div>
                    <div class="dm-body">
                        <div class="dm-row">
                            <div class="dm-info">
                                <h3>Reset student data &mdash; <?= h($purgeScopeLabel) ?></h3>
                                <p>
                                    Permanently deletes every student profile and everything students filled in:
                                    uploaded documents &amp; photos, selected games, provisional / final team entries,
                                    and external entries (submissions, their files and any unverified sign-ups).
                                    <span class="keep">Eligibility archive files and entry links are kept.</span>
                                    <span class="warn">This cannot be undone.</span>
                                </p>
                            </div>
                            <button type="button" class="btn-danger-solid" id="dmOpenBtn">
                                <i class="bi bi-trash3"></i> Delete student data
                            </button>
                        </div>

                        <hr class="dm-divider">

                        <div class="dm-individual">
                            <h3>Manage individual students</h3>
                            <p>
                                Reset a student back to Step 1 (clears their documents, photo, selected games,
                                bank details, and provisional / final entries — their login stays active), or
                                delete their record entirely. Scoped to <?= h($purgeScopeLabel) ?>.
                            </p>

                            <?php if ($dm_dept_id === null): ?>
                                <div class="filter-hint" style="padding:1rem 0">
                                    <i class="bi bi-info-circle"></i> Select a faculty to manage individual students.
                                </div>
                            <?php elseif (empty($dm_students)): ?>
                                <div class="filter-hint" style="padding:1rem 0">
                                    <i class="bi bi-info-circle"></i> No students yet for <?= h($purgeScopeLabel) ?>.
                                </div>
                            <?php else: ?>
                                <input type="text" id="dmSearch" class="dm-search" placeholder="Search by name, enrollment no. or email…">
                                <div class="dm-table-wrap">
                                    <table class="data-table" id="dmStudentTable">
                                        <thead>
                                            <tr>
                                                <th style="width:2.2rem"><input type="checkbox" id="dmSelectAll" aria-label="Select all"></th>
                                                <th>Student</th>
                                                <th>Enrollment</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($dm_students as $s): ?>
                                            <tr data-search="<?= h(mb_strtolower($s['full_name'] . ' ' . ($s['enrollment_no'] ?? '') . ' ' . ($s['email'] ?? ''))) ?>">
                                                <td><input type="checkbox" class="dm-row-check" value="<?= (int)$s['id'] ?>"></td>
                                                <td>
                                                    <div class="student-name"><?= h($s['full_name']) ?></div>
                                                    <div class="student-meta"><?= h($s['email'] ?: '—') ?></div>
                                                </td>
                                                <td><?= h($s['enrollment_no'] ?: '—') ?></td>
                                                <td>
                                                    <?php if (!empty($s['form_submitted_at'])): ?>
                                                        <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .55rem;border-radius:50px;font-size:.7rem;font-weight:600;background:rgba(25,135,84,.12);color:#0a3622">
                                                            <i class="bi bi-check-circle-fill"></i> Submitted
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .55rem;border-radius:50px;font-size:.7rem;font-weight:600;background:rgba(255,193,7,.12);color:#664d03">
                                                            <i class="bi bi-pencil"></i> Draft
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="dm-bulk-actions">
                                    <span class="dm-sel-count" id="dmSelCount">0 selected</span>
                                    <button type="button" class="btn-reset-outline" id="dmResetSelBtn" disabled>
                                        <i class="bi bi-arrow-counterclockwise"></i> Reset selected
                                    </button>
                                    <button type="button" class="btn-danger-solid" id="dmDeleteSelBtn" disabled>
                                        <i class="bi bi-trash3"></i> Delete selected
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="dm-modal" id="dmBulkModal" hidden>
                    <div class="dm-modal-box" role="dialog" aria-modal="true" aria-labelledby="dmBulkModalTitle">
                        <div class="dm-modal-head">
                            <h3 id="dmBulkModalTitle"><i class="bi bi-exclamation-triangle-fill"></i> Confirm</h3>
                            <button type="button" class="dm-modal-x" id="dmBulkCloseBtn" aria-label="Close">&times;</button>
                        </div>
                        <div class="dm-modal-body">
                            <p id="dmBulkModalText"></p>
                            <form method="post" action="student_bulk_manage.php" id="dmBulkForm">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" id="dmBulkAction" value="">
                                <div id="dmBulkIdsWrap"></div>
                                <label for="dmBulkConfirm">Type <code id="dmBulkWordHint">DELETE</code> to confirm</label>
                                <input type="text" id="dmBulkConfirm" name="confirm" autocomplete="off">
                                <div class="dm-modal-actions">
                                    <button type="button" class="btn-ghost" id="dmBulkCancelBtn">Cancel</button>
                                    <button type="submit" class="btn-danger-solid" id="dmBulkSubmitBtn" disabled>Confirm</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="dm-modal" id="dmModal" hidden>
                    <div class="dm-modal-box" role="dialog" aria-modal="true" aria-labelledby="dmModalTitle">
                        <div class="dm-modal-head">
                            <h3 id="dmModalTitle"><i class="bi bi-exclamation-triangle-fill"></i> Confirm deletion</h3>
                            <button type="button" class="dm-modal-x" id="dmCloseBtn" aria-label="Close">&times;</button>
                        </div>
                        <div class="dm-modal-body">
                            <p>
                                This permanently deletes <strong>all student records and external entries for <?= h($purgeScopeLabel) ?></strong>.
                                Eligibility archive files are kept. <strong>This cannot be undone.</strong>
                            </p>
                            <form method="post" action="student_data_purge.php" id="dmForm">
                                <?= csrf_field() ?>
                                <label for="dmConfirm">Type <code>DELETE</code> to confirm</label>
                                <input type="text" id="dmConfirm" name="confirm" autocomplete="off" placeholder="DELETE">
                                <?php if ($purgeIsSuperGlobal): ?>
                                    <label for="dmConfirmAll">Then type <code>DELETE ALL</code> (wipes every department)</label>
                                    <input type="text" id="dmConfirmAll" name="confirm_all" autocomplete="off" placeholder="DELETE ALL">
                                <?php endif; ?>
                                <div class="dm-modal-actions">
                                    <button type="button" class="btn-ghost" id="dmCancelBtn">Cancel</button>
                                    <button type="submit" class="btn-danger-solid" id="dmSubmitBtn" disabled>
                                        <i class="bi bi-trash3"></i> Delete permanently
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var modal   = document.getElementById('dmModal');
            var openBtn = document.getElementById('dmOpenBtn');
            if (!modal || !openBtn) return;

            var closeEls = [document.getElementById('dmCloseBtn'), document.getElementById('dmCancelBtn')];
            var confirmA = document.getElementById('dmConfirm');
            var confirmB = document.getElementById('dmConfirmAll'); // only present for the global case
            var submit   = document.getElementById('dmSubmitBtn');

            function evaluate() {
                var ok = confirmA && confirmA.value.trim() === 'DELETE';
                if (confirmB) ok = ok && confirmB.value.trim() === 'DELETE ALL';
                submit.disabled = !ok;
            }
            function open() {
                modal.hidden = false;
                if (confirmA) { confirmA.value = ''; }
                if (confirmB) { confirmB.value = ''; }
                evaluate();
                if (confirmA) confirmA.focus();
                document.addEventListener('keydown', onKey);
            }
            function close() {
                modal.hidden = true;
                document.removeEventListener('keydown', onKey);
                openBtn.focus();
            }
            function onKey(e) { if (e.key === 'Escape') close(); }

            openBtn.addEventListener('click', open);
            closeEls.forEach(function (el) { if (el) el.addEventListener('click', close); });
            modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
            if (confirmA) confirmA.addEventListener('input', evaluate);
            if (confirmB) confirmB.addEventListener('input', evaluate);
        })();

        (function () {
            var table = document.getElementById('dmStudentTable');
            if (!table) return;

            var search     = document.getElementById('dmSearch');
            var selectAll  = document.getElementById('dmSelectAll');
            var selCount   = document.getElementById('dmSelCount');
            var resetBtn   = document.getElementById('dmResetSelBtn');
            var deleteBtn  = document.getElementById('dmDeleteSelBtn');

            function rowChecks() {
                return Array.prototype.slice.call(table.querySelectorAll('.dm-row-check'));
            }
            function visibleChecks() {
                return rowChecks().filter(function (cb) { return cb.closest('tr').style.display !== 'none'; });
            }
            function updateCount() {
                var checked = rowChecks().filter(function (cb) { return cb.checked; });
                selCount.textContent = checked.length + ' selected';
                resetBtn.disabled = checked.length === 0;
                deleteBtn.disabled = checked.length === 0;
            }

            if (search) {
                search.addEventListener('input', function () {
                    var q = search.value.trim().toLowerCase();
                    table.querySelectorAll('tbody tr').forEach(function (tr) {
                        tr.style.display = tr.dataset.search.indexOf(q) === -1 ? 'none' : '';
                    });
                    if (selectAll) selectAll.checked = false;
                });
            }
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    visibleChecks().forEach(function (cb) { cb.checked = selectAll.checked; });
                    updateCount();
                });
            }
            table.addEventListener('change', function (e) {
                if (e.target.classList.contains('dm-row-check')) updateCount();
            });

            var bulkModal   = document.getElementById('dmBulkModal');
            var bulkAction  = document.getElementById('dmBulkAction');
            var bulkIdsWrap = document.getElementById('dmBulkIdsWrap');
            var bulkTitle   = document.getElementById('dmBulkModalTitle');
            var bulkText    = document.getElementById('dmBulkModalText');
            var bulkWordHint= document.getElementById('dmBulkWordHint');
            var bulkConfirm = document.getElementById('dmBulkConfirm');
            var bulkSubmit  = document.getElementById('dmBulkSubmitBtn');

            function openBulk(action) {
                var checked = rowChecks().filter(function (cb) { return cb.checked; });
                if (!checked.length) return;

                bulkAction.value = action;
                bulkIdsWrap.innerHTML = '';
                checked.forEach(function (cb) {
                    var h = document.createElement('input');
                    h.type = 'hidden';
                    h.name = 'ids[]';
                    h.value = cb.value;
                    bulkIdsWrap.appendChild(h);
                });

                var word = action === 'delete' ? 'DELETE' : 'RESET';
                bulkTitle.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> '
                    + (action === 'delete' ? 'Delete ' : 'Reset ') + checked.length
                    + ' student' + (checked.length !== 1 ? 's' : '');
                bulkText.textContent = action === 'delete'
                    ? 'This permanently deletes the selected student record(s) and everything they filled in (documents, photo, games, provisional/final entries). This cannot be undone.'
                    : 'This clears documents, photo, selected games, bank details, and provisional/final entries for the selected student(s), and restarts their wizard at Step 1. Their login stays active. This cannot be undone.';
                bulkWordHint.textContent = word;
                bulkConfirm.value = '';
                bulkSubmit.disabled = true;

                bulkModal.hidden = false;
                bulkConfirm.focus();
                document.addEventListener('keydown', onBulkKey);
            }
            function closeBulk() {
                bulkModal.hidden = true;
                document.removeEventListener('keydown', onBulkKey);
            }
            function onBulkKey(e) { if (e.key === 'Escape') closeBulk(); }

            bulkConfirm.addEventListener('input', function () {
                bulkSubmit.disabled = bulkConfirm.value.trim() !== bulkWordHint.textContent;
            });
            resetBtn.addEventListener('click', function () { openBulk('reset'); });
            deleteBtn.addEventListener('click', function () { openBulk('delete'); });
            document.getElementById('dmBulkCloseBtn').addEventListener('click', closeBulk);
            document.getElementById('dmBulkCancelBtn').addEventListener('click', closeBulk);
            bulkModal.addEventListener('click', function (e) { if (e.target === bulkModal) closeBulk(); });

            updateCount();
        })();
    </script>
</body>
</html>
