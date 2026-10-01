<?php
/**
 * Default-password self-check.
 *
 * The seed data (sql/seed.ready.sql) creates a handful of well-known accounts
 * (`admin`, `eng_faculty`, ...) whose passwords are documented — in the
 * README/INSTALL notes and in this repo. Anyone who can read those docs can
 * sign in as any of them until the password is changed, so the useful question
 * is not "did the seed drift?" but "is a documented default STILL ACTIVE?".
 *
 * This check answers that. On a staff page, for a signed-in faculty member, it
 * verifies whether a seeded account still accepts its documented default and,
 * if so, exposes a dismissable banner via seed_check_warning():
 *   - SUPER_ADMIN sees every seeded account that still has its default.
 *   - A FACULTY user is only told about their OWN account (they can't fix the
 *     others, and there is no reason to list account names to them).
 *
 * (It used to work the other way round — it warned when a seed password
 * STOPPED working and stayed silent while the defaults were live, which is
 * the opposite of what protects a deployment.)
 *
 * Cost control: bcrypt verification is deliberately slow, so this
 *   - never runs for anonymous requests (login pages, bots) — no DB work at all;
 *   - verifies one account for FACULTY, the seeded set for SUPER_ADMIN;
 *   - caches the result in $_SESSION['seed_check'] for SEED_CHECK_TTL.
 *
 * Only loads on admin/faculty areas (caller in bootstrap.php checks the path).
 */

declare(strict_types=1);

// username => documented default password.
const SEED_CHECK_USERS = [
    'admin'          => 'Admin@123',
    'eng_faculty'    => 'Faculty@123',
    'poly_faculty'   => 'Faculty@123',
    'pharm_faculty'  => 'Faculty@123',
    'dpharm_faculty' => 'Faculty@123',
];
const SEED_CHECK_TTL = 3600; // 1 hour

/**
 * Run the check, cache the result in the session, and return true when NO
 * checked account still uses its documented default password.
 */
function seed_check_run(): bool {
    // Static cache within the request.
    static $cached = null;
    if ($cached !== null) return $cached;

    $me = current_faculty();
    if (!$me) {
        // Anonymous / expired session: nothing to warn about, and no reason to
        // spend a bcrypt verification or a DB query on it.
        return $cached = true;
    }

    // Session cache to avoid re-verifying on every request. Keyed to the
    // signed-in faculty so a different login in the same session re-checks.
    if (isset($_SESSION['seed_check']) && is_array($_SESSION['seed_check'])
        && isset($_SESSION['seed_check']['at'], $_SESSION['seed_check']['ok'])
        && ($_SESSION['seed_check']['uid'] ?? null) === $me['id']
        && (time() - (int)$_SESSION['seed_check']['at']) < SEED_CHECK_TTL) {
        return $cached = (bool)$_SESSION['seed_check']['ok'];
    }

    $toCheck = ($me['role'] === 'SUPER_ADMIN')
        ? array_keys(SEED_CHECK_USERS)
        : (isset(SEED_CHECK_USERS[$me['username']]) ? [$me['username']] : []);

    $details = [];
    foreach ($toCheck as $username) {
        $row = db_one('SELECT password_hash FROM faculty WHERE username = ? LIMIT 1', [$username], 's');
        if (!$row) { continue; } // account removed — not our problem.
        if (password_verify(SEED_CHECK_USERS[$username], (string)$row['password_hash'])) {
            $details[] = $username;   // documented default still works
        }
    }

    $ok = ($details === []);
    $_SESSION['seed_check'] = [
        'at'      => time(),
        'ok'      => $ok,
        'uid'     => $me['id'],
        'details' => $details,
    ];
    return $cached = $ok;
}

/**
 * Returns a dismissable warning string when a documented default password is
 * still active, or an empty string when everything is fine. Layouts render this
 * as a yellow banner.
 */
function seed_check_warning(): string {
    if (empty($_SESSION['seed_check']) || !is_array($_SESSION['seed_check'])) return '';
    if (!empty($_SESSION['seed_check']['ok'])) return '';
    $details = $_SESSION['seed_check']['details'] ?? [];
    if (!$details) return '';

    $me = current_faculty();
    if (!$me) return '';
    $mine = in_array($me['username'], $details, true);

    if ($me['role'] === 'SUPER_ADMIN') {
        $msg = '<strong>Default password still active.</strong> '
            . 'These seeded account(s) still accept their documented default password: '
            . '<code>' . htmlspecialchars(implode(', ', $details), ENT_QUOTES) . '</code>. '
            . 'Anyone who has read the setup notes can sign in as them — change each password now.';
    } elseif ($mine) {
        $msg = '<strong>Your password is still the default.</strong> '
            . 'It is documented publicly, so anyone could sign in as you. Please change it now.';
    } else {
        return '';
    }
    return '<div class="seed-check-warn" role="alert">' . $msg
        . ' <a href="?dismiss_seed_check=1">Dismiss</a></div>';
}

/**
 * Side-effect helper: run the check and, if it flags something, mark the
 * session so the bootstrap output buffer can inject a visible warning into the
 * rendered HTML. Idempotent: only runs once per request.
 */
function seed_check_flash(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    seed_check_run();
}
