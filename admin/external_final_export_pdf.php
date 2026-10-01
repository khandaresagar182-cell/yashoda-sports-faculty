<?php
/**
 * PDF export (college's physical format) for one external-entry link's
 * final team. Mirrors admin/final_export_pdf.php but sources rows from
 * external_team_entries + external_students, and archives with
 * is_external = true.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_department();

$linkId = (int)($_GET['link'] ?? 0);
$link   = $linkId > 0 ? external_link_by_id($linkId) : null;
$deptId = effective_department_id();

if (!$link || $deptId === null || (int)$link['department_id'] !== $deptId) {
    http_response_code(400);
    exit('Invalid link.');
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
$deptCode = (string)($me['department_code'] ?? '');

$rows = db_select(
    "SELECT es.id, ete.roll_no,
            es.full_name, es.mother_name, '' AS enrollment_no, es.dob, es.aadhar_number,
            es.study_year, es.program, es.course_duration_years, es.admission_year, es.academic_year,
            es.first_admission_university_year, es.first_admission_course_year, es.first_admission_class_year,
            es.hsc_passing_year, es.diploma_passing_year, es.has_gap_year, es.gap_year_detail,
            es.mobile, es.bank_account_number, es.bank_ifsc, es.photo_path,
            d.code AS dept_code, d.name AS dept_name
       FROM external_team_entries ete
       JOIN external_students es ON es.id = ete.external_student_id
       JOIN departments d        ON d.id = es.department_id
      WHERE es.link_id = ?
      ORDER BY ete.created_at ASC, es.full_name ASC",
    [$linkId], 'i'
);

if ($deptCode === '') {
    $deptCode = (string)($rows[0]['dept_code'] ?? '');
}
$deptName = (string)($me['department_name'] ?? $rows[0]['dept_name'] ?? '');
$isPolytechnic = $deptCode === 'polytechnic';
// External Entries only: D.Pharm externals get the DBATU format too (same
// DBATU-Lonere affiliation as engineering/pharmacy/ytc_pharmacy) — see the
// matching remap in external_final_export_docx.php.
$proformaDeptCode = $deptCode === 'dpharm' ? 'engineering' : $deptCode;
$game  = (string)$link['game_name'];
$event = (string)$link['game_level'];
$ay    = (string)$link['academic_year'];
$gender = (string)$link['gender'];

foreach ($rows as &$r) {
    $r['prev_years'] = [];
}
unset($r);

$playersPerPage = $isPolytechnic ? 16 : SHIVAJI_ELIGIBILITY_ROWS_PER_PAGE;
$pages = array_chunk($rows, $playersPerPage);
if ($pages === []) {
    $pages = [[]];
}

$pdf = new TCPDF($isPolytechnic ? 'P' : 'L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Sports Portal');
$pdf->SetAuthor('Yashoda Technical Campus');
$pdf->SetTitle('External Eligibility Form - ' . $game);
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
            'game' => $game, 'event' => $event, 'academic_year' => $ay,
            'logo_path' => __DIR__ . '/../images/ytc-logo.png',
            'participants' => $pageRows, 'row_count' => count($pageRows),
            'starting_number' => ($pageIndex * $playersPerPage) + 1,
            'include_certification' => $pageIndex === count($pages) - 1,
        ]);
    } else {
        $sectionLabel = gender_list_options()[$gender] ?? '';
        draw_shivaji_eligibility_proforma($pdf, [
            'game' => $game, 'section' => $sectionLabel !== '' ? $sectionLabel . "'s" : '',
            'academic_year' => $ay, 'department_code' => $proformaDeptCode, 'department_name' => $deptName,
            'participants' => $pageRows, 'starting_number' => ($pageIndex * $playersPerPage) + 1,
            'include_certification' => $pageIndex === count($pages) - 1,
        ]);
    }
}

$safe_game = preg_replace('/[^A-Za-z0-9_-]+/', '_', $game);
$genderLabel = gender_list_options()[$gender] ?? '';
$genderSuffix = $genderLabel !== '' ? '_' . $genderLabel : '';
if ($isPolytechnic) {
    $prefix = 'External_Eligibility_Form_';
} elseif (in_array($proformaDeptCode, ['engineering', 'pharmacy', 'ytc_pharmacy'], true)) {
    $prefix = 'External_DBATU_Eligibility_Proforma_';
} else {
    $prefix = 'External_Shivaji_Eligibility_Proforma_';
}
$pdf->Output($prefix . trim((string)$safe_game, '_') . $genderSuffix . '.pdf', 'D');
