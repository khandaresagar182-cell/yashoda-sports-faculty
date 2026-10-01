<?php
/**
 * Student reset-password page. ?token=HEXSTRING
 * Validates the token against student_password_resets and lets the
 * student set a new password. Mirrors reset_password.php (the faculty
 * equivalent) — see student_forgot_process.php for how the token is
 * issued and emailed.
 *
 * Setting a password here is a real, student-chosen password, so this
 * also flips students.password_set_by_user to 1 — the same flag
 * email_verify.php sets for a self-chosen password — so
 * student_dashboard_process.php stops re-deriving the password from DOB
 * on future Step-1 edits (see migration-v47 / migration-v54).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_student()) {
    redirect('student-dashboard.php');
}

$token = (string)($_GET['token'] ?? '');
$err   = null;
$valid_student_id = null;
$row   = null;

if ($token === '' || !preg_match('/^[0-9a-f]{64}$/i', $token)) {
    $err = 'Invalid or missing token.';
} else {
    $hash = hash('sha256', $token);
    $row  = db_one(
        'SELECT id, student_id, expires_at, used_at FROM student_password_resets
          WHERE token_hash = ? LIMIT 1',
        [$hash], 's'
    );
    if (!$row) {
        $err = 'This reset link is invalid or has been used.';
    } elseif ($row['used_at']) {
        $err = 'This reset link has already been used.';
    } elseif (strtotime($row['expires_at']) < time()) {
        $err = 'This reset link has expired. Please request a new one.';
    } else {
        $valid_student_id = (int)$row['student_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_student_id) {
    csrf_check();
    $pw1 = (string)($_POST['password']  ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');
    if (strlen($pw1) < 8) {
        $err = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $err = 'Passwords do not match.';
    } else {
        $new_hash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 12]);
        db_execute('UPDATE students SET password_hash = ?, password_set_by_user = 1 WHERE id = ?',
            [$new_hash, $valid_student_id], 'si');
        db_execute('UPDATE student_password_resets SET used_at = NOW() WHERE id = ?',
            [$row['id']], 'i');
        $_SESSION['_student_reset_done'] = true; // banner on student-login.php
        redirect('student-login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password | Student Portal</title>
    <?= csrf_meta() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= h(url('css/public.css')) ?>">
    <style>
        :root{--primary-navy:#1a365d;--primary-navy-dark:#0f2744;--accent-gold:#c9a227;--accent-maroon:#722f37;--white:#fff;--off-white:#f8f9fa;--light-gray:#e9ecef;--medium-gray:#6c757d;--text-dark:#212529;--font-primary:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;--transition-smooth:all .3s ease-in-out}
        *{margin:0;padding:0;box-sizing:border-box}html,body{height:100%}
        body{font-family:var(--font-primary);color:var(--text-dark);line-height:1.6;background:var(--primary-navy-dark);display:flex;flex-direction:column}
        .login-page{flex:1;display:flex;align-items:center;justify-content:center;padding:2rem 1rem;position:relative;overflow:hidden}
        .login-card{position:relative;z-index:1;width:100%;max-width:400px;background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.25);overflow:hidden}
        .login-card-header{background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-dark));padding:1.5rem;text-align:center;position:relative}
        .login-card-header::after{content:'';position:absolute;bottom:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--accent-gold),var(--accent-maroon),var(--accent-gold))}
        .login-icon{width:54px;height:54px;background:rgba(255,255,255,.12);border:2px solid rgba(201,162,39,.4);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .7rem}
        .login-icon i{font-size:1.4rem;color:var(--accent-gold)}
        .login-card-header h1{color:#fff;font-size:1.2rem;font-weight:700;margin-bottom:.15rem}
        .login-card-header p{color:rgba(255,255,255,.6);font-size:.78rem}
        .login-card-body{padding:1.5rem}
        .form-group{margin-bottom:1rem}
        .form-group label{display:block;font-size:.82rem;font-weight:600;color:var(--primary-navy);margin-bottom:.4rem;letter-spacing:.3px;text-transform:uppercase}
        .input-wrapper{position:relative}
        .input-wrapper i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--medium-gray);pointer-events:none;z-index:1}
        .input-wrapper input{width:100%;padding:.75rem .75rem .75rem 2.75rem;border:2px solid var(--light-gray);border-radius:8px;font-family:inherit;font-size:.95rem;background:#fff;outline:none;transition:var(--transition-smooth)}
        .input-wrapper input:focus-visible{border-color:var(--primary-navy);box-shadow:0 0 0 3px rgba(26,54,93,.1)}
        .btn-login{width:100%;padding:.85rem;background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-dark));color:#fff;border:none;border-radius:8px;font-family:inherit;font-size:.95rem;font-weight:600;letter-spacing:.5px;text-transform:uppercase;cursor:pointer;transition:var(--transition-smooth)}
        .btn-login:hover{background:linear-gradient(135deg,var(--primary-navy-light),var(--primary-navy));transform:translateY(-1px)}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;font-size:.88rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .login-card-footer{padding:1.25rem 2rem;background:var(--off-white);border-top:1px solid var(--light-gray);text-align:center}
        .back-link{font-size:.85rem;color:var(--medium-gray);text-decoration:none;display:inline-flex;align-items:center;gap:.4rem}
        .back-link:hover{color:var(--primary-navy)}
        @media (max-width: 576px) {
            .login-card { max-width: 95%; border-radius: 12px; }
            .login-card-body { padding: 1.15rem 1.15rem 1rem; }
            .login-card-footer { padding: 1rem 1.15rem; }
            .login-card-header { padding: 1.1rem 1.2rem 1rem; }
            .login-card-header h1 { font-size: 1.1rem; }
            .login-icon { width: 46px; height: 46px; }
            .input-wrapper input { padding: .7rem .7rem .7rem 2.5rem; font-size: .9rem; }
            .btn-login { padding: .75rem; font-size: .9rem; }
        }
    </style>
</head>
<body>
    <main class="login-page">
        <div class="login-card">
            <div class="login-card-header">
                <div class="login-icon"><i class="bi bi-shield-lock-fill"></i></div>
                <h1>Set New Password</h1>
                <p>Choose a strong password for your account</p>
            </div>
            <div class="login-card-body">
                <?php if ($err): ?>
                    <div class="alert-banner error" role="alert"><i class="bi bi-exclamation-circle"></i> <?= h($err) ?></div>
                <?php endif; ?>
                <?php if ($valid_student_id): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                                <i class="bi bi-lock"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password2">Confirm Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="password2" name="password2" required minlength="8" autocomplete="new-password">
                                <i class="bi bi-lock-fill"></i>
                            </div>
                        </div>
                        <button type="submit" class="btn-login"><i class="bi bi-check-circle"></i> Update Password</button>
                    </form>
                <?php else: ?>
                    <p style="text-align:center;color:var(--medium-gray);font-size:.9rem">
                        <a href="student-forgot-password.php">Request a new reset link</a>
                    </p>
                <?php endif; ?>
            </div>
            <div class="login-card-footer">
                <a href="student-login.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Login</a>
            </div>
        </div>
    </main>
</body>
</html>
