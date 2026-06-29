<?php
/**
 * Single entry point. All .php files that need DB/session/helpers start with:
 *   require __DIR__ . '/../includes/bootstrap.php';
 * (or the appropriate relative path).
 */

declare(strict_types=1);

/* ---------------- configuration & DB ---------------- */
// db.php defines DB_* and APP_ENV constants used by the rest of bootstrap.
require_once __DIR__ . '/db.php';

/**
 * Load config.local.php if present, and mirror its values into both
 * getenv() (via putenv) and $_SERVER so the rest of the codebase can
 * read them with either API. This is the bridge needed on hosts (GoDaddy
 * shared cPanel) that block env[] in .user.ini / MultiPHP INI Editor.
 *
 * The file is .gitignored. See includes/config.local.example.php for shape.
 */
$localCfgPath = __DIR__ . '/config.local.php';
if (is_file($localCfgPath)) {
    $localCfg = require $localCfgPath;
    if (is_array($localCfg)) {
        // Keys like DB_SSL, SITE_URL, APP_ENV are critical on shared hosts
        // (Namecheap, GoDaddy) that block env[] in .user.ini. Push every
        // key into both getenv() and $_SERVER so the rest of the codebase
        // can read them with either API.
        foreach ($localCfg as $k => $v) {
            $sv = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
            // Put in both places so getenv() AND $_SERVER lookups work.
            if (getenv($k) === false) {
                @putenv($k . '=' . $sv);
            }
            if (!isset($_SERVER[$k])) {
                $_SERVER[$k] = $sv;
            }
        }
    }
}

/* ---------------- site URL ---------------- */
// Trusted public base URL used to build absolute links in emails, password
// resets, and any other outbound URLs. NEVER use $_SERVER['HTTP_HOST'] for
// this — attackers can poison the Host header to redirect victims.
if (!defined('SITE_URL')) {
    // Check env vars, $_SERVER (set by config.local.php bridge above),
    // Railway's RAILWAY_PUBLIC_DOMAIN, then fall back to localhost.
    $env = getenv('SITE_URL');
    if (($env === false || $env === '') && isset($_SERVER['SITE_URL']) && $_SERVER['SITE_URL'] !== '') {
        $env = $_SERVER['SITE_URL'];
    }
    $railway_domain = getenv('RAILWAY_PUBLIC_DOMAIN');
    define('SITE_URL', $env !== false && $env !== ''
        ? rtrim($env, '/')
        : ($railway_domain !== false && $railway_domain !== ''
            ? 'https://' . rtrim($railway_domain, '/')
            : 'http://localhost/college-sports-faculty'));
}

/* ---------------- error reporting ---------------- */

if (APP_ENV === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

/* ---------------- session ---------------- */

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_name('CSF_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        // Lax instead of Strict: Strict breaks same-origin form POSTs in
        // several real-world scenarios (browser back-button cache, top-level
        // navigation that initiated outside the site, some proxy setups
        // like DigitalOcean App Platform edge). Lax still blocks CSRF for
        // cross-site requests (POSTs from <form> on attacker.com) while
        // allowing same-origin navigations to carry the session cookie.
        // For a college admin portal this is the right trade-off.
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '0');
    ini_set('session.gc_maxlifetime', '1800');
    session_start();
}

/* ---------------- autoload sibling includes ---------------- */
// db.php is already required above; load the rest here.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/jersey.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/student_rendering.php';
require_once __DIR__ . '/seed_check.php';
require_once __DIR__ . '/mobile_sidebar.php';

/* ---------------- staff-area gate (admin / faculty / student-side pages) ---------------- */
// Both the seed-user check and the mobile-sidebar injector only run on
// pages the faculty/student side actually renders. Public pages
// (index.php, login pages, student-register.php) skip these.
// student-profile.php and student-search.php live at the docroot, not
// under /admin/, so they need an explicit path check.
$req_uri = $_SERVER['REQUEST_URI'] ?? '';
$is_staff_area = (strpos($req_uri, '/admin/') !== false)
              || (strpos($req_uri, '/faculty-') !== false)
              || (preg_match('#/admin($|\?)#', $req_uri) === 1)
              || (strpos($req_uri, '/student-profile.php') !== false)
              || (strpos($req_uri, '/student-search.php') !== false);

if ($is_staff_area) {
    // Mobile sidebar: 10 admin pages (faculty_manage, notices_list,
    // notice_edit, achievements_list, achievement_edit, provisional_list,
    // final_list, jersey_dashboard, jersey_manage, student_list) lack a
    // toggle button / overlay / JS handler. The injector adds those
    // pieces on the fly. Pages that already have a working pattern
    // (dashboard.php, faculty-select.php, student-profile.php,
    // student-search.php) get a no-op. Opt-out: append ?nomobile=1.
    mobile_sidebar_inject();
}

/* ---------------- seed-user self-check (admin/faculty areas only) ---------------- */
// Verify the published seed passwords still work, but only in admin/faculty
// areas — public pages and student pages skip this to keep the DB idle.
if ($is_staff_area) {
    if (isset($_GET['dismiss_seed_check'])) {
        // Acknowledge the warning for the rest of this session.
        if (isset($_SESSION['seed_check']) && is_array($_SESSION['seed_check'])) {
            $_SESSION['seed_check']['ok'] = true;
        }
    } else {
        @seed_check_flash();
        if (!empty($_SESSION['seed_check']) && empty($_SESSION['seed_check']['ok'])) {
            // Inject a visible warning banner into the HTML response just before
            // </body>. Output buffering is the only uniform injection point that
            // covers every admin/faculty page without per-page edits.
            if (ob_get_level() === 0) ob_start();
            ob_start(function ($html) {
                $warn = seed_check_warning();
                if ($warn === '') return $html;
                $style = '<style>.seed-check-warn{position:fixed;left:0;right:0;bottom:0;z-index:9999;'
                    . 'background:#fff3cd;color:#664d03;border-top:3px solid #ffc107;'
                    . 'padding:.85rem 1.25rem;font-size:.9rem;box-shadow:0 -2px 12px rgba(0,0,0,.08);'
                    . 'display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}'
                    . '.seed-check-warn code{background:rgba(0,0,0,.06);padding:1px 5px;border-radius:3px;font-size:.85em}'
                    . '.seed-check-warn a{margin-left:auto;color:#664d03;text-decoration:underline;font-weight:600}</style>';
                if (stripos($html, '</body>') !== false) {
                    $html = preg_replace('~</body>~i', $style . $warn . "\n</body>", $html, 1);
                } else {
                    $html .= $style . $warn;
                }
                return $html;
            });
        }
    }
}

/* ---------------- security headers ---------------- */

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; "
         . "img-src 'self' data: https: uploads/; "
         . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
         . "frame-ancestors 'self'");
}
