<?php
/**
 * One-shot page that shows the new student's username + password.
 * Backed by $_SESSION['_new_account']; that data is removed on first read
 * so a refresh of this page shows an "already used" message.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$creds = $_SESSION['_new_account'] ?? null;
if ($creds) unset($_SESSION['_new_account']);

// If they refresh the page, we have nothing to show.
$expired = !$creds;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Created | Faculty of Sports</title>
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
        .success-page { flex:1; display:flex; align-items:center; justify-content:center; padding:2rem 1rem; position:relative; overflow:hidden; }
        .success-page::before { content:''; position:absolute; inset:-50%; background: radial-gradient(circle at 30% 40%, rgba(40,167,69,.12) 0%, transparent 50%), radial-gradient(circle at 70% 70%, rgba(201,162,39,.08) 0%, transparent 50%); }
        .success-card { position:relative; z-index:1; width:100%; max-width:520px; background:#fff; border-radius:14px; box-shadow:0 12px 40px rgba(0,0,0,.25), 0 4px 12px rgba(0,0,0,.15); overflow:hidden; animation: cardEntry .6s ease-out; }
        @keyframes cardEntry { from { opacity:0; transform: translateY(30px) scale(.97); } to { opacity:1; transform: translateY(0) scale(1); } }
        .success-header { background: linear-gradient(135deg, #1e7e34, #155724); padding:1.6rem 1.5rem 1.4rem; text-align:center; position:relative; }
        .success-header::after { content:''; position:absolute; bottom:0; left:0; right:0; height:4px; background: linear-gradient(90deg, var(--accent-gold), var(--accent-maroon), var(--accent-gold)); }
        .success-icon { width:64px; height:64px; background: rgba(255,255,255,.15); border:2px solid rgba(255,255,255,.4); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto .7rem; }
        .success-icon i { font-size:1.9rem; color:#fff; }
        .success-header h1 { color:#fff; font-size:1.25rem; font-weight:700; margin-bottom:.2rem; }
        .success-header p { color: rgba(255,255,255,.85); font-size:.85rem; }
        .success-body { padding:1.5rem 1.6rem 1.4rem; }
        .cred-row { display:flex; align-items:stretch; border:2px solid var(--light-gray); border-radius:8px; overflow:hidden; margin-bottom:.85rem; }
        .cred-label { background: var(--off-white); padding:.65rem .9rem; min-width:120px; display:flex; align-items:center; gap:.5rem; font-size:.78rem; font-weight:700; color: var(--primary-navy); text-transform:uppercase; letter-spacing:.3px; border-right:1px solid var(--light-gray); }
        .cred-label i { color: var(--accent-gold); font-size:.95rem; }
        .cred-value { flex:1; padding:.65rem .9rem; font-family: 'Courier New', monospace; font-size:1rem; font-weight:600; color: var(--text-dark); background:#fff; display:flex; align-items:center; word-break: break-all; }
        .btn-copy { background: var(--accent-gold); color:#fff; border:none; padding:0 .8rem; cursor:pointer; font-size:.8rem; font-weight:600; transition: var(--transition-smooth); min-width:60px; }
        .btn-copy:hover { background: var(--accent-gold-light, #d4b84a); }
        .btn-copy.copied { background:#1e7e34; }
        .warning-box { background: rgba(255,193,7,.1); border:1px solid rgba(255,193,7,.3); color:#664d03; padding:.75rem .9rem; border-radius:8px; font-size:.82rem; line-height:1.5; margin-bottom:1.1rem; }
        .warning-box i { color:#856404; margin-right:.3rem; }
        .btn-primary-action { display:inline-flex; align-items:center; gap:.5rem; width:100%; justify-content:center; padding:.85rem; background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark)); color:#fff; border:none; border-radius:8px; font-family:inherit; font-size:.95rem; font-weight:600; letter-spacing:.5px; text-transform:uppercase; cursor:pointer; transition: var(--transition-smooth); text-decoration:none; }
        .btn-primary-action:hover { background: linear-gradient(135deg, var(--primary-navy-light), var(--primary-navy)); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(26,54,93,.35); }
        .success-footer { padding:1rem 1.6rem; background: var(--off-white); border-top:1px solid var(--light-gray); text-align:center; font-size:.85rem; }
        .login-footer { background: var(--primary-navy-dark); border-top:3px solid var(--accent-gold); padding:1rem 0; text-align:center; }
        .login-footer p { color: rgba(255,255,255,.5); font-size:.8rem; margin:0; }
        .login-footer a { color: var(--accent-gold-light); }
        .expired-box { text-align:center; padding:2rem 1.5rem; }
        .expired-box i { font-size:3rem; color: var(--medium-gray); margin-bottom:1rem; display:block; }
        .expired-box h2 { font-size:1.1rem; color: var(--primary-navy); margin-bottom:.5rem; }
        .expired-box p { color: var(--medium-gray); font-size:.9rem; margin-bottom:1.2rem; }
        @media (max-width: 576px) {
            .success-card { max-width: 95%; border-radius: 12px; }
            .success-header { padding: 1.25rem 1.25rem 1.1rem; }
            .success-header h1 { font-size: 1.1rem; }
            .success-header p { font-size: 0.78rem; }
            .success-icon { width: 50px; height: 50px; }
            .success-icon i { font-size: 1.5rem; }
            .success-body { padding: 1.15rem 1.15rem 1rem; }
            .warning-box { font-size: 0.75rem; padding: 0.6rem 0.8rem; margin-bottom: 0.85rem; }
            .cred-row { flex-direction: column; }
            .cred-label { min-width: auto; border-right: none; border-bottom: 1px solid var(--light-gray); padding: .5rem .75rem; font-size: .72rem; }
            .cred-value { font-size: .9rem; padding: .55rem .75rem; }
            .btn-copy { padding: .55rem .75rem; min-width: auto; }
            .btn-primary-action { padding: 0.75rem; font-size: 0.9rem; }
            .login-footer p { font-size: 0.75rem; }
        }
    </style>
</head>
<body>
    <main class="success-page">
        <div class="success-card">
            <?php if ($creds): ?>
                <div class="success-header">
                    <div class="success-icon"><i class="bi bi-check-lg"></i></div>
                    <h1>Account Created Successfully!</h1>
                    <p>Welcome, <?= h($creds['name']) ?>. Please save your credentials below.</p>
                </div>

                <div class="success-body">
                    <div class="warning-box">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <strong>Important:</strong> Save these credentials now.
                        This page will not show them again after a refresh.
                        You can change your password from your dashboard at any time.
                    </div>

                    <?php if (!empty($creds['emailed'])): ?>
                        <div class="warning-box" style="background: rgba(25,135,84,.1); border-color: rgba(25,135,84,.3); color:#0a3622">
                            <i class="bi bi-envelope-check-fill" style="color:#198754"></i>
                            A copy of these credentials was also emailed to <strong><?= h($creds['email']) ?></strong>.
                        </div>
                    <?php endif; ?>

                    <div class="cred-row">
                        <div class="cred-label"><i class="bi bi-person"></i> Username</div>
                        <div class="cred-value" id="credUser"><?= h($creds['email']) ?></div>
                        <button type="button" class="btn-copy" data-copy="credUser">Copy</button>
                    </div>

                    <div class="cred-row">
                        <div class="cred-label"><i class="bi bi-key"></i> Password</div>
                        <div class="cred-value" id="credPass"><?= h($creds['password']) ?></div>
                        <button type="button" class="btn-copy" data-copy="credPass">Copy</button>
                    </div>

                    <a href="student-login.php" class="btn-primary-action" style="margin-top:.5rem">
                        <i class="bi bi-box-arrow-in-right"></i> Go to Login
                    </a>
                </div>

                <div class="success-footer">
                    <a href="index.php" style="color: var(--medium-gray); text-decoration:none">
                        <i class="bi bi-arrow-left"></i> Back to Homepage
                    </a>
                </div>
            <?php else: ?>
                <div class="success-header">
                    <div class="success-icon"><i class="bi bi-info-circle"></i></div>
                    <h1>Credentials Already Viewed</h1>
                    <p>For security, the credentials are shown only once.</p>
                </div>
                <div class="expired-box">
                    <i class="bi bi-shield-lock"></i>
                    <h2>Need your password?</h2>
                    <p>If you didn't save your password, use the "Forgot Password" link on the login page to reset it to your date of birth.</p>
                    <a href="student-login.php" class="btn-primary-action">
                        <i class="bi bi-box-arrow-in-right"></i> Go to Login
                    </a>
                </div>
            <?php endif; ?>
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
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(showCopied).catch(fallbackCopy);
                    } else {
                        fallbackCopy();
                    }
                    function fallbackCopy() {
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.select();
                        try { document.execCommand('copy'); } catch (e) {}
                        document.body.removeChild(ta);
                        showCopied();
                    }
                    function showCopied() {
                        var orig = btn.textContent;
                        btn.textContent = 'Copied!';
                        btn.classList.add('copied');
                        setTimeout(function () {
                            btn.textContent = orig;
                            btn.classList.remove('copied');
                        }, 1500);
                    }
                });
            });
        });
    </script>
</body>
</html>
