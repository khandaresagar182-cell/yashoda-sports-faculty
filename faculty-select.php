<?php
/**
 * Faculty / Department selector.
 * For FACULTY: shows the 8 department cards; clicking one POSTs to set session dept.
 * For SUPER_ADMIN: same UI, with the addition that no scope is forced (sees all).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

require_login();

// If they want to change department, clear it from session
if (isset($_GET['change'])) {
    unset($_SESSION['department_id'], $_SESSION['department_code'], $_SESSION['department_name']);
    redirect('faculty-select.php');
}

// If already has a department, send them onward.
$f = current_faculty();
if ($f['department_id']) {
    redirect('admin/dashboard.php');
}

// Handle POST: faculty picked a department
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $dept_id = (int)($_POST['dept_id'] ?? 0);
    if ($dept_id <= 0) {
        flash_set('dept_error', 'Invalid faculty selection.', 'error');
        redirect('faculty-select.php');
    }

    // FACULTY users: verify this department is assigned to them
    if ($f['role'] === 'FACULTY') {
        $allowed = db_one(
            'SELECT 1 FROM faculty_departments WHERE faculty_id = ? AND department_id = ?',
            [$f['id'], $dept_id], 'ii'
        );
        if (!$allowed) {
            flash_set('dept_error', 'You do not have access to that faculty.', 'error');
            redirect('faculty-select.php');
        }
    }

    auth_set_department($dept_id);
    flash_set('dashboard_info', 'Welcome! Faculty ' . ($_SESSION['department_name'] ?? '') . ' selected.', 'info');
    redirect('admin/dashboard.php');
}

$flash = flash_get('dept_error');

// Load departments based on role.
// SUPER_ADMIN → all active departments.
// FACULTY     → only departments assigned via faculty_departments table.
if ($f['role'] === 'SUPER_ADMIN') {
    $departments = db_select(
        'SELECT d.id, d.code, d.name, d.full_name, d.icon, d.display_order,
                (SELECT COUNT(*) FROM students s
                  WHERE s.department_id = d.id
                    AND s.form_submitted_at IS NOT NULL) AS student_count
           FROM departments d
          WHERE d.is_active = 1
          ORDER BY d.display_order, d.name'
    );
} else {
    $departments = db_select(
        'SELECT d.id, d.code, d.name, d.full_name, d.icon, d.display_order,
                (SELECT COUNT(*) FROM students s
                  WHERE s.department_id = d.id
                    AND s.form_submitted_at IS NOT NULL) AS student_count
           FROM departments d
           JOIN faculty_departments fd ON fd.department_id = d.id
          WHERE fd.faculty_id = ? AND d.is_active = 1
          ORDER BY d.display_order, d.name',
        [$f['id']], 'i'
    );
}

// If faculty has exactly 1 assigned department, auto-select it and skip this page.
if ($f['role'] === 'FACULTY' && count($departments) === 1) {
    auth_set_department((int)$departments[0]['id']);
    redirect('admin/dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Select Faculty - Student Sports Database Management System">
    <title>Select Faculty | Sports Database Management</title>

    <?= csrf_meta() ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('css/admin.css')) ?>">

    <style>
        :root {
            --primary-navy: #1a365d; --primary-navy-dark: #0f2744; --primary-navy-light: #2c5282;
            --accent-gold: #c9a227; --accent-gold-light: #d4b84a; --accent-maroon: #722f37;
            --white: #ffffff; --off-white: #f8f9fa; --light-gray: #e9ecef; --medium-gray: #6c757d;
            --dark-gray: #343a40; --text-dark: #212529;
            --font-primary: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --transition-smooth: all 0.3s ease-in-out; --sidebar-width: 260px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; overflow: hidden; }
        body { font-family: var(--font-primary); color: var(--text-dark); line-height: 1.6; background: var(--off-white); }
        .app-wrapper { display: flex; height: 100vh; }

        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-navy-dark) 0%, var(--primary-navy) 100%);
            color: var(--white);
            display: flex; flex-direction: column; flex-shrink: 0;
            transition: width 0.3s ease; z-index: 100; overflow: hidden;
        }
        .sidebar-brand {
            padding: 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex; align-items: center; gap: 0.75rem;
        }
        .sidebar-brand img { width: 42px; height: 42px; border-radius: 8px; object-fit: contain; background: rgba(255,255,255,0.1); padding: 3px; flex-shrink: 0; }
        .sidebar-brand-text h2 { font-size: 0.85rem; font-weight: 700; color: var(--white); margin: 0; white-space: nowrap; }
        .sidebar-brand-text span { font-size: 0.7rem; color: rgba(255,255,255,0.5); }
        .sidebar-nav { flex: 1; padding: 1rem 0; overflow-y: auto; }
        .sidebar-nav-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(255,255,255,0.35); padding: 0.75rem 1.5rem 0.4rem; }
        .sidebar-nav a { display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 1.5rem; color: rgba(255,255,255,0.65); font-size: 0.88rem; font-weight: 500; text-decoration: none; transition: var(--transition-smooth); border-left: 3px solid transparent; }
        .sidebar-nav a:hover { color: var(--white); background: rgba(255,255,255,0.06); border-left-color: rgba(201,162,39,0.4); }
        .sidebar-nav a.active { color: var(--white); background: rgba(201,162,39,0.12); border-left-color: var(--accent-gold); }
        .sidebar-nav a.active i { color: var(--accent-gold); }
        .sidebar-nav a i { font-size: 1.15rem; width: 22px; text-align: center; flex-shrink: 0; }
        .sidebar-nav a .nav-badge { margin-left: auto; background: var(--accent-gold); color: var(--primary-navy-dark); font-size: 0.65rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 10px; }
        .sidebar-footer { padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.08); }
        .sidebar-user { display: flex; align-items: center; gap: 0.75rem; }
        .sidebar-user-avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--accent-gold), var(--accent-maroon)); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; color: var(--white); flex-shrink: 0; }
        .sidebar-user-info h4 { font-size: 0.82rem; font-weight: 600; color: var(--white); margin: 0; }
        .sidebar-user-info span { font-size: 0.7rem; color: rgba(255,255,255,0.5); }
        .btn-logout { margin-left: auto; background: 0 0; border: 1px solid rgba(255,255,255,0.15); color: rgba(255,255,255,0.6); padding: 0.35rem 0.5rem; border-radius: 6px; cursor: pointer; transition: var(--transition-smooth); font-size: 0.85rem; }
        .btn-logout:hover { background: rgba(220,53,69,0.2); border-color: rgba(220,53,69,0.4); color: #ff8a8a; }

        .main-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .top-bar { background: var(--white); border-bottom: 1px solid var(--light-gray); padding: 0.75rem 2rem; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .top-bar-left { display: flex; align-items: center; gap: 1rem; }
        .sidebar-toggle { background: 0 0; border: 1px solid var(--light-gray); color: var(--medium-gray); padding: 0.4rem 0.55rem; border-radius: 6px; cursor: pointer; font-size: 1.1rem; display: flex; align-items: center; transition: var(--transition-smooth); }
        .sidebar-toggle:hover { background: var(--off-white); color: var(--primary-navy); }
        .breadcrumb-nav { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }
        .breadcrumb-nav a { color: var(--medium-gray); text-decoration: none; }
        .breadcrumb-nav a:hover { color: var(--primary-navy); }
        .breadcrumb-nav .sep { color: var(--light-gray); }
        .breadcrumb-nav .current { color: var(--primary-navy); font-weight: 600; }
        .icon-btn { background: 0 0; border: 1px solid var(--light-gray); color: var(--medium-gray); width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition-smooth); }
        .icon-btn:hover { background: var(--off-white); color: var(--primary-navy); }
        .content-body { flex: 1; overflow-y: auto; padding: 2rem; }

        .page-header { margin-bottom: 1.5rem; }
        .year-badge { display: inline-flex; align-items: center; gap: 0.4rem; background: rgba(201,162,39,0.12); color: var(--accent-gold); padding: 0.3rem 0.9rem; border-radius: 20px; font-size: 0.78rem; font-weight: 600; margin-bottom: 0.7rem; }
        .page-header h1 { font-size: 1.6rem; font-weight: 700; color: var(--primary-navy); margin-bottom: 0.3rem; }
        .page-header p { color: var(--medium-gray); font-size: 0.95rem; }

        .faculty-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.25rem; }
        .faculty-card {
            background: var(--white); border: 1px solid var(--light-gray);
            border-radius: 10px; padding: 1.5rem; text-align: center;
            text-decoration: none; color: inherit;
            position: relative; overflow: hidden;
            transition: var(--transition-smooth);
            animation: cardIn 0.4s ease both; cursor: pointer;
            border: none; font: inherit; width: 100%;
        }
        .faculty-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(26,54,93,0.12);
            border-color: var(--accent-gold);
        }
        .faculty-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, var(--primary-navy), var(--accent-gold));
            transform: scaleX(0); transform-origin: left;
            transition: transform 0.3s ease;
        }
        .faculty-card:hover::before { transform: scaleX(1); }
        .faculty-card-icon {
            width: 64px; height: 64px; margin: 0 auto 1rem;
            background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-light));
            border-radius: 14px; display: flex; align-items: center; justify-content: center;
            transition: var(--transition-smooth);
        }
        .faculty-card:hover .faculty-card-icon { transform: scale(1.05) rotate(-3deg); }
        .faculty-card-icon i { font-size: 1.6rem; color: var(--accent-gold); }
        .faculty-card h3 { font-size: 1.05rem; font-weight: 700; color: var(--primary-navy); margin-bottom: 0.2rem; }
        .faculty-full-name { font-size: 0.78rem; color: var(--medium-gray); margin-bottom: 1rem; }
        .faculty-card-stats { display: flex; justify-content: space-around; padding: 0.7rem 0; border-top: 1px solid var(--light-gray); }
        .faculty-stat { display: flex; flex-direction: column; gap: 0.15rem; }
        .faculty-stat .num { font-size: 1.15rem; font-weight: 700; color: var(--primary-navy); }
        .faculty-stat .lbl { font-size: 0.7rem; color: var(--medium-gray); text-transform: uppercase; letter-spacing: 0.4px; }
        .arrow-go { position: absolute; bottom: 1rem; right: 1rem; width: 32px; height: 32px; border-radius: 50%; background: var(--off-white); display: flex; align-items: center; justify-content: center; color: var(--primary-navy); font-size: 0.85rem; opacity: 0; transform: translateX(-5px); transition: all 0.3s ease; }
        .faculty-card:hover .arrow-go { opacity: 1; transform: translateX(0); }

        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1040; }
        .sidebar-overlay.show { display: block; }

        .alert-banner { padding: 0.8rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem; }
        .alert-banner.error { background: rgba(220,53,69,0.1); color: #842029; border: 1px solid rgba(220,53,69,0.2); }

        @keyframes cardIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 992px) {
            .sidebar { position: fixed; left: -280px; top: 0; height: 100vh; transition: left 0.3s ease; z-index: 1050; }
            .sidebar.open { left: 0; }
            .top-bar { padding: 0.75rem 1.25rem; }
            .content-body { padding: 1.25rem; }
        }
        @media (max-width: 576px) {
            .faculty-grid { grid-template-columns: 1fr; }
            .page-header h1 { font-size: 1.3rem; }
        }
    </style>
</head>

<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="app-wrapper">

        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <img src="<?= h(url('images/ytc-logo.png')) ?>" alt="YTC Logo">
                <div class="sidebar-brand-text">
                    <h2>Sports Database</h2>
                    <span>Yashoda Technical Campus</span>
                </div>
            </div>
            <nav class="sidebar-nav">
                <div class="sidebar-nav-label">Main</div>
                <a href="#" class="active">
                    <i class="bi bi-building"></i> <span>Select Faculty</span>
                </a>
                <a href="#" onclick="alert('Pick a faculty first.'); return false;">
                    <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
                </a>
                <a href="#" onclick="alert('Pick a faculty first.'); return false;">
                    <i class="bi bi-search"></i> <span>Search Students</span>
                </a>
                <a href="#" onclick="alert('Pick a faculty first.'); return false;">
                    <i class="bi bi-clipboard-check"></i> <span>Provisional Players</span>
                </a>
                <a href="#" onclick="alert('Pick a faculty first.'); return false;">
                    <i class="bi bi-check-all"></i> <span>Final Teams</span>
                </a>
                <div class="sidebar-nav-label">Settings</div>
                <a href="index.php">
                    <i class="bi bi-globe"></i> <span>View Website</span>
                </a>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar"><?= h(initials($f['full_name'])) ?></div>
                    <div class="sidebar-user-info">
                        <h4><?= h($f['full_name']) ?></h4>
                        <span><?= h($f['role']) ?></span>
                    </div>
                    <a href="admin/logout.php?_csrf=<?= h(csrf_token()) ?>" class="btn-logout" title="Logout">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <header class="top-bar">
                <div class="top-bar-left">
                    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                        <i class="bi bi-list"></i>
                    </button>
                    <nav class="breadcrumb-nav">
                        <span class="current">Select Faculty</span>
                    </nav>
                </div>
                <div class="top-bar-right">
                    <span style="font-size:.85rem;color:var(--medium-gray)">
                        Signed in as <strong><?= h($f['full_name']) ?></strong>
                    </span>
                </div>
            </header>

            <div class="content-body">
                <div class="page-header">
                    <div class="year-badge">
                        <i class="bi bi-calendar3"></i>
                        <span>Academic Year <?= h(current_academic_year()) ?></span>
                    </div>
                    <h1>Select Faculty</h1>
                    <p>Choose a faculty to view and manage its student sports database.</p>
                </div>

                <?php if ($flash): ?>
                    <div class="alert-banner <?= h($flash['level']) ?>">
                        <i class="bi bi-exclamation-circle"></i> <?= h($flash['msg']) ?>
                    </div>
                <?php endif; ?>

                <div class="faculty-grid">
                    <?php foreach ($departments as $d): ?>
                        <form method="post" action="faculty-select.php" style="margin:0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="dept_id" value="<?= (int)$d['id'] ?>">
                            <button type="submit" class="faculty-card" data-faculty="<?= h($d['code']) ?>" style="animation-delay: <?= (int)$d['display_order'] * 0.05 ?>s">
                                <div class="faculty-card-icon">
                                    <i class="bi <?= h($d['icon'] ?: 'bi-mortarboard') ?>"></i>
                                </div>
                                <h3><?= h($d['name']) ?></h3>
                                <p class="faculty-full-name"><?= h($d['full_name']) ?></p>
                                <div class="faculty-card-stats">
                                    <div class="faculty-stat">
                                        <span class="num"><?= (int)$d['student_count'] ?></span>
                                        <span class="lbl">Students</span>
                                    </div>
                                    <div class="faculty-stat">
                                        <span class="num">—</span>
                                        <span class="lbl">Sports</span>
                                    </div>
                                    <div class="faculty-stat">
                                        <span class="num">—</span>
                                        <span class="lbl">Awards</span>
                                    </div>
                                </div>
                                <div class="arrow-go"><i class="bi bi-arrow-right"></i></div>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggle = document.getElementById('sidebarToggle');
            if (toggle) {
                toggle.addEventListener('click', function () {
                    sidebar.classList.toggle('open');
                    overlay.classList.toggle('show');
                });
                overlay.addEventListener('click', function () {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('show');
                });
            }
        });
    </script>
</body>
</html>
