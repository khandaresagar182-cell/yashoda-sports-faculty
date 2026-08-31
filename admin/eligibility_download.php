<?php
/**
 * Authenticated download of one archived eligibility Word file.
 *
 * The archive folder (uploads/eligibility_archive/) carries a deny-all
 * .htaccess and is NOT in serve_file.php's bucket list, so this is the
 * only route to the bytes. Access is scoped to the caller's department.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/eligibility_archive.php';

require_login();
require_department();

$deptId = effective_department_id();
$id     = (int)($_GET['id'] ?? 0);

$row = eligibility_archive_row($id, $deptId);
if ($row === null) {
    http_response_code(404);
    exit('File not found.');
}

$abs = eligibility_archive_abs_path($row);
if ($abs === null) {
    http_response_code(404);
    exit('File not found.');
}

$downloadName = (string)($row['file_name'] ?? 'eligibility.docx');
$downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName) ?: 'eligibility.docx';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

readfile($abs);
exit;
