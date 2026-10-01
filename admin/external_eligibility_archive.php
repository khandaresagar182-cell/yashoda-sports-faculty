<?php
/**
 * External Eligibility Archive — same mechanism as admin/eligibility_archive.php
 * (year-wise backup of every generated Word eligibility form), filtered to
 * is_external = 1 so external-entry archives never mix with regular ones.
 * Downloads still go through admin/eligibility_download.php (it scopes by
 * department_id + row id only, so it already works for both).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/eligibility_archive.php';
require_once __DIR__ . '/../includes/mailer.php';

require_login();
require_department();

$me     = current_faculty();
$deptId = effective_department_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action    = (string)($_POST['action'] ?? '');
    $yearParam = trim((string)($_POST['year'] ?? ''));
    $back      = 'external_eligibility_archive.php' . ($yearParam !== '' ? '?year=' . urlencode($yearParam) : '');

    if ($deptId === null || $deptId <= 0) {
        flash_set('extarch_err', 'Select a faculty first.', 'error');
        redirect($back);
    }

    if ($action === 'save_email') {
        $email = trim((string)($_POST['archive_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('extarch_err', 'That does not look like a valid email address.', 'error');
        } else {
            db_execute('UPDATE faculty SET archive_email = ? WHERE id = ?', [$email !== '' ? $email : null, (int)$me['id']], 'si');
            flash_set('extarch_ok', $email !== '' ? 'Backup email saved.' : 'Backup email cleared.', 'success');
        }
        redirect($back);
    }

    if ($action === 'send') {
        $now = time();
        if (($_SESSION['extarch_last_send'] ?? 0) > $now - 15) {
            flash_set('extarch_err', 'Please wait a few seconds before sending again.', 'error');
            redirect($back);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn($v) => $v > 0)));
        if (!$ids) {
            flash_set('extarch_err', 'Select at least one eligibility form to send.', 'error');
            redirect($back);
        }
        $rows = [];
        foreach ($ids as $id) {
            $row = eligibility_archive_row($id, $deptId);
            if ($row !== null && (int)($row['is_external'] ?? 0) === 1) $rows[] = $row;
        }
        if (!$rows) {
            flash_set('extarch_err', 'None of the selected files are available.', 'error');
            redirect($back);
        }
        $target = trim((string)($_POST['archive_email'] ?? ''));
        if ($target === '') {
            $saved  = db_one('SELECT archive_email FROM faculty WHERE id = ?', [(int)$me['id']], 'i');
            $target = trim((string)($saved['archive_email'] ?? ''));
        }
        if ($target === '' || !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            flash_set('extarch_err', 'Enter a valid email address (or save one) before sending.', 'error');
            redirect($back);
        }
        if (!mail_is_configured()) {
            flash_set('extarch_err', 'Email is not set up on the server yet — ask the administrator to configure SMTP.', 'error');
            redirect($back);
        }
        try {
            if (count($rows) === 1) {
                $abs  = eligibility_archive_abs_path($rows[0]);
                $data = $abs !== null ? @file_get_contents($abs) : false;
                if ($data === false) throw new RuntimeException('The selected file could not be read.');
                $attachments = [['data' => $data, 'name' => (string)$rows[0]['file_name']]];
            } else {
                $zipName = 'External_Eligibility_' . eligibility_archive_slug((string)($me['department_name'] ?? 'archive'), 'archive')
                    . ($yearParam !== '' ? '_' . eligibility_archive_year_slug($yearParam) : '') . '.zip';
                $attachments = [['data' => eligibility_archive_zip($rows), 'name' => $zipName]];
            }
            $ok = send_eligibility_archive_email($target, (string)($me['department_name'] ?? 'Sports') . ' (External)', $yearParam !== '' ? $yearParam : 'all years', $attachments);
            $_SESSION['extarch_last_send'] = $now;
            flash_set($ok ? 'extarch_ok' : 'extarch_err', $ok ? ('Sent ' . count($rows) . ' file(s) to ' . h($target) . '.') : 'The email could not be sent. Please try again later.', $ok ? 'success' : 'error');
        } catch (\Throwable $e) {
            error_log('[external_eligibility_archive] send failed: ' . $e->getMessage());
            flash_set('extarch_err', 'Could not prepare the files: ' . h($e->getMessage()), 'error');
        }
        redirect($back);
    }

    if ($action === 'delete') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn($v) => $v > 0)));
        if (!$ids) {
            flash_set('extarch_err', 'Select at least one eligibility form to delete.', 'error');
            redirect($back);
        }
        $deletedCount = 0;
        foreach ($ids as $id) {
            $row = eligibility_archive_row($id, $deptId);
            if ($row === null || (int)($row['is_external'] ?? 0) !== 1) continue;
            $abs = eligibility_archive_abs_path($row);
            if ($abs !== null) @unlink($abs);
            db_execute('DELETE FROM eligibility_archive WHERE id = ? AND department_id = ? AND is_external = 1', [$id, $deptId], 'ii');
            $deletedCount++;
        }
        flash_set($deletedCount > 0 ? 'extarch_ok' : 'extarch_err', $deletedCount > 0 ? "Deleted {$deletedCount} eligibility form(s)." : 'None of the selected files were found.', $deletedCount > 0 ? 'success' : 'error');
        redirect($back);
    }

    http_response_code(400);
    exit('Bad request.');
}

$ok  = flash_get('extarch_ok');
$err = flash_get('extarch_err');

$year        = trim((string)($_GET['year'] ?? ''));
$yearIsValid = $year !== '' && preg_match('/^(\d{4}-\d{2}|unspecified)$/', $year) === 1;
if ($year !== '' && !$yearIsValid) {
    http_response_code(400);
    exit('Invalid academic year.');
}

$years = [];
$files = [];
$savedEmail = '';
if ($deptId !== null && $deptId > 0) {
    $savedRow   = db_one('SELECT archive_email FROM faculty WHERE id = ?', [(int)$me['id']], 'i');
    $savedEmail = (string)($savedRow['archive_email'] ?? '');
    if ($yearIsValid) {
        $files = eligibility_archive_files($deptId, $year === 'unspecified' ? '' : $year, true);
    } else {
        $years = eligibility_archive_years($deptId, true);
    }
}
$yearLabel = static fn(string $ay): string => $ay === '' ? 'Unspecified' : $ay;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>External Eligibility Archive | Sports Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">
    <style>
        :root{--primary-navy:#1a365d;--primary-navy-dark:#0f2744;--primary-navy-light:#2c5282;--accent-gold:#c9a227;--accent-maroon:#722f37;--white:#fff;--off-white:#f8f9fa;--light-gray:#e9ecef;--medium-gray:#6c757d;--dark-gray:#343a40;--text-dark:#212529;--font-primary:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;--sidebar-width:260px;--transition-smooth:all .3s ease-in-out}
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
        .btn-danger{background:#fff5f5;color:#c53030;border:1px solid #fed7d7}.btn-danger:hover:not(:disabled){background:#fed7d7}.btn-danger:disabled{opacity:.5;cursor:not-allowed}
        .btn-sm{padding:.35rem .7rem;font-size:.8rem}
        .data-card{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;overflow:hidden}
        .data-card-header{padding:1rem 1.25rem;border-bottom:1px solid var(--light-gray);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
        .data-card-header h2{font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.7rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.7rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);color:var(--text-dark);vertical-align:middle}
        .data-table tr:last-child td{border-bottom:none}
        .data-table tr:hover{background:var(--off-white)}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .empty-row{text-align:center;color:var(--medium-gray);padding:3rem 1rem;font-size:.9rem}
        .empty-row i{font-size:2.5rem;display:block;margin-bottom:.5rem;color:var(--light-gray)}
        .year-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem}
        .year-card{display:block;background:var(--white);border:1px solid var(--light-gray);border-radius:10px;padding:1.1rem 1.2rem;text-decoration:none;color:inherit;transition:var(--transition-smooth)}
        .year-card:hover{border-color:var(--primary-navy);box-shadow:0 4px 14px rgba(15,23,42,.08);transform:translateY(-1px)}
        .year-card .yc-ico{font-size:1.5rem;color:var(--accent-gold)}
        .year-card .yc-name{font-weight:700;color:var(--primary-navy);font-size:1.05rem;margin:.35rem 0 .1rem}
        .year-card .yc-meta{font-size:.78rem;color:var(--medium-gray)}
        .mail-panel{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;padding:1.1rem 1.25rem;margin-top:1.25rem}
        .mail-panel h3{font-size:.95rem;font-weight:700;color:var(--primary-navy);margin:0 0 .3rem;display:flex;align-items:center;gap:.5rem}
        .mail-panel p.hint{font-size:.8rem;color:var(--medium-gray);margin:0 0 .8rem}
        .mail-row{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
        .mail-row input[type=email]{flex:1;min-width:220px;padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font:inherit;font-size:.9rem}
        .crumbs{font-size:.85rem;color:var(--medium-gray);margin-bottom:1rem}
        .crumbs a{color:var(--primary-navy);text-decoration:none;font-weight:600}
        .dl-link{color:var(--primary-navy);font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem}
        .sec-tag{display:inline-block;background:rgba(26,54,93,.08);color:var(--primary-navy);padding:.15rem .55rem;border-radius:4px;font-size:.72rem;font-weight:700}
        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .data-card{overflow-x:auto;-webkit-overflow-scrolling:touch}
            .data-table{min-width:640px}
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <img src="<?= h(url('images/ytc-logo.png')) ?>" alt="YTC Logo">
                <div class="sidebar-brand-text"><h2>Sports Database</h2><span>Yashoda Technical Campus</span></div>
            </div>
            <nav class="sidebar-nav">
                <div class="sidebar-nav-label">Main</div>
                <?php if (has_multiple_departments()): ?><a href="../faculty-select.php?change=1"><i class="bi bi-building"></i> <span>Select Faculty</span></a><?php endif; ?>
                <a href="dashboard.php"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a>
                <a href="../student-search.php"><i class="bi bi-search"></i> <span>Search Students</span></a>
                <a href="../student-profile.php?new=1"><i class="bi bi-person-plus"></i> <span>Add Student</span></a>
                <a href="provisional_list.php"><i class="bi bi-clipboard-check"></i> <span>Provisional Players</span></a>
                <a href="final_list.php"><i class="bi bi-check-all"></i> <span>Final Teams</span></a>
                <a href="eligibility_archive.php"><i class="bi bi-folder2-open"></i> <span>Eligibility Archive</span></a>
                <div class="sidebar-nav-label">External Entries</div>
                <a href="external_entries.php"><i class="bi bi-link-45deg"></i> <span>Links &amp; Entries</span></a>
                <a href="external_final_team.php"><i class="bi bi-people"></i> <span>External Final Team</span></a>
                <a href="external_eligibility_archive.php" class="active"><i class="bi bi-folder2"></i> <span>External Archive</span></a>
                <div class="sidebar-nav-label">Other</div>
                <a href="jersey_dashboard.php"><i class="bi bi-person-badge"></i> <span>Jersey Kit</span></a>
                <a href="data_management.php"><i class="bi bi-database-fill-gear"></i> <span>Data Management</span></a>
                <?php if (($me['role'] ?? '') === 'SUPER_ADMIN'): ?>
                    <div class="sidebar-nav-label">Admin</div>
                    <a href="faculty_manage.php"><i class="bi bi-people-fill"></i> <span>Faculty Management</span></a>
                <?php endif; ?>
                <div class="sidebar-nav-label">Site</div>
                <a href="../index.php"><i class="bi bi-globe"></i> <span>View Website</span></a>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar"><?= h(initials($me['full_name'])) ?></div>
                    <div class="sidebar-user-info"><h4><?= h($me['full_name']) ?></h4><span><?= h($me['department_name'] ?? $me['role']) ?></span></div>
                    <a href="logout.php?_csrf=<?= h(csrf_token()) ?>" class="btn-logout" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <header class="top-bar"><h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">External Eligibility Archive</h2></header>
            <div class="content-body">
                <?php if ($ok):  ?><div class="alert-banner success" role="alert"><i class="bi bi-check-circle"></i> <?= h($ok['msg']) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert-banner error" role="alert"><i class="bi bi-exclamation-circle"></i> <?= h($err['msg']) ?></div><?php endif; ?>

                <?php if ($deptId === null || $deptId <= 0): ?>
                    <div class="page-header"><h1>External Eligibility Archive</h1><p>Year-wise backup of every external-entry eligibility form.</p></div>
                    <div class="data-card"><div class="empty-row">
                        <i class="bi bi-building"></i> Select a faculty to view its external eligibility archive.<br><br>
                        <a href="../faculty-select.php?change=1" class="btn btn-secondary btn-sm"><i class="bi bi-building"></i> Select Faculty</a>
                    </div></div>

                <?php elseif (!$yearIsValid): ?>
                    <div class="page-header">
                        <h1>External Eligibility Archive &mdash; <?= h($me['department_name'] ?? '') ?></h1>
                        <p>Every Word eligibility form generated from <strong>External Final Team &rarr; Export Word</strong> is saved here, filed by academic year.</p>
                    </div>
                    <?php if (!$years): ?>
                        <div class="data-card"><div class="empty-row">
                            <i class="bi bi-folder2-open"></i> No external eligibility forms archived yet.<br>
                            <span style="font-size:.82rem">Open an external final team and click <strong>Export Word</strong> &mdash; a copy is filed here automatically.</span>
                        </div></div>
                    <?php else: ?>
                        <div class="year-grid">
                            <?php foreach ($years as $y): ?>
                                <?php $ay = (string)$y['academic_year']; $slug = $ay === '' ? 'unspecified' : $ay; ?>
                                <a class="year-card" href="external_eligibility_archive.php?year=<?= h(urlencode($slug)) ?>">
                                    <i class="bi bi-folder-fill yc-ico"></i>
                                    <div class="yc-name"><?= h($yearLabel($ay)) ?></div>
                                    <div class="yc-meta"><?= (int)$y['n'] ?> file<?= (int)$y['n'] === 1 ? '' : 's' ?> &middot; updated <?= h(date('d M Y', strtotime((string)$y['last_at']))) ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <?php $ayDisplay = $year === 'unspecified' ? 'Unspecified' : $year; ?>
                    <div class="crumbs"><a href="external_eligibility_archive.php"><i class="bi bi-folder2-open"></i> External Eligibility Archive</a> &nbsp;&rsaquo;&nbsp; <?= h($ayDisplay) ?></div>
                    <div class="page-header"><h1><?= h($ayDisplay) ?></h1><p><?= count($files) ?> eligibility form<?= count($files) === 1 ? '' : 's' ?> archived for <?= h($me['department_name'] ?? '') ?>.</p></div>

                    <?php if (!$files): ?>
                        <div class="data-card"><div class="empty-row"><i class="bi bi-inbox"></i> No files in this folder.</div></div>
                    <?php else: ?>
                        <form method="post" action="external_eligibility_archive.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="year" value="<?= h($year) ?>">
                            <div class="data-card">
                                <div class="data-card-header">
                                    <h2><?= h($ayDisplay) ?> &mdash; files</h2>
                                    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                                        <label style="font-size:.82rem;color:var(--medium-gray);display:flex;align-items:center;gap:.4rem;cursor:pointer"><input type="checkbox" id="selAll"> Select all (whole folder)</label>
                                        <button type="submit" name="action" value="delete" class="btn btn-danger btn-sm" id="deleteSelBtn" disabled onclick="return confirm('Permanently delete the selected eligibility form(s)? This cannot be undone.');"><i class="bi bi-trash3"></i> Delete selected</button>
                                    </div>
                                </div>
                                <div style="overflow-x:auto"><table class="data-table">
                                    <thead><tr><th style="width:34px"></th><th>Game</th><th>Section</th><th>Event</th><th>Players</th><th>Generated</th><th>Download</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($files as $f): ?>
                                        <?php $sec = gender_list_options()[(string)($f['gender'] ?? '')] ?? ''; ?>
                                        <tr>
                                            <td><input type="checkbox" class="fileChk" name="ids[]" value="<?= (int)$f['id'] ?>" aria-label="Select <?= h($f['game_name']) ?> eligibility form"></td>
                                            <td style="font-weight:600;color:var(--primary-navy)"><?= h($f['game_name']) ?></td>
                                            <td><?= $sec !== '' ? '<span class="sec-tag">' . h($sec) . '</span>' : '<span style="color:var(--medium-gray)">&mdash;</span>' ?></td>
                                            <td><?= h((string)($f['event_label'] ?? '')) ?: '<span style="color:var(--medium-gray)">&mdash;</span>' ?></td>
                                            <td><?= (int)$f['player_count'] ?></td>
                                            <td style="white-space:nowrap"><?= h(date('d M Y, H:i', strtotime((string)$f['created_at']))) ?></td>
                                            <td><a class="dl-link" href="eligibility_download.php?id=<?= (int)$f['id'] ?>"><i class="bi bi-file-earmark-word"></i> Word</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table></div>
                            </div>
                            <div class="mail-panel">
                                <h3><i class="bi bi-envelope-arrow-up"></i> Email a backup</h3>
                                <p class="hint">
                                    Tick one file to email it as Word, or tick several / <em>Select all</em> to email the folder as one ZIP.
                                    <?php if (!mail_is_configured()): ?><br><strong style="color:#842029">Email is not configured on this server yet.</strong><?php endif; ?>
                                </p>
                                <div class="mail-row">
                                    <input type="email" name="archive_email" placeholder="you@example.com" value="<?= h($savedEmail) ?>" autocomplete="email">
                                    <button type="submit" name="action" value="save_email" class="btn btn-secondary"><i class="bi bi-bookmark"></i> Save email</button>
                                    <button type="submit" name="action" value="send" class="btn btn-primary" <?= !mail_is_configured() ? 'disabled' : '' ?>><i class="bi bi-send"></i> Send selected</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var all = document.getElementById('selAll');
            if (!all) return;
            var boxes = Array.prototype.slice.call(document.querySelectorAll('.fileChk'));
            var deleteBtn = document.getElementById('deleteSelBtn');
            function refreshDeleteBtn() { if (deleteBtn) deleteBtn.disabled = !boxes.some(function (b) { return b.checked; }); }
            all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); refreshDeleteBtn(); });
            boxes.forEach(function (b) { b.addEventListener('change', function () { if (!b.checked) all.checked = false; refreshDeleteBtn(); }); });
            refreshDeleteBtn();
        })();
    </script>
</body>
</html>
