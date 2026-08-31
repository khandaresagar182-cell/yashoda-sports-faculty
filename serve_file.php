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
