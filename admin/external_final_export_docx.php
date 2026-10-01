<?php
/**
 * Editable Word export for one external-entry link's final team.
 * Mirrors admin/final_export_docx.php exactly (same builders, same
 * `$rows` shape) but sources rows from external_team_entries +
 * external_students instead of final_teams + students, and archives
 * with is_external = true (see includes/eligibility_archive.php).
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

$me = current_faculty();
$departmentCode = (string)($me['department_code'] ?? '');

$rows = db_select(
    "SELECT es.id, ete.roll_no,
            es.full_name, es.mother_name, '' AS enrollment_no, es.dob, es.aadhar_number,
            es.study_year, es.program, es.course_duration_years,
            es.admission_year, es.academic_year,
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

if ($departmentCode === '') {
    $departmentCode = (string)($rows[0]['dept_code'] ?? '');
}
$isPolytechnic = $departmentCode === 'polytechnic';
// External Entries only: D.Pharm externals get the DBATU format too (same
// DBATU-Lonere affiliation as engineering/pharmacy/ytc_pharmacy), unlike
// the internal path which shows both university names for dpharm — see
// shivaji_docx_page() in includes/shivaji_eligibility_proforma_docx.php.
// Only the format SELECTION is remapped; archive storage below still
// records the student's real department code.
$proformaDeptCode = $departmentCode === 'dpharm' ? 'engineering' : $departmentCode;
$game  = (string)$link['game_name'];
$event = (string)$link['game_level'];
$ay    = (string)$link['academic_year'];
$gender = (string)$link['gender'];

// External entries have no historical final_teams rows to cross-reference,
// so "Previous Participation" is simply empty for every row.
foreach ($rows as &$r) {
    $r['prev_years'] = [];
}
unset($r);

if ($isPolytechnic) {
    require_once __DIR__ . '/../includes/polytechnic_eligibility_docx.php';
    $docx = build_polytechnic_eligibility_docx($game, $event, $ay, $rows, __DIR__ . '/../images/ytc-logo.png');
} else {
    require_once __DIR__ . '/../includes/shivaji_eligibility_proforma_docx.php';
    $sectionLabel = gender_list_options()[$gender] ?? '';
    $docx = build_shivaji_eligibility_proforma_docx(
        $game,
        $sectionLabel !== '' ? $sectionLabel . "'s" : '',
        $ay,
        $proformaDeptCode,
        (string)($me['department_name'] ?? $rows[0]['dept_name'] ?? strtoupper($departmentCode)),
        $rows
    );
}

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
    (int)($me['id'] ?? 0),
    true // is_external
);

$safeGame = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $game), '_');
$genderLabel = gender_list_options()[$gender] ?? '';
$safeGenderSuffix = $genderLabel !== '' ? '_' . $genderLabel : '';
if ($isPolytechnic) {
    $prefix = 'External_Eligibility_Form_';
} elseif (in_array($proformaDeptCode, ['engineering', 'pharmacy', 'ytc_pharmacy'], true)) {
    $prefix = 'External_DBATU_Eligibility_Proforma_';
} else {
    $prefix = 'External_Shivaji_Eligibility_Proforma_';
}
$filename = $prefix . ($safeGame !== '' ? $safeGame : 'Team') . $safeGenderSuffix . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($docx));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: public');
echo $docx;
