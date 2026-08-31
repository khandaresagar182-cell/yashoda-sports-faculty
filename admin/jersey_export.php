<?php
/**
 * Jersey Kit — Export approved orders as CSV.
 *
 * GET params: game, event, ay
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_department();

$me     = current_faculty();
$game   = trim((string)($_GET['game'] ?? ''));
$event  = trim((string)($_GET['event'] ?? ''));
$ay     = trim((string)($_GET['ay'] ?? ''));
$gender = trim((string)($_GET['gender'] ?? ''));
if (!array_key_exists($gender, gender_list_options())) $gender = '';

if ($game === '' || $event === '' || $gender === '') {
    flash_set('jersey_error', 'Gender, game, and event are required.', 'error');
    redirect('jersey_manage.php');
}

$ay_val = $ay === '' ? null : $ay;
$dept_id = jersey_forms_has_department_id()
    ? jersey_department_for_team($game, $event, $ay_val, $gender)
    : null;
[$dept_filter, $dept_params, $dept_types] = jersey_form_department_filter($dept_id);
[$gender_filter, $gender_params, $gender_types] = jersey_form_gender_filter($gender);

// Load the form
$form = db_one(
    "SELECT id FROM jersey_forms
      WHERE game_name = ? AND event_label = ? AND academic_year <=> ? $dept_filter $gender_filter",
    array_merge([$game, $event, $ay_val], $dept_params, $gender_params),
    'sss' . $dept_types . $gender_types
);

if (!$form) {
    flash_set('jersey_error', 'No jersey form found for this team.', 'error');
    redirect('final_list.php');
}

// Load approved requests with student info
$rows = db_select(
    "SELECT jr.enrollment_no, s.full_name, jr.mobile, jr.tshirt_size,
            jr.jersey_name,
            COALESCE(jr.final_number, jr.preferred_number) AS jersey_number,
            jr.status
       FROM jersey_requests jr
       JOIN students s ON s.id = jr.student_id
      WHERE jr.jersey_form_id = ?
        AND jr.status = 'Approved'
        AND s.form_submitted_at IS NOT NULL
      ORDER BY COALESCE(jr.final_number, jr.preferred_number) ASC",
    [(int)$form['id']], 'i'
);

if (empty($rows)) {
    flash_set('jersey_error', 'No approved jersey requests to export.', 'error');
    redirect('jersey_manage.php?' . http_build_query(array_filter([
        'game' => $game, 'event' => $event, 'ay' => $ay, 'gender' => $gender, 'dept' => $dept_id,
    ], static fn($value) => $value !== null && $value !== '')));
}

// Generate CSV
$genderLabel = gender_list_options()[$gender] ?? '';
$filename = 'Jersey_Order_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $game)
          . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $event)
          . ($genderLabel !== '' ? '_' . $genderLabel : '')
          . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$fp = fopen('php://output', 'w');

// BOM for Excel UTF-8 compatibility
fwrite($fp, "\xEF\xBB\xBF");

// Header row
fputcsv($fp, ['#', 'Student Name', 'Enrollment No.', 'Mobile', 'T-Shirt Size', 'Jersey Name', 'Jersey Number', 'Status']);

$i = 1;
foreach ($rows as $r) {
    fputcsv($fp, [
        $i++,
        $r['full_name'],
        $r['enrollment_no'],
        $r['mobile'],
        $r['tshirt_size'],
        $r['jersey_name'],
        $r['jersey_number'],
        $r['status'],
    ]);
}

fclose($fp);
exit;
