<?php
/**
 * Student forgot-password.
 * Step 1: enter email -> we look up the student.
 * Step 2: show their username (email) and the new password (their DOB in DDMMYYYY)
 *         on screen. They can copy and use it to log in.
 *
 * The "step 2" is rendered on the same page via a flash payload
 * so we don't need a separate URL.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_student()) {
    redirect('student-dashboard.php');
}

$flash_err = flash_get('student_forgot_error');
$flash_ok  = flash_get('student_forgot_ok');
$reset     = $_SESSION['_student_reset_show'] ?? null;
if ($reset !== null) unset($_SESSION['_student_reset_show']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Student Portal</title>
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
        .forgot-page { flex:1; display:flex; align-items:center; justify-content:center; padding:2rem 1rem; position:relative; overflow:hidden; }
        .forgot-page::before { content:''; position:absolute; inset:-50%; background: radial-gradient(circle at 20% 50%, rgba(201,162,39,.08) 0%, transparent 50%), radial-gradient(circle at 80% 20%, rgba(114,47,55,.06) 0%, transparent 50%); animation: bgShift 15s ease-in-out infinite alternate; }
        @keyframes bgShift { 0% { transform: translate(0,0) rotate(0deg); } 100% { transform: translate(-3%,-3%) rotate(2deg); } }
        .forgot-card { position:relative; z-index:1; width:100%; max-width:480px; background:#fff; border-radius:14px; box-shadow:0 12px 40px rgba(0,0,0,.25), 0 4px 12px rgba(0,0,0,.15); overflow:hidden; animation: cardEntry .6s ease-out; }
        @keyframes cardEntry { from { opacity:0; transform: translateY(30px) scale(.97); } to { opacity:1; transform: translateY(0) scale(1); } }
        .forgot-card-header { background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); padding:1.4rem 1.5rem 1.2rem; text-align:center; position:relative; }
        .forgot-card-header::after { content:''; position:absolute; bottom:0; left:0; right:0; height:4px; background: linear-gradient(90deg, var(--accent-gold), var(--accent-maroon), var(--accent-gold)); }
        .forgot-icon { width:54px; height:54px; background: rgba(255,255,255,.12); border:2px solid rgba(201,162,39,.4); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto .65rem; }
        .forgot-icon i { font-size:1.4rem; color: var(--accent-gold); }
        .forgot-card-header h1 { color:#fff; font-size:1.15rem; font-weight:700; margin-bottom:.15rem; }
        .forgot-card-header p { color: rgba(255,255,255,.6); font-size:.78rem; }
        .forgot-card-body { padding:1.4rem 1.6rem 1.3rem; }
        .instruction-text { font-size:.85rem; color: var(--medium-gray); margin-bottom:1.1rem; line-height:1.55; }
        .form-group { margin-bottom:1.1rem; }
        .form-group label { display:block; font-size:.78rem; font-weight:600; color: var(--primary-navy); margin-bottom:.4rem; text-transform:uppercase; letter-spacing:.3px; }
        .input-wrapper { position:relative; }
        .input-wrapper > i { position:absolute; left:14px; top:50%; transform: translateY(-50%); color: var(--medium-gray); font-size:1rem; pointer-events:none; z-index:1; }
        .input-wrapper input { width:100%; padding:.75rem .75rem .75rem 2.75rem; border:2px solid var(--light-gray); border-radius:8px; font-family:inherit; font-size:.95rem; background:#fff; outline:none; transition: var(--transition-smooth); }
        .input-wrapper input:focus { border-color: var(--primary-navy); box-shadow: 0 0 0 3px rgba(26,54,93,.1); }
        .btn-submit { width:100%; padding:.85rem; background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); color:#fff; border:none; border-radius:8px; font-family:inherit; font-size:.95rem; font-weight:600; letter-spacing:.5px; text-transform:uppercase; cursor:pointer; transition: var(--transition-smooth); }
        .btn-submit:hover { background: linear-gradient(135deg, var(--primary-navy-light), var(--primary-navy)); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(26,54,93,.3); }
        .btn-submit i { margin-right:.5rem; }
        .forgot-card-footer { padding:1.1rem 1.5rem; background: var(--off-white); border-top:1px solid var(--light-gray); text-align:center; font-size:.85rem; }
        .back-link { color: var(--accent-maroon); font-weight:500; text-decoration:none; }
        .back-link:hover { color: var(--primary-navy); text-decoration:underline; }
        .login-footer { background: var(--primary-navy-dark); border-top:3px solid var(--accent-gold); padding:1rem 0; text-align:center; }
        .login-footer p { color: rgba(255,255,255,.5); font-size:.8rem; margin:0; }
        .login-footer a { color: var(--accent-gold-light); }
        .login-footer a:hover { color:#fff; }
        .alert-danger, .alert-info { padding:.7rem .9rem; border-radius:8px; font-size:.85rem; margin-bottom:1.1rem; display:flex; align-items:center; gap:.5rem; }
        .alert-danger { background: rgba(220,53,69,.1); color:#842029; border:1px solid rgba(220,53,69,.2); }
        .alert-info { background: rgba(13,202,240,.1); color:#055160; border:1px solid rgba(13,202,240,.2); }
        .cred-row { display:flex; align-items:stretch; border:2px solid var(--light-gray); border-radius:8px; overflow:hidden; margin-bottom:.85rem; }
        .cred-label { background: var(--off-white); padding:.65rem .9rem; min-width:120px; display:flex; align-items:center; gap:.5rem; font-size:.78rem; font-weight:700; color: var(--primary-navy); text-transform:uppercase; letter-spacing:.3px; border-right:1px solid var(--light-gray); }
        .cred-label i { color: var(--accent-gold); font-size:.95rem; }
        .cred-value { flex:1; padding:.65rem .9rem; font-family: 'Courier New', monospace; font-size:1rem; font-weight:600; color: var(--text-dark); background:#fff; display:flex; align-items:center; word-break: break-all; }
        .btn-copy { background: var(--accent-gold); color:#fff; border:none; padding:0 .8rem; cursor:pointer; font-size:.8rem; font-weight:600; min-width:60px; }
        .btn-copy:hover { background:#d4b84a; }
        .btn-copy.copied { background:#1e7e34; }
        .warn-box { background: rgba(255,193,7,.1); border:1px solid rgba(255,193,7,.3); color:#664d03; padding:.7rem .85rem; border-radius:8px; font-size:.8rem; line-height:1.5; margin-bottom:1rem; }
        .warn-box i { color:#856404; margin-right:.3rem; }
        @media (max-width: 576px) {
            .forgot-card { max-width: 95%; }
            .forgot-card-body { padding: 1.15rem 1.15rem 1rem; }
            .forgot-card-footer { padding: .85rem 1.15rem; }
            .forgot-card-header { padding: 1.1rem 1.2rem 1rem; }
            .forgot-card-header h1 { font-size: 1rem; }
            .forgot-icon { width: 48px; height: 48px; }
            .login-footer p { font-size: .75rem; }
            .cred-row { flex-direction: column; }
            .cred-label { min-width: auto; border-right: none; border-bottom: 1px solid var(--light-gray); padding: .5rem .75rem; font-size: .72rem; }
            .cred-value { font-size: .9rem; padding: .55rem .75rem; }
            .btn-copy { padding: .55rem .75rem; min-width: auto; }
        }
    </style>
</head>
<body>
    <main class="forgot-page">
        <div class="forgot-card">
            <div class="forgot-card-header">
                <div class="forgot-icon"><i class="bi bi-key"></i></div>
                <h1>Forgot Password</h1>
                <p>Student Portal — Faculty of Sports</p>
            </div>

            <div class="forgot-card-body">
                <?php if ($flash_err): ?>
                    <div class="alert-danger"><i class="bi bi-exclamation-circle"></i> <?= h($flash_err['msg']) ?></div>
                <?php endif; ?>

                <?php if ($reset): ?>
                    <div class="alert-info"><i class="bi bi-check-circle"></i> Your password has been reset to your date of birth. Use the credentials below to sign in.</div>
                    <div class="warn-box">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <strong>Important:</strong> Save these credentials now. You can change your password from your dashboard at any time.
                    </div>
                    <div class="cred-row">
                        <div class="cred-label"><i class="bi bi-person"></i> Username</div>
                        <div class="cred-value" id="credUser"><?= h($reset['email']) ?></div>
                        <button type="button" class="btn-copy" data-copy="credUser">Copy</button>
                    </div>
                    <div class="cred-row">
                        <div class="cred-label"><i class="bi bi-key"></i> Password</div>
                        <div class="cred-value" id="credPass"><?= h($reset['password']) ?></div>
                        <button type="button" class="btn-copy" data-copy="credPass">Copy</button>
                    </div>
                    <a href="student-login.php" class="btn-submit" style="display:inline-flex; align-items:center; justify-content:center; text-decoration:none; margin-top:.4rem">
                        <i class="bi bi-box-arrow-in-right"></i> Go to Login
                    </a>
                <?php else: ?>
                    <p class="instruction-text">
                        Enter the email you used to register. We'll reset your password back to your
                        date of birth (DDMMYYYY) and show it on screen so you can sign in.
                    </p>
                    <form method="post" action="student_forgot_process.php">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="input-wrapper">
                                <input type="email" id="email" name="email" required
                                       placeholder="you@example.com"
                                       value="<?= h($_POST['email'] ?? '') ?>" autocomplete="email">
                                <i class="bi bi-envelope"></i>
                            </div>
                        </div>
                        <button type="submit" class="btn-submit">
                            <i class="bi bi-arrow-repeat"></i> Reset My Password
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="forgot-card-footer">
                <a href="student-login.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Login</a>
            </div>
        </div>
    </main>

    <footer class="login-footer">
        <p>&copy; 2026 <a href="index.php">YSPM's Yashoda Technical Campus, Satara</a>. All Rights Reserved.</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.btn-copy[data-copy]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var el = document.getElementById(btn.getAttribute('data-copy'));
                    if (!el) return;
                    var text = el.textContent.trim();
                    function showCopied() {
                        var orig = btn.textContent;
                        btn.textContent = 'Copied!';
                        btn.classList.add('copied');
                        setTimeout(function () { btn.textContent = orig; btn.classList.remove('copied'); }, 1500);
                    }
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(showCopied).catch(function () {
                            var ta = document.createElement('textarea');
                            ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
                            document.body.appendChild(ta); ta.select();
                            try { document.execCommand('copy'); } catch (e) {}
                            document.body.removeChild(ta); showCopied();
                        });
                    } else {
                        var ta = document.createElement('textarea');
                        ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
                        document.body.appendChild(ta); ta.select();
                        try { document.execCommand('copy'); } catch (e) {}
                        document.body.removeChild(ta); showCopied();
                    }
                });
            });
        });
    </script>
</body>
</html>
