<?php
/**
 * Faculty Login page.
 * Migrated from faculty-login.html with CSRF, flash, and "already logged in" redirect.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Already signed in? Bounce to the right place.
if (current_faculty()) {
    $f = current_faculty();
    if ($f['role'] === 'SUPER_ADMIN') {
        redirect('admin/dashboard.php');
    }
    redirect('faculty-select.php');
}

$flash = flash_get('login_error');
$flash_msg  = $flash['msg']  ?? '';
$flash_kind = $flash['level'] ?? 'error';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Faculty Login - YSPM's Yashoda Technical Campus Sports Faculty Portal">
    <title>Faculty Login | Faculty of Sports</title>

    <?= csrf_meta() ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        :root {
            --primary-navy: #1a365d;
            --primary-navy-dark: #0f2744;
            --primary-navy-light: #2c5282;
            --accent-gold: #c9a227;
            --accent-gold-light: #d4b84a;
            --accent-maroon: #722f37;
            --white: #ffffff;
            --off-white: #f8f9fa;
            --light-gray: #e9ecef;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
            --text-dark: #212529;
            --font-primary: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --transition-smooth: all 0.3s ease-in-out;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }

        body {
            font-family: var(--font-primary);
            color: var(--text-dark);
            line-height: 1.6;
            background: var(--primary-navy-dark);
            display: flex;
            flex-direction: column;
        }

        .login-page {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            overflow: hidden;
        }

        .login-bg-image {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            opacity: 0.25;
            z-index: 0;
        }

        .login-page::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background:
                radial-gradient(circle at 20% 50%, rgba(201, 162, 39, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(114, 47, 55, 0.06) 0%, transparent 50%),
                radial-gradient(circle at 50% 80%, rgba(44, 82, 130, 0.08) 0%, transparent 50%);
            animation: bgShift 15s ease-in-out infinite alternate;
            z-index: 0;
        }

        @keyframes bgShift {
            0%   { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-3%, -3%) rotate(2deg); }
        }

        .login-card {
            position: relative; z-index: 1;
            width: 100%; max-width: 360px;
            background: var(--white);
            border-radius: 14px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25), 0 4px 12px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            animation: cardEntry 0.6s ease-out;
        }

        @keyframes cardEntry {
            from { opacity: 0; transform: translateY(30px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .login-card-header {
            background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark));
            padding: 1.25rem 1.5rem 1.1rem;
            text-align: center;
            position: relative;
        }

        .login-card-header::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-gold), var(--accent-maroon), var(--accent-gold));
        }

        .login-icon {
            width: 48px; height: 48px;
            background: rgba(255, 255, 255, 0.12);
            border: 2px solid rgba(201, 162, 39, 0.4);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 0.6rem;
            backdrop-filter: blur(4px);
        }

        .login-icon i { font-size: 1.2rem; color: var(--accent-gold); }

        .login-card-header h1 {
            color: var(--white);
            font-size: 1.1rem; font-weight: 700;
            margin-bottom: 0.15rem;
            letter-spacing: 0.3px;
        }

        .login-card-header p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.75rem; font-weight: 400;
        }

        .login-card-body { padding: 1.25rem 1.5rem 1rem; }

        .form-group { margin-bottom: 0.9rem; }

        .form-group label {
            display: block;
            font-size: 0.82rem; font-weight: 600;
            color: var(--primary-navy);
            margin-bottom: 0.4rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .input-wrapper { position: relative; }

        .input-wrapper > i {
            position: absolute;
            left: 14px; top: 50%;
            transform: translateY(-50%);
            color: var(--medium-gray);
            font-size: 1rem;
            transition: var(--transition-smooth);
            pointer-events: none;
            z-index: 1;
        }

        .input-wrapper input {
            width: 100%;
            padding: 0.75rem 0.75rem 0.75rem 2.75rem;
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            font-family: var(--font-primary);
            font-size: 0.95rem;
            color: var(--text-dark);
            background: var(--white);
            transition: var(--transition-smooth);
            outline: none;
        }

        .input-wrapper input::placeholder { color: #adb5bd; font-weight: 400; }

        .input-wrapper input:focus {
            border-color: var(--primary-navy);
            box-shadow: 0 0 0 3px rgba(26, 54, 93, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 14px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--medium-gray);
            cursor: pointer;
            padding: 2px; font-size: 1rem;
            transition: var(--transition-smooth);
            pointer-events: all;
            z-index: 2;
        }

        .password-toggle:hover { color: var(--primary-navy); }

        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .remember-me { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; }
        .remember-me input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--primary-navy); cursor: pointer; }
        .remember-me span { font-size: 0.85rem; color: var(--medium-gray); user-select: none; }

        .forgot-link {
            font-size: 0.85rem; color: var(--accent-maroon); font-weight: 500;
            transition: var(--transition-smooth);
        }
        .forgot-link:hover { color: var(--primary-navy); text-decoration: underline; }

        .btn-login {
            width: 100%;
            padding: 0.85rem;
            background: linear-gradient(135deg, var(--primary-navy), var(--primary-navy-dark));
            color: var(--white);
            border: none; border-radius: 8px;
            font-family: var(--font-primary);
            font-size: 0.95rem; font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            cursor: pointer;
            transition: var(--transition-smooth);
            position: relative; overflow: hidden;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(201, 162, 39, 0.15), transparent);
            transition: left 0.5s ease;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, var(--primary-navy-light), var(--primary-navy));
            box-shadow: 0 6px 20px rgba(26, 54, 93, 0.35);
            transform: translateY(-1px);
        }
        .btn-login:hover::before { left: 100%; }
        .btn-login:active { transform: translateY(0); }
        .btn-login i { margin-right: 0.5rem; }

        .login-card-footer {
            padding: 1.25rem 2rem;
            background: var(--off-white);
            border-top: 1px solid var(--light-gray);
            text-align: center;
        }

        .back-link {
            font-size: 0.85rem; color: var(--medium-gray); font-weight: 500;
            display: inline-flex; align-items: center; gap: 0.4rem;
            transition: var(--transition-smooth);
        }
        .back-link:hover { color: var(--primary-navy); }
        .back-link i { font-size: 0.9rem; transition: transform 0.3s ease; }
        .back-link:hover i { transform: translateX(-3px); }

        .login-footer {
            background: var(--primary-navy-dark);
            border-top: 3px solid var(--accent-gold);
            padding: 1rem 0; text-align: center;
        }
        .login-footer p { color: rgba(255, 255, 255, 0.5); font-size: 0.8rem; margin: 0; }
        .login-footer a { color: var(--accent-gold-light); }
        .login-footer a:hover { color: var(--white); }

        .login-alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 1.25rem;
            display: none;
            align-items: center;
            gap: 0.5rem;
            animation: alertIn 0.3s ease;
        }

        .login-alert.alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #842029;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        @keyframes alertIn {
            from { opacity: 0; transform: translateY(-5px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 576px) {
            .login-card { max-width: 95%; border-radius: 12px; }
            .login-card-body { padding: 1.25rem 1.25rem 1rem; }
            .login-card-footer { padding: 1rem 1.25rem; }
            .login-footer { padding: 0.75rem 0; }
            .login-footer p { font-size: 0.75rem; }
            .input-wrapper input { padding: 0.7rem 0.7rem 0.7rem 2.5rem; font-size: 0.9rem; }
            .btn-login { padding: 0.75rem; font-size: 0.9rem; }
        }
        @media (max-width: 480px) {
            .login-card-header { padding: 1.25rem 1.25rem 1.1rem; }
            .login-card-body   { padding: 1rem 1.15rem 0.85rem; }
            .login-card-footer { padding: 0.85rem 1.15rem; }
            .login-card-header h1 { font-size: 1rem; }
            .login-card-header p { font-size: 0.7rem; }
            .login-icon { width: 42px; height: 42px; }
            .form-options { flex-direction: column; align-items: flex-start; }
            .form-group label { font-size: 0.75rem; }
            .back-link { font-size: 0.8rem; }
        }
    </style>
</head>

<body>
    <main class="login-page">
        <img src="images/bg2.jpg" alt="" class="login-bg-image">
        <div class="login-card">

            <div class="login-card-header">
                <div class="login-icon">
                    <img src="images/ytc-logo.png" alt="YTC Logo" style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <h1>Faculty Login</h1>
                <p>Faculty of Sports - Secure Portal</p>
            </div>

            <div class="login-card-body">

                <div class="login-alert alert-danger" id="loginAlert" style="<?= $flash_msg ? 'display:flex' : 'display:none' ?>">
                    <i class="bi bi-exclamation-circle"></i>
                    <span id="alertMessage"><?= h($flash_msg ?: '') ?></span>
                </div>

                <form id="loginForm" action="admin/login_process.php" method="POST" novalidate>
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label for="facultyEmail">Email or Username</label>
                        <div class="input-wrapper">
                            <input type="text" id="facultyEmail" name="username" placeholder="Enter your email or username"
                                required autocomplete="username" value="<?= h($_POST['username'] ?? '') ?>">
                            <i class="bi bi-person"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="facultyPassword">Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="facultyPassword" name="password"
                                placeholder="Enter your password" required autocomplete="current-password">
                            <i class="bi bi-lock"></i>
                            <button type="button" class="password-toggle" id="togglePassword"
                                aria-label="Toggle password visibility">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember" id="rememberMe">
                            <span>Remember me</span>
                        </label>
                        <a href="forgot-password.php" class="forgot-link">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn-login" id="loginBtn">
                        <i class="bi bi-box-arrow-in-right"></i> Sign In
                    </button>
                </form>
            </div>

            <div class="login-card-footer">
                <a href="index.php" class="back-link">
                    <i class="bi bi-arrow-left"></i> Back to Homepage
                </a>
            </div>
        </div>
    </main>

    <footer class="login-footer">
        <p>&copy; 2026 <a href="index.php">YSPM's Yashoda Technical Campus, Satara</a>. All Rights Reserved.</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toggleBtn = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('facultyPassword');

            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function () {
                    const icon = this.querySelector('i');
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
