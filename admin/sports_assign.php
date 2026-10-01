<?php
/**
 * Super-admin only: choose which sports a faculty/department's students can
 * pick from in Step 3 (Played) of the student wizard, and in the Provisional
 * / Final "Game" dropdowns for that department.
 *
 * Reads / writes `dept_game_catalog.is_active`. The 42-game catalog itself
 * (migration-v43-unified-game-catalog.sql) is shared across every
 * department — this screen only toggles which of those rows are switched
 * on for one department at a time. No rows are ever added or removed here,
 * so existing student picks (student_selected_games) are never touched.
 *
 * If every game is switched off for a department, that department's wizard
 * falls back to the legacy free-text Sport 1 / Sport 2 fields (see
 * student-profile.php $uses_game_picker).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('SUPER_ADMIN');

$me = current_faculty();

$departments = db_select('SELECT id, code, name FROM departments ORDER BY display_order, id');
$dept_by_id  = [];
foreach ($departments as $d) {
    $dept_by_id[(int)$d['id']] = $d;
}

/** Resolve the department in scope from ?dept=, falling back to the first. */
function sa_current_dept_id(array $dept_by_id): int
{
    $req = (int)($_GET['dept'] ?? $_POST['dept'] ?? 0);
    if ($req > 0 && isset($dept_by_id[$req])) {
        return $req;
    }
    return (int)(array_key_first($dept_by_id) ?? 0);
}

$dept_id = sa_current_dept_id($dept_by_id);
$dept    = $dept_by_id[$dept_id] ?? null;

$ok  = flash_get('sports_assign_saved');
$err = flash_get('sports_assign_error');

if (!$dept) {
    http_response_code(500);
    exit('No departments found.');
}

/* ---------------- POST handler ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? 'save_department');

    if ($do === 'bulk_assign') {
        $sport_codes = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['bulk_game_codes'] ?? [])))));
        $dept_ids    = array_map('intval', (array)($_POST['bulk_dept_ids'] ?? []));
        $dept_ids    = array_values(array_unique(array_filter($dept_ids, static fn($v) => isset($dept_by_id[$v]))));

        if (!$sport_codes || !$dept_ids) {
            flash_set('sports_assign_error', 'Pick at least one sport and at least one faculty/department to assign.', 'error');
            redirect('sports_assign.php');
        }

        $sportPh = implode(',', array_fill(0, count($sport_codes), '?'));
        $deptPh  = implode(',', array_fill(0, count($dept_ids), '?'));
        $changed = db_execute(
            "UPDATE dept_game_catalog
                SET is_active = 1
              WHERE game_code IN ($sportPh)
                AND department_id IN ($deptPh)
                AND is_active = 0",
            array_merge($sport_codes, $dept_ids),
            str_repeat('s', count($sport_codes)) . str_repeat('i', count($dept_ids))
        );

        $deptNames = array_map(static fn($id) => $dept_by_id[$id]['name'], $dept_ids);
        flash_set(
            'sports_assign_saved',
            count($sport_codes) . ' sport(s) assigned to ' . implode(', ', $deptNames)
                . " — {$changed} newly turned on (any already active were left as is).",
            'success'
        );
        redirect('sports_assign.php?dept=' . $dept_ids[0]);
    }

    // do === 'save_department' — exact on/off review for one department
    $post_dept = (int)($_POST['dept'] ?? 0);
    if ($post_dept <= 0 || !isset($dept_by_id[$post_dept])) {
        flash_set('sports_assign_error', 'Unknown department.', 'error');
        redirect('sports_assign.php');
    }
    $back = 'sports_assign.php?dept=' . $post_dept;

    $checked = array_flip(array_map('strval', (array)($_POST['game_codes'] ?? [])));
    $rows    = db_select('SELECT id, game_code, is_active FROM dept_game_catalog WHERE department_id = ?', [$post_dept], 'i');

    $changed = 0;
    foreach ($rows as $r) {
        $shouldBeActive = isset($checked[$r['game_code']]) ? 1 : 0;
        if ($shouldBeActive !== (int)$r['is_active']) {
            db_execute('UPDATE dept_game_catalog SET is_active = ? WHERE id = ?', [$shouldBeActive, (int)$r['id']], 'ii');
            $changed++;
        }
    }

    $activeCount = count($checked);
    flash_set(
        'sports_assign_saved',
        $changed > 0
            ? "Saved — {$activeCount} sport(s) active for {$dept_by_id[$post_dept]['name']} ({$changed} changed)."
            : 'No changes.',
        'success'
    );
    redirect($back);
}

/* ---------------- data for view ---------------- */

// The catalog is one unified 42-game list shared by every department
// (migration-v43) — DISTINCT collapses it to the master list for the
// bulk-assign picker below.
$all_games = db_select(
    'SELECT DISTINCT game_code, display_name FROM dept_game_catalog ORDER BY display_name'
);

$games = db_select(
    'SELECT id, game_code, display_name, is_active
       FROM dept_game_catalog
      WHERE department_id = ?
      ORDER BY display_name',
    [$dept_id], 'i'
);
$activeCount = 0;
foreach ($games as $g) {
    if ((int)$g['is_active'] === 1) $activeCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sports Assignment | Sports Portal</title>
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
        .page-header{margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
        .page-header h1{font-size:1.4rem;font-weight:700;color:var(--primary-navy);margin:0}
        .btn{padding:.55rem 1.1rem;border-radius:6px;font-size:.88rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem}
        .btn-primary{background:var(--primary-navy);color:#fff}.btn-primary:hover{background:var(--primary-navy-dark)}
        .btn-secondary{background:var(--off-white);color:var(--primary-navy);border:1px solid var(--light-gray)}.btn-secondary:hover{background:var(--light-gray)}
        .data-card{background:#fff;border:1px solid var(--light-gray);border-radius:10px;overflow:hidden;margin-bottom:1.25rem}
        .muted{color:var(--medium-gray);font-size:.8rem}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .toolbar{display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
        .toolbar .form-group{margin:0}
        .form-group{display:flex;flex-direction:column;gap:.3rem}
        .form-group label{font-size:.78rem;font-weight:600;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.3px}
        .form-group select{padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.92rem;background:#fff;min-width:220px}
        .hint-box{background:rgba(13,110,253,.05);border:1px solid rgba(13,110,253,.15);border-radius:8px;padding:.75rem 1rem;font-size:.82rem;color:#052c65;margin-bottom:1.25rem}
        .warn-box{background:rgba(255,193,7,.1);border:1px solid rgba(255,193,7,.3);color:#664d03;padding:.75rem 1rem;border-radius:8px;font-size:.82rem;margin-bottom:1.25rem;display:none}
        .games-body{padding:1.25rem 1.5rem}
        .games-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
        .games-count{font-size:.85rem;font-weight:600;color:var(--primary-navy)}
        .quick-actions{display:flex;gap:.5rem}
        .quick-actions button{background:none;border:none;color:var(--primary-navy-light);font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:underline;padding:0}
        .game-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.5rem}
        .game-check{display:flex;align-items:center;gap:.5rem;padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-size:.9rem;font-weight:500;cursor:pointer;background:#fff}
        .game-check:hover{border-color:var(--primary-navy-light);background:var(--off-white)}
        .game-check input{width:auto;margin:0}
        .form-actions{display:flex;gap:.75rem;margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--light-gray)}
        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .btn{width:100%;justify-content:center}
            .toolbar{flex-direction:column;align-items:stretch}
            .form-actions{flex-direction:column}
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
                <a href="achievements_list.php"><i class="bi bi-trophy"></i> <span>Achievements</span></a>
                <a href="committee_manage.php"><i class="bi bi-people-fill"></i> <span>Committee</span></a>
                <div class="sidebar-nav-label">Admin</div>
                <a href="faculty_manage.php"><i class="bi bi-people-fill"></i> <span>Faculty Management</span></a>
                <a href="document_requirements.php"><i class="bi bi-file-earmark-ruled"></i> <span>Document Requirements</span></a>
                <a href="sports_assign.php" class="active"><i class="bi bi-trophy-fill"></i> <span>Sports Assignment</span></a>
                <div class="sidebar-nav-label">Site</div>
                <a href="../index.php"><i class="bi bi-globe"></i> <span>View Website</span></a>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar"><?= h(initials($me['full_name'])) ?></div>
                    <div class="sidebar-user-info">
                        <h4><?= h($me['full_name']) ?></h4>
                        <span><?= h($me['role']) ?></span>
                    </div>
                    <a href="logout.php?_csrf=<?= h(csrf_token()) ?>" class="btn-logout" title="Logout" aria-label="Logout"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <header class="top-bar">
                <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">Sports Assignment</h2>
            </header>

            <div class="content-body">
                <?php if ($ok): ?><div class="alert-banner success" role="alert"><i class="bi bi-check-circle"></i> <?= h($ok['msg']) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert-banner error" role="alert"><i class="bi bi-exclamation-circle"></i> <?= h($err['msg']) ?></div><?php endif; ?>

                <div class="page-header">
                    <h1>Sports Assignment</h1>
                </div>

                <div class="hint-box">
                    <i class="bi bi-info-circle"></i>
                    Sports are tied to a faculty/department (all its students share one picker). Use
                    <strong>Bulk Assign</strong> below to grant several sports to several departments at once, or
                    <strong>Review by Department</strong> to fine-tune the exact list for one department. Either way
                    this controls <strong>Step 3 &mdash; Played</strong> of the student wizard <em>and</em> the Game
                    dropdown on that department's Provisional Players / Final Teams lists. Students' existing picks
                    are kept even if you later untick a sport they already chose.
                </div>

                <div class="page-header" style="margin-bottom:.75rem">
                    <h1 style="font-size:1.1rem">Bulk Assign</h1>
                </div>
                <p class="muted" style="margin-bottom:1rem">
                    Tick sports, tick faculties/departments, then Assign. This only turns sports <strong>on</strong>
                    for the picked departments &mdash; it never removes a sport that's already active anywhere.
                </p>

                <form method="post" action="sports_assign.php" id="bulkForm"
                      onsubmit="return confirm('Assign the selected sport(s) to the selected faculty/department(s)?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="bulk_assign">
                    <div class="data-card">
                        <div class="games-body">
                            <div class="games-head">
                                <span class="games-count" id="bulkSportsCount">0 sport(s) selected</span>
                                <div class="quick-actions">
                                    <button type="button" id="bulkSportsAllBtn">Select all</button>
                                    <span class="muted">|</span>
                                    <button type="button" id="bulkSportsNoneBtn">Select none</button>
                                </div>
                            </div>
                            <div class="game-grid">
                                <?php foreach ($all_games as $g): ?>
                                    <label class="game-check">
                                        <input type="checkbox" name="bulk_game_codes[]" value="<?= h($g['game_code']) ?>">
                                        <span><?= h($g['display_name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="data-card">
                        <div class="games-body">
                            <div class="games-head">
                                <span class="games-count" id="bulkDeptsCount">0 faculty/department(s) selected</span>
                            </div>
                            <div class="game-grid">
                                <?php foreach ($departments as $d): ?>
                                    <label class="game-check">
                                        <input type="checkbox" name="bulk_dept_ids[]" value="<?= (int)$d['id'] ?>">
                                        <span><?= h($d['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="bulkSubmitBtn" disabled>
                            <i class="bi bi-check2-circle"></i> Assign selected sports
                        </button>
                    </div>
                </form>

                <div class="page-header" style="margin-top:2rem;margin-bottom:.75rem">
                    <h1 style="font-size:1.1rem">Review by Department</h1>
                </div>

                <div class="warn-box" id="allOffWarning">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    No sports are ticked. Students in this department will see the old free-text Sport 1 / Sport 2
                    fields instead of the sport picker.
                </div>

                <form method="get" action="sports_assign.php" class="toolbar">
                    <div class="form-group">
                        <label for="dept">Faculty / Department</label>
                        <select id="dept" name="dept">
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $dept_id ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary">Go</button>
                </form>

                <form method="post" action="sports_assign.php" id="sportsForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="save_department">
                    <input type="hidden" name="dept" value="<?= $dept_id ?>">
                    <div class="data-card">
                        <div class="games-body">
                            <?php if (!$games): ?>
                                <p class="muted">No sports catalog found for <?= h($dept['name']) ?>.</p>
                            <?php else: ?>
                                <div class="games-head">
                                    <span class="games-count" id="gamesCount"><?= $activeCount ?> of <?= count($games) ?> active for <?= h($dept['name']) ?></span>
                                    <div class="quick-actions">
                                        <button type="button" id="selectAllBtn">Select all</button>
                                        <span class="muted">|</span>
                                        <button type="button" id="selectNoneBtn">Select none</button>
                                    </div>
                                </div>
                                <div class="game-grid">
                                    <?php foreach ($games as $g): ?>
                                        <label class="game-check">
                                            <input type="checkbox" name="game_codes[]" value="<?= h($g['game_code']) ?>"
                                                   <?= (int)$g['is_active'] === 1 ? 'checked' : '' ?>>
                                            <span><?= h($g['display_name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($games): ?>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save for <?= h($dept['name']) ?></button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var form = document.getElementById('sportsForm');
            if (!form) return;
            var boxes  = Array.prototype.slice.call(form.querySelectorAll('input[name="game_codes[]"]'));
            var count  = document.getElementById('gamesCount');
            var warn   = document.getElementById('allOffWarning');
            var total  = boxes.length;

            function refresh() {
                var n = boxes.filter(function (b) { return b.checked; }).length;
                if (count) count.textContent = n + ' of ' + total + ' active for <?= h(addslashes($dept['name'])) ?>';
                if (warn) warn.style.display = n === 0 ? 'flex' : 'none';
            }
            boxes.forEach(function (b) { b.addEventListener('change', refresh); });

            var selectAll = document.getElementById('selectAllBtn');
            var selectNone = document.getElementById('selectNoneBtn');
            if (selectAll) selectAll.addEventListener('click', function () {
                boxes.forEach(function (b) { b.checked = true; });
                refresh();
            });
            if (selectNone) selectNone.addEventListener('click', function () {
                boxes.forEach(function (b) { b.checked = false; });
                refresh();
            });

            refresh();
        })();

        (function () {
            var form = document.getElementById('bulkForm');
            if (!form) return;
            var sportBoxes = Array.prototype.slice.call(form.querySelectorAll('input[name="bulk_game_codes[]"]'));
            var deptBoxes  = Array.prototype.slice.call(form.querySelectorAll('input[name="bulk_dept_ids[]"]'));
            var sportsCount = document.getElementById('bulkSportsCount');
            var deptsCount  = document.getElementById('bulkDeptsCount');
            var submit      = document.getElementById('bulkSubmitBtn');

            function refresh() {
                var sN = sportBoxes.filter(function (b) { return b.checked; }).length;
                var dN = deptBoxes.filter(function (b) { return b.checked; }).length;
                if (sportsCount) sportsCount.textContent = sN + ' sport(s) selected';
                if (deptsCount) deptsCount.textContent = dN + ' faculty/department(s) selected';
                if (submit) submit.disabled = !(sN > 0 && dN > 0);
            }
            sportBoxes.concat(deptBoxes).forEach(function (b) { b.addEventListener('change', refresh); });

            var allBtn  = document.getElementById('bulkSportsAllBtn');
            var noneBtn = document.getElementById('bulkSportsNoneBtn');
            if (allBtn) allBtn.addEventListener('click', function () {
                sportBoxes.forEach(function (b) { b.checked = true; });
                refresh();
            });
            if (noneBtn) noneBtn.addEventListener('click', function () {
                sportBoxes.forEach(function (b) { b.checked = false; });
                refresh();
            });

            refresh();
        })();
    </script>
</body>
</html>
