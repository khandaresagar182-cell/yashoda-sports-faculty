<?php
/**
 * External Final Team — build the roster for one external-entry link and
 * generate its eligibility DOCX/PDF. No provisional stage for externals
 * (per the External Entries brief) — submitted entries go straight from
 * "External Students" into this roster.
 *
 * GET ?link=<id> selects the link (its game/level/gender/year are fixed,
 * so unlike final_list.php there's no free-text game/event picker — the
 * roster is scoped directly by link_id, not by a (game,event,ay,gender)
 * tuple, since two links could otherwise collide on those values).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_department();

$me     = current_faculty();
$deptId = effective_department_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $linkId = (int)($_POST['link_id'] ?? 0);
    $link   = $linkId > 0 ? external_link_by_id($linkId) : null;
    $back   = 'external_final_team.php' . ($linkId > 0 ? '?link=' . $linkId : '');

    if (!$link || $deptId === null || (int)$link['department_id'] !== $deptId) {
        flash_set('extf_err', 'Select a valid link first.', 'error');
        redirect($back);
    }

    if ($action === 'add') {
        $studentIds = array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['student_id'] ?? [])),
            static fn($v) => $v > 0
        )));
        if (!$studentIds) {
            flash_set('extf_err', 'Select at least one student to add.', 'error');
            redirect($back);
        }
        $ph = implode(',', array_fill(0, count($studentIds), '?'));
        $candidates = db_select(
            "SELECT id FROM external_students WHERE link_id = ? AND form_submitted_at IS NOT NULL AND id IN ($ph)",
            array_merge([$linkId], $studentIds), 'i' . str_repeat('i', count($studentIds))
        );
        $added = 0;
        foreach ($candidates as $c) {
            $entryParams = [(int)$c['id'], (string)$link['game_name'], (string)$link['gender'], (string)$link['academic_year'], (string)$link['game_level'], '', (int)$me['id']];
            $affected = db_execute(
                'INSERT IGNORE INTO external_team_entries (external_student_id, game_name, gender, academic_year, event_label, roll_no, added_by)
                 VALUES (?,?,?,?,?,?,?)',
                $entryParams,
                'isssssi'
            );
            if ($affected > 0) $added++;
        }
        flash_set('extf_ok', "Added $added student(s) to the final team.", 'success');
        redirect($back);
    }

    if ($action === 'remove') {
        $id = (int)($_POST['entry_id'] ?? 0);
        db_execute(
            'DELETE ete FROM external_team_entries ete
              JOIN external_students es ON es.id = ete.external_student_id
             WHERE ete.id = ? AND es.link_id = ?',
            [$id, $linkId], 'ii'
        );
        flash_set('extf_ok', 'Removed from the final team.', 'success');
        redirect($back);
    }

    http_response_code(400);
    exit('Bad request.');
}

$ok  = flash_get('extf_ok');
$err = flash_get('extf_err');
$genderOpts = gender_list_options();

$links = [];
$savedRosters = [];
if ($deptId !== null && $deptId > 0) {
    $links = db_select('SELECT * FROM external_entry_links WHERE department_id = ? ORDER BY created_at DESC', [$deptId], 'i');

    // "Folders" — one row per link that already has at least one confirmed
    // roster entry, same shape as final_list.php's $saved_lists.
    $savedRosters = db_select(
        'SELECT l.id AS link_id, l.game_name, l.game_level, l.gender, l.academic_year, l.representing_team,
                COUNT(ete.id) AS player_count, MAX(ete.created_at) AS last_added
           FROM external_entry_links l
           JOIN external_students es ON es.link_id = l.id
           JOIN external_team_entries ete ON ete.external_student_id = es.id
          WHERE l.department_id = ?
          GROUP BY l.id, l.game_name, l.game_level, l.gender, l.academic_year, l.representing_team
          ORDER BY last_added DESC',
        [$deptId], 'i'
    );
}

$linkId = (int)($_GET['link'] ?? 0);
$link   = $linkId > 0 ? external_link_by_id($linkId) : null;
if ($link && ($deptId === null || (int)$link['department_id'] !== $deptId)) {
    $link = null;
    $linkId = 0;
}

$roster = [];
$available = [];
$rosterTotal = 0;
$rpage  = max(1, (int)($_GET['rpage'] ?? 1));
$rper   = 50;
$rpages = 1;
if ($link) {
    $rosterTotal = (int)(db_one(
        'SELECT COUNT(*) AS n
           FROM external_team_entries ete
           JOIN external_students es ON es.id = ete.external_student_id
          WHERE es.link_id = ?',
        [$linkId], 'i'
    )['n'] ?? 0);
    $rpages = max(1, (int)ceil($rosterTotal / $rper));
    if ($rpage > $rpages) $rpage = $rpages;
    $roffset = ($rpage - 1) * $rper;

    $roster = db_select(
        "SELECT ete.id AS entry_id, ete.created_at AS added_at, es.*
           FROM external_team_entries ete
           JOIN external_students es ON es.id = ete.external_student_id
          WHERE es.link_id = ?
          ORDER BY ete.created_at ASC
          LIMIT $rper OFFSET $roffset",
        [$linkId], 'i'
    );
    // Roster IDs across the WHOLE team (not just this page) so the
    // available-to-add list doesn't re-offer someone already confirmed
    // on a different page of the roster.
    $rosterIdRows = db_select(
        'SELECT es.id FROM external_team_entries ete
           JOIN external_students es ON es.id = ete.external_student_id
          WHERE es.link_id = ?',
        [$linkId], 'i'
    );
    $rosterIds = array_map(static fn($r) => (int)$r['id'], $rosterIdRows);
    $available = db_select(
        'SELECT * FROM external_students WHERE link_id = ? AND form_submitted_at IS NOT NULL ORDER BY full_name',
        [$linkId], 'i'
    );
    $available = array_values(array_filter($available, static fn($s) => !in_array((int)$s['id'], $rosterIds, true)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>External Final Team | Sports Portal</title>
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
        .btn-danger{background:#fff5f5;color:#c53030;border:1px solid #fed7d7}.btn-danger:hover{background:#fed7d7}
        .btn-sm{padding:.35rem .7rem;font-size:.8rem}
        .data-card{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;overflow:hidden;margin-bottom:1.5rem}
        .data-card-header{padding:1rem 1.25rem;border-bottom:1px solid var(--light-gray);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
        .data-card-header h2{font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.7rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.7rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);vertical-align:middle}
        .data-table tr:last-child td{border-bottom:none}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .empty-row{text-align:center;color:var(--medium-gray);padding:3rem 1rem;font-size:.9rem}
        .empty-row i{font-size:2.5rem;display:block;margin-bottom:.5rem;color:var(--light-gray)}
        .pagination{padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;border-top:1px solid var(--light-gray)}
        .pagination .info{font-size:.85rem;color:var(--medium-gray)}
        .pagination .pages{display:flex;gap:.25rem}
        .pagination .pages a{padding:.35rem .65rem;border:1px solid var(--light-gray);border-radius:4px;font-size:.85rem;color:var(--primary-navy);text-decoration:none;background:var(--white)}
        .pagination .pages a:hover{background:var(--off-white)}
        .pagination .pages a.active{background:var(--primary-navy);color:var(--white);border-color:var(--primary-navy)}
        .pagination .pages a.disabled{opacity:.4;pointer-events:none}
        .two-col{display:grid;grid-template-columns:1.5fr 1fr;gap:1.25rem;align-items:start}
        @media (max-width: 1100px){.two-col{grid-template-columns:1fr}}
        .list-summary{background:var(--white);border:1px solid var(--light-gray);border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .list-summary .label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--medium-gray);margin-right:.4rem}
        .list-summary .value{font-weight:600;color:var(--primary-navy)}
        .list-summary .group{display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
        .list-row{display:flex;align-items:center;gap:.7rem;padding:.65rem 1.1rem;border-bottom:1px solid var(--light-gray)}
        .list-row:last-child{border-bottom:none}
        .list-row .info{flex:1;min-width:0}
        .list-row .info .name{font-weight:600;color:var(--primary-navy);font-size:.9rem}
        .list-row .info .meta{font-size:.75rem;color:var(--medium-gray);margin-top:.1rem}
        .icon-remove{background:0 0;border:1px solid var(--light-gray);color:var(--medium-gray);width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;flex-shrink:0;text-decoration:none;transition:var(--transition-smooth)}
        .icon-remove:hover{background:rgba(220,53,69,.1);border-color:rgba(220,53,69,.3);color:#dc3545}
        .count-pill{display:inline-block;background:rgba(201,162,39,.15);color:var(--accent-gold);font-size:.72rem;font-weight:700;padding:.15rem .55rem;border-radius:10px;margin-left:.4rem}
        .student-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-light));color:var(--white);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.72rem;flex-shrink:0;overflow:hidden}
        .student-avatar img{width:100%;height:100%;object-fit:cover}
        .search-form{background:var(--white);border-bottom:1px solid var(--light-gray);padding:1rem 1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end}
        .search-form .form-group{display:flex;flex-direction:column;gap:.3rem;flex:1;min-width:160px}
        .search-form label{font-size:.75rem;font-weight:600;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.3px}
        .search-form input{padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.9rem;background:var(--white);color:var(--text-dark)}
        .search-form input:focus{outline:none;border-color:var(--primary-navy)}
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
                <img src="<?= h(url('images/ytc-logo.png')) ?>" alt="YTC Logo" width="42" height="42">
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
                <a href="external_final_team.php" class="active"><i class="bi bi-people"></i> <span>External Final Team</span></a>
                <a href="external_eligibility_archive.php"><i class="bi bi-folder2"></i> <span>External Archive</span></a>
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
                    <a href="logout.php?_csrf=<?= h(csrf_token()) ?>" class="btn-logout" title="Logout" aria-label="Logout"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <header class="top-bar"><h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">External Final Team</h2></header>
            <div class="content-body">
                <?php if ($ok):  ?><div class="alert-banner success" role="alert"><i class="bi bi-check-circle"></i> <?= h($ok['msg']) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert-banner error" role="alert"><i class="bi bi-exclamation-circle"></i> <?= h($err['msg']) ?></div><?php endif; ?>

                <div class="page-header">
                    <h1>External Final Team</h1>
                    <p>Build the confirmed roster for one link, then generate its eligibility form. No provisional stage — submitted entries go straight here.</p>
                </div>

                <?php if (!$link): ?>
                    <div class="two-col" style="grid-template-columns: 1.2fr 1fr;">
                        <div class="data-card">
                            <div class="data-card-header"><h2><i class="bi bi-link-45deg"></i> &nbsp;Select a link</h2></div>
                            <form method="get" action="external_final_team.php" style="padding:1.25rem">
                                <div class="form-group" style="margin-bottom:1rem">
                                    <label for="link" style="display:block;font-size:.75rem;font-weight:600;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.3px;margin-bottom:.4rem">Link</label>
                                    <select id="link" name="link" required style="padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.9rem;background:var(--white);width:100%">
                                        <option value="">Choose a link&hellip;</option>
                                        <?php foreach ($links as $l): ?>
                                            <option value="<?= (int)$l['id'] ?>">
                                                <?= h($l['game_name'] . ' — ' . $l['game_level'] . ' — ' . ($genderOpts[$l['gender']] ?? $l['gender']) . ' — ' . $l['academic_year']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-right-circle"></i> Open</button>
                            </form>
                        </div>

                        <div class="data-card">
                            <div class="data-card-header">
                                <h2><i class="bi bi-collection"></i> &nbsp;Saved Final Teams</h2>
                                <?php if ($savedRosters): ?><span class="count-pill"><?= count($savedRosters) ?></span><?php endif; ?>
                            </div>
                            <?php if (!$savedRosters): ?>
                                <div class="empty-row"><i class="bi bi-collection"></i> No external final teams saved yet.</div>
                            <?php else: ?>
                                <?php foreach ($savedRosters as $sr): ?>
                                    <?php
                                        $sr_gender_label = $genderOpts[$sr['gender']] ?? $sr['gender'];
                                        $sr_count = (int)$sr['player_count'];
                                    ?>
                                    <div class="list-row">
                                        <div class="info">
                                            <div class="name"><?= h($sr['game_name']) ?> <span class="count-pill" style="background:rgba(26,54,93,.1);color:var(--primary-navy)"><?= h($sr_gender_label) ?></span></div>
                                            <div class="meta">
                                                <?= h($sr['game_level']) ?> · <?= h($sr['representing_team']) ?> · <?= h($sr['academic_year']) ?>
                                                <span class="count-pill"><?= $sr_count ?> player<?= $sr_count === 1 ? '' : 's' ?></span>
                                            </div>
                                        </div>
                                        <a class="btn btn-secondary" style="padding:.3rem .65rem;font-size:.78rem" href="external_final_team.php?link=<?= (int)$sr['link_id'] ?>" title="Edit this team">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="list-summary">
                        <div class="group">
                            <span><span class="label">Game</span><span class="value"><?= h($link['game_name']) ?></span></span>
                            <span><span class="label">Level</span><span class="value"><?= h($link['game_level']) ?></span></span>
                            <span><span class="label">Gender</span><span class="value"><?= h($genderOpts[$link['gender']] ?? $link['gender']) ?></span></span>
                            <span><span class="label">AY</span><span class="value"><?= h($link['academic_year']) ?></span></span>
                        </div>
                        <div class="group">
                            <?php if ($roster): ?>
                                <a class="btn btn-primary btn-sm" href="external_final_export_docx.php?link=<?= $linkId ?>"><i class="bi bi-file-earmark-word"></i> Export Word</a>
                                <a class="btn btn-secondary btn-sm" href="external_final_export_pdf.php?link=<?= $linkId ?>"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
                            <?php endif; ?>
                            <a href="external_final_team.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left-right"></i> Change list</a>
                        </div>
                    </div>

                    <div class="two-col">
                        <!-- LEFT: add from submitted entries -->
                        <div class="data-card">
                            <div class="data-card-header"><h2><i class="bi bi-search"></i> &nbsp;Add submitted entries to this team</h2></div>
                            <?php if (!$available): ?>
                                <div class="empty-row"><i class="bi bi-inbox"></i> No submitted entries waiting to be added.</div>
                            <?php else: ?>
                            <form method="post" action="external_final_team.php" id="bulkAddForm">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="link_id" value="<?= $linkId ?>">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th style="width:36px"><input type="checkbox" id="selectAllStudents" title="Select all" aria-label="Select all"></th>
                                            <th>Student</th>
                                            <th>Email</th>
                                            <th>College</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($available as $s): ?>
                                        <tr>
                                            <td><input type="checkbox" class="student-check" name="student_id[]" value="<?= (int)$s['id'] ?>"></td>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:.7rem">
                                                    <div class="student-avatar">
                                                        <?php if (!empty($s['photo_path']) && is_file(__DIR__ . '/../' . $s['photo_path'])): ?>
                                                            <img src="<?= h(url($s['photo_path'])) ?>" alt="" width="34" height="34">
                                                        <?php else: ?>
                                                            <?= h(initials($s['full_name'])) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="font-weight:600;color:var(--primary-navy)"><?= h($s['full_name']) ?></div>
                                                </div>
                                            </td>
                                            <td><?= h($s['email']) ?></td>
                                            <td><?= h($s['college_name']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div style="display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.1rem;border-top:1px solid var(--light-gray);background:var(--off-white)">
                                    <span id="selectedCount" style="font-size:.85rem;color:var(--medium-gray);font-weight:600">0 selected</span>
                                    <button type="submit" class="btn btn-primary" id="addSelectedBtn" disabled>
                                        <i class="bi bi-plus-circle"></i> Add Selected
                                    </button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>

                        <!-- RIGHT: current final team -->
                        <div class="data-card">
                            <div class="data-card-header">
                                <h2>
                                    <i class="bi bi-check-circle-fill"></i> &nbsp;Final Roster
                                    <span class="count-pill"><?= $rosterTotal ?> player<?= $rosterTotal === 1 ? '' : 's' ?></span>
                                </h2>
                            </div>
                            <?php if ($rosterTotal === 0): ?>
                                <div class="empty-row">
                                    <i class="bi bi-person-x"></i>
                                    No players confirmed for this team yet.<br>
                                    <span style="font-size:.82rem">Add submitted entries from the left.</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($roster as $r): ?>
                                    <div class="list-row">
                                        <div class="student-avatar">
                                            <?php if (!empty($r['photo_path']) && is_file(__DIR__ . '/../' . $r['photo_path'])): ?>
                                                <img src="<?= h(url($r['photo_path'])) ?>" alt="" width="34" height="34">
                                            <?php else: ?>
                                                <?= h(initials($r['full_name'])) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="info">
                                            <div class="name"><?= h($r['full_name']) ?></div>
                                            <div class="meta"><?= h($r['email']) ?> · <?= h($r['college_name']) ?></div>
                                        </div>
                                        <form method="post" action="external_final_team.php" onsubmit="return confirm('Remove <?= h(addslashes($r['full_name'])) ?> from final team?');" style="margin:0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="link_id" value="<?= $linkId ?>">
                                            <input type="hidden" name="entry_id" value="<?= (int)$r['entry_id'] ?>">
                                            <button type="submit" class="icon-remove" title="Remove" aria-label="Remove <?= h($r['full_name']) ?> from final team"><i class="bi bi-x-lg"></i></button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($rpages > 1): ?>
                                    <div class="pagination">
                                        <div class="info">Page <?= $rpage ?> of <?= $rpages ?> · <?= $rosterTotal ?> total</div>
                                        <div class="pages">
                                            <?php
                                            $rprev = max(1, $rpage - 1);
                                            $rnext = min($rpages, $rpage + 1);
                                            ?>
                                            <a class="<?= $rpage <= 1 ? 'disabled' : '' ?>" aria-label="Previous page" href="?link=<?= $linkId ?>&rpage=<?= $rprev ?>"><i class="bi bi-chevron-left"></i></a>
                                            <?php for ($i = 1; $i <= $rpages; $i++): ?>
                                                <a class="<?= $i === $rpage ? 'active' : '' ?>" href="?link=<?= $linkId ?>&rpage=<?= $i ?>"><?= $i ?></a>
                                            <?php endfor; ?>
                                            <a class="<?= $rpage >= $rpages ? 'disabled' : '' ?>" aria-label="Next page" href="?link=<?= $linkId ?>&rpage=<?= $rnext ?>"><i class="bi bi-chevron-right"></i></a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var form = document.getElementById('bulkAddForm');
        if (!form) return;
        var selectAll = document.getElementById('selectAllStudents');
        var checks    = Array.prototype.slice.call(form.querySelectorAll('.student-check'));
        var countEl   = document.getElementById('selectedCount');
        var addBtn    = document.getElementById('addSelectedBtn');

        function refresh() {
            var n = checks.filter(function (c) { return c.checked; }).length;
            countEl.textContent = n + (n === 1 ? ' selected' : ' selected');
            addBtn.disabled = n === 0;
            if (selectAll) selectAll.checked = n > 0 && n === checks.length;
        }

        checks.forEach(function (c) { c.addEventListener('change', refresh); });
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checks.forEach(function (c) { c.checked = selectAll.checked; });
                refresh();
            });
        }
        refresh();
    })();
    </script>
</body>
</html>
