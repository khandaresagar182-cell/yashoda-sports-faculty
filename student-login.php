<?php
/**
 * Student login page. Username = email, Password = DOB in DDMMYYYY.
 * Locks out after LOGIN_LOCKOUT_MAX failed attempts (same constants
 * used for faculty login).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_student()) {
    redirect('student-dashboard.php');
}

$flash = flash_get('student_login_error');
$flash_msg  = $flash['msg']  ?? '';

// "Account reset" one-shot banner
$reset_info = $_SESSION['_student_reset_done'] ?? null;
if ($reset_info !== null) unset($_SESSION['_student_reset_done']);

// Account-created banner (so users can come here right after registering)
$just_registered = $_SESSION['_student_just_registered'] ?? null;
if ($just_registered !== null) unset($_SESSION['_student_just_registered']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Student Login - YSPM's Yashoda Technical Campus Sports Faculty Portal">
    <title>Student Login | Faculty of Sports</title>

    <?= csrf_meta() ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">

    <style>
        body { background: var(--primary-navy-dark); display:flex; flex-direction:column; min-height:100vh; }
        .login-page { flex:1; display:flex; align-items:center; justify-content:center; padding:2rem 1rem; position:relative; overflow:hidden; }
        .login-page::before { content:''; position:absolute; inset:-50%; background: radial-gradient(circle at 20% 50%, rgba(201,162,39,.08) 0%, transparent 50%), radial-gradient(circle at 80% 20%, rgba(114,47,55,.06) 0%, transparent 50%); animation: bgShift 15s ease-in-out infinite alternate; }
        @keyframes bgShift { 0% { transform: translate(0,0) rotate(0deg); } 100% { transform: translate(-3%,-3%) rotate(2deg); } }
        .login-card { position:relative; z-index:1; width:100%; max-width:380px; background:#fff; border-radius:14px; box-shadow:0 12px 40px rgba(0,0,0,.25), 0 4px 12px rgba(0,0,0,.15); overflow:hidden; animation: cardEntry .6s ease-out; }
        @keyframes cardEntry { from { opacity:0; transform: translateY(30px) scale(.97); } to { opacity:1; transform: translateY(0) scale(1); } }
        .login-card-header { background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); padding:1.25rem 1.5rem 1.1rem; text-align:center; position:relative; }
        .login-card-header::after { content:''; position:absolute; bottom:0; left:0; right:0; height:4px; background: linear-gradient(90deg, var(--accent-gold), var(--accent-maroon), var(--accent-gold)); }
        .login-icon { width:48px; height:48px; background: rgba(255,255,255,.12); border:2px solid rgba(201,162,39,.4); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto .6rem; backdrop-filter: blur(4px); }
        .login-icon i { font-size:1.2rem; color: var(--accent-gold); }
        .login-card-header h1 { color:#fff; font-size:1.1rem; font-weight:700; margin-bottom:.15rem; }
        .login-card-header p { color: rgba(255,255,255,.6); font-size:.75rem; }
        .login-card-body { padding:1.25rem 1.5rem 1rem; }
        .form-group { margin-bottom:.9rem; }
        .form-group label { display:block; font-size:.82rem; font-weight:600; color: var(--primary-navy); margin-bottom:.4rem; letter-spacing:.3px; text-transform:uppercase; }
        .input-wrapper { position:relative; }
        .input-wrapper > i { position:absolute; left:14px; top:50%; transform: translateY(-50%); color: var(--medium-gray); font-size:1rem; pointer-events:none; z-index:1; }
        .input-wrapper input { width:100%; padding:.75rem .75rem .75rem 2.75rem; border:2px solid var(--light-gray); border-radius:8px; font-family:inherit; font-size:.95rem; color: var(--text-dark); background:#fff; transition: var(--transition-smooth); outline:none; }
        .input-wrapper input:focus { border-color: var(--primary-navy); box-shadow: 0 0 0 3px rgba(26,54,93,.1); }
        .password-toggle { position:absolute; right:14px; top:50%; transform: translateY(-50%); background:none; border:none; color: var(--medium-gray); cursor:pointer; padding:2px; font-size:1rem; z-index:2; }
        .password-toggle:hover { color: var(--primary-navy); }
        .form-options { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.4rem; flex-wrap:wrap; gap:.5rem; }
        .forgot-link { font-size:.85rem; color: var(--accent-maroon); font-weight:500; }
        .forgot-link:hover { color: var(--primary-navy); text-decoration:underline; }
        .btn-login { width:100%; padding:.85rem; background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); color:#fff; border:none; border-radius:8px; font-family:inherit; font-size:.95rem; font-weight:600; letter-spacing:.5px; text-transform:uppercase; cursor:pointer; transition: var(--transition-smooth); position:relative; overflow:hidden; }
        .btn-login::before { content:''; position:absolute; inset:0; background: linear-gradient(90deg, transparent, rgba(201,162,39,.15), transparent); left:-100%; transition: left .5s ease; }
        .btn-login:hover { background: linear-gradient(135deg, var(--primary-navy-light), var(--primary-navy)); box-shadow: 0 6px 20px rgba(26,54,93,.35); transform: translateY(-1px); }
        .btn-login:hover::before { left:100%; }
        .btn-login:active { transform: translateY(0); }
        .btn-login i { margin-right:.5rem; }
        .login-card-footer { padding:1.1rem 1.5rem; background: var(--off-white); border-top:1px solid var(--light-gray); text-align:center; font-size:.85rem; color: var(--medium-gray); }
        .login-card-footer a { color: var(--accent-maroon); font-weight:600; }
        .login-card-footer a:hover { color: var(--primary-navy); text-decoration:underline; }
        .login-footer { background: var(--primary-navy-dark); border-top:3px solid var(--accent-gold); padding:1rem 0; text-align:center; }
        .login-footer p { color: rgba(255,255,255,.5); font-size:.8rem; margin:0; }
        .login-footer a { color: var(--accent-gold-light); }
        .login-footer a:hover { color:#fff; }
        .login-alert { padding:.75rem 1rem; border-radius:8px; font-size:.85rem; margin-bottom:1.15rem; display:flex; align-items:center; gap:.5rem; animation: alertIn .3s ease; }
        .login-alert.alert-danger { background: rgba(220,53,69,.1); color:#842029; border:1px solid rgba(220,53,69,.2); }
        .login-alert.alert-success { background: rgba(25,135,84,.1); color:#0a3622; border:1px solid rgba(25,135,84,.2); }
        .login-alert.alert-info { background: rgba(13,202,240,.1); color:#055160; border:1px solid rgba(13,202,240,.2); }
        @keyframes alertIn { from { opacity:0; transform: translateY(-5px); } to { opacity:1; transform: translateY(0); } }
        .pwd-hint { font-size:.72rem; color: var(--medium-gray); margin-top:.3rem; line-height:1.4; }
        @media (max-width: 576px) {
            .login-card { max-width: 95%; border-radius: 12px; }
            .login-card-body { padding: 1.1rem 1.15rem .9rem; }
            .login-card-footer { padding: .9rem 1.15rem; font-size: .82rem; }
            .login-footer p { font-size: .75rem; }
            .input-wrapper input { padding: .7rem .7rem .7rem 2.5rem; font-size: .9rem; }
            .btn-login { padding: .75rem; font-size: .9rem; }
        }
        @media (max-width: 480px) {
            .login-card-header { padding: 1.1rem 1.15rem 1rem; }
            .login-card-header h1 { font-size: 1rem; }
            .login-card-header p { font-size: .7rem; }
            .login-icon { width: 42px; height: 42px; }
            .form-options { flex-direction: column; align-items: flex-start; }
            .form-group label { font-size: .75rem; }
        }
    </style>
</head>
<body>
    <main class="login-page">
        <div class="login-card">
            <div class="login-card-header">
                <div class="login-icon">
                    <img src="<?= h(url('images/ytc-logo.png')) ?>" alt="YTC Logo" style="width:100%; height:100%; object-fit:contain;">
                </div>
                <h1>Student Login</h1>
                <p>Faculty of Sports — Secure Portal</p>
            </div>

            <div class="login-card-body">
                <?php if ($flash_msg): ?>
                    <div class="login-alert alert-danger">
                        <i class="bi bi-exclamation-circle"></i>
                        <span><?= h($flash_msg) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($reset_info): ?>
                    <div class="login-alert alert-info">
                        <i class="bi bi-key"></i>
                        <span>Your password has been reset to your date of birth (DDMMYYYY). Please sign in.</span>
                    </div>
                <?php endif; ?>

                <form id="loginForm" action="student_login_process.php" method="POST" novalidate>
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label for="studentEmail">Email (Username)</label>
                        <div class="input-wrapper">
                            <input type="email" id="studentEmail" name="email" required autocomplete="username"
                                   placeholder="Enter your email"
                                   value="<?= h($_POST['email'] ?? '') ?>">
                            <i class="bi bi-envelope"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="studentPassword">Password (Your DOB)</label>
                        <div class="input-wrapper">
                            <input type="password" id="studentPassword" name="password" required
                                   autocomplete="current-password"
                                   placeholder="DDMMYYYY"
                                   pattern="[0-9]{8}" maxlength="8" inputmode="numeric">
                            <i class="bi bi-lock"></i>
                            <button type="button" class="password-toggle" id="togglePassword"
                                    aria-label="Toggle password visibility">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="pwd-hint">First-time login? Your password is your date of birth in DDMMYYYY format (e.g. 15082004).</div>
                    </div>

                    <div class="form-options">
                        <a href="student-forgot-password.php" class="forgot-link">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn-login" id="loginBtn">
                        <i class="bi bi-box-arrow-in-right"></i> Sign In
                    </button>
                </form>
            </div>

            <div class="login-card-footer">
                New here? <a href="student-register.php">Create an account</a>
            </div>
        </div>
    </main>

    <footer class="login-footer">
        <p>&copy; 2026 <a href="index.php">YSPM's Yashoda Technical Campus, Satara</a>. All Rights Reserved.</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var toggleBtn = document.getElementById('togglePassword');
            var passwordInput = document.getElementById('studentPassword');
            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function () {
                    var icon = this.querySelector('i');
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        icon.classList.replace('bi-eye', 'bi-eye-slash');
                    } else {
                        passwordInput.type = 'password';
                        icon.classList.replace('bi-eye-slash', 'bi-eye');
                    }
                });
            }
        });
    </script>
</body>
</html>
