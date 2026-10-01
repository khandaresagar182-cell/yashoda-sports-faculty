<?php
/**
 * Email verification for self-registered students.
 * ?token=HEXSTRING (from the link in send_verification_email()).
 *
 * Clicking the link (GET) validates the token against pending_registrations
 * and, if it's still good, creates the real `students` row right away —
 * password follows the same DOB-derived scheme (DDMMYYYY) faculty-created
 * accounts use, so there's no separate "choose a password" step. The
 * student is logged straight in and shown their credentials once (for
 * signing in again on another device) before continuing to the wizard.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_student()) {
    redirect('student-dashboard.php');
}

$token    = (string)($_GET['token'] ?? '');
$err      = null;
$verified = null;

if ($token === '' || !preg_match('/^[0-9a-f]{64}$/i', $token)) {
    $err = 'Invalid or missing verification link.';
} else {
    $hash = hash('sha256', $token);
    $row  = db_one(
        'SELECT id, email, full_name, mother_name, gender, dob, mobile, department_id, expires_at
           FROM pending_registrations
          WHERE token_hash = ? LIMIT 1',
        [$hash], 's'
    );
    if (!$row) {
        $err = 'This verification link is invalid or has already been used.';
    } elseif (strtotime($row['expires_at']) < time()) {
        $err = 'This verification link has expired. Please register again to get a new one.';
    } else {
        // Someone could theoretically finish a second pending registration
        // for the same email (or a faculty could add the student directly)
        // between the link being issued and clicked — guard against that.
        $already = db_one('SELECT id FROM students WHERE email = ?', [$row['email']], 's');
        if ($already) {
            $err = 'An account with this email already exists. Please use the login page or the "Forgot Password" link.';
            db_execute('DELETE FROM pending_registrations WHERE id = ?', [(int)$row['id']], 'i');
        } else {
            $plaintext_password = dob_to_password($row['dob']);
            $password_hash = password_hash((string)$plaintext_password, PASSWORD_BCRYPT);
            try {
                $new_id = db_insert(
                    'INSERT INTO students
                        (enrollment_no, full_name, mother_name, dob, gender, email, mobile, department_id,
                         password_hash, password_set_by_user, is_active, is_self_registered, registered_at)
                     VALUES (NULL,?,?,?,?,?,?,?,?,0,1,1,NOW())',
                    [
                        $row['full_name'],
                        $row['mother_name'],
                        $row['dob'],
                        $row['gender'],
                        $row['email'],
                        $row['mobile'],
                        (int)$row['department_id'],
                        $password_hash,
                    ],
                    'ssssssis'
                );
            } catch (Throwable $e) {
                error_log('[email_verify] insert failed: ' . $e->getMessage());
                $showDebug = getenv('APP_DEBUG') === '1';
                $err = $showDebug
                    ? 'Could not create the account: ' . $e->getMessage()
                    : 'Could not create the account. Please try again.';
                $new_id = null;
            }

            if ($new_id) {
                db_execute('DELETE FROM pending_registrations WHERE id = ?', [(int)$row['id']], 'i');
                $emailed = send_student_credentials_email($row['email'], $row['full_name'], $row['email'], (string)$plaintext_password);
                student_login([
                    'id'            => $new_id,
                    'email'         => $row['email'],
                    'full_name'     => $row['full_name'],
                    'department_id' => (int)$row['department_id'],
                ]);
                $verified = [
                    'name'     => $row['full_name'],
                    'email'    => $row['email'],
                    'password' => $plaintext_password,
                    'emailed'  => $emailed,
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email | Faculty of Sports</title>
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
        .login-card{position:relative;z-index:1;width:100%;max-width:440px;background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.25);overflow:hidden}
        .login-card-header{background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-dark));padding:1.5rem;text-align:center;position:relative}
        .login-card-header::after{content:'';position:absolute;bottom:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--accent-gold),var(--accent-maroon),var(--accent-gold))}
        .login-icon{width:54px;height:54px;background:rgba(255,255,255,.12);border:2px solid rgba(201,162,39,.4);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .7rem}
        .login-icon i{font-size:1.4rem;color:var(--accent-gold)}
        .login-card-header h1{color:#fff;font-size:1.2rem;font-weight:700;margin-bottom:.15rem}
        .login-card-header p{color:rgba(255,255,255,.6);font-size:.78rem}
        .login-card-body{padding:1.5rem}
        .btn-login{width:100%;padding:.85rem;background:linear-gradient(135deg,var(--primary-navy),var(--primary-navy-dark));color:#fff;border:none;border-radius:8px;font-family:inherit;font-size:.95rem;font-weight:600;letter-spacing:.5px;text-transform:uppercase;cursor:pointer;transition:var(--transition-smooth);text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:.5rem}
        .btn-login:hover{background:linear-gradient(135deg,var(--primary-navy-light),var(--primary-navy));transform:translateY(-1px)}
        .alert-banner{padding:.8rem 1rem;border-radius:8px;font-size:.88rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem}
        .alert-banner.error{background:rgba(220,53,69,.1);color:#842029;border:1px solid rgba(220,53,69,.2)}
        .alert-banner.success{background:rgba(25,135,84,.1);color:#0a3622;border:1px solid rgba(25,135,84,.2)}
        .login-card-footer{padding:1.25rem 2rem;background:var(--off-white);border-top:1px solid var(--light-gray);text-align:center}
        .back-link{font-size:.85rem;color:var(--medium-gray);text-decoration:none;display:inline-flex;align-items:center;gap:.4rem}
        .back-link:hover{color:var(--primary-navy)}
        .verify-email-note{font-size:.85rem;color:var(--medium-gray);text-align:center;margin-bottom:1.1rem}
        .verify-email-note strong{color:var(--primary-navy)}
        .warning-box{background:rgba(255,193,7,.1);border:1px solid rgba(255,193,7,.3);color:#664d03;padding:.75rem .9rem;border-radius:8px;font-size:.82rem;line-height:1.5;margin-bottom:1.1rem}
        .warning-box i{color:#856404;margin-right:.3rem}
        .cred-row{display:flex;align-items:stretch;border:2px solid var(--light-gray);border-radius:8px;overflow:hidden;margin-bottom:.85rem}
        .cred-label{background:var(--off-white);padding:.6rem .8rem;min-width:100px;display:flex;align-items:center;gap:.4rem;font-size:.74rem;font-weight:700;color:var(--primary-navy);text-transform:uppercase;letter-spacing:.3px;border-right:1px solid var(--light-gray)}
        .cred-value{flex:1;padding:.6rem .8rem;font-family:'Courier New',monospace;font-size:.95rem;font-weight:600;color:var(--text-dark);background:#fff;display:flex;align-items:center;word-break:break-all}
        .btn-copy{background:var(--accent-gold);color:#fff;border:none;padding:0 .7rem;cursor:pointer;font-size:.76rem;font-weight:600;transition:var(--transition-smooth);min-width:56px}
        .btn-copy:hover{background:#d4b84a}
        .btn-copy.copied{background:#1e7e34}
        @media (max-width: 576px) {
            .login-card { max-width: 95%; border-radius: 12px; }
            .login-card-body { padding: 1.15rem 1.15rem 1rem; }
            .login-card-footer { padding: 1rem 1.15rem; }
            .login-card-header { padding: 1.1rem 1.2rem 1rem; }
            .login-card-header h1 { font-size: 1.1rem; }
            .login-icon { width: 46px; height: 46px; }
            .cred-row { flex-direction: column; }
            .cred-label { min-width: auto; border-right: none; border-bottom: 1px solid var(--light-gray); padding: .5rem .75rem; font-size: .72rem; }
            .cred-value { font-size: .88rem; padding: .55rem .75rem; }
        }
    </style>
</head>
<body>
    <main class="login-page">
        <div class="login-card">
            <?php if ($verified): ?>
                <div class="login-card-header">
                    <div class="login-icon"><i class="bi bi-patch-check-fill"></i></div>
                    <h1>Email Verified!</h1>
                    <p>Your account is ready</p>
                </div>
                <div class="login-card-body">
                    <div class="alert-banner success" role="alert"><i class="bi bi-check-circle-fill"></i> <?= h($verified['email']) ?> is verified and your account has been created.</div>

                    <div class="warning-box">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <strong>Save these credentials</strong> — you'll need the password below to sign in on another device. You can change it anytime from your dashboard.
                    </div>

                    <?php if (!empty($verified['emailed'])): ?>
                        <div class="warning-box" style="background:rgba(25,135,84,.1);border-color:rgba(25,135,84,.3);color:#0a3622">
                            <i class="bi bi-envelope-check-fill" style="color:#198754"></i>
                            A copy was also emailed to <strong><?= h($verified['email']) ?></strong>.
                        </div>
                    <?php endif; ?>

                    <div class="cred-row">
                        <div class="cred-label"><i class="bi bi-person"></i> Username</div>
                        <div class="cred-value" id="credUser"><?= h($verified['email']) ?></div>
                        <button type="button" class="btn-copy" data-copy="credUser">Copy</button>
                    </div>
                    <div class="cred-row">
                        <div class="cred-label"><i class="bi bi-key"></i> Password</div>
                        <div class="cred-value" id="credPass"><?= h($verified['password']) ?></div>
                        <button type="button" class="btn-copy" data-copy="credPass">Copy</button>
                    </div>

                    <a href="student-dashboard.php" class="btn-login" style="margin-top:.5rem"><i class="bi bi-arrow-right-circle"></i> Continue to Your Dashboard</a>
                </div>
            <?php else: ?>
                <div class="login-card-header">
                    <div class="login-icon"><i class="bi bi-envelope-exclamation-fill"></i></div>
                    <h1>Verify Your Email</h1>
                    <p>Account activation</p>
                </div>
                <div class="login-card-body">
                    <?php if ($err): ?>
                        <div class="alert-banner error" role="alert"><i class="bi bi-exclamation-circle"></i> <?= h($err) ?></div>
                    <?php endif; ?>
                    <p style="text-align:center;color:var(--medium-gray);font-size:.9rem">
                        <a href="student-register.php">Register again to get a new link</a>
                    </p>
                </div>
            <?php endif; ?>
            <div class="login-card-footer">
                <a href="student-login.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Login</a>
            </div>
        </div>
    </main>
    <?php if ($verified): ?>
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
                    function fallbackCopy() {
                        var ta = document.createElement('textarea');
                        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                        document.body.appendChild(ta); ta.select();
                        try { document.execCommand('copy'); } catch (e) {}
                        document.body.removeChild(ta);
                        showCopied();
                    }
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(showCopied).catch(fallbackCopy);
                    } else {
                        fallbackCopy();
                    }
                });
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>
