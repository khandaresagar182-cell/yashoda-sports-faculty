<?php
/**
 * Super-admin only: manage the per-department document checklist that drives
 * Step 5 (Documents) of the student wizard.
 *
 * Reads / writes `dept_document_requirements`. Each department has its own
 * ordered list of documents; a student in that department sees exactly these
 * upload slots. "Load standard document set" re-asserts the canonical list
 * (same rows as sql/migration-v46-heal-document-requirements.sql) for a
 * department that is missing them — this is the supported way to heal a
 * live database where the migration never ran.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('SUPER_ADMIN');

$me = current_faculty();

/* ---------------- reference data ---------------- */

const DOC_FILE_TYPES = [
    'application/pdf' => 'PDF document',
    'image/jpeg'      => 'Photo (JPEG / JPG)',
];

// Canonical list from migration v46. Polytechnic intentionally only gets the
// passport photo; every other department gets the full academic set + photo.
const DOC_STANDARD_SET = [
    ['10th board certificate',                    'application/pdf'],
    ['12th marksheet',                            'application/pdf'],
    ['Gap certificate',                           'application/pdf'],
    ['Birth certificate or Leaving certificate',  'application/pdf'],
    ['Last year marksheet',                       'application/pdf'],
    ['College ID Card',                           'application/pdf'],
    ['Aadhaar Card',                              'application/pdf'],
    ['Bank passbook',                             'application/pdf'],
];
const DOC_PASSPORT_PHOTO = ['Passport-size Photo', 'image/jpeg'];

$departments = db_select('SELECT id, code, name FROM departments ORDER BY display_order, id');
$dept_by_id  = [];
foreach ($departments as $d) {
    $dept_by_id[(int)$d['id']] = $d;
}

/** Resolve the department in scope from ?dept=, falling back to the first. */
function dr_current_dept_id(array $dept_by_id): int
{
    $req = (int)($_GET['dept'] ?? $_POST['dept'] ?? 0);
    if ($req > 0 && isset($dept_by_id[$req])) {
        return $req;
    }
    return (int)(array_key_first($dept_by_id) ?? 0);
}

$dept_id   = dr_current_dept_id($dept_by_id);
$dept      = $dept_by_id[$dept_id] ?? null;
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

$ok  = flash_get('doc_req_saved');
$err = flash_get('doc_req_error');

if (!$dept) {
    http_response_code(500);
    exit('No departments found.');
}

/* ---------------- POST handlers ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do        = $_POST['do'] ?? '';
    $post_dept = (int)($_POST['dept'] ?? 0);
    if ($post_dept <= 0 || !isset($dept_by_id[$post_dept])) {
        flash_set('doc_req_error', 'Unknown department.', 'error');
        redirect('document_requirements.php');
    }
    $back = 'document_requirements.php?dept=' . $post_dept;

    if ($do === 'create' || $do === 'edit') {
        $name      = trim((string)($_POST['document_name'] ?? ''));
        $mime      = (string)($_POST['file_type'] ?? 'application/pdf');
        $required  = isset($_POST['is_required']) ? 1 : 0;

        if (!isset(DOC_FILE_TYPES[$mime])) {
            $mime = 'application/pdf';
        }
        if ($name === '' || mb_strlen($name) > 100) {
            flash_set('doc_req_error', 'Document name is required (max 100 characters).', 'error');
            redirect($back . ($do === 'edit' ? '&action=edit&id=' . (int)$_POST['id'] : '&action=new'));
        }

        if ($do === 'create') {
            $dupe = db_one(
                'SELECT id FROM dept_document_requirements WHERE department_id = ? AND document_name = ?',
                [$post_dept, $name], 'is'
            );
            if ($dupe) {
                flash_set('doc_req_error', "\"$name\" already exists for this department.", 'error');
                redirect($back . '&action=new');
            }
            db_insert(
                'INSERT INTO dept_document_requirements (department_id, document_name, is_required, allowed_mime_types)
                 VALUES (?,?,?,?)',
                [$post_dept, $name, $required, $mime],
                'issi'
            );
            flash_set('doc_req_saved', "Added \"$name\".", 'success');
            redirect($back);
        }

        // edit
        $rid = (int)($_POST['id'] ?? 0);
        $row = db_one('SELECT id FROM dept_document_requirements WHERE id = ? AND department_id = ?', [$rid, $post_dept], 'ii');
        if (!$row) {
            flash_set('doc_req_error', 'Requirement not found.', 'error');
            redirect($back);
        }
        db_execute(
            'UPDATE dept_document_requirements SET document_name = ?, is_required = ?, allowed_mime_types = ? WHERE id = ?',
            [$name, $required, $mime, $rid],
            'sisi'
        );
        flash_set('doc_req_saved', 'Requirement updated.', 'success');
        redirect($back);
    }

    if ($do === 'delete') {
        $rid = (int)($_POST['id'] ?? 0);
        $row = db_one('SELECT document_name FROM dept_document_requirements WHERE id = ? AND department_id = ?', [$rid, $post_dept], 'ii');
        if ($row) {
            db_execute('DELETE FROM dept_document_requirements WHERE id = ?', [$rid], 'i');
            flash_set('doc_req_saved', "Deleted \"{$row['document_name']}\".", 'success');
        }
        redirect($back);
    }

    if ($do === 'load_standard') {
        $isPoly = ($dept_by_id[$post_dept]['code'] ?? '') === 'polytechnic';
        $wanted = $isPoly ? [DOC_PASSPORT_PHOTO] : array_merge(DOC_STANDARD_SET, [DOC_PASSPORT_PHOTO]);

        $existing = db_select(
            'SELECT document_name FROM dept_document_requirements WHERE department_id = ?',
            [$post_dept], 'i'
        );
        $have = [];
        foreach ($existing as $e) {
            $have[mb_strtolower(trim((string)$e['document_name']))] = true;
        }

        $added = 0;
        foreach ($wanted as [$docName, $docMime]) {
            if (isset($have[mb_strtolower($docName)])) {
                continue;
            }
            db_insert(
                'INSERT INTO dept_document_requirements (department_id, document_name, is_required, allowed_mime_types)
                 VALUES (?,?,1,?)',
                [$post_dept, $docName, $docMime],
                'iss'
            );
            $added++;
        }
        flash_set(
            'doc_req_saved',
            $added > 0
                ? "Standard document set loaded — $added document(s) added."
                : 'Standard document set already complete for this department.',
            'success'
        );
        redirect($back);
    }

    redirect($back);
}

/* ---------------- data for views ---------------- */

$requirements = db_select(
    'SELECT dr.id, dr.document_name, dr.is_required, dr.allowed_mime_types,
            (SELECT COUNT(*) FROM student_documents sd WHERE sd.requirement_id = dr.id) AS upload_count
       FROM dept_document_requirements dr
      WHERE dr.department_id = ?
      ORDER BY (dr.document_name = "Passport-size Photo") DESC, dr.id',
    [$dept_id], 'i'
);

$edit_row = null;
if ($action === 'edit' && $id > 0) {
    $edit_row = db_one(
        'SELECT * FROM dept_document_requirements WHERE id = ? AND department_id = ?',
        [$id, $dept_id], 'ii'
    );
    if (!$edit_row) {
        http_response_code(404);
        exit('Requirement not found.');
    }
}

/** Friendly label for a stored mime string. */
function dr_type_label(?string $mime): string
{
    $mime = trim((string)$mime);
    // A row can carry a comma list; treat "photo unless it also allows pdf".
    $parts = array_filter(array_map('trim', explode(',', $mime)));
    if (in_array('image/jpeg', $parts, true) && !in_array('application/pdf', $parts, true)) {
        return DOC_FILE_TYPES['image/jpeg'];
    }
    return DOC_FILE_TYPES['application/pdf'];
}

/** The <select> value we should pre-pick for a stored mime string. */
function dr_type_value(?string $mime): string
{
    return dr_type_label($mime) === DOC_FILE_TYPES['image/jpeg'] ? 'image/jpeg' : 'application/pdf';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Requirements | Sports Portal</title>
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
        .btn-gold{background:var(--accent-gold);color:#2b2200}.btn-gold:hover{background:#b8931f}
        .btn-danger{background:#fff5f5;color:#c53030;border:1px solid #fed7d7}.btn-danger:hover{background:#fed7d7}
        .data-card{background:#fff;border:1px solid var(--light-gray);border-radius:10px;overflow:hidden;margin-bottom:1.25rem}
        .data-table{width:100%;border-collapse:collapse}
        .data-table th{background:var(--off-white);padding:.75rem 1rem;font-size:.75rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--light-gray)}
        .data-table td{padding:.75rem 1rem;font-size:.88rem;border-bottom:1px solid var(--light-gray);color:var(--text-dark);vertical-align:middle}
        .data-table tr:last-child td{border-bottom:none}
        .pill{display:inline-block;padding:.15rem .55rem;border-radius:50px;font-size:.72rem;font-weight:700;letter-spacing:.3px}
        .pill.req{background:rgba(114,47,55,.12);color:var(--accent-maroon)}
        .pill.opt{background:rgba(26,54,93,.08);color:var(--primary-navy-light)}
        .pill.type{background:rgba(26,54,93,.08);color:var(--primary-navy)}
        .muted{color:var(--medium-gray);font-size:.8rem}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .alert-banner.info{background:rgba(13,110,253,.08);color:#052c65;border:1px solid rgba(13,110,253,.18)}
        .toolbar{display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
        .toolbar .form-group{margin:0}
        .form-card{background:#fff;border:1px solid var(--light-gray);border-radius:10px;padding:1.5rem;max-width:680px}
        .form-card h2{font-size:1.05rem;font-weight:600;color:var(--primary-navy);margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--light-gray)}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem}
        .form-group{display:flex;flex-direction:column;gap:.3rem}
        .form-group label{font-size:.78rem;font-weight:600;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.3px}
        .form-group input,.form-group select{padding:.55rem .75rem;border:1px solid var(--light-gray);border-radius:6px;font-family:inherit;font-size:.92rem;background:#fff}
        .form-group input:focus,.form-group select:focus{outline:none;border-color:var(--primary-navy)}
        .check-line{display:flex;align-items:center;gap:.5rem;font-size:.92rem;font-weight:500}
        .form-actions{display:flex;gap:.75rem;margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--light-gray)}
        .hint-box{background:rgba(13,110,253,.05);border:1px solid rgba(13,110,253,.15);border-radius:8px;padding:.75rem 1rem;font-size:.82rem;color:#052c65;margin-bottom:1.25rem}
        @media(max-width:992px){
            .sidebar{position:fixed;left:-280px;top:0;height:100vh;transition:left .3s ease;z-index:1050}
            .sidebar.open{left:0}
            .top-bar{padding:.75rem 1.25rem}
            .content-body{padding:1.25rem}
            .btn{width:100%;justify-content:center}
            .data-card{overflow-x:auto;-webkit-overflow-scrolling:touch}
            .data-table{min-width:640px}
            .form-grid{grid-template-columns:1fr}
            .form-actions{flex-direction:column}
            .toolbar{flex-direction:column;align-items:stretch}
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
                <div class="sidebar-nav-label">Site Content</div>
                <a href="notices_list.php"><i class="bi bi-megaphone"></i> <span>Notices</span></a>
                <a href="achievements_list.php"><i class="bi bi-trophy"></i> <span>Achievements</span></a>
                <div class="sidebar-nav-label">Admin</div>
                <a href="faculty_manage.php"><i class="bi bi-people-fill"></i> <span>Faculty Management</span></a>
                <a href="document_requirements.php" class="active"><i class="bi bi-file-earmark-ruled"></i> <span>Document Requirements</span></a>
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
                    <a href="logout.php?_csrf=<?= h(csrf_token()) ?>" class="btn-logout" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <header class="top-bar">
                <h2 style="font-size:1rem;font-weight:600;color:var(--primary-navy);margin:0">Document Requirements</h2>
                <?php if ($action === 'new' || $action === 'edit'): ?>
                    <a href="document_requirements.php?dept=<?= $dept_id ?>" class="btn btn-secondary" style="padding:.35rem .7rem">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                <?php endif; ?>
            </header>

            <div class="content-body">
                <?php if ($ok): ?><div class="alert-banner success"><i class="bi bi-check-circle"></i> <?= h($ok['msg']) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert-banner error"><i class="bi bi-exclamation-circle"></i> <?= h($err['msg']) ?></div><?php endif; ?>

                <?php if ($action === 'new' || $action === 'edit'):
                    $isEdit   = $action === 'edit';
                    $curName  = $isEdit ? (string)$edit_row['document_name'] : '';
                    $curReq   = $isEdit ? (int)$edit_row['is_required'] === 1 : true;
                    $curType  = $isEdit ? dr_type_value($edit_row['allowed_mime_types']) : 'application/pdf';
                ?>
                    <div class="page-header">
                        <h1><?= $isEdit ? 'Edit Document Requirement' : 'Add Document Requirement' ?></h1>
                    </div>
                    <form method="post" action="document_requirements.php" class="form-card">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="<?= $isEdit ? 'edit' : 'create' ?>">
                        <input type="hidden" name="dept" value="<?= $dept_id ?>">
                        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$edit_row['id'] ?>"><?php endif; ?>
                        <h2><?= h($dept['name']) ?></h2>
                        <div class="form-grid">
                            <div class="form-group" style="grid-column:1/-1">
                                <label>Document name *</label>
                                <input type="text" name="document_name" required maxlength="100"
                                       value="<?= h($curName) ?>" placeholder="e.g. 10th board certificate">
                            </div>
                            <div class="form-group">
                                <label>Accepted file type *</label>
                                <select name="file_type" required>
                                    <?php foreach (DOC_FILE_TYPES as $mimeVal => $mimeLabel): ?>
                                        <option value="<?= h($mimeVal) ?>" <?= $curType === $mimeVal ? 'selected' : '' ?>><?= h($mimeLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Required</label>
                                <label class="check-line" style="font-weight:normal;text-transform:none;letter-spacing:0">
                                    <input type="checkbox" name="is_required" value="1" <?= $curReq ? 'checked' : '' ?>>
                                    Student must upload this document
                                </label>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> <?= $isEdit ? 'Save Changes' : 'Add Document' ?></button>
                            <a href="document_requirements.php?dept=<?= $dept_id ?>" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Cancel</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="page-header">
                        <h1>Document Requirements</h1>
                        <a href="document_requirements.php?dept=<?= $dept_id ?>&action=new" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Document</a>
                    </div>

                    <div class="hint-box">
                        <i class="bi bi-info-circle"></i>
                        These are the upload slots a student in the selected faculty sees on <strong>Step 5 &mdash; Documents</strong>
                        of the profile wizard. Use <strong>Load standard document set</strong> to add the college's default
                        checklist for any faculty that is missing it.
                    </div>

                    <form method="get" action="document_requirements.php" class="toolbar">
                        <div class="form-group">
                            <label for="dept">Faculty / Department</label>
                            <select id="dept" name="dept" onchange="this.form.submit()">
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $dept_id ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <noscript><button type="submit" class="btn btn-secondary">Go</button></noscript>
                    </form>

                    <form method="post" action="document_requirements.php" style="margin-bottom:1.25rem"
                          onsubmit="return confirm('Add the standard document checklist for <?= h(addslashes($dept['name'])) ?>? Existing documents are kept; only missing ones are added.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="load_standard">
                        <input type="hidden" name="dept" value="<?= $dept_id ?>">
                        <button type="submit" class="btn btn-gold"><i class="bi bi-magic"></i> Load standard document set</button>
                    </form>

                    <div class="data-card">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:44px">#</th>
                                    <th>Document</th>
                                    <th style="width:120px">Required</th>
                                    <th style="width:170px">File type</th>
                                    <th style="width:110px">Uploads</th>
                                    <th style="width:160px"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$requirements): ?>
                                <tr><td colspan="6" class="muted" style="text-align:center;padding:1.5rem">
                                    No document requirements for <?= h($dept['name']) ?> yet.
                                    Click <strong>Load standard document set</strong> or <strong>Add Document</strong>.
                                </td></tr>
                            <?php else: $n = 1; foreach ($requirements as $r): ?>
                                <tr>
                                    <td class="muted"><?= $n++ ?></td>
                                    <td><strong><?= h($r['document_name']) ?></strong></td>
                                    <td>
                                        <?php if ((int)$r['is_required'] === 1): ?>
                                            <span class="pill req">Required</span>
                                        <?php else: ?>
                                            <span class="pill opt">Optional</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="pill type"><?= h(dr_type_label($r['allowed_mime_types'])) ?></span></td>
                                    <td class="muted"><?= (int)$r['upload_count'] ?></td>
                                    <td>
                                        <a class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.78rem"
                                           href="?dept=<?= $dept_id ?>&action=edit&id=<?= (int)$r['id'] ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                        <form method="post" action="document_requirements.php" style="display:inline"
                                              onsubmit="return confirm('Delete &quot;<?= h(addslashes($r['document_name'])) ?>&quot;?<?= (int)$r['upload_count'] > 0 ? '\n\nThis will also remove ' . (int)$r['upload_count'] . ' student upload(s) linked to it.' : '' ?>');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="do" value="delete">
                                            <input type="hidden" name="dept" value="<?= $dept_id ?>">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.78rem"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
