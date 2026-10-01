<?php
/**
 * Super-admin only: list / create / edit / delete / reset faculty accounts.
 * Also lets the super-admin reset their own password.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('SUPER_ADMIN');

$me = current_faculty();
$action = $_GET['action'] ?? 'list';
// Falls back to $_POST['id'] because the delete/toggle-active forms below
// POST to plain faculty_manage.php with no ?id= in the URL — only a hidden
// POST field. GET-based id (the edit link/form) still takes priority.
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$ok  = flash_get('faculty_saved');
$err = flash_get('faculty_error');

/** All departments a faculty login can be granted access to. */
$departments = db_select('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY display_order, id');

/** Read + sanitise the posted department checkbox list. */
function faculty_posted_dept_ids(): array
{
    return array_values(array_unique(array_filter(array_map('intval', (array)($_POST['department_ids'] ?? [])))));
}

/**
 * Replace a faculty user's department grants with exactly $deptIds
 * (validated against the departments table). Pass [] to clear all.
 */
function faculty_sync_departments(int $facultyId, array $deptIds): void
{
    db_execute('DELETE FROM faculty_departments WHERE faculty_id = ?', [$facultyId], 'i');
    if (!$deptIds) return;
    $ph    = implode(',', array_fill(0, count($deptIds), '?'));
    $valid = db_select("SELECT id FROM departments WHERE id IN ($ph)", $deptIds, str_repeat('i', count($deptIds)));
    foreach ($valid as $v) {
        db_execute(
            'INSERT INTO faculty_departments (faculty_id, department_id) VALUES (?, ?)',
            [$facultyId, (int)$v['id']],
            'ii'
        );
    }
}

/* ---------------- POST handlers ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'create') {
        $username  = trim((string)($_POST['username']  ?? ''));
        $email     = trim((string)($_POST['email']     ?? ''));
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $role      = ($_POST['role'] ?? 'FACULTY') === 'SUPER_ADMIN' ? 'SUPER_ADMIN' : 'FACULTY';
        $phone     = trim((string)($_POST['phone']     ?? ''));
        $password  = (string)($_POST['password']      ?? '');

        $dept_ids = faculty_posted_dept_ids();

        if ($username === '' || $email === '' || $full_name === '' || strlen($password) < 8) {
            flash_set('faculty_error', 'All fields required; password must be 8+ characters.', 'error');
            redirect('faculty_manage.php?action=new');
        }
        if ($role === 'FACULTY' && !$dept_ids) {
            flash_set('faculty_error', 'Assign the faculty login to at least one faculty / department.', 'error');
            redirect('faculty_manage.php?action=new');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $new_faculty_id = db_insert(
                'INSERT INTO faculty (username, email, full_name, password_hash, role, phone, is_active, must_reset_pw)
                 VALUES (?,?,?,?,?,?,1,1)',
                [$username, $email, $full_name, $hash, $role, $phone ?: null],
                'ssssss'
            );
            faculty_sync_departments((int)$new_faculty_id, $role === 'FACULTY' ? $dept_ids : []);
            flash_set('faculty_saved', "Faculty '$username' created.", 'success');
            redirect('faculty_manage.php');
        } catch (Throwable $e) {
            flash_set('faculty_error', 'Username or email already exists.', 'error');
            redirect('faculty_manage.php?action=new');
        }
    }

    if ($do === 'edit' && $id > 0) {
        $username  = trim((string)($_POST['username']  ?? ''));
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email     = trim((string)($_POST['email']     ?? ''));
        $phone     = trim((string)($_POST['phone']     ?? ''));
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $role      = ($_POST['role'] ?? 'FACULTY') === 'SUPER_ADMIN' ? 'SUPER_ADMIN' : 'FACULTY';
        $new_pw    = (string)($_POST['new_password'] ?? '');
        $dept_ids  = faculty_posted_dept_ids();

        if ($username === '' || $full_name === '' || $email === '') {
            flash_set('faculty_error', 'Username, name and email are required.', 'error');
            redirect("faculty_manage.php?action=edit&id=$id");
        }
        if (!preg_match('/^[a-z0-9_]+$/', $username)) {
            flash_set('faculty_error', 'Username may only contain lowercase letters, digits and underscore.', 'error');
            redirect("faculty_manage.php?action=edit&id=$id");
        }
        if ($role === 'FACULTY' && !$dept_ids) {
            flash_set('faculty_error', 'Assign the faculty login to at least one faculty / department.', 'error');
            redirect("faculty_manage.php?action=edit&id=$id");
        }

        // Don't let the only super-admin demote themselves
        if ($id === (int)$me['id'] && $role !== 'SUPER_ADMIN') {
            flash_set('faculty_error', 'You cannot demote yourself.', 'error');
            redirect("faculty_manage.php?action=edit&id=$id");
        }
        if ($id === (int)$me['id'] && !$is_active) {
            flash_set('faculty_error', 'You cannot deactivate yourself.', 'error');
            redirect("faculty_manage.php?action=edit&id=$id");
        }

        $dupe = db_one('SELECT id FROM faculty WHERE username = ? AND id <> ?', [$username, $id], 'si');
        if ($dupe) {
            flash_set('faculty_error', "Username '$username' is already taken.", 'error');
            redirect("faculty_manage.php?action=edit&id=$id");
        }

        if ($new_pw !== '' && strlen($new_pw) < 8) {
            flash_set('faculty_error', 'Password must be 8+ characters.', 'error');
            redirect("faculty_manage.php?action=edit&id=$id");
        }

        db_execute(
            'UPDATE faculty SET username=?, full_name=?, email=?, phone=?, is_active=?, role=?, must_reset_pw=? WHERE id=?',
            [$username, $full_name, $email, $phone ?: null, $is_active, $role, $new_pw !== '' ? 1 : 0, $id],
            'ssssisii'
        );
        if ($new_pw !== '') {
            $hash = password_hash($new_pw, PASSWORD_BCRYPT, ['cost' => 12]);
            db_execute('UPDATE faculty SET password_hash=? WHERE id=?', [$hash, $id], 'si');
        }
        faculty_sync_departments($id, $role === 'FACULTY' ? $dept_ids : []);

        // Keep the active session in sync if the super-admin just edited their own login.
        if ($id === (int)$me['id']) {
            $_SESSION['faculty_username'] = $username;
            $_SESSION['faculty_name']     = $full_name;
            $_SESSION['faculty_role']     = $role;
            if ($new_pw !== '') {
                $_SESSION['must_reset_pw'] = 1;
            }
        }

        flash_set('faculty_saved', 'Faculty updated.', 'success');
        redirect('faculty_manage.php');
    }

    if ($do === 'toggle_active' && $id > 0) {
        if ($id === (int)$me['id']) {
            flash_set('faculty_error', 'You cannot deactivate yourself.', 'error');
            redirect('faculty_manage.php');
        }
        $target = db_one('SELECT role, is_active FROM faculty WHERE id=?', [$id], 'i');
        if (!$target) {
            flash_set('faculty_error', 'Faculty not found.', 'error');
            redirect('faculty_manage.php');
        }
        $new_active = $target['is_active'] ? 0 : 1;
        if (!$new_active) {
            // Don't allow deactivating the last active super-admin
            $admins = (int)(db_one("SELECT COUNT(*) AS n FROM faculty WHERE role='SUPER_ADMIN' AND is_active=1")['n'] ?? 0);
            if ($target['role'] === 'SUPER_ADMIN' && $admins <= 1) {
                flash_set('faculty_error', 'Cannot deactivate the last active super-admin.', 'error');
                redirect('faculty_manage.php');
            }
        }
        db_execute('UPDATE faculty SET is_active=? WHERE id=?', [$new_active, $id], 'ii');
        flash_set('faculty_saved', $new_active ? 'Faculty reactivated.' : 'Faculty deactivated.', 'success');
        redirect('faculty_manage.php');
    }

    if ($do === 'delete' && $id > 0) {
        if ($id === (int)$me['id']) {
            flash_set('faculty_error', 'You cannot delete yourself.', 'error');
            redirect('faculty_manage.php');
        }
        $target = db_one('SELECT username, role, is_active FROM faculty WHERE id=?', [$id], 'i');
        if (!$target) {
            flash_set('faculty_error', 'Faculty not found.', 'error');
            redirect('faculty_manage.php');
        }
        // Don't allow deleting the last active super-admin
        $admins = (int)(db_one("SELECT COUNT(*) AS n FROM faculty WHERE role='SUPER_ADMIN' AND is_active=1")['n'] ?? 0);
        if ($target['role'] === 'SUPER_ADMIN' && $target['is_active'] == 1 && $admins <= 1) {
            flash_set('faculty_error', 'Cannot delete the last active super-admin.', 'error');
            redirect('faculty_manage.php');
        }
        $confirm_username = trim((string)($_POST['confirm_username'] ?? ''));
        if ($confirm_username === '' || $confirm_username !== $target['username']) {
            flash_set('faculty_error', 'Typed username did not match — nothing was deleted.', 'error');
            redirect('faculty_manage.php');
        }
        // Related rows are FK-safe: faculty_departments/password_resets cascade,
        // students.created_by / notices.posted_by / hero_settings.updated_by /
        // final_teams.added_by / provisional_entries.added_by all go NULL
        // (see sql/migration-v48-provisional-added-by-nullable.sql for the
        // last one — it used to RESTRICT, which made this fail silently).
        try {
            db_execute('DELETE FROM faculty WHERE id=?', [$id], 'i');
        } catch (Throwable $e) {
            error_log('[faculty_manage] delete failed for faculty #' . $id . ': ' . $e->getMessage());
            flash_set('faculty_error', 'Could not delete this account — it is still referenced by other records. Deactivate it instead, or check php_errors.log.', 'error');
            redirect('faculty_manage.php');
        }
        error_log(sprintf(
            '[faculty_manage] %s (faculty #%d) permanently deleted faculty account "%s" (#%d)',
            $me['username'] ?? '?', (int)$me['id'], $target['username'], $id
        ));
        flash_set('faculty_saved', "Faculty '{$target['username']}' permanently deleted.", 'success');
        redirect('faculty_manage.php');
    }
}

/* ---------------- Data for views ---------------- */

$faculty_list = db_select(
    'SELECT id, username, email, full_name, role, phone, is_active, last_login_at, created_at
       FROM faculty ORDER BY is_active DESC, role, username'
);

// faculty_id => ["Engineering", "Polytechnic", ...] for the list column.
$assigned_by_faculty = [];
foreach (db_select(
    'SELECT fd.faculty_id, d.name
       FROM faculty_departments fd
       JOIN departments d ON d.id = fd.department_id
      ORDER BY d.display_order, d.id'
) as $a) {
    $assigned_by_faculty[(int)$a['faculty_id']][] = $a['name'];
}

$edit_user = null;
$edit_dept_ids = [];
if ($action === 'edit' && $id > 0) {
    $edit_user = db_one('SELECT * FROM faculty WHERE id=?', [$id], 'i');
    if (!$edit_user) {
        http_response_code(404); exit('Faculty not found.');
    }
    foreach (db_select('SELECT department_id FROM faculty_departments WHERE faculty_id=?', [$id], 'i') as $r) {
        $edit_dept_ids[] = (int)$r['department_id'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Management | Sports Portal</title>
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
        .btn-danger{background:#fff5f5;color:#c53030;border:1px solid #fed7d7}.btn-danger:hover{background:#fed7d7}
        .data-card{background:#fff;border:1px solid var(--light-gray);border-radius:10px;overflow:hidden;margin-bottom:1.25rem}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.75rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.75rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);color:var(--text-dark)}
        .data-table tr:last-child td{border-bottom:none}
        .role-badge{display:inline-block;padding:.15rem .55rem;border-radius:4px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
        .role-badge.admin{background:rgba(114,47,55,.12);color:var(--accent-maroon)}
        .role-badge.faculty{background:rgba(26,54,93,.1);color:var(--primary-navy)}
        .status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:.3rem}
        .status-dot.active{background:#198754}
        .status-dot.inactive{background:#6c757d}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .form-card{background:#fff;border:1px solid var(--light-gray);border-radius:10px;padding:1.5rem;max-width:680px}
        .form-card h2{font-size:1.05rem;font-weight:600;color:var(--primary-navy);margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--light-gray)}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem}
        .form-group{display:flex;flex-direction:column;gap:.3rem}
        .form-group label{font-size:.78rem;font-weight:600;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.3px}
        .form-group input,.form-group select{padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.92rem;background:#fff}
        .form-group input:focus,.form-group select:focus{outline:none;border-color:var(--primary-navy)}
        .form-actions{display:flex;gap:.75rem;margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--light-gray)}
        .dept-check-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.5rem;margin-top:.25rem}
        .dept-check{display:flex;align-items:center;gap:.5rem;padding:.5rem .7rem;border:1px solid var(--light-gray);border-radius:6px;font-size:.9rem;font-weight:500;cursor:pointer;background:#fff;text-transform:none;letter-spacing:0}
        .dept-check:hover{border-color:var(--primary-navy-light);background:var(--off-white)}
        .dept-check input{width:auto;margin:0}
        .faculties-cell{font-size:.82rem;color:var(--text-dark)}
        .faculties-cell .all{color:var(--accent-maroon);font-weight:600}
        .faculties-cell .none{color:var(--medium-gray);font-style:italic}
        .btn-danger-solid{display:inline-flex;align-items:center;gap:.45rem;white-space:nowrap;padding:.62rem 1.2rem;border:none;border-radius:8px;background:#c53030;color:#fff;font:inherit;font-size:.85rem;font-weight:600;cursor:pointer;transition:var(--transition-smooth)}
        .btn-danger-solid:hover:not(:disabled){background:#9b2c2c;box-shadow:0 6px 18px rgba(197,48,48,.28);transform:translateY(-1px)}
        .btn-danger-solid:disabled{opacity:.5;cursor:not-allowed}
        .dm-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;z-index:2000;padding:1rem}
        .dm-modal[hidden]{display:none}
        .dm-modal-box{background:#fff;border-radius:12px;max-width:460px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.35);overflow:hidden;animation:dmPop .14s ease-out}
        @keyframes dmPop{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
        .dm-modal-head{display:flex;align-items:center;justify-content:space-between;padding:.95rem 1.25rem;background:#fff5f5;border-bottom:1px solid #fed7d7}
        .dm-modal-head h3{margin:0;font-size:.98rem;font-weight:700;color:#c53030;display:flex;align-items:center;gap:.5rem}
        .dm-modal-x{background:0 0;border:none;font-size:1.5rem;line-height:1;color:var(--medium-gray);cursor:pointer;padding:0 .25rem}
        .dm-modal-x:hover{color:var(--text-dark)}
        .dm-modal-body{padding:1.25rem}
        .dm-modal-body>p{font-size:.86rem;color:var(--text-dark);margin:0 0 .5rem;line-height:1.55}
        .dm-modal-body label{display:block;font-size:.78rem;font-weight:600;color:var(--primary-navy);margin:.9rem 0 .3rem}
        .dm-modal-body code{background:var(--off-white);border:1px solid var(--light-gray);border-radius:4px;padding:.05rem .35rem;font-size:.8rem;color:#c53030;font-weight:700}
        .dm-modal-body input{width:100%;padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font:inherit;font-size:.9rem;letter-spacing:.5px}
        .dm-modal-body input:focus{outline:none;border-color:#c53030;box-shadow:0 0 0 3px rgba(197,48,48,.12)}
        .dm-modal-actions{display:flex;gap:.6rem;justify-content:flex-end;margin-top:1.4rem}
        .btn-ghost{padding:.55rem 1rem;border:1px solid var(--light-gray);border-radius:8px;background:#fff;color:var(--text-dark);font:inherit;font-size:.85rem;font-weight:600;cursor:pointer}
        .btn-ghost:hover{background:var(--off-white)}
        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .btn { width: 100%; justify-content: center; }
            .data-card { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .data-table { min-width: 600px; }
            .form-grid { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
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
                <a href="faculty_manage.php" class="active"><i class="bi bi-people-fill"></i> <span>Faculty Management</span></a>
                <a href="document_requirements.php"><i class="bi bi-file-earmark-ruled"></i> <span>Document Requirements</span></a>
                <a href="sports_assign.php"><i class="bi bi-trophy-fill"></i> <span>Sports Assignment</span></a>
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
                <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">Faculty Management</h2>
                <?php if ($action === 'new' || ($action === 'edit' && $edit_user)): ?>
                    <a href="faculty_manage.php" class="top-back-btn" title="Back to faculty list" aria-label="Back to faculty list">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                <?php endif; ?>
            </header>

            <div class="content-body">
                <?php if ($ok): ?><div class="alert-banner success" role="alert"><i class="bi bi-check-circle"></i> <?= h($ok['msg']) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert-banner error" role="alert"><i class="bi bi-exclamation-circle"></i> <?= h($err['msg']) ?></div><?php endif; ?>

                <?php if ($action === 'new' || ($action === 'edit' && $edit_user)): ?>
                    <div class="page-header">
                        <h1><?= $action === 'new' ? 'Add Faculty' : 'Edit Faculty: ' . h($edit_user['full_name']) ?></h1>
                    </div>

                    <form method="post" action="faculty_manage.php<?= $action==='edit' ? '?action=edit&id='.(int)$edit_user['id'] : '?action=new' ?>" class="form-card">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="<?= $action === 'new' ? 'create' : 'edit' ?>">
                        <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= (int)$edit_user['id'] ?>"><?php endif; ?>

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Username *</label>
                                <input type="text" name="username" required pattern="[a-z0-9_]+" title="lowercase letters, digits, underscore"
                                       value="<?= h($edit_user['username'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" required value="<?= h($edit_user['full_name'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Email *</label>
                                <input type="email" name="email" required value="<?= h($edit_user['email'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="tel" name="phone" value="<?= h($edit_user['phone'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Role *</label>
                                <select name="role" id="roleSelect" required>
                                    <option value="FACULTY" <?= ($edit_user['role'] ?? 'FACULTY') === 'FACULTY' ? 'selected' : '' ?>>Faculty</option>
                                    <option value="SUPER_ADMIN" <?= ($edit_user['role'] ?? '') === 'SUPER_ADMIN' ? 'selected' : '' ?>>Super Admin</option>
                                </select>
                            </div>
                            <div class="form-group dept-assign" id="deptAssignGroup" style="grid-column:1/-1">
                                <label>Assigned Faculties / Departments *</label>
                                <div class="dept-check-grid">
                                    <?php foreach ($departments as $d): ?>
                                        <label class="dept-check">
                                            <input type="checkbox" name="department_ids[]" value="<?= (int)$d['id'] ?>"
                                                   <?= in_array((int)$d['id'], $edit_dept_ids, true) ? 'checked' : '' ?>>
                                            <span><?= h($d['name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <small style="color:var(--medium-gray);font-size:.78rem">
                                    This login can access students of the ticked faculties. Not used for Super Admin (sees every faculty).
                                </small>
                            </div>
                            <?php if ($action === 'edit'): ?>
                                <div class="form-group">
                                    <label>Status</label>
                                    <label style="font-weight:normal;font-size:.92rem;text-transform:none;letter-spacing:0;display:flex;align-items:center;gap:.5rem">
                                        <input type="checkbox" name="is_active" value="1" <?= !empty($edit_user['is_active']) ? 'checked' : '' ?>>
                                        Active
                                    </label>
                                </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label><?= $action === 'new' ? 'Password * (min 8 chars)' : 'New Password (leave blank to keep)' ?></label>
                                <input type="password" name="<?= $action==='new' ? 'password' : 'new_password' ?>" autocomplete="new-password" <?= $action==='new' ? 'required minlength="8"' : 'minlength="8"' ?>>
                                <?php if ($action === 'edit'): ?>
                                    <small style="color:var(--medium-gray);font-size:.78rem">If set, the user will be required to change it on next login.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> <?= $action === 'new' ? 'Create Faculty' : 'Save Changes' ?></button>
                            <a href="faculty_manage.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Cancel</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="page-header">
                        <h1>Faculty Accounts</h1>
                        <a href="faculty_manage.php?action=new" class="btn btn-primary"><i class="bi bi-person-plus"></i> Add Faculty</a>
                    </div>

                    <div class="data-card">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Faculties</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($faculty_list as $u): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($u['full_name']) ?></strong>
                                        <div style="font-size:.78rem;color:var(--medium-gray)">@<?= h($u['username']) ?></div>
                                    </td>
                                    <td><?= h($u['email']) ?></td>
                                    <td><span class="role-badge <?= $u['role']==='SUPER_ADMIN' ? 'admin' : 'faculty' ?>"><?= h($u['role']) ?></span></td>
                                    <td class="faculties-cell">
                                        <?php if ($u['role'] === 'SUPER_ADMIN'): ?>
                                            <span class="all">All faculties</span>
                                        <?php elseif (!empty($assigned_by_faculty[(int)$u['id']])): ?>
                                            <?= h(implode(', ', $assigned_by_faculty[(int)$u['id']])) ?>
                                        <?php else: ?>
                                            <span class="none">None assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-dot <?= $u['is_active'] ? 'active' : 'inactive' ?>"></span>
                                        <?= $u['is_active'] ? 'Active' : 'Disabled' ?>
                                    </td>
                                    <td style="font-size:.85rem;color:var(--medium-gray)">
                                        <?= $u['last_login_at'] ? h(format_date($u['last_login_at'], 'd M Y, H:i')) : '—' ?>
                                    </td>
                                    <td>
                                        <a class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.78rem" href="?action=edit&id=<?= (int)$u['id'] ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                        <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                                            <form method="post" action="faculty_manage.php" style="display:inline"
                                                  onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> this login?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="do" value="toggle_active">
                                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                <button class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.78rem"
                                                        title="<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?>"
                                                        aria-label="<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= h($u['full_name']) ?>">
                                                    <i class="bi bi-<?= $u['is_active'] ? 'slash-circle' : 'check-circle' ?>"></i>
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-danger" style="padding:.3rem .6rem;font-size:.78rem"
                                                    title="Delete permanently"
                                                    aria-label="Delete <?= h($u['full_name']) ?> permanently"
                                                    data-del-id="<?= (int)$u['id'] ?>"
                                                    data-del-username="<?= h($u['username']) ?>"
                                                    data-del-name="<?= h($u['full_name']) ?>">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="dm-modal" id="delModal" hidden>
                        <div class="dm-modal-box" role="dialog" aria-modal="true" aria-labelledby="delModalTitle">
                            <div class="dm-modal-head">
                                <h3 id="delModalTitle"><i class="bi bi-exclamation-triangle-fill"></i> Delete faculty account</h3>
                                <button type="button" class="dm-modal-x" id="delCloseBtn" aria-label="Close">&times;</button>
                            </div>
                            <div class="dm-modal-body">
                                <p>
                                    This permanently deletes the login for <strong id="delModalName"></strong>.
                                    Any student records, notices etc. they created are kept, just unattributed.
                                    <strong>This cannot be undone.</strong>
                                </p>
                                <form method="post" action="faculty_manage.php" id="delForm">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="delete">
                                    <input type="hidden" name="id" id="delFormId" value="">
                                    <label for="delConfirm">Type <code id="delWordHint"></code> to confirm</label>
                                    <input type="text" id="delConfirm" name="confirm_username" autocomplete="off">
                                    <div class="dm-modal-actions">
                                        <button type="button" class="btn-ghost" id="delCancelBtn">Cancel</button>
                                        <button type="submit" class="btn-danger-solid" id="delSubmitBtn" disabled>
                                            <i class="bi bi-trash3"></i> Delete permanently
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var role = document.getElementById('roleSelect');
            var grp  = document.getElementById('deptAssignGroup');
            if (!role || !grp) return;
            function sync() {
                var isFaculty = role.value === 'FACULTY';
                grp.style.display = isFaculty ? '' : 'none';
                grp.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                    cb.disabled = !isFaculty;
                });
            }
            role.addEventListener('change', sync);
            sync();
        })();

        (function () {
            var modal = document.getElementById('delModal');
            if (!modal) return;
            var idField  = document.getElementById('delFormId');
            var nameEl   = document.getElementById('delModalName');
            var hint     = document.getElementById('delWordHint');
            var confirm_ = document.getElementById('delConfirm');
            var submit   = document.getElementById('delSubmitBtn');
            var expected = '';

            function evaluate() {
                submit.disabled = confirm_.value.trim() !== expected;
            }
            function open(id, username, fullname) {
                idField.value = id;
                nameEl.textContent = fullname + ' (@' + username + ')';
                hint.textContent = username;
                expected = username;
                confirm_.value = '';
                modal.hidden = false;
                evaluate();
                confirm_.focus();
                document.addEventListener('keydown', onKey);
            }
            function close() {
                modal.hidden = true;
                document.removeEventListener('keydown', onKey);
            }
            function onKey(e) { if (e.key === 'Escape') close(); }

            document.querySelectorAll('[data-del-id]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    open(btn.getAttribute('data-del-id'), btn.getAttribute('data-del-username'), btn.getAttribute('data-del-name'));
                });
            });
            document.getElementById('delCloseBtn').addEventListener('click', close);
            document.getElementById('delCancelBtn').addEventListener('click', close);
            modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
            confirm_.addEventListener('input', evaluate);
        })();
    </script>
</body>
</html>
