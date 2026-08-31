<?php
/**
 * Editable Word export for supported final-team formats.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_department();

$game   = trim((string)($_GET['game'] ?? ''));
$event  = trim((string)($_GET['event'] ?? ''));
$ay     = trim((string)($_GET['ay'] ?? ''));
$gender = trim((string)($_GET['gender'] ?? ''));
if (!array_key_exists($gender, gender_list_options())) $gender = '';

if ($game === '' || $event === '') {
    http_response_code(400);
    exit('Game and event label are required.');
}

$me = current_faculty();
$departmentCode = (string)($me['department_code'] ?? '');

[$scope, $params, $types] = scope_sql_department('s');
// Hide wizard drafts (students who registered but haven't hit Final Submit).
$visible = faculty_visible_student_filter('s');
$scope  .= $visible[0];
$params  = array_merge($params, $visible[1]);
$types  .= $visible[2];
$rows = db_select(
    "SELECT s.id, ft.roll_no,
            s.full_name, s.mother_name, s.enrollment_no, s.dob, s.aadhar_number,
            s.study_year, s.program, s.course_duration_years,
            s.admission_year, s.academic_year,
            s.first_admission_university_year, s.first_admission_course_year, s.first_admission_class_year,
            s.hsc_passing_year, s.has_gap_year, s.gap_year_detail,
            s.mobile, s.bank_account_number, s.bank_ifsc, s.photo_path,
            d.code AS dept_code, d.name AS dept_name
       FROM final_teams ft
       JOIN students s    ON s.id = ft.student_id
       JOIN departments d ON d.id = s.department_id
      WHERE ft.game_name = ?
        AND ft.event_label = ?
        AND ft.academic_year <=> ?
        AND ft.gender <=> ?
        $scope
      ORDER BY ft.created_at ASC, s.enrollment_no ASC",
    array_merge([$game, $event, $ay === '' ? null : $ay, $gender === '' ? null : $gender], $params),
    'ssss' . $types
);

if ($departmentCode === '') {
    $departmentCode = (string)($rows[0]['dept_code'] ?? '');
}
$isPolytechnic = $departmentCode === 'polytechnic';

if ($isPolytechnic) {
    require_once __DIR__ . '/../includes/polytechnic_eligibility_docx.php';
    $docx = build_polytechnic_eligibility_docx(
        $game,
        $event,
        $ay,
        $rows,
        __DIR__ . '/../images/ytc-logo.png'
    );
} else {
    // Batch-load each student's OTHER final_teams academic years for this
    // same game, for the "Previous Participation" columns.
    if ($rows) {
        $studentIds = array_values(array_unique(array_map(static fn($r) => (int)$r['id'], $rows)));
        $ph = implode(',', array_fill(0, count($studentIds), '?'));
        $prevRows = db_select(
            "SELECT DISTINCT student_id, academic_year
               FROM final_teams
              WHERE game_name = ?
                AND student_id IN ($ph)
                AND academic_year IS NOT NULL
                AND NOT (academic_year <=> ?)
              ORDER BY student_id, academic_year",
            array_merge([$game], $studentIds, [$ay === '' ? null : $ay]),
            's' . str_repeat('i', count($studentIds)) . 's'
        );
        $prevByStudent = [];
        foreach ($prevRows as $pr) {
            $prevByStudent[(int)$pr['student_id']][] = $pr['academic_year'];
        }
        foreach ($rows as &$r) {
            $r['prev_years'] = $prevByStudent[(int)$r['id']] ?? [];
        }
        unset($r);
    }

    require_once __DIR__ . '/../includes/shivaji_eligibility_proforma_docx.php';
    // "Section" on the Shivaji proforma is the gender section (Men's / Women's),
    // not the event label.
    $sectionLabel = gender_list_options()[$gender] ?? '';
    $docx = build_shivaji_eligibility_proforma_docx(
        $game,
        $sectionLabel !== '' ? $sectionLabel . "'s" : '',
        $ay,
        $departmentCode,
        (string)($me['department_name'] ?? $rows[0]['dept_name'] ?? strtoupper($departmentCode)),
        $rows
    );
}

// Best-effort server-side backup: keep a copy of every generated Word form
// in the per-department, per-academic-year eligibility archive. Never blocks
// or breaks the download (see includes/eligibility_archive.php).
require_once __DIR__ . '/../includes/eligibility_archive.php';
$archiveDept = db_one('SELECT id FROM departments WHERE code = ?', [$departmentCode], 's');
eligibility_archive_store(
    (int)($archiveDept['id'] ?? 0),
    $departmentCode,
    $ay,
    $game,
    $gender,
    $event,
    $docx,
    count($rows),
    (int)($me['id'] ?? 0)
);

$safeGame = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $game), '_');
$genderLabel = gender_list_options()[$gender] ?? '';
$safeGenderSuffix = $genderLabel !== '' ? '_' . $genderLabel : '';
if ($isPolytechnic) {
    $prefix = 'Eligibility_Form_';
} elseif (in_array($departmentCode, ['engineering', 'pharmacy', 'ytc_pharmacy'], true)) {
    // eng_faculty departments are affiliated to DBATU Lonere, not Shivaji University.
    $prefix = 'DBATU_Eligibility_Proforma_';
} else {
    $prefix = 'Shivaji_Eligibility_Proforma_';
}
$filename = $prefix . ($safeGame !== '' ? $safeGame : 'Team') . $safeGenderSuffix . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($docx));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: public');
echo $docx;
