<?php
/**
 * Excel export of the final-team eligibility roster.
 *
 * Sits beside final_export_pdf.php / final_export_docx.php and takes the same
 * GET params (game, event, ay, gender). Produces a SpreadsheetML-2003 XML
 * workbook (.xls) — opens in Excel / LibreOffice / Google Sheets, no library
 * needed (same technique as admin/export_xlsx.php).
 *
 * The column layout mirrors the Word proforma for the faculty's department:
 *   - Polytechnic  -> the 9-column Polytechnic eligibility layout
 *   - everyone else -> the Shivaji / DBATU "Eligibility Proforma" columns
 *     (the two Bank columns are dropped for eng_faculty departments, exactly
 *      as in the DOCX/PDF builders).
 *
 * Unlike the Word export this does NOT write to the eligibility archive —
 * that archive is intentionally Word-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_department();

// Pure transform helpers reused from the DOCX builders (no side effects on include).
require_once __DIR__ . '/../includes/polytechnic_eligibility_docx.php';
require_once __DIR__ . '/../includes/shivaji_eligibility_proforma_docx.php';

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
            s.hsc_passing_year, s.diploma_passing_year, s.has_gap_year, s.gap_year_detail,
            s.mobile, s.bank_account_number, s.bank_ifsc,
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
$isPolytechnic  = $deptCode === 'polytechnic';
$hideBankColumns = in_array($deptCode, ['engineering', 'pharmacy', 'ytc_pharmacy'], true);

// Shivaji proforma: batch-load each student's OTHER final_teams academic years
// for this same game, for the "Previous Participation" columns.
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

/* ---------------- SpreadsheetML helpers ---------------- */

function xlsx_escape($s): string
{
    if ($s === null) return '';
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_cell($value, string $type = 'String', ?string $styleId = null): string
{
    $s = $styleId !== null ? ' ss:StyleID="' . $styleId . '"' : '';
    return "<Cell$s><Data ss:Type=\"$type\">" . xlsx_escape($value) . "</Data></Cell>";
}

/* ---------------- column definitions ---------------- */

$genderLabel  = gender_list_options()[$gender] ?? '';
$presentClassYear = ($ay !== '' && preg_match('/^(\d{4})/', $ay, $ym)) ? $ym[1] : '';

if ($isPolytechnic) {
    $columns = [
        'Sr. No.', 'Name of Participant', 'Year & Course', 'Roll No.',
        'Enrollment Number', 'Date of Birth', 'Mobile No.',
        'Bank A/C Number', 'IFSC Code',
    ];
    $rowFn = static function (array $r, int $sr): array {
        return [
            [$sr, 'Number'],
            [trim((string)($r['full_name'] ?? '')), 'String'],
            [poly_docx_year_course($r['study_year'] ?? null, $r['program'] ?? null), 'String'],
            [trim((string)($r['roll_no'] ?? '')), 'String'],
            [trim((string)($r['enrollment_no'] ?? '')), 'String'],
            [poly_docx_dob($r['dob'] ?? null), 'String'],
            [trim((string)($r['mobile'] ?? '')), 'String'],
            [trim((string)($r['bank_account_number'] ?? '')), 'String'],
            [strtoupper(trim((string)($r['bank_ifsc'] ?? ''))), 'String'],
        ];
    };
    $widths = [50, 200, 120, 80, 150, 100, 120, 150, 110];
} else {
    $columns = [
        'Sr. No.', 'Name of the Player', "Mother's Name", 'University P.R.N. No.',
        'Roll No.', 'Date of Birth', 'Name of Exam', 'Date & Year',
        'Present Class', 'Name of Present Course', 'Duration of Course',
        'First Admission — University/College', 'First Admission — Present Course',
        'First Admission — Present Class',
        'Prev. Participation — Graduate Course', 'Prev. Participation — P.G. Course',
        'Aadhar Number', 'Mobile No.',
    ];
    if (!$hideBankColumns) {
        $columns[] = 'Bank A/C Number';
        $columns[] = 'IFSC Code';
    }
    $columns[] = 'Remarks';

    $rowFn = static function (array $r, int $sr) use ($hideBankColumns, $presentClassYear): array {
        $program  = trim((string)($r['program'] ?? ''));
        $duration = trim((string)($r['course_duration_years'] ?? ''));
        $isPG     = $program !== '' && shivaji_docx_is_postgraduate($program);
        $prevYears = implode(', ', (array)($r['prev_years'] ?? []));
        $admissionYear = trim((string)($r['admission_year'] ?? ''));
        $faUniversity  = trim((string)($r['first_admission_university_year'] ?? '')) ?: $admissionYear;
        $faCourse      = trim((string)($r['first_admission_course_year'] ?? '')) ?: $admissionYear;
        $faClass       = trim((string)($r['first_admission_class_year'] ?? '')) ?: $presentClassYear;
        $exam          = $program !== '' ? shivaji_docx_qualifying_exam($r) : ['label' => '', 'year' => ''];

        $cells = [
            [$sr, 'Number'],
            [trim((string)($r['full_name'] ?? '')), 'String'],
            [trim((string)($r['mother_name'] ?? '')), 'String'],
            [trim((string)($r['enrollment_no'] ?? '')), 'String'],
            [trim((string)($r['roll_no'] ?? '')), 'String'],
            [shivaji_docx_dob($r['dob'] ?? null), 'String'],
            [$exam['label'], 'String'],
            [$exam['year'], 'String'],
            [shivaji_docx_class($r['study_year'] ?? null, $duration), 'String'],
            [$program, 'String'],
            [$duration, 'String'],
            [$faUniversity, 'String'],
            [$faCourse, 'String'],
            [$faClass, 'String'],
            [!$isPG ? $prevYears : '', 'String'],
            [$isPG ? $prevYears : '', 'String'],
            [trim((string)($r['aadhar_number'] ?? '')), 'String'],
            [trim((string)($r['mobile'] ?? '')), 'String'],
        ];
        if (!$hideBankColumns) {
            $cells[] = [trim((string)($r['bank_account_number'] ?? '')), 'String'];
            $cells[] = [strtoupper(trim((string)($r['bank_ifsc'] ?? ''))), 'String'];
        }
        $cells[] = [shivaji_docx_remarks($r), 'String'];
        return $cells;
    };
    $widths = array_fill(0, count($columns), 130);
    $widths[0] = 50;   // Sr
    $widths[1] = 190;  // Name
    $widths[9] = 190;  // Present course
    $widths[count($columns) - 1] = 160; // Remarks
}

/* ---------------- build workbook ---------------- */

$college = db_one('SELECT name FROM college_settings WHERE id = 1') ?? ['name' => 'Yashoda Technical Campus'];

$styles = '
<Styles>
  <Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14"/><Alignment ss:Horizontal="Left" ss:Vertical="Center"/></Style>
  <Style ss:ID="Label"><Font ss:Bold="1"/><Alignment ss:Horizontal="Right" ss:Vertical="Top"/></Style>
  <Style ss:ID="Header">
    <Font ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#1A365D" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>
  <Style ss:ID="Data">
    <Alignment ss:Vertical="Top" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="0.5"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="0.5"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="0.5"/>
    </Borders>
  </Style>
</Styles>';

$headerRows = [
    ['College',        (string)$college['name']],
    ['Faculty',        $deptName !== '' ? $deptName : strtoupper($deptCode)],
    ['Game',           $game],
    ['Section',        $genderLabel !== '' ? $genderLabel : '—'],
    ['Event',          $event],
    ['Academic Year',  $ay !== '' ? $ay : '—'],
    ['Generated On',   date('d M Y, H:i')],
    ['Generated By',   (string)($me['full_name'] ?? '')],
    ['Players',        (string)count($rows)],
];
$headerBlock = '';
foreach ($headerRows as $hr) {
    $headerBlock .= '<Row>' . xlsx_cell($hr[0], 'String', 'Label') . xlsx_cell($hr[1], 'String') . '</Row>';
}
$headerBlock .= '<Row></Row>';

$colHeaderRow = '<Row ss:Height="34">';
foreach ($columns as $c) {
    $colHeaderRow .= xlsx_cell($c, 'String', 'Header');
}
$colHeaderRow .= '</Row>';

$dataRows = '';
$sr = 1;
foreach ($rows as $r) {
    $dataRows .= '<Row>';
    foreach ($rowFn($r, $sr) as [$val, $type]) {
        $dataRows .= xlsx_cell($val, $type, 'Data');
    }
    $dataRows .= '</Row>';
    $sr++;
}

$colsXml = '';
foreach ($widths as $i => $w) {
    $colsXml .= '<Column ss:Index="' . ($i + 1) . '" ss:Width="' . (int)$w . '"/>';
}

$xml = '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
' . $styles . '
<Worksheet ss:Name="Eligibility">
<Table>
' . $colsXml . $headerBlock . $colHeaderRow . $dataRows . '
</Table>
</Worksheet>
</Workbook>';

/* ---------------- send ---------------- */

$safeGame = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $game), '_');
$safeAy   = preg_replace('/[^0-9-]/', '', $ay);
$genderSuffix = $genderLabel !== '' ? '_' . $genderLabel : '';
$filename = 'Eligibility_' . ($safeGame !== '' ? $safeGame : 'Team') . $genderSuffix
    . ($safeAy !== '' ? '_' . $safeAy : '') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo "\xEF\xBB\xBF" . $xml;
