<?php
/**
 * External Entries — link validation, WhatsApp-style message composition,
 * and the verify/resume token plumbing shared by admin/external_entries.php,
 * external-entry.php, external_entry_verify.php and external_entry_process.php.
 *
 * Token scheme mirrors register_process.php / email_verify.php exactly:
 * bin2hex(random_bytes(32)) in the link/email, sha256 hash stored in the
 * DB, 24h expiry for verification+resume tokens. Nothing here is a
 * password — identity in this flow is "possession of a live session
 * created by verifying an email or clicking a resume link" (see
 * external_student_login() in includes/auth.php).
 */

declare(strict_types=1);

function new_token_pair(): array
{
    $token = bin2hex(random_bytes(32));
    return [$token, hash('sha256', $token)];
}

function is_valid_token_format(string $token): bool
{
    return (bool)preg_match('/^[0-9a-f]{64}$/i', $token);
}

/* ---------------- links ---------------- */

/**
 * Short, shareable invite-code style token for external-entry links —
 * "k3n9-x7pq-2mzr" instead of a raw 64-char hex string. This is the link
 * a faculty pastes into WhatsApp, so it should read like a real product's
 * join link (think Google Meet / Zoom), not a hash. 12 lowercase
 * alphanumeric characters (~62 bits of entropy) is far more than enough
 * to resist guessing over a link's whole shared lifetime (days to weeks,
 * shared with a known, bounded group of students) — this is not a
 * password, and cryptographic-strength length isn't the goal here.
 */
function new_link_token_pair(): array
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $chars = '';
    for ($i = 0; $i < 12; $i++) {
        $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $token = substr($chars, 0, 4) . '-' . substr($chars, 4, 4) . '-' . substr($chars, 8, 4);
    return [$token, hash('sha256', $token)];
}

function is_valid_link_token_format(string $token): bool
{
    // New links: "xxxx-xxxx-xxxx". Old links (generated before this
    // change) used the original 64-hex format — accept both so links
    // already shared with students keep working.
    return (bool)preg_match('/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/', $token)
        || (bool)preg_match('/^[0-9a-f]{64}$/i', $token);
}

function external_link_by_token(string $token): ?array
{
    if (!is_valid_link_token_format($token)) return null;
    return db_one(
        'SELECT * FROM external_entry_links WHERE token_hash = ? LIMIT 1',
        [hash('sha256', $token)], 's'
    );
}

function external_link_by_id(int $id): ?array
{
    return db_one('SELECT * FROM external_entry_links WHERE id = ? LIMIT 1', [$id], 'i');
}

function external_link_is_open(array $link): bool
{
    if (!empty($link['revoked_at'])) return false;
    return strtotime((string)$link['expires_at']) >= time();
}

/**
 * The faculty-facing message preview AND the final text sent (with the
 * real link appended) both go through this — same template, either a
 * placeholder or the real URL passed in as $link.
 */
function compose_external_whatsapp_message(string $gameLevel, string $representingTeam, string $expiresAt, string $link): string
{
    $deadline = format_date($expiresAt, 'd M Y');
    return "Dear Student,\n\n"
         . "You are hereby informed that you have been provisionally selected in the *{$gameLevel}* tournament, "
         . "representing *{$representingTeam}*.\n\n"
         . "You are requested to fill your details for confirmation using the link below, before *{$deadline}*.\n\n"
         . $link;
}

/* ---------------- pending verification (pre-row staging) ---------------- */

/**
 * Drop staged Step-1 rows whose verification token has expired. A row holds
 * name / DOB / Aadhaar / mobile / address and the raw link token, so an
 * unconsumed one must not live forever. Consumption and re-staging already
 * delete their own row; this catches the "never clicked" rest.
 */
function external_purge_expired_pending(): void
{
    db_execute('DELETE FROM external_pending_verifications WHERE expires_at < NOW()');
}

/**
 * Stage Step-1 personal fields behind a fresh token. Nothing is written to
 * `external_students` here — see the file docblock. Re-staging the same
 * (link, email) pair before it's verified replaces the old pending row,
 * same "resend" behavior as pending_registrations (migration v47).
 *
 * `$personalFields['_link_token']` is the raw (shareable) link token — the
 * link table only keeps its hash, so external_entry_verify.php needs it here
 * to redirect back into the wizard. It is a join link, not a credential, and
 * the row is swept once expired (external_purge_expired_pending()).
 *
 * @return array{0:string,1:string} [$rawToken, $expiresAtSql]
 */
function stage_external_pending(int $linkId, string $email, array $personalFields): array
{
    external_purge_expired_pending();
    [$token, $hash] = new_token_pair();
    $expires = date('Y-m-d H:i:s', time() + 86400);
    db_execute('DELETE FROM external_pending_verifications WHERE link_id = ? AND email = ?', [$linkId, $email], 'is');
    db_insert(
        'INSERT INTO external_pending_verifications (link_id, email, staged_data, token_hash, expires_at) VALUES (?,?,?,?,?)',
        [$linkId, $email, json_encode($personalFields, JSON_UNESCAPED_UNICODE), $hash, $expires],
        'issss'
    );
    return [$token, $expires];
}

function external_pending_by_token(string $token): ?array
{
    if (!is_valid_token_format($token)) return null;
    return db_one(
        'SELECT * FROM external_pending_verifications WHERE token_hash = ? LIMIT 1',
        [hash('sha256', $token)], 's'
    );
}

/* ---------------- resume (magic link when the session/cookie is gone) ---------------- */

function issue_external_resume_token(int $externalStudentId): string
{
    [$token, $hash] = new_token_pair();
    $expires = date('Y-m-d H:i:s', time() + 86400);
    db_execute(
        'UPDATE external_students SET resume_token_hash = ?, resume_token_expires_at = ? WHERE id = ?',
        [$hash, $expires, $externalStudentId], 'ssi'
    );
    return $token;
}

function external_student_by_resume_token(string $token): ?array
{
    if (!is_valid_token_format($token)) return null;
    $row = db_one(
        'SELECT * FROM external_students WHERE resume_token_hash = ? LIMIT 1',
        [hash('sha256', $token)], 's'
    );
    if (!$row || strtotime((string)$row['resume_token_expires_at']) < time()) return null;
    return $row;
}

function clear_external_resume_token(int $externalStudentId): void
{
    db_execute(
        'UPDATE external_students SET resume_token_hash = NULL, resume_token_expires_at = NULL WHERE id = ?',
        [$externalStudentId], 'i'
    );
}

function external_student_by_link_email(int $linkId, string $email): ?array
{
    return db_one('SELECT * FROM external_students WHERE link_id = ? AND email = ? LIMIT 1', [$linkId, $email], 'is');
}

function external_student_by_id(int $id): ?array
{
    return db_one('SELECT * FROM external_students WHERE id = ? LIMIT 1', [$id], 'i');
}

/* ---------------- outbound-mail throttle (public, unauthenticated actions) ---------------- */

const EXTERNAL_MAIL_MIN_GAP_SECONDS    = 60;  // between two mails to one address on one link
const EXTERNAL_MAIL_PER_ADDRESS_HOURLY = 5;   // per (link, address)
const EXTERNAL_MAIL_PER_IP_HOURLY      = 40;  // per client IP — generous on purpose: a class often shares one campus IP

/**
 * Rate-limit the two public actions that make this server send email
 * (`verify_start`, `resume_request`). Without it anyone holding a link can
 * mail-bomb an address, burn the shared host's mail quota (which also blocks
 * password-reset mail) or keep overwriting a student's live resume token.
 *
 * Storage reuses the `login_attempts` table so there is nothing new to apply
 * by hand in phpMyAdmin. Rows are namespaced `extmail:` in `username` and
 * written with success=1, so the login-lockout query (success=0) can never
 * see them. Both counters key off a hash in `username`, NOT the `ip` column:
 * record_login_attempt() binds that column with the wrong type today (see
 * REFACTOR-AUDIT P7-29), so it cannot be relied on for lookups.
 *
 * Call once per request, after cheap validation and before any mail is sent.
 * Returns null when the request may proceed (it is then recorded), otherwise
 * a static, user-facing reason.
 */
function external_mail_throttle(int $linkId, string $email): ?string
{
    $remote = client_ip();
    if ($remote === '') {
        return 'Please try again in a moment.';   // fail closed, like is_locked_out()
    }
    $addrKey = 'extmail:a:' . substr(hash('sha256', $linkId . '|' . strtolower($email)), 0, 32);
    $ipKey   = 'extmail:i:' . substr(hash('sha256', $remote), 0, 32);

    // Housekeeping: rows are only meaningful for an hour; keep a day.
    if (random_int(1, 40) === 1) {
        db_execute("DELETE FROM login_attempts WHERE username LIKE 'extmail:%' AND attempted_at < (NOW() - INTERVAL 1 DAY)");
    }

    $addr = db_one(
        'SELECT COUNT(*) AS n, MIN(TIMESTAMPDIFF(SECOND, attempted_at, NOW())) AS age
           FROM login_attempts
          WHERE username = ? AND attempted_at > (NOW() - INTERVAL 1 HOUR)',
        [$addrKey], 's'
    );
    $addrCount = (int)($addr['n'] ?? 0);
    if ($addrCount > 0 && $addr['age'] !== null && (int)$addr['age'] < EXTERNAL_MAIL_MIN_GAP_SECONDS) {
        return 'Please wait a minute before asking for another email.';
    }
    if ($addrCount >= EXTERNAL_MAIL_PER_ADDRESS_HOURLY) {
        return 'Too many emails have been requested for this address. Please try again in an hour.';
    }

    $byIp = db_one(
        'SELECT COUNT(*) AS n FROM login_attempts WHERE username = ? AND attempted_at > (NOW() - INTERVAL 1 HOUR)',
        [$ipKey], 's'
    );
    if ((int)($byIp['n'] ?? 0) >= EXTERNAL_MAIL_PER_IP_HOURLY) {
        return 'Too many requests from your network. Please try again later.';
    }

    // `ip` is informational only (NOT NULL VARBINARY); UNHEX keeps the raw
    // address bytes intact without relying on a blob/int bind type.
    $ipHex = bin2hex((string)(@inet_pton($remote) ?: ''));
    foreach ([$addrKey, $ipKey] as $key) {
        db_insert(
            'INSERT INTO login_attempts (username, ip, user_agent, success) VALUES (?, UNHEX(?), ?, 1)',
            [$key, $ipHex, 'external-entry mail'], 'sss'
        );
    }
    return null;
}

/* ---------------- shared field validators ---------------- */
/*
 * One rule set for the public wizard (external_entry_process.php) and the
 * faculty add-student form (admin/external_entries.php). They had drifted:
 * the admin path was strict, the public path let through over-long or
 * malformed values, which MariaDB (STRICT_ALL_TABLES, see db.php) turns into
 * an uncaught exception → HTTP 500 on a JSON endpoint. Limits mirror
 * sql/migration-v51 / v52. Lengths are counted in characters (mb_strlen),
 * matching VARCHAR, so Devanagari names are not penalised.
 *
 * Every message is static text — the wizard renders errors via innerHTML,
 * so nothing user-supplied may be echoed back in them.
 */

function external_valid_year(string $v): bool
{
    return (bool)preg_match('/^[0-9]{4}$/', $v);
}

/** Strict Y-m-d (what the wizard's date picker submits), real calendar date, not in the future. */
function external_valid_date(string $v): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    $errs = DateTime::getLastErrors();
    if ($d === false || ($errs && ($errs['warning_count'] > 0 || $errs['error_count'] > 0))) {
        return false;
    }
    return $d->format('Y-m-d') === $v
        && $d <= new DateTime('today')
        && (int)$d->format('Y') >= 1950;
}

/**
 * Personal fields. $in keys: surname, first_name, middle_name, email, mother_name, dob, gender,
 * aadhar_number, mobile, whatsapp_no, permanent_address, current_address (all pre-trimmed strings).
 * $opts: 'email' => validate $in['email'] (default false); 'mobile_required' => bool (default false).
 *
 * @return string[]
 */
function external_validate_personal(array $in, array $opts = []): array
{
    $g = static fn(string $k): string => (string)($in[$k] ?? '');
    $len = static fn(string $s): int => mb_strlen($s, 'UTF-8');
    $e = [];

    $surname = $g('surname'); $first = $g('first_name'); $middle = $g('middle_name');
    if ($surname === '' || $len($surname) > 60) $e[] = 'Surname is required (max 60 characters).';
    if ($first === ''   || $len($first) > 60)   $e[] = 'First name is required (max 60 characters).';
    if ($len($middle) > 60)                     $e[] = 'Middle name is too long (max 60 characters).';
    $full = trim(implode(' ', array_filter([$surname, $first, $middle])));
    if ($len($full) < 2 || $len($full) > 160)   $e[] = 'Full name must be 2–160 characters.';

    if (!empty($opts['email'])) {
        $email = $g('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) $e[] = 'Enter a valid email address.';
    }
    if ($len($g('mother_name')) > 160) $e[] = "Mother's name is too long (max 160 characters).";

    $mobile = $g('mobile');
    if ($mobile === '' ? !empty($opts['mobile_required']) : !preg_match('/^[0-9]{10}$/', $mobile)) {
        $e[] = 'Mobile number must be exactly 10 digits.';
    }
    if ($g('whatsapp_no') !== '' && !preg_match('/^[0-9]{10}$/', $g('whatsapp_no'))) {
        $e[] = 'WhatsApp number must be exactly 10 digits.';
    }
    if ($g('aadhar_number') !== '' && !preg_match('/^[0-9]{12}$/', $g('aadhar_number'))) {
        $e[] = 'Aadhar number must be exactly 12 digits.';
    }
    if ($g('gender') !== '' && !in_array($g('gender'), ['Male', 'Female', 'Other'], true)) {
        $e[] = 'Select a valid gender.';
    }
    if ($g('dob') !== '' && !external_valid_date($g('dob'))) {
        $e[] = 'Enter a valid date of birth.';
    }
    if ($len($g('permanent_address')) > 500) $e[] = 'Permanent address is too long (max 500 characters).';
    if ($len($g('current_address')) > 500)   $e[] = 'Current address is too long (max 500 characters).';
    return $e;
}

/**
 * Academic fields (Step 2). $in keys: college_name, program, study_year, course_duration_years,
 * admission_year, ssc_passing_year, hsc_passing_year, diploma_passing_year,
 * first_admission_university_year, first_admission_course_year, first_admission_class_year,
 * has_gap_year ('' | '0' | '1'), gap_year_detail.
 *
 * @return string[]
 */
function external_validate_academic(array $in): array
{
    $g = static fn(string $k): string => (string)($in[$k] ?? '');
    $len = static fn(string $s): int => mb_strlen($s, 'UTF-8');
    $e = [];

    if ($g('college_name') === '' || $len($g('college_name')) > 200) $e[] = "Enter the student's college name (max 200 characters).";
    if ($len($g('program')) > 120) $e[] = 'Program is too long (max 120 characters).';
    if ($g('study_year') !== '' && !in_array($g('study_year'), ['First', 'Second', 'Third', 'Final'], true)) {
        $e[] = 'Select a valid year of study.';
    }
    if ($len($g('course_duration_years')) > 4) $e[] = 'Course duration is too long (max 4 characters).';

    $years = [
        'Admission year'                     => 'admission_year',
        'SSC passing year'                   => 'ssc_passing_year',
        'HSC passing year'                   => 'hsc_passing_year',
        'Diploma passing year'               => 'diploma_passing_year',
        'First admission (University) year'  => 'first_admission_university_year',
        'First admission (Present Course) year' => 'first_admission_course_year',
        'First admission (Present Class) year'  => 'first_admission_class_year',
    ];
    foreach ($years as $label => $key) {
        if ($g($key) !== '' && !external_valid_year($g($key))) $e[] = "Enter a valid 4-digit $label.";
    }
    if ($g('has_gap_year') !== '' && !in_array($g('has_gap_year'), ['0', '1'], true)) {
        $e[] = 'Select Yes or No for Gap / Year Drop.';
    }
    if ($len($g('gap_year_detail')) > 100) $e[] = 'Gap year detail is too long (max 100 characters).';
    return $e;
}

/**
 * "Played before" years (Step 3): each free-text year cell is VARCHAR(100).
 *
 * @return string[]
 */
function external_validate_played(array $in): array
{
    foreach (['zonal', 'interzonal', 'all_india', 'west_zone', 'krida_mahotsav'] as $k) {
        if (mb_strlen((string)($in[$k . '_year'] ?? ''), 'UTF-8') > 100) {
            return ['A "years played" entry is too long (max 100 characters).'];
        }
    }
    return [];
}

/**
 * Jersey / kit fields (Step 5) — same whitelists as the internal wizard
 * (student_dashboard_process.php handle_jersey_save).
 *
 * @return string[]
 */
function external_validate_jersey(array $in): array
{
    $g = static fn(string $k): string => (string)($in[$k] ?? '');
    $e = [];
    if ($g('jersey_number') !== '' && !preg_match('/^[A-Za-z0-9]{1,10}$/', $g('jersey_number'))) {
        $e[] = 'Jersey number can only contain letters and digits (max 10 characters).';
    }
    if ($g('jersey_size') !== '' && !array_key_exists($g('jersey_size'), jersey_size_options())) {
        $e[] = 'Please choose a valid jersey size.';
    }
    if ($g('shorts_size') !== '' && !array_key_exists($g('shorts_size'), shorts_size_options())) {
        $e[] = 'Please choose a valid shorts size.';
    }
    if ($g('track_size') !== '' && !array_key_exists($g('track_size'), shorts_size_options())) {
        $e[] = 'Please choose a valid track pant size.';
    }
    return $e;
}
