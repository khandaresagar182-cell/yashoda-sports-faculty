<?php
/**
 * One-shot "check your email" page shown right after registering.
 * Backed by $_SESSION['_register_pending']; that data is removed on first
 * read so a refresh of this page shows a generic fallback instead.
 * The actual account isn't created yet — see email_verify.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pending = $_SESSION['_register_pending'] ?? null;
if ($pending) unset($_SESSION['_register_pending']);

// If they refresh the page, we have nothing to show.
$expired = !$pending;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Your Email | Faculty of Sports</title>
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
        @media (prefers-reduced-motion: reduce) { .success-card { animation: none; } }
        .success-header { background: linear-gradient(135deg, #1e7e34, #155724); padding:1.6rem 1.5rem 1.4rem; text-align:center; position:relative; }
        .success-header::after { content:''; position:absolute; bottom:0; left:0; right:0; height:4px; background: linear-gradient(90deg, var(--accent-gold), var(--accent-maroon), var(--accent-gold)); }
        .success-icon { width:64px; height:64px; background: rgba(255,255,255,.15); border:2px solid rgba(255,255,255,.4); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto .7rem; }
        .success-icon i { font-size:1.9rem; color:#fff; }
        .success-header h1 { color:#fff; font-size:1.25rem; font-weight:700; margin-bottom:.2rem; }
        .success-header p { color: rgba(255,255,255,.85); font-size:.85rem; }
        .success-body { padding:1.5rem 1.6rem 1.4rem; }
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
            .btn-primary-action { padding: 0.75rem; font-size: 0.9rem; }
            .login-footer p { font-size: 0.75rem; }
        }
    </style>
</head>
<body>
    <main class="success-page">
        <div class="success-card">
            <?php if ($pending): ?>
                <div class="success-header">
                    <div class="success-icon"><i class="bi bi-envelope-check"></i></div>
                    <h1>Check Your Email</h1>
                    <p>Welcome, <?= h($pending['name']) ?>. One step left to activate your account.</p>
                </div>

                <div class="success-body">
                    <?php if (!empty($pending['emailed'])): ?>
                        <div class="warning-box" style="background: rgba(25,135,84,.1); border-color: rgba(25,135,84,.3); color:#0a3622">
                            <i class="bi bi-envelope-check-fill" style="color:#198754"></i>
                            We've sent a verification link to <strong><?= h($pending['email']) ?></strong>.
                            Open it to activate your account — your login will be created automatically.
                        </div>
                    <?php else: ?>
                        <div class="warning-box">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            We couldn't send the verification email to <strong><?= h($pending['email']) ?></strong> right now.
                            Please try registering again shortly, or contact the Faculty of Sports if this keeps happening.
                        </div>
                    <?php endif; ?>

                    <div class="warning-box">
                        <i class="bi bi-clock-history"></i>
                        The link expires in 24 hours. Didn't get it? Check your spam folder, or
                        <a href="student-register.php">register again with the same email</a> to get a new one.
                    </div>

                    <?php if (!empty($pending['dev_link'])): ?>
                        <div class="warning-box" style="background: rgba(13,110,253,.06); border-color: rgba(13,110,253,.2); color:#052c65">
                            <i class="bi bi-bug-fill"></i>
                            <strong>Local dev only:</strong>
                            <a href="<?= h($pending['dev_link']) ?>"><?= h($pending['dev_link']) ?></a>
                        </div>
                    <?php endif; ?>

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
                    <h1>Nothing to Show</h1>
                    <p>This page is only shown right after registering.</p>
                </div>
                <div class="expired-box">
                    <i class="bi bi-envelope"></i>
                    <h2>Already registered?</h2>
                    <p>Check your email for the verification link, or register again if it expired.</p>
                    <a href="student-register.php" class="btn-primary-action">
                        <i class="bi bi-person-plus"></i> Register
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="login-footer">
        <p>&copy; 2026 <a href="index.php">YSPM's Yashoda Technical Campus, Satara</a>. All Rights Reserved.</p>
    </footer>
</body>
</html>
