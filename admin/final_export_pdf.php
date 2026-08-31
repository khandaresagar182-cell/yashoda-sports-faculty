<?php
/**
 * Export the final-team eligibility form in the college's physical format.
 *
 * Final-roster students are printed into the physical form. Unused participant
 * rows remain blank so more names can still be entered by hand.
 *
 * Every department except Polytechnic uses the Shivaji University
 * "Eligibility Proforma for Zonal/Inter-Zonal Tournaments" (exact table
 * format supplied by the college). Polytechnic keeps its own separate
 * eligibility form.
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

$tcpdf_path = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdf_path)) {
    http_response_code(500);
    exit('TCPDF library not found.');
}

require_once $tcpdf_path;
require_once __DIR__ . '/../includes/eligibility_form_pdf.php';
require_once __DIR__ . '/../includes/shivaji_eligibility_proforma_pdf.php';

$me = current_faculty();
[$scope, $params, $types] = scope_sql_department('s');
// Hide wizard drafts (students who registered but haven't hit Final Submit).
$visible = faculty_visible_student_filter('s');
$scope  .= $visible[0];
$params  = array_merge($params, $visible[1]);
$types  .= $visible[2];

$rows = db_select(
    "SELECT s.id, ft.roll_no,
            s.full_name, s.mother_name, s.enrollment_no, s.dob, s.aadhar_number,
            s.study_year, s.program,
            s.course_duration_years, s.admission_year, s.academic_year,
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

$deptCode = (string)($me['department_code'] ?? $rows[0]['dept_code'] ?? '');
$deptName = (string)($me['department_name'] ?? $rows[0]['dept_name'] ?? '');
$isPolytechnic = $deptCode === 'polytechnic';

// Shivaji proforma: batch-load each student's OTHER final_teams academic
// years for this same game, for the "Previous Participation" columns.
if (!$isPolytechnic && $rows) {
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

$playersPerPage = $isPolytechnic ? 16 : 7;
$pages = array_chunk($rows, $playersPerPage);
if ($pages === []) {
    $pages = [[]];
}

$pdf = new TCPDF($isPolytechnic ? 'P' : 'L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Sports Portal');
$pdf->SetAuthor('Yashoda Technical Campus');
$pdf->SetTitle('Eligibility Form - ' . $game);
$pdf->SetMargins(0, 0, 0);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(0);
$pdf->SetAutoPageBreak(false);
$pdf->SetPrintHeader(false);
$pdf->SetPrintFooter(false);

foreach ($pages as $pageIndex => $pageRows) {
    $pdf->AddPage();
    if ($isPolytechnic) {
        draw_eligibility_form($pdf, [
            'game'          => $game,
            'event'         => $event,
            'academic_year' => $ay,
            'logo_path'     => __DIR__ . '/../images/ytc-logo.png',
            'participants'  => $pageRows,
            'row_count'     => count($pageRows),
        ]);
    } else {
        // "Section" on the Shivaji proforma is the gender section
        // (Men's / Women's), not the event label.
        $sectionLabel = gender_list_options()[$gender] ?? '';
        draw_shivaji_eligibility_proforma($pdf, [
            'game'            => $game,
            'section'         => $sectionLabel !== '' ? $sectionLabel . "'s" : '',
            'academic_year'   => $ay,
            'department_code' => $deptCode,
            'department_name' => $deptName,
            'participants'    => $pageRows,
            'starting_number' => ($pageIndex * $playersPerPage) + 1,
        ]);
    }
}

$safe_game = preg_replace('/[^A-Za-z0-9_-]+/', '_', $game);
$genderLabel = gender_list_options()[$gender] ?? '';
$genderSuffix = $genderLabel !== '' ? '_' . $genderLabel : '';
$prefix = $isPolytechnic ? 'Eligibility_Form_' : 'Eligibility_Proforma_';
$pdf->Output($prefix . trim((string)$safe_game, '_') . $genderSuffix . '.pdf', 'D');
