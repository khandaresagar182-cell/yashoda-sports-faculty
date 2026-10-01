<?php
/**
 * Forgot-password page. Accepts username or email and (in dev) shows a reset link.
 * In production the link would be emailed; for XAMPP / cPanel without SMTP we
 * display it on screen so the user can copy it.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_faculty()) {
    redirect('admin/dashboard.php');
}

$flash = flash_get('forgot_info');
$dev_link = $_SESSION['_flash_forgot_devlink'] ?? null;
if ($dev_link !== null) unset($_SESSION['_flash_forgot_devlink']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Forgot Password - YSPM's Yashoda Technical Campus Sports Faculty Portal">
    <title>Forgot Password | Faculty of Sports</title>

    <?= csrf_meta() ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
</head>
<body>
    <main class="forgot-page">
        <img src="<?= h(url('images/bg2.jpg')) ?>" alt="" class="forgot-bg-image">
        <div class="forgot-card">

            <div class="forgot-card-header">
                <div class="forgot-icon">
                    <i class="bi bi-key"></i>
                </div>
                <h1>Reset Password</h1>
                <p>Faculty of Sports — Secure Portal</p>
            </div>

            <div class="forgot-card-body">
                <?php if ($flash): ?>
                    <div class="alert-banner info" role="alert" style="padding:.75rem 1rem;border-radius:8px;font-size:.85rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;background:rgba(13,202,240,.08);color:#055160;border:1px solid rgba(13,202,240,.2)">
                        <i class="bi bi-info-circle"></i> <?= h($flash['msg']) ?>
                    </div>
                    <?php if ($dev_link): ?>
                        <div class="dev-link" style="background:rgba(255,193,7,.1);border:1px solid rgba(255,193,7,.3);padding:.75rem;border-radius:8px;font-size:.78rem;color:#664d03;margin-top:1rem;word-break:break-all">
                            <strong style="display:block;margin-bottom:.3rem"><i class="bi bi-tools"></i> Dev only:</strong>
                            <a href="<?= h($dev_link) ?>"><?= h($dev_link) ?></a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <form method="post" action="forgot_process.php">
                    <?= csrf_field() ?>
                    <p class="instruction-text">
                        Enter your email address or faculty ID and we'll send you instructions to reset your password.
                    </p>
                    <div class="form-group" style="margin-bottom:1.25rem">
                        <label for="emailOrId" style="display:block;font-size:.85rem;font-weight:600;color:var(--primary-navy);margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.5px">Email Address or Faculty ID</label>
                        <div class="input-wrapper" style="position:relative">
                            <input type="text" id="emailOrId" name="email_or_username" required autocomplete="username"
                                style="width:100%;padding:.75rem .75rem .75rem 2.75rem;border:2px solid var(--light-gray);border-radius:8px;font-family:inherit;font-size:.95rem;background:#fff;outline:none;transition:var(--transition-smooth)">
                            <i class="bi bi-person" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--medium-gray);font-size:1rem;pointer-events:none;z-index:1"></i>
                        </div>
                    </div>
                    <button type="submit" class="btn-submit" style="width:100%;padding:.85rem;background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-dark));color:#fff;border:none;border-radius:8px;font-family:inherit;font-size:.95rem;font-weight:600;letter-spacing:.5px;text-transform:uppercase;cursor:pointer;transition:var(--transition-smooth);margin-bottom:1rem">
                        <i class="bi bi-send"></i> Send Reset Link
                    </button>
                </form>
            </div>

            <div class="forgot-card-footer">
                <a href="faculty-login.php" class="back-link" style="font-size:.9rem;color:var(--medium-gray);font-weight:500;display:inline-flex;align-items:center;gap:.4rem;transition:var(--transition-smooth);text-decoration:none">
                    <i class="bi bi-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
    </main>

    <footer class="forgot-footer">
        <p>&copy; <?= date('Y') ?> <a href="index.php">YSPM's Yashoda Technical Campus, Satara</a>. All Rights Reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
