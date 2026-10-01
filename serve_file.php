<?php
/**
 * serve_file.php — Reliable file server for uploaded documents.
 *
 * Serves files from the uploads/ directory through PHP to avoid
 * Apache/LiteSpeed/ModSecurity permission issues on shared hosting
 * (Namecheap, GoDaddy, etc.).
 *
 * The root .htaccess rewrites requests like:
 *   /uploads/documents/abc123.pdf  →  serve_file.php?f=documents/abc123.pdf
 * so that existing links keep working without code changes.
 */

declare(strict_types=1);

// bootstrap gives us the session + db_one() for the access-control check
// below. serve_file.php's request URI is not a "staff area" path, so
// bootstrap's seed-check output buffering / sidebar injection stay dormant.
require_once __DIR__ . '/includes/bootstrap.php';

// Only serve from allowed upload buckets. eligibility_archive/ (Word forms
// with Aadhaar / bank / mobile columns) is deliberately NOT listed: it is
// downloaded through admin/eligibility_download.php, never through here.
$allowed_buckets = ['documents', 'students', 'achievements', 'notices', 'external', 'committee'];

// Get the requested file path
$requested = $_GET['f'] ?? '';
$requested = ltrim(str_replace('\\', '/', $requested), '/');

if ($requested === '' || strpos($requested, "\0") !== false) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

// Cheap lexical pre-check: must at least *start* with an allowed bucket.
// This is only an early exit for junk — it is NOT the authorisation decision
// (see the resolved-path check below).
$parts = explode('/', $requested);
if (count($parts) < 2 || !in_array($parts[0], $allowed_buckets, true)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

// Resolve the real path BEFORE deciding anything else, and take the bucket
// from where the file actually lives. The request is untrusted: a URL such as
//   ?f=notices/../documents/abc.pdf
// starts with the public `notices` bucket but resolves into the gated
// `documents` one. Authorising on the first URL segment let an anonymous
// caller read PII that way, so every decision below uses the RESOLVED path.
$uploads_dir = realpath(__DIR__ . '/uploads');
if ($uploads_dir === false) {
    http_response_code(500);
    echo 'Server configuration error.';
    exit;
}

$safe_path = realpath(__DIR__ . '/uploads/' . $requested);

if ($safe_path === false || strpos($safe_path, $uploads_dir . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$resolved = str_replace('\\', '/', substr($safe_path, strlen($uploads_dir) + 1)); // e.g. documents/abc.pdf
$rparts   = explode('/', $resolved);
$bucket   = $rparts[0];
if (count($rparts) < 2 || !in_array($bucket, $allowed_buckets, true)) {
    // Resolved outside every served bucket (e.g. into eligibility_archive/).
    http_response_code(404);
    echo 'File not found.';
    exit;
}

// Access control (S1). `documents`, `students` and `external` hold personal
// data — Aadhaar cards, bank passbooks, marksheets, passport photos.
//   documents / students : any signed-in faculty/admin, or the student session
//                          that owns the file.
//   external             : SUPER_ADMIN, a faculty assigned to the file's
//                          department, or the external-entry session that
//                          owns the file (so the wizard can show a student
//                          their own upload). Faculty are department-scoped
//                          here because external submissions are always
//                          reviewed per department.
// `notices`, `achievements` and `committee` stay public: all three are
// linked from the public homepage (index.php).
// Idle-timeout-aware helpers (current_*) are used rather than a bare
// $_SESSION key check, so a session past SESSION_IDLE_TIMEOUT is refused.
$gated_buckets = ['documents', 'students', 'external'];
$is_gated      = in_array($bucket, $gated_buckets, true);
if ($is_gated) {
    $rel        = 'uploads/' . $resolved;         // canonical stored form
    $authorized = false;

    $faculty = current_faculty();
    if ($faculty) {
        if ($bucket !== 'external' || $faculty['role'] === 'SUPER_ADMIN') {
            $authorized = true;                   // any signed-in faculty/admin
        } else {
            // Which department owns this external file? Photo first, then documents.
            $owner = db_one('SELECT department_id FROM external_students WHERE photo_path = ?', [$rel], 's')
                  ?? db_one(
                        'SELECT es.department_id
                           FROM external_student_documents d
                           JOIN external_students es ON es.id = d.external_student_id
                          WHERE d.file_path = ?',
                        [$rel], 's'
                    );
            if ($owner) {
                $authorized = db_one(
                    'SELECT 1 FROM faculty_departments WHERE faculty_id = ? AND department_id = ?',
                    [$faculty['id'], (int)$owner['department_id']], 'ii'
                ) !== null;
            }
        }
    }

    if (!$authorized && $bucket !== 'external') {
        $student = current_student();
        if ($student) {
            $sql = $bucket === 'students'
                ? 'SELECT 1 FROM students WHERE id = ? AND photo_path = ?'
                : 'SELECT 1 FROM student_documents WHERE student_id = ? AND file_path = ?';
            $authorized = db_one($sql, [$student['id'], $rel], 'is') !== null;
        }
    }

    if (!$authorized && $bucket === 'external') {
        $extSession = current_external_student();
        if ($extSession) {
            $authorized = db_one('SELECT 1 FROM external_students WHERE id = ? AND photo_path = ?', [$extSession['id'], $rel], 'is') !== null
                       || db_one('SELECT 1 FROM external_student_documents WHERE external_student_id = ? AND file_path = ?', [$extSession['id'], $rel], 'is') !== null;
        }
    }

    if (!$authorized) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden.';
        exit;
    }
}

if (!is_file($safe_path) || !is_readable($safe_path)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

// Determine MIME type from extension
$ext = strtolower(pathinfo($safe_path, PATHINFO_EXTENSION));

// Security: never serve PHP or executable files
$blocked_extensions = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'sh', 'bat', 'exe', 'com'];
if (in_array($ext, $blocked_extensions, true)) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

$mime_types = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

$mime = $mime_types[$ext] ?? 'application/octet-stream';

// Clean any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Serve the file
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($safe_path));
header('Content-Disposition: inline; filename="' . basename($safe_path) . '"');
header('X-Content-Type-Options: nosniff');
// Gated files are personal data: keep them out of shared/proxy caches.
header('Cache-Control: ' . ($is_gated ? 'private, max-age=3600' : 'public, max-age=86400'));
header('Accept-Ranges: bytes');

readfile($safe_path);
exit;
