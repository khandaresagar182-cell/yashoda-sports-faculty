<?php
/**
 * Returns the logged-in student's own document uploads + requirements.
 * Student-self only — the session's student_id is the only allowed target.
 *
 * Returns JSON:
 * [
 *   { req_id, document_name, is_required, file_path, uploaded_at }
 * ]
 * `file_path` is null if not yet uploaded.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$meId = (int)($_SESSION['student_id'] ?? 0);
if ($meId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

$rows = db_select(
    'SELECT dr.id        AS req_id,
            dr.document_name,
            dr.is_required,
            sd.file_path,
            sd.uploaded_at
       FROM dept_document_requirements dr
       LEFT JOIN student_documents sd
              ON sd.requirement_id = dr.id
             AND sd.student_id    = ?
      WHERE dr.department_id = (SELECT department_id FROM students WHERE id = ?)
      ORDER BY dr.id',
    [$meId, $meId], 'ii'
);

// The Gap certificate is only mandatory for students who answered "Yes"
// to "Gap / Year Drop?" in Step 2 — everyone else sees it as optional.
$student = db_one('SELECT has_gap_year FROM students WHERE id = ?', [$meId], 'i');
$hasGapYear = (int)($student['has_gap_year'] ?? 0) === 1;
foreach ($rows as &$row) {
    if (stripos((string)$row['document_name'], 'gap certificate') === 0) {
        $row['is_required'] = $hasGapYear ? 1 : 0;
    }
}
unset($row);

echo json_encode($rows);
