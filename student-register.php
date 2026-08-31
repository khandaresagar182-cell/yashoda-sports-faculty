<?php
/**
 * Public student self-registration page.
 * Collects: name, email, mobile, DOB, department.
 * Username (email) and password (DOB as DDMMYYYY) are then shown on screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Already signed in as student? Bounce to dashboard.
if (current_student()) {
    redirect('student-dashboard.php');
}

$departments = db_select(
    'SELECT id, code, name FROM departments WHERE is_active = 1 ORDER BY display_order, name'
);

$flash = flash_get('register_error');
$flash_msg = $flash['msg'] ?? '';

// Preserve form values on re-render
$old = $_SESSION['_register_old'] ?? [];
if ($old) unset($_SESSION['_register_old']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Student Registration - YSPM's Yashoda Technical Campus Sports Faculty Portal">
    <title>Student Register | Faculty of Sports</title>

    <?= csrf_meta() ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <style>
        /* Date of Birth picker (flatpickr) — brand colors, quick year jump */
        .flatpickr-calendar { border-radius: 10px; box-shadow: 0 12px 30px rgba(0,0,0,.18); }
        .flatpickr-months .flatpickr-month, .flatpickr-current-month .flatpickr-monthDropdown-months,
        .flatpickr-current-month input.cur-year { color: var(--primary-navy); fill: var(--primary-navy); }
        .flatpickr-current-month input.cur-year { font-weight: 600; }
        span.flatpickr-weekday { color: var(--primary-navy); font-weight: 600; }
        .flatpickr-day.selected, .flatpickr-day.selected:hover { background: var(--primary-navy); border-color: var(--primary-navy); }
        .flatpickr-day.today { border-color: var(--accent-gold); }
        .flatpickr-day.today:hover { background: var(--accent-gold); border-color: var(--accent-gold); }
        .flatpickr-day:hover { background: var(--off-white); }
        body { background: var(--primary-navy-dark); display:flex; flex-direction:column; min-height:100vh; }
        .register-page { flex:1; display:flex; align-items:center; justify-content:center; padding:2rem 1rem; position:relative; overflow:hidden; }
        .register-page::before { content:''; position:absolute; inset:-50%; background: radial-gradient(circle at 20% 50%, rgba(201,162,39,.08) 0%, transparent 50%), radial-gradient(circle at 80% 20%, rgba(114,47,55,.06) 0%, transparent 50%); animation: bgShift 15s ease-in-out infinite alternate; }
        @keyframes bgShift { 0% { transform: translate(0,0) rotate(0deg); } 100% { transform: translate(-3%,-3%) rotate(2deg); } }
        .register-card { position:relative; z-index:1; width:100%; max-width:520px; background:#fff; border-radius:14px; box-shadow:0 12px 40px rgba(0,0,0,.25), 0 4px 12px rgba(0,0,0,.15); overflow:hidden; animation: cardEntry .6s ease-out; }
        @keyframes cardEntry { from { opacity:0; transform: translateY(30px) scale(.97); } to { opacity:1; transform: translateY(0) scale(1); } }
        .register-card-header { background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); padding:1.4rem 1.5rem 1.2rem; text-align:center; position:relative; }
        .register-card-header::after { content:''; position:absolute; bottom:0; left:0; right:0; height:4px; background: linear-gradient(90deg, var(--accent-gold), var(--accent-maroon), var(--accent-gold)); }
        .register-icon { width:54px; height:54px; background: rgba(255,255,255,.12); border:2px solid rgba(201,162,39,.4); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto .65rem; backdrop-filter: blur(4px); }
        .register-icon i { font-size:1.4rem; color: var(--accent-gold); }
        .register-card-header h1 { color:#fff; font-size:1.2rem; font-weight:700; margin-bottom:.15rem; letter-spacing:.3px; }
        .register-card-header p { color: rgba(255,255,255,.6); font-size:.78rem; font-weight:400; }
        .register-card-body { padding:1.5rem 1.6rem 1.3rem; }
        .form-row { display:grid; grid-template-columns: 1fr 1fr; gap: 0.9rem 1rem; }
        @media (max-width: 520px) { .form-row { grid-template-columns: 1fr; } }
        .form-group { margin-bottom:.85rem; }
        .form-group label { display:block; font-size:.78rem; font-weight:600; color: var(--primary-navy); margin-bottom:.4rem; letter-spacing:.3px; text-transform: uppercase; }
        .form-group input, .form-group select { width:100%; padding:.65rem .75rem; border:2px solid var(--light-gray); border-radius:8px; font-family:inherit; font-size:.92rem; color: var(--text-dark); background:#fff; transition: var(--transition-smooth); outline:none; }
        .form-group input:focus, .form-group select:focus { border-color: var(--primary-navy); box-shadow: 0 0 0 3px rgba(26,54,93,.1); }
        .form-group .hint { font-size:.72rem; color: var(--medium-gray); margin-top:.25rem; }
        .alert-danger { padding:.7rem .9rem; border-radius:8px; font-size:.85rem; margin-bottom:1.1rem; display:flex; align-items:center; gap:.5rem; background: rgba(220,53,69,.1); color:#842029; border:1px solid rgba(220,53,69,.2); }
        .btn-register { width:100%; padding:.85rem; background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); color:#fff; border:none; border-radius:8px; font-family:inherit; font-size:.95rem; font-weight:600; letter-spacing:.5px; text-transform:uppercase; cursor:pointer; transition: var(--transition-smooth); position:relative; overflow:hidden; }
        .btn-register::before { content:''; position:absolute; inset:0; background: linear-gradient(90deg, transparent, rgba(201,162,39,.15), transparent); left:-100%; transition: left .5s ease; }
        .btn-register:hover { background: linear-gradient(135deg, var(--primary-navy-light), var(--primary-navy)); box-shadow: 0 6px 20px rgba(26,54,93,.35); transform: translateY(-1px); }
        .btn-register:hover::before { left:100%; }
        .btn-register:active { transform: translateY(0); }
        .btn-register i { margin-right:.5rem; }
        .register-card-footer { padding:1.1rem 1.6rem; background: var(--off-white); border-top:1px solid var(--light-gray); text-align:center; font-size:.85rem; color: var(--medium-gray); }
        .register-card-footer a { color: var(--accent-maroon); font-weight:600; }
        .register-card-footer a:hover { color: var(--primary-navy); text-decoration:underline; }
        .login-footer { background: var(--primary-navy-dark); border-top:3px solid var(--accent-gold); padding:1rem 0; text-align:center; }
        .login-footer p { color: rgba(255,255,255,.5); font-size:.8rem; margin:0; }
        .login-footer a { color: var(--accent-gold-light); }
        .login-footer a:hover { color:#fff; }
        .info-note { font-size:.78rem; color: var(--medium-gray); background: rgba(13,202,240,.06); border:1px solid rgba(13,202,240,.2); padding:.55rem .7rem; border-radius:6px; margin-top:.4rem; line-height:1.5; }
        .info-note i { color:#055160; margin-right:.3rem; }
        @media (max-width: 576px) {
            .register-card { max-width: 95%; border-radius: 12px; }
            .register-card-header { padding: 1.1rem 1.2rem 1rem; }
            .register-card-header h1 { font-size: 1.05rem; }
            .register-card-header p { font-size: .72rem; }
            .register-icon { width: 46px; height: 46px; }
            .register-card-body { padding: 1.15rem 1.15rem 1rem; }
            .register-card-footer { padding: .9rem 1.15rem; font-size: .82rem; }
            .form-group input, .form-group select { padding: .6rem .7rem; font-size: .88rem; }
            .btn-register { padding: .75rem; font-size: .9rem; }
            .login-footer p { font-size: .75rem; }
        }
        @media (max-width: 480px) {
            .register-card-body { padding: 1rem; }
            .form-group label { font-size: .72rem; }
            .form-group { margin-bottom: .7rem; }
        }
    </style>
</head>
<body>
    <main class="register-page">
        <div class="register-card">
            <div class="register-card-header">
                <div class="register-icon">
                    <i class="bi bi-person-plus-fill"></i>
                </div>
                <h1>Student Registration</h1>
                <p>Faculty of Sports — Create your portal account</p>
            </div>

            <div class="register-card-body">
                <?php if ($flash_msg): ?>
                    <div class="alert-danger">
                        <i class="bi bi-exclamation-circle"></i>
                        <span><?= h($flash_msg) ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" action="register_process.php" novalidate>
                    <?= csrf_field() ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="surname">Surname *</label>
                            <input type="text" id="surname" name="surname" required
                                   minlength="1" maxlength="60"
                                   placeholder="e.g. Khandare"
                                   value="<?= h($old['surname'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" id="first_name" name="first_name" required
                                   minlength="1" maxlength="60"
                                   placeholder="e.g. Sagar"
                                   value="<?= h($old['first_name'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="middle_name">Middle Name *</label>
                        <input type="text" id="middle_name" name="middle_name" required
                               minlength="1" maxlength="60"
                               placeholder="e.g. Vinod"
                               value="<?= h($old['middle_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="mother_name">Mother Name *</label>
                        <input type="text" id="mother_name" name="mother_name" required
                               minlength="1" maxlength="100"
                               placeholder="e.g. Sunita"
                               value="<?= h($old['mother_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="gender">Gender *</label>
                        <select id="gender" name="gender" required>
                            <option value="">— Select —</option>
                            <?php foreach (gender_options() as $g): ?>
                                <option value="<?= h($g) ?>" <?= is_selected($g, $old['gender'] ?? '') ?>><?= h($g) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="full_name" id="full_name_field" value="">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" required
                                   maxlength="160"
                                   placeholder="you@example.com"
                                   value="<?= h($old['email'] ?? '') ?>">
                            <div class="hint">This will be your username.</div>
                        </div>
                        <div class="form-group">
                            <label for="mobile">Mobile No. *</label>
                            <input type="tel" id="mobile" name="mobile" required
                                   pattern="[0-9]{10}" maxlength="10" inputmode="numeric"
                                   placeholder="10-digit mobile"
                                   value="<?= h($old['mobile'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="dob">Date of Birth *</label>
                            <input type="text" id="dob" name="dob" required autocomplete="off"
                                   placeholder="dd-mm-yyyy"
                                   value="<?= h($old['dob'] ?? '') ?>">
                            <div class="hint">Password = DOB in DDMMYYYY format.</div>
                        </div>
                        <div class="form-group">
                            <label for="department_id">Faculty *</label>
                            <select id="department_id" name="department_id" required>
                                <option value="">— Select Faculty —</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>"
                                        <?= is_selected($d['id'], $old['department_id'] ?? '') ?>>
                                        <?= h($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="info-note">
                        <i class="bi bi-info-circle"></i>
                        After registering, your <strong>username (email)</strong> and
                        <strong>password (your DOB in DDMMYYYY)</strong> will be shown on screen.
                        Please save them — you can change your password later from your dashboard.
                    </div>

                    <div style="margin-top:1.1rem">
                        <button type="submit" class="btn-register">
                            <i class="bi bi-check2-circle"></i> Create My Account
                        </button>
                    </div>
                </form>
            </div>

            <div class="register-card-footer">
                Already have an account? <a href="student-login.php">Sign in</a>
            </div>
        </div>
    </main>

    <footer class="login-footer">
        <p>&copy; 2026 <a href="index.php">YSPM's Yashoda Technical Campus, Satara</a>. All Rights Reserved.</p>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        flatpickr('#dob', {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'd-m-Y',
            minDate: '1995-01-01',
            maxDate: 'today',
            disableMobile: true
        });
    </script>
    <script>
        // Auto-combine the three name fields into the hidden `full_name`
        // field on submit so the server still receives a single value to
        // store in the `students.full_name` column.
        // Stored format: "Surname First Middle" (matches dashboard form).
        (function () {
            var form = document.querySelector('form[action="register_process.php"]');
            if (!form) return;
            form.addEventListener('submit', function () {
                var sn = (document.getElementById('surname')     || {}).value || '';
                var fn = (document.getElementById('first_name')  || {}).value || '';
                var mn = (document.getElementById('middle_name') || {}).value || '';
                var parts = [sn, fn, mn].map(function (s) { return s.trim(); }).filter(Boolean);
                document.getElementById('full_name_field').value = parts.join(' ');
            });
        })();
    </script>
</body>
</html>
<?php
function is_selected($value, $current): string {
    return (string)$value === (string)$current ? 'selected' : '';
}
?>
