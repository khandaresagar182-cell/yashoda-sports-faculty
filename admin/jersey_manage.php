<?php
/**
 * Jersey Kit Management — Faculty Dashboard.
 *
 * GET params: game, event, ay (same as final_list.php)
 *
 * Features:
 *   - Toggle form open/closed
 *   - Display shareable link + QR code
 *   - List all jersey requests with actions: approve, reject, and edit number
 *   - Export + delete data
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_department();

$me    = current_faculty();
if ($me === null) { redirect('../faculty-login.php'); exit; }

$game   = trim((string)($_GET['game'] ?? ''));
$event  = trim((string)($_GET['event'] ?? ''));
$ay     = trim((string)($_GET['ay'] ?? ''));
$gender = trim((string)($_GET['gender'] ?? ''));
if (!array_key_exists($gender, gender_list_options())) $gender = '';

if ($game === '' || $event === '' || $gender === '') {
    flash_set('final_error', 'Gender, game, and event are required to manage jerseys.', 'error');
    redirect('final_list.php');
}

$ay_val = $ay === '' ? null : $ay;
$dept_id = jersey_forms_has_department_id()
    ? jersey_department_for_team($game, $event, $ay_val, $gender)
    : null;
[$dept_filter, $dept_params, $dept_types] = jersey_form_department_filter($dept_id);
[$gender_filter, $gender_params, $gender_types] = jersey_form_gender_filter($gender);

/* ------------------------------------------------------------------ */
/*  Load jersey form row (may not exist yet)                          */
/* ------------------------------------------------------------------ */

$form = db_one(
    "SELECT * FROM jersey_forms
      WHERE game_name = ? AND event_label = ? AND academic_year <=> ? $dept_filter $gender_filter",
    array_merge([$game, $event, $ay_val], $dept_params, $gender_params),
    'sss' . $dept_types . $gender_types
);

$is_open = $form ? (int)$form['is_open'] : 0;

// Build the public URL
$public_url = '';
if ($form) {
    $forwarded_proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwarded_proto === 'https')
        ? 'https'
        : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Detect base path
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base   = dirname(dirname($script)); // go up from /admin/jersey_manage.php
    if ($base === '/' || $base === '\\') $base = '';
    $public_url = $scheme . '://' . $host . $base . '/jersey-form.php?token=' . urlencode($form['access_token']);
}

/* ------------------------------------------------------------------ */
/*  Load jersey requests                                              */
/* ------------------------------------------------------------------ */

$requests = [];
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];

if ($form) {
    $requests = db_select(
        "SELECT jr.*, s.full_name, s.photo_path, d.name AS dept_name
           FROM jersey_requests jr
           JOIN students s ON s.id = jr.student_id
           JOIN departments d ON d.id = s.department_id
          WHERE jr.jersey_form_id = ?
          ORDER BY jr.created_at ASC",
        [(int)$form['id']], 'i'
    );
    $stats['total'] = count($requests);
    foreach ($requests as $r) {
        $s = strtolower($r['status']);
        if (isset($stats[$s])) $stats[$s]++;
    }
}

/* ------------------------------------------------------------------ */
/*  Final team player count (for context)                             */
/* ------------------------------------------------------------------ */

[$scope, $sp, $st] = scope_sql_department('s');
// Hide wizard drafts (students who registered but haven't hit Final Submit).
$visible = faculty_visible_student_filter('s');
$scope  .= $visible[0];
$sp      = array_merge($sp, $visible[1]);
$st     .= $visible[2];
$team_count = (int)(db_one(
    "SELECT COUNT(*) AS n FROM final_teams ft
       JOIN students s ON s.id = ft.student_id
      WHERE ft.game_name = ? AND ft.event_label = ? AND ft.academic_year <=> ? AND ft.gender <=> ? $scope",
    array_merge([$game, $event, $ay_val, $gender], $sp), 'ssss' . $st
)['n'] ?? 0);

$gender_label = gender_list_options()[$gender] ?? '';

$list_query = http_build_query(array_filter([
    'game' => $game,
    'event' => $event,
    'ay' => $ay,
    'gender' => $gender,
    'dept' => $dept_id,
], static fn($value) => $value !== null && $value !== ''));

$flash_ok  = flash_get('jersey_ok');
$flash_err = flash_get('jersey_error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jersey Kit — <?= h($game) ?> | Sports Portal</title>
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
        .content-body{flex:1;overflow-y:auto;padding:2rem}
        .page-header{margin-bottom:1.25rem}
        .page-header h1{font-size:1.4rem;font-weight:700;color:var(--primary-navy)}
        .page-header p{color:var(--medium-gray);font-size:.9rem;margin-top:.25rem}

        .btn{padding:.55rem 1.1rem;border-radius:6px;font-size:.88rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;transition:var(--transition-smooth)}
        .btn-primary{background:var(--primary-navy);color:var(--white)}.btn-primary:hover{background:var(--primary-navy-dark)}
        .btn-secondary{background:var(--off-white);color:var(--primary-navy);border:1px solid var(--light-gray)}.btn-secondary:hover{background:var(--light-gray)}
        .btn-success{background:#198754;color:var(--white)}.btn-success:hover{background:#157347}
        .btn-danger{background:#dc3545;color:var(--white)}.btn-danger:hover{background:#b02a37}
        .btn-warning{background:var(--accent-gold);color:var(--primary-navy-dark)}.btn-warning:hover{background:var(--accent-gold-light)}
        .btn-sm{padding:.3rem .6rem;font-size:.78rem}
        .btn-outline-danger{background:transparent;color:#dc3545;border:1px solid #dc3545}.btn-outline-danger:hover{background:#dc3545;color:#fff}

        .data-card{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;overflow:hidden;margin-bottom:1.25rem}
        .data-card-header{padding:1rem 1.25rem;border-bottom:1px solid var(--light-gray);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
        .data-card-header h2{font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0}
        .data-card-body{padding:1.25rem}

        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.7rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.75rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);color:var(--text-dark);vertical-align:middle}
        .data-table tr:last-child td{border-bottom:none}
        .data-table tr:hover{background:var(--off-white)}

        .student-cell{display:flex;align-items:center;gap:.7rem}
        .student-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-light));color:var(--white);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;flex-shrink:0;overflow:hidden}
        .student-avatar img{width:100%;height:100%;object-fit:cover}
        .student-name{font-weight:600;color:var(--primary-navy)}
        .student-meta{font-size:.75rem;color:var(--medium-gray)}

        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}

        .count-pill{display:inline-block;background:rgba(201,162,39,.15);color:var(--accent-gold);font-size:.72rem;font-weight:700;padding:.15rem .55rem;border-radius:10px;margin-left:.4rem}
        .empty-row{text-align:center;color:var(--medium-gray);padding:3rem 1rem;font-size:.9rem}
        .empty-row i{font-size:2.5rem;display:block;margin-bottom:.5rem;color:var(--light-gray)}

        /* Share section */
        .share-section{display:grid;grid-template-columns:1fr auto;gap:1.5rem;align-items:start}
        .share-link-box{background:var(--off-white);border:1px solid var(--light-gray);border-radius:8px;padding:.75rem 1rem;font-size:.82rem;word-break:break-all;color:var(--primary-navy);display:flex;align-items:center;gap:.75rem}
        .share-link-box input{flex:1;background:none;border:none;font-family:inherit;font-size:.82rem;color:var(--primary-navy);outline:none}
        .share-link-box button{background:var(--primary-navy);color:var(--white);border:none;padding:.35rem .75rem;border-radius:5px;font-size:.75rem;font-weight:600;cursor:pointer;white-space:nowrap}
        .share-link-box button:hover{background:var(--primary-navy-dark)}
        #qrcode-canvas{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;padding:.75rem;display:flex;align-items:center;justify-content:center;min-width:148px;min-height:148px}

        /* Status badges */
        .status-badge{display:inline-block;padding:.2rem .55rem;border-radius:4px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
        .status-pending{background:rgba(255,193,7,.12);color:#664d03}
        .status-approved{background:rgba(25,135,84,.1);color:#0a3622}
        .status-rejected{background:rgba(220,53,69,.1);color:#842029}
        /* Toggle switch */
        .toggle-row{display:flex;align-items:center;gap:1rem;margin-bottom:1rem}
        .toggle-label{font-size:.85rem;font-weight:600;color:var(--primary-navy)}
        .toggle-status{font-size:.78rem;font-weight:700;padding:.2rem .65rem;border-radius:4px}
        .toggle-open{background:rgba(25,135,84,.1);color:#0a3622}
        .toggle-closed{background:rgba(220,53,69,.1);color:#842029}

        /* Stats mini grid */
        .stats-mini{display:flex;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap}
        .stat-mini{background:var(--white);border:1px solid var(--light-gray);border-radius:8px;padding:.75rem 1rem;flex:1;min-width:100px;text-align:center}
        .stat-mini .number{font-size:1.3rem;font-weight:700;color:var(--primary-navy)}
        .stat-mini .label{font-size:.68rem;text-transform:uppercase;letter-spacing:.4px;color:var(--medium-gray);font-weight:600}

        /* Action cell */
        .action-cell{display:flex;gap:.3rem;flex-wrap:wrap;align-items:center}

        /* Edit number inline form */
        .edit-num-form{display:inline-flex;align-items:center;gap:.25rem}
        .edit-num-form input{width:52px;padding:.15rem .35rem;border:1px solid var(--light-gray);border-radius:4px;font-size:.78rem;text-align:center}

        /* Confirm modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center}
        .modal-overlay.show{display:flex}
        .modal-box{background:var(--white);border-radius:12px;padding:2rem;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.2)}
        .modal-box h3{font-size:1.05rem;font-weight:700;color:var(--primary-navy);margin-bottom:.5rem}
        .modal-box p{font-size:.88rem;color:var(--medium-gray);margin-bottom:1.25rem}
        .modal-box .modal-actions{display:flex;gap:.75rem;justify-content:center}

        @media (max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .search-form { flex-direction: column; align-items: stretch; gap: 0.75rem; }
            .search-form .form-group { width: 100%; flex: none; }
            .btn { width: 100%; justify-content: center; }
            .data-card { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .data-table { min-width: 600px; }
            .share-section{grid-template-columns:1fr}
        }
        @media (max-width:768px){
            .action-cell{flex-direction:column;align-items:flex-start}
        }
        @media (max-width:576px){
            .content-body { padding: 1rem 0.75rem; }
            .search-form { padding: 1rem; }
            .stats-mini { gap: 0.5rem; }
            .stat-mini { padding: 0.5rem; font-size: 0.8rem; }
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
                <?php endif; ?>
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
                <div>
                    <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">Jersey Kit Management</h2>
                </div>
                <a href="jersey_dashboard.php" class="top-back-btn" title="Back to jersey dashboard" aria-label="Back to jersey dashboard">
                    <i class="bi bi-arrow-left"></i>
                </a>
            </header>

            <div class="content-body">
                <div class="page-header">
                    <h1><i class="bi bi-person-badge"></i> Jersey Kit — <?= h($game) ?> (<?= h($gender_label) ?>)</h1>
                    <p><?= h($event) ?><?= $ay ? ' · ' . h($ay) : '' ?> · <?= $team_count ?> players on final team</p>
                </div>

                <?php if ($flash_ok): ?>
                    <div class="alert-banner success"><i class="bi bi-check-circle"></i> <?= h($flash_ok['msg']) ?></div>
                <?php endif; ?>
                <?php if ($flash_err): ?>
                    <div class="alert-banner error"><i class="bi bi-exclamation-circle"></i> <?= h($flash_err['msg']) ?></div>
                <?php endif; ?>

                <!-- Toggle + Share Section -->
                <div class="data-card">
                    <div class="data-card-header">
                        <h2><i class="bi bi-toggles"></i>&nbsp; Form Control & Share Link</h2>
                    </div>
                    <div class="data-card-body">
                        <div class="toggle-row">
                            <span class="toggle-label">Jersey Form Status:</span>
                            <span class="toggle-status <?= $is_open ? 'toggle-open' : 'toggle-closed' ?>">
                                <i class="bi bi-<?= $is_open ? 'unlock' : 'lock' ?>"></i>
                                <?= $is_open ? 'OPEN' : 'CLOSED' ?>
                            </span>
                            <form method="post" action="jersey_action.php" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_form">
                                <input type="hidden" name="game" value="<?= h($game) ?>">
                                <input type="hidden" name="event" value="<?= h($event) ?>">
                                <input type="hidden" name="ay" value="<?= h($ay) ?>">
                                <input type="hidden" name="gender" value="<?= h($gender) ?>">
                                <?php if ($dept_id !== null): ?><input type="hidden" name="dept" value="<?= (int)$dept_id ?>"><?php endif; ?>
                                <button type="submit" class="btn <?= $is_open ? 'btn-outline-danger' : 'btn-success' ?> btn-sm">
                                    <i class="bi bi-<?= $is_open ? 'x-circle' : 'check-circle' ?>"></i>
                                    <?= $is_open ? 'Close Form' : 'Open Form' ?>
                                </button>
                            </form>
                        </div>

                        <?php if ($form): ?>
                            <div class="share-section">
                                <div>
                                    <div style="font-size:.78rem;font-weight:600;color:var(--primary-navy);margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.3px">
                                        <i class="bi bi-link-45deg"></i> Student Form Link
                                    </div>
                                    <div class="share-link-box">
                                        <input type="text" value="<?= h($public_url) ?>" id="shareUrl" readonly>
                                        <button onclick="copyLink()" id="copyBtn"><i class="bi bi-clipboard"></i> Copy</button>
                                    </div>
                                    <div style="font-size:.72rem;color:var(--medium-gray);margin-top:.5rem">
                                        Share this link with students on the final team. They can submit their jersey preferences without logging in.
                                    </div>
                                </div>
                                <div>
                                    <div style="font-size:.78rem;font-weight:600;color:var(--primary-navy);margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.3px;text-align:center">
                                        <i class="bi bi-qr-code"></i> QR Code
                                    </div>
                                    <div id="qrcode-canvas"></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="color:var(--medium-gray);font-size:.88rem">
                                <i class="bi bi-info-circle"></i> Click <strong>Open Form</strong> to generate the student link and QR code.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stats -->
                <?php if ($form): ?>
                <div class="stats-mini">
                    <div class="stat-mini">
                        <div class="number"><?= $stats['total'] ?></div>
                        <div class="label">Total Requests</div>
                    </div>
                    <div class="stat-mini">
                        <div class="number" style="color:#664d03"><?= $stats['pending'] ?></div>
                        <div class="label">Pending</div>
                    </div>
                    <div class="stat-mini">
                        <div class="number" style="color:#0a3622"><?= $stats['approved'] ?></div>
                        <div class="label">Approved</div>
                    </div>
                    <div class="stat-mini">
                        <div class="number" style="color:#842029"><?= $stats['rejected'] ?></div>
                        <div class="label">Rejected</div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Requests Table -->
                <div class="data-card">
                    <div class="data-card-header">
                        <h2><i class="bi bi-list-check"></i>&nbsp; Jersey Requests <span class="count-pill"><?= $stats['total'] ?></span></h2>
                        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                            <?php if ($stats['approved'] > 0): ?>
                                <a href="jersey_export.php?<?= h($list_query) ?>" class="btn btn-primary btn-sm">
                                    <i class="bi bi-download"></i> Export Order List
                                </a>
                            <?php endif; ?>
                            <?php if ($form): ?>
                                <button class="btn btn-outline-danger btn-sm" onclick="showDeleteModal()">
                                    <i class="bi bi-trash3"></i> Delete Jersey Data
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$requests): ?>
                        <div class="empty-row">
                            <i class="bi bi-inbox"></i>
                            No jersey requests yet.<?php if (!$is_open): ?><br><span style="font-size:.82rem">Open the form and share the link with students.</span><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Size</th>
                                    <th>Jersey Name</th>
                                    <th>Preferred No.</th>
                                    <th>Final No.</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($requests as $r): ?>
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
                                                <div class="student-meta"><?= h($r['enrollment_no']) ?> · <?= h($r['mobile']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><strong><?= h($r['tshirt_size']) ?></strong></td>
                                    <td><strong><?= h($r['jersey_name']) ?></strong></td>
                                    <td style="text-align:center"><?= (int)$r['preferred_number'] ?></td>
                                    <td style="text-align:center">
                                        <?php if ($r['final_number']): ?>
                                            <strong><?= (int)$r['final_number'] ?></strong>
                                        <?php else: ?>
                                            <span style="color:var(--medium-gray)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower($r['status']) ?>"><?= h($r['status']) ?></span>
                                    </td>
                                    <td>
                                        <div class="action-cell">
                                            <?php if ($r['status'] !== 'Approved'): ?>
                                                <form method="post" action="jersey_action.php" style="display:inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                                    <input type="hidden" name="game" value="<?= h($game) ?>">
                                                    <input type="hidden" name="event" value="<?= h($event) ?>">
                                                    <input type="hidden" name="ay" value="<?= h($ay) ?>">
                                <input type="hidden" name="gender" value="<?= h($gender) ?>">
                                                    <?php if ($dept_id !== null): ?><input type="hidden" name="dept" value="<?= (int)$dept_id ?>"><?php endif; ?>
                                                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Approve</button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Edit Number -->
                                            <form method="post" action="jersey_action.php" class="edit-num-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="edit_number">
                                                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                                <input type="hidden" name="game" value="<?= h($game) ?>">
                                                <input type="hidden" name="event" value="<?= h($event) ?>">
                                                <input type="hidden" name="ay" value="<?= h($ay) ?>">
                                <input type="hidden" name="gender" value="<?= h($gender) ?>">
                                                <?php if ($dept_id !== null): ?><input type="hidden" name="dept" value="<?= (int)$dept_id ?>"><?php endif; ?>
                                                <input type="number" name="final_number" value="<?= (int)($r['final_number'] ?: $r['preferred_number']) ?>" min="1" max="99">
                                                <button type="submit" class="btn btn-warning btn-sm" title="Save number"><i class="bi bi-pencil"></i></button>
                                            </form>

                                            <?php if ($r['status'] !== 'Rejected'): ?>
                                                <form method="post" action="jersey_action.php" style="display:inline" onsubmit="return confirm('Reject this request?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                                    <input type="hidden" name="game" value="<?= h($game) ?>">
                                                    <input type="hidden" name="event" value="<?= h($event) ?>">
                                                    <input type="hidden" name="ay" value="<?= h($ay) ?>">
                                <input type="hidden" name="gender" value="<?= h($gender) ?>">
                                                    <?php if ($dept_id !== null): ?><input type="hidden" name="dept" value="<?= (int)$dept_id ?>"><?php endif; ?>
                                                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg"></i> Reject</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-box">
            <h3><i class="bi bi-exclamation-triangle-fill" style="color:#dc3545"></i> Delete All Jersey Data?</h3>
            <p>This will permanently delete all jersey requests and the form for this team. This action cannot be undone. Make sure you've exported the order list first.</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="hideDeleteModal()">Cancel</button>
                <form method="post" action="jersey_action.php" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_all">
                    <input type="hidden" name="game" value="<?= h($game) ?>">
                    <input type="hidden" name="event" value="<?= h($event) ?>">
                    <input type="hidden" name="ay" value="<?= h($ay) ?>">
                    <input type="hidden" name="gender" value="<?= h($gender) ?>">
                    <?php if ($dept_id !== null): ?><input type="hidden" name="dept" value="<?= (int)$dept_id ?>"><?php endif; ?>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Yes, Delete All</button>
                </form>
            </div>
        </div>
    </div>

    <!-- QR Code Library (lightweight, CDN) -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <script>
        // QR Code generation
        (function() {
            const url = <?= json_encode($public_url) ?>;
            const container = document.getElementById('qrcode-canvas');
            if (container && url) {
                const qr = qrcode(0, 'M');
                qr.addData(url);
                qr.make();
                container.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });
            }
        })();

        // Copy link
        function copyLink() {
            const input = document.getElementById('shareUrl');
            const btn = document.getElementById('copyBtn');
            if (input) {
                input.select();
                input.setSelectionRange(0, 99999);
                navigator.clipboard.writeText(input.value).then(() => {
                    btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
                    setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy'; }, 2000);
                }).catch(() => {
                    document.execCommand('copy');
                    btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
                    setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy'; }, 2000);
                });
            }
        }

        // Delete modal
        function showDeleteModal() { document.getElementById('deleteModal').classList.add('show'); }
        function hideDeleteModal() { document.getElementById('deleteModal').classList.remove('show'); }
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) hideDeleteModal();
        });
    </script>
</body>
</html>
