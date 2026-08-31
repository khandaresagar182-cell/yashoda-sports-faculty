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

// Only serve from allowed upload buckets
$allowed_buckets = ['documents', 'students', 'achievements', 'notices'];

// Get the requested file path
$requested = $_GET['f'] ?? '';
$requested = ltrim(str_replace('\\', '/', $requested), '/');

if ($requested === '') {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

// Validate: must start with an allowed bucket
$parts = explode('/', $requested);
if (count($parts) < 2 || !in_array($parts[0], $allowed_buckets, true)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

// Access control (S1). `documents` and `students` hold student PII —
// Aadhaar cards, bank passbooks, marksheets, passport photos. Require an
// authenticated faculty session, or the student session that owns the file.
// `notices` and `achievements` stay public: both are linked from the
// public homepage (index.php).
if (in_array($parts[0], ['documents', 'students'], true)) {
    $faculty_id = (int)($_SESSION['faculty_id'] ?? 0);
    $student_id = (int)($_SESSION['student_id'] ?? 0);
    $authorized = false;

    if ($faculty_id > 0) {
        $authorized = true;                       // any signed-in faculty/admin
    } elseif ($student_id > 0) {
        $rel = 'uploads/' . $requested;           // canonical stored form
        $sql = $parts[0] === 'students'
            ? 'SELECT 1 FROM students WHERE id = ? AND photo_path = ?'
            : 'SELECT 1 FROM student_documents WHERE student_id = ? AND file_path = ?';
        $authorized = db_one($sql, [$student_id, $rel], 'is') !== null;
    }

    if (!$authorized) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden.';
        exit;
    }
}

// Prevent path traversal — resolve real paths and verify containment
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
header('Cache-Control: public, max-age=86400');
header('Accept-Ranges: bytes');

readfile($safe_path);
exit;
