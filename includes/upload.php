<?php
/**
 * File upload helper. Validates MIME, size, and renames to a safe filename.
 * Used by student_save.php and notice_save.php.
 */

declare(strict_types=1);

const UPLOAD_BASE = __DIR__ . '/../uploads';

// Whitelist of allowed upload buckets. Anything outside this list is rejected
// to prevent path traversal (e.g., $bucket = 'students/../../etc').
const UPLOAD_BUCKETS = ['students', 'notices', 'achievements', 'documents', 'external', 'committee'];

function upload_dir(string $bucket): string
{
    if (!in_array($bucket, UPLOAD_BUCKETS, true)) {
        throw new InvalidArgumentException("Invalid upload bucket: $bucket");
    }
    $dir = UPLOAD_BASE . '/' . $bucket;
    if (!is_dir($dir)) {
        // Single atomic-ish create: suppress warnings, but verify result.
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create upload directory: $dir");
        }
    }
    return $dir;
}

/**
 * Validate and store a single uploaded image.
 *
 * @param string      $bucket  'students' or 'notices'
 * @param array|null  $file    the $_FILES[…] entry; if null, returns null (no upload)
 * @param int         $max_kb  size limit
 * @param string[]    $allowed_extensions e.g. ['jpg','jpeg','png','webp']
 * @return array{ok:true,path:string,filename:string}|array{ok:false,error:string}
 */
function handle_image_upload(string $bucket, ?array $file, int $max_kb = 2000, array $allowed_extensions = ['jpg','jpeg','png','webp']): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'no_file'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'upload_error_' . (int)$file['error']];
    }
    if ($file['size'] > $max_kb * 1024) {
        return ['ok' => false, 'error' => 'too_large'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_extensions, true)) {
        return ['ok' => false, 'error' => 'bad_extension'];
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return ['ok' => false, 'error' => 'not_an_image'];
    }
    $safe_name = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest_dir  = upload_dir($bucket);
    $dest_path = $dest_dir . '/' . $safe_name;
    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['ok' => false, 'error' => 'move_failed'];
    }
    @chmod($dest_path, 0644);
    return ['ok' => true, 'path' => 'uploads/' . $bucket . '/' . $safe_name, 'filename' => $safe_name];
}

/**
 * Generic document upload (PDF, JPG, PNG).
 */
function handle_generic_document_upload(string $bucket, ?array $file, array $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png'], int $max_kb = 5000): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'no_file'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'upload_error_' . (int)$file['error']];
    }
    if ($file['size'] > $max_kb * 1024) {
        return ['ok' => false, 'error' => 'too_large'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_mimes, true)) {
        return ['ok' => false, 'error' => 'bad_mime'];
    }

    $ext = match($mime) {
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        default           => 'bin',
    };

    $safe_name = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest_dir  = upload_dir($bucket);
    $dest_path = $dest_dir . '/' . $safe_name;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['ok' => false, 'error' => 'move_failed'];
    }
    @chmod($dest_path, 0644);
    return ['ok' => true, 'path' => 'uploads/' . $bucket . '/' . $safe_name, 'filename' => $safe_name];
}

/**
 * PDF upload for notices.
 */
function handle_pdf_upload(string $bucket, ?array $file, int $max_kb = 5000): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'no_file'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'upload_error_' . (int)$file['error']];
    }
    if ($file['size'] > $max_kb * 1024) {
        return ['ok' => false, 'error' => 'too_large'];
    }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        return ['ok' => false, 'error' => 'bad_extension'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== 'application/pdf') {
        return ['ok' => false, 'error' => 'bad_mime'];
    }
    $safe_name = bin2hex(random_bytes(8)) . '.pdf';
    $dest_dir  = upload_dir($bucket);
    $dest_path = $dest_dir . '/' . $safe_name;
    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['ok' => false, 'error' => 'move_failed'];
    }
    @chmod($dest_path, 0644);
    return ['ok' => true, 'path' => 'uploads/' . $bucket . '/' . $safe_name, 'filename' => $safe_name];
}
