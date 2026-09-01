<?php
/**
 * Small, dependency-free helpers used across the app.
 * Single source of truth for: h(), url(), redirect(), flash, format helpers.
 */

declare(strict_types=1);

/**
 * HTML-escape a value for output. ALWAYS wrap dynamic output in this.
 */
function h($s): string
{
    if ($s === null) return '';
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * Cache-busted asset URL.
 *   echo '<link href="' . url('css/admin.css') . '">';
 *
 * Uploaded files (uploads/documents/*, uploads/students/*, etc.) are routed
 * through serve_file.php so that LiteSpeed / ModSecurity on shared hosting
 * (Namecheap) cannot 403-block them.
 */
function url(string $path): string
{
    static $cached = [];
    if (!isset($cached[$path])) {
        $abs = __DIR__ . '/../' . ltrim($path, '/');

        // Get the folder name of this project (e.g., college-sports-faculty)
        $dir_name = basename(dirname(__DIR__));

        // Get script name, e.g. /college-sports-faculty/admin/dashboard.php
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $script_name = str_replace('\\', '/', $script_name);

        // Check if the script path starts with the project directory name as a segment
        $prefix = '';
        if (strpos($script_name, '/' . $dir_name . '/') === 0) {
            $prefix = '/' . $dir_name;
        }

        // For uploaded files, route through serve_file.php for reliable
        // access on LiteSpeed / Namecheap shared hosting (avoids 403).
        $clean = ltrim($path, '/');
        if (preg_match('#^uploads/(documents|students|achievements|notices)/.+#', $clean)) {
            // serve_file.php expects f=documents/xxx.pdf (without "uploads/" prefix)
            $file_param = substr($clean, strlen('uploads/'));
            $cached[$path] = $prefix . '/serve_file.php?f=' . rawurlencode($file_param);
        } else {
            $web_path = $prefix . '/' . $clean;
            // Add cache buster if file exists
            $cached[$path] = $web_path . (is_file($abs) ? '?v=' . filemtime($abs) : '');
        }
    }
    return $cached[$path];
}


/**
 * 302 redirect + exit. Pass an absolute or root-relative path.
 * Always emits a full absolute URL so the browser never mistakes
 * a relative path for a domain name.
 */
function redirect(string $path): never
{
    // Already absolute? Send as-is.
    if (preg_match('~^https?://~i', $path)) {
        header('Location: ' . $path);
        exit;
    }

    // Make it root-relative first
    if ($path[0] !== '/') {
        $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $path = $dir . '/' . ltrim($path, '/');
    }

    // Resolve any "../" segments
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') { array_pop($parts); }
        elseif ($seg !== '' && $seg !== '.') { $parts[] = $seg; }
    }
    $path = '/' . implode('/', $parts);

    // Build full absolute URL
    $forwarded_proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwarded_proto === 'https')
        ? 'https'
        : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    header('Location: ' . $scheme . '://' . $host . $path);
    exit;
}

/* ---------------- flash messages (PRG pattern) ---------------- */

function flash_set(string $key, string $msg, string $level = 'info', array $meta = []): void
{
    $entry = ['msg' => $msg, 'level' => $level];
    if ($meta) $entry['meta'] = $meta;
    $_SESSION['_flash'][$key] = $entry;
}
function flash_get(string $key): ?array
{
    if (!isset($_SESSION['_flash'][$key])) return null;
    $f = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $f;
}
function flash_pull(string $key): string
{
    $f = flash_get($key);
    return $f ? $f['msg'] : '';
}

/* ---------------- formatters ---------------- */

function format_date($d, string $fmt = 'd M Y'): string
{
    if (empty($d) || $d === '0000-00-00') return '';
    $ts = is_numeric($d) ? (int)$d : strtotime((string)$d);
    return $ts ? date($fmt, $ts) : '';
}

/**
 * Academic sessions roll over on June 1.
 * Example: 2026-05-31 => 2025-26, 2026-06-01 => 2026-27.
 */
function current_academic_year(?DateTimeInterface $date = null): string
{
    $date ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $calendarYear = (int)$date->format('Y');
    $startYear = (int)$date->format('n') >= 6 ? $calendarYear : $calendarYear - 1;

    return $startYear . '-' . substr((string)($startYear + 1), -2);
}

function academic_year_options(): array
{
    $current = current_academic_year();
    $currentStart = (int)substr($current, 0, 4);
    $start = $currentStart - 2;
    $opts  = [];
    for ($y = $start; $y <= $start + 5; $y++) {
        $opts[] = $y . '-' . substr((string)($y + 1), -2);
    }
    return $opts;
}

/**
 * Stored value => display label for the Men/Women list-gender picker
 * used by Provisional Player Lists and Final Team Lists. The stored
 * value matches students.gender exactly so it can be used directly in
 * queries; the label follows the "Men/Women" convention already used
 * by the faculty dashboard's Players by Game card.
 */
function gender_list_options(): array
{
    return ['Male' => 'Men', 'Female' => 'Women'];
}

function sport_options(): array
{
    return [
        'Athletics','Badminton','Basketball','Boxing','Carrom','Chess',
        'Cricket','Football','Handball','Hockey','Judo','Kabaddi',
        'Kho Kho','Lawn Tennis','Shooting','Swimming','Table Tennis',
        'Throwball','Volleyball','Weightlifting','Wrestling','Yoga',
    ];
}

function gender_options(): array  { return ['Male','Female','Other']; }
function blood_options(): array  { return ['A+','A-','B+','B-','O+','O-','AB+','AB-']; }
function year_options(): array   { return ['First','Second','Third','Final']; }

/**
 * The five tournament-level participation questions asked in Step 4
 * (Played History). Each level has a `_played` (TINYINT 0/1/NULL) and
 * a `_year` (free text) column on `students`.
 */
function participation_levels(): array
{
    return [
        ['slug' => 'zonal',           'label' => 'Zonal',           'played_col' => 'zonal_played',           'year_col' => 'zonal_year'],
        ['slug' => 'interzonal',      'label' => 'Interzonal',      'played_col' => 'interzonal_played',      'year_col' => 'interzonal_year'],
        ['slug' => 'all_india',       'label' => 'All India',       'played_col' => 'all_india_played',       'year_col' => 'all_india_year'],
        ['slug' => 'west_zone',       'label' => 'West Zone',       'played_col' => 'west_zone_played',       'year_col' => 'west_zone_year'],
        ['slug' => 'krida_mahotsav',  'label' => 'Krida Mahotsav',  'played_col' => 'krida_mahotsav_played',  'year_col' => 'krida_mahotsav_year'],
    ];
}

/**
 * A student's wizard is locked once they've submitted it — every step
 * (and every write endpoint in student_dashboard_process.php) becomes
 * read-only, and student-dashboard.php shows the "Submitted" screen
 * instead of the editable wizard. Faculty re-opens editing for a
 * single student via the "Allow Edit" action on student-profile.php,
 * which sets edit_unlocked = 1; the next successful submit flips it
 * back to 0, re-locking the form. Accepts a row (or partial row) from
 * `students` that includes form_submitted_at and edit_unlocked.
 */
function student_form_locked(array $student): bool
{
    return !empty($student['form_submitted_at']) && (int)($student['edit_unlocked'] ?? 0) === 0;
}

function dept_label(?int $id, ?array $depts = null): string
{
    static $cache = null;
    if ($id === null) return '—';
    if ($cache === null) {
        $cache = [];
        foreach (db_select('SELECT id, name FROM departments') as $r) {
            $cache[(int)$r['id']] = $r['name'];
        }
    }
    return $cache[$id] ?? '—';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach ($parts as $p) if ($p !== '') {
        // mb_* handles Unicode (Devanagari, CJK, etc.) correctly, but the
        // App Platform PHP runtime doesn't always ship with mbstring. Fall
        // back to ASCII substr/strtoupper so the sidebar doesn't break.
        if (function_exists('mb_substr')) {
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
        } else {
            $out .= strtoupper(substr($p, 0, 1));
        }
    }
    if (function_exists('mb_substr')) {
        return mb_substr($out, 0, 2) ?: '?';
    }
    return substr($out, 0, 2) ?: '?';
}

/**
 * Split a stored "full_name" into [first, middle, surname].
 * If the name has only one part, surname = the whole name.
 * If two parts, [first, '', surname].
 * If three or more, the LAST is surname and the first is first_name;
 * everything in between is joined as middle_name.
 */
function split_full_name(?string $full): array
{
    $full = trim((string)$full);
    if ($full === '') return ['first_name' => '', 'middle_name' => '', 'surname' => ''];
    $parts = preg_split('/\s+/', $full);
    if (count($parts) === 1) {
        return ['first_name' => '', 'middle_name' => '', 'surname' => $parts[0]];
    }
    if (count($parts) === 2) {
        return ['first_name' => $parts[0], 'middle_name' => '', 'surname' => $parts[1]];
    }
    $surname  = array_pop($parts);
    $first    = array_shift($parts);
    $middle   = implode(' ', $parts);
    return ['first_name' => $first, 'middle_name' => $middle, 'surname' => $surname];
}

/**
 * Split a stored "Surname First Middle" name into [surname, first, middle].
 * The FIRST part is the surname, the LAST is the middle name, and anything
 * in between is the first name.
 *   "Khandare Sagar Vinod"     -> surname=Khandare, first=Sagar,    middle=Vinod
 *   "Khandare Sagar"           -> surname=Khandare, first=Sagar,    middle=''
 *   "Sagar"                    -> surname=Sagar,    first='',       middle=''
 *   ""                         -> all empty
 */
function split_full_name_sf(?string $full): array
{
    $full = trim((string)$full);
    if ($full === '') return ['surname' => '', 'first_name' => '', 'middle_name' => ''];
    $parts = preg_split('/\s+/', $full);
    if (count($parts) === 1) {
        return ['surname' => $parts[0], 'first_name' => '', 'middle_name' => ''];
    }
    if (count($parts) === 2) {
        return ['surname' => $parts[0], 'first_name' => $parts[1], 'middle_name' => ''];
    }
    $surname = array_shift($parts);
    $middle  = array_pop($parts);
    $first   = implode(' ', $parts);
    return ['surname' => $surname, 'first_name' => $first, 'middle_name' => $middle];
}

/* ---------------- client IP (Cloudflare-aware) ---------------- */

/**
 * Published Cloudflare edge IP ranges (https://www.cloudflare.com/ips/).
 * These change only a few times a year.
 */
function cloudflare_ip_ranges(): array
{
    return [
        // IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
}

/** True if $ip (v4 or v6) falls inside any CIDR in $ranges. */
function ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $bits = (int)$bits;
    $ipBin = @inet_pton($ip);
    $subBin = @inet_pton((string)$subnet);
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
        return false; // v4-vs-v6 mismatch or unparseable
    }
    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = chr(0xff << (8 - $rem) & 0xff);
    return (($ipBin[$bytes] ^ $subBin[$bytes]) & $mask) === "\x00";
}

/**
 * Real client IP.
 *
 * When the request genuinely arrives from a Cloudflare edge IP, trust
 * CF-Connecting-IP (Cloudflare's authoritative visitor IP). Otherwise —
 * including a request straight to the origin that forges the header —
 * fall back to REMOTE_ADDR. Used for login lockout + reset rate limiting,
 * which would otherwise treat every visitor as one shared Cloudflare IP.
 */
function client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === '') {
        return '';
    }
    $viaCloudflare = false;
    foreach (cloudflare_ip_ranges() as $cidr) {
        if (ip_in_cidr($remote, $cidr)) { $viaCloudflare = true; break; }
    }
    if ($viaCloudflare) {
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
            return $cf;
        }
    }
    return $remote;
}
