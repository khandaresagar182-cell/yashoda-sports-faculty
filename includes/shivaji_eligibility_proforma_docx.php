<?php
/**
 * Editable Shivaji University eligibility proforma for pharm_faculty departments.
 */

declare(strict_types=1);

require_once __DIR__ . '/polytechnic_eligibility_docx.php';

function shivaji_docx_dob(?string $dob): string
{
    if ($dob === null || $dob === '' || $dob === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($dob);
    return $timestamp === false ? '' : date('d/m/Y', $timestamp);
}

function shivaji_docx_ordinal(int $n): string
{
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    return $n . (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
}

/** Pull the leading integer out of a "5 Year" style duration string. */
function shivaji_docx_duration_number(?string $duration): ?int
{
    if ($duration === null) return null;
    return preg_match('/(\d+)/', $duration, $m) ? (int)$m[1] : null;
}

/**
 * "Present Class" text. First/Second/Third map directly; "Final" is
 * resolved to an actual ordinal using the student's course duration
 * (e.g. a 5-Year program's Final year renders as "5th Year") since
 * `study_year` itself only has four buckets system-wide.
 */
function shivaji_docx_class(?string $studyYear, ?string $durationYears = null): string
{
    $key = strtolower(trim((string)$studyYear));
    $ordinalMap = ['first' => 1, 'second' => 2, 'third' => 3];
    if (isset($ordinalMap[$key])) {
        return shivaji_docx_ordinal($ordinalMap[$key]) . ' Year';
    }
    if ($key === 'final') {
        $dur = shivaji_docx_duration_number($durationYears);
        return $dur !== null ? shivaji_docx_ordinal($dur) . ' Year' : 'Final Year';
    }
    return trim((string)$studyYear);
}

/** Roman-numeral year, used in the "Present Course" admission sub-column. */
function shivaji_docx_roman(?string $studyYear, ?string $durationYears): string
{
    $roman = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI'];
    $key = strtolower(trim((string)$studyYear));
    $ordinalMap = ['first' => 1, 'second' => 2, 'third' => 3];
    if (isset($ordinalMap[$key])) {
        return $roman[$ordinalMap[$key]] ?? '';
    }
    if ($key === 'final') {
        $dur = shivaji_docx_duration_number($durationYears);
        return $dur !== null ? ($roman[$dur] ?? '') : '';
    }
    return '';
}

/** Heuristic UG/PG split for a free-text Program value. */
function shivaji_docx_is_postgraduate(string $program): bool
{
    $p = strtolower(trim($program));
    if ($p === '') return false;
    if (str_contains($p, 'master') || str_contains($p, 'post grad') || str_contains($p, 'postgrad')) {
        return true;
    }
    foreach (['mba', 'mca', 'm.tech', 'mtech', 'm.e.', 'm.e', 'm.arch', 'march', 'm.pharm', 'mpharm', 'm.sc', 'msc'] as $needle) {
        if (str_starts_with($p, $needle)) return true;
    }
    return false;
}

function shivaji_docx_remarks(array $participant): string
{
    if ((int)($participant['has_gap_year'] ?? 0) !== 1) {
        return '';
    }
    $detail = trim((string)($participant['gap_year_detail'] ?? ''));
    if ($detail === '') return 'Gap';
    return stripos($detail, 'gap') !== false ? $detail : $detail . ' Gap';
}

function shivaji_participating_college(string $departmentCode, string $departmentName): string
{
    return match ($departmentCode) {
        'architecture' => 'Yashoda College of Architecture, Wadhe, Satara',
        'management'   => 'Yashoda College of Management, Satara',
        default => 'Yashoda Technical Campus, ' . $departmentName . ', Satara',
    };
}

function shivaji_docx_text(
    string $text,
    int $size = 22,
    bool $bold = false,
    string $align = 'center'
): string {
    return poly_docx_paragraph(
        poly_docx_run($text, ['bold' => $bold, 'size' => $size, 'font' => 'Times New Roman']),
        ['align' => $align, 'line' => 220]
    );
}

/**
 * @param array{span?:int,vmerge?:string,padding?:int} $options
 */
function shivaji_docx_cell(string $content, int $width, array $options = []): string
{
    $span = max(1, (int)($options['span'] ?? 1));
    $padding = max(0, (int)($options['padding'] ?? 35));
    $vmerge = '';
    if (isset($options['vmerge'])) {
        $vmerge = $options['vmerge'] === 'restart'
            ? '<w:vMerge w:val="restart"/>'
            : '<w:vMerge/>';
    }

    return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>'
        . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '')
        . $vmerge
        . '<w:vAlign w:val="center"/>'
        . '<w:tcMar><w:top w:w="' . $padding . '" w:type="dxa"/>'
        . '<w:left w:w="' . $padding . '" w:type="dxa"/>'
        . '<w:bottom w:w="' . $padding . '" w:type="dxa"/>'
        . '<w:right w:w="' . $padding . '" w:type="dxa"/></w:tcMar>'
        . poly_docx_borders(true)
        . '</w:tcPr>' . $content . '</w:tc>';
}

/**
 * @param array<int,array<int,array{content:string,width:int,span?:int,vmerge?:string,padding?:int}>> $rows
 * @param array<int,int> $gridWidths
 * @param array<int,int> $rowHeights
 */
function shivaji_docx_table(array $rows, array $gridWidths, array $rowHeights): string
{
    $total = array_sum($gridWidths);
    $grid = '';
    foreach ($gridWidths as $width) {
        $grid .= '<w:gridCol w:w="' . $width . '"/>';
    }

    $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . $total . '" w:type="dxa"/>'
        . '<w:jc w:val="center"/><w:tblLayout w:type="fixed"/>'
        . '<w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="0" w:type="dxa"/>'
        . '<w:bottom w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar>'
        . '</w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>';

    foreach ($rows as $rowIndex => $cells) {
        $height = (int)($rowHeights[$rowIndex] ?? 0);
        $xml .= '<w:tr><w:trPr>'
            . ($height > 0 ? '<w:trHeight w:val="' . $height . '" w:hRule="atLeast"/>' : '')
            . '<w:cantSplit/></w:trPr>';
        foreach ($cells as $cell) {
            $options = $cell;
            unset($options['content'], $options['width']);
            $xml .= shivaji_docx_cell($cell['content'], $cell['width'], $options);
        }
        $xml .= '</w:tr>';
    }

    return $xml . '</w:tbl>';
}

function shivaji_docx_seal(): string
{
    return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="20" w:after="0"/></w:pPr>'
        . '<w:r><w:pict><v:oval style="width:54pt;height:54pt" fillcolor="white" strokecolor="black">'
        . '<v:textbox inset="0,0,0,0"><w:txbxContent><w:p><w:pPr>'
        . '<w:jc w:val="center"/><w:spacing w:before="260" w:after="0" w:line="180" w:lineRule="auto"/>'
        . '</w:pPr>' . poly_docx_run("Seal of the\nCollege", ['size' => 16])
        . '</w:p></w:txbxContent></v:textbox></v:oval></w:pict></w:r></w:p>';
}

/**
 * @param array<int,array<string,mixed>> $participants
 */
function shivaji_docx_page(
    string $game,
    string $section,
    string $academicYear,
    string $departmentCode,
    string $departmentName,
    array $participants,
    int $startingNumber
): string {
    // Header university line depends on the department's affiliating university:
    //   management / architecture (pharm_faculty) -> Shivaji University only
    //   engineering / pharmacy / ytc_pharmacy (eng_faculty) -> DBATU Lonere only
    //   everything else (e.g. dpharm) -> keep both, separated by the oblique
    if (in_array($departmentCode, ['management', 'architecture'], true)) {
        $content = poly_docx_paragraph(
            poly_docx_run('Shivaji University, Kolhapur.', ['bold' => true, 'size' => 32]),
            ['align' => 'center', 'after' => 80, 'keep' => true]
        );
    } elseif (in_array($departmentCode, ['engineering', 'pharmacy', 'ytc_pharmacy'], true)) {
        $content = poly_docx_paragraph(
            poly_docx_run('Dr. Babasaheb Ambedkar Technological University, Lonere.', ['bold' => true, 'size' => 32]),
            ['align' => 'center', 'after' => 80, 'keep' => true]
        );
    } else {
        $content = poly_docx_paragraph(
            poly_docx_run('Shivaji University, Kolhapur. /', ['bold' => true, 'size' => 32]),
            ['align' => 'center', 'after' => 40, 'keep' => true]
        );
        $content .= poly_docx_paragraph(
            poly_docx_run('Dr. Babasaheb Ambedkar Technological University, Lonere.', ['bold' => true, 'size' => 24, 'color' => 'C00000']),
            ['align' => 'center', 'after' => 80, 'keep' => true]
        );
    }
    $content .= poly_docx_paragraph(
        poly_docx_run('Eligibility Proforma for Zonal/Inter-Zonal Tournaments', ['bold' => true, 'size' => 26]),
        ['align' => 'center', 'after' => 220, 'keep' => true]
    );

    $content .= poly_docx_table([[
        poly_docx_paragraph(
            poly_docx_run('Name of the Tournament: ', ['bold' => true, 'size' => 21])
            . poly_docx_run($game, ['size' => 21])
            . poly_docx_run('     Section: ', ['bold' => true, 'size' => 21])
            . poly_docx_run($section !== '' ? $section : '____________', ['size' => 21]),
            ['line' => 210]
        ),
        poly_docx_paragraph(
            poly_docx_run('Name of the team manager & Mob No: ', ['bold' => true, 'size' => 21])
            . poly_docx_run('______________________', ['size' => 21]),
            ['align' => 'center', 'line' => 210]
        ),
        poly_docx_paragraph(
            poly_docx_run('His/her Status: ', ['bold' => true, 'size' => 21])
            . poly_docx_run('________________', ['size' => 21]),
            ['align' => 'right', 'line' => 210]
        ),
    ]], [5900, 6600, 3100], ['row_heights' => [0 => 420]]);

    $content .= poly_docx_table([[
        poly_docx_paragraph(
            poly_docx_run('Name of the Organizing College: ', ['bold' => true, 'size' => 21])
            . poly_docx_run('________________________________________', ['size' => 21]),
            ['line' => 210]
        ),
        poly_docx_paragraph(
            poly_docx_run('Name of the Participating College: ', ['bold' => true, 'size' => 21])
            . poly_docx_run(shivaji_participating_college($departmentCode, $departmentName), ['size' => 21]),
            ['align' => 'right', 'line' => 210]
        ),
    ]], [7600, 8000], ['row_heights' => [0 => 420]]);

    $content .= poly_docx_paragraph(
        poly_docx_run(
            'Year A.Y. ' . ($academicYear !== '' ? $academicYear : '________'),
            ['bold' => true, 'size' => 24]
        ),
        ['align' => 'center', 'before' => 100, 'after' => 40]
    );
    // Print date — auto-filled with the day the proforma is generated, in the
    // same header slot as the college's reference format (below the
    // participating-college line, just above the player table).
    $content .= poly_docx_paragraph(
        poly_docx_run('        Date: ' . date('d/m/Y'), ['bold' => true, 'size' => 21]),
        ['align' => 'left', 'before' => 0, 'after' => 140]
    );

    // 21 columns. "Date & Year of First Admission to" is a 3-way split
    // (University/College, Present Course, Present Class) — all YEARS.
    // "Aadhar Number" plus "Mobile No.", "Bank A/C Number" and "IFSC Code"
    // sit just before "Remarks" (all existing widths were trimmed to make room).
    // eng_faculty departments drop the two bank columns (18 = Bank A/C Number,
    // 19 = IFSC Code) from this Final export — the wizard still collects them.
    $widths = [430, 1100, 800, 950, 560, 1040, 560, 640, 760, 800, 700, 620, 620, 620, 560, 560, 980, 850, 1150, 850, 760];
    $hideBankColumns = in_array($departmentCode, ['engineering', 'pharmacy', 'ytc_pharmacy'], true);
    if ($hideBankColumns) {
        $fullWidth = array_sum($widths);
        $factor = $fullWidth / ($fullWidth - $widths[18] - $widths[19]);
        foreach ($widths as $i => $w) {
            if ($i === 18 || $i === 19) continue;
            $widths[$i] = (int) round($w * $factor);
        }
        // absorb rounding drift into Remarks so the kept columns still sum
        // to the original table width
        $keptSum = 0;
        foreach ($widths as $i => $w) {
            if ($i !== 18 && $i !== 19) $keptSum += $w;
        }
        $widths[20] += $fullWidth - $keptSum;
    }
    $keepBank = static fn(int $i): bool => !($hideBankColumns && ($i === 18 || $i === 19));
    $gridWidths = [];
    foreach ($widths as $i => $w) {
        if ($keepBank($i)) $gridWidths[] = $w;
    }
    $header = static fn(string $text): string => shivaji_docx_text($text, 21, true);
    $subheader = static fn(string $text): string => shivaji_docx_text($text, 18);
    $continue = static fn(int $width): array => [
        'content' => poly_docx_paragraph(''),
        'width' => $width,
        'vmerge' => 'continue',
    ];

    $presentClassYear = $academicYear !== '' && preg_match('/^(\d{4})/', $academicYear, $ym)
        ? $ym[1]
        : '';

    $rows = [
        [
            ['content' => $header("Sr.\nNo."), 'width' => $widths[0], 'vmerge' => 'restart'],
            ['content' => $header("Name of the\nPlayer\n(Beginning\nSurname)"), 'width' => $widths[1], 'vmerge' => 'restart'],
            ['content' => $header("Mother's\nName"), 'width' => $widths[2], 'vmerge' => 'restart'],
            ['content' => $header("University\nP.R.N. no."), 'width' => $widths[3], 'vmerge' => 'restart'],
            ['content' => $header("Roll\nNo."), 'width' => $widths[4], 'vmerge' => 'restart'],
            ['content' => $header("Date of\nBirth"), 'width' => $widths[5], 'vmerge' => 'restart'],
            [
                'content' => $header("Date & Year\nof Passing\nH.S.C.\nExamination"),
                'width' => $widths[6] + $widths[7],
                'span' => 2,
            ],
            ['content' => $header("Present\nClass"), 'width' => $widths[8], 'vmerge' => 'restart'],
            ['content' => $header("Name of\nthe\nPresent\nCourse"), 'width' => $widths[9], 'vmerge' => 'restart'],
            ['content' => $header("Duration of\nCourse"), 'width' => $widths[10], 'vmerge' => 'restart'],
            [
                'content' => $header("Date & Year of First\nAdmission to"),
                'width' => $widths[11] + $widths[12] + $widths[13],
                'span' => 3,
            ],
            [
                'content' => $header("Number of Year\nof Previous\nParticipation\nwhile pursuing"),
                'width' => $widths[14] + $widths[15],
                'span' => 2,
            ],
            ['content' => $header("Aadhar\nNumber"), 'width' => $widths[16], 'vmerge' => 'restart'],
            ['content' => $header("Mobile\nNo."), 'width' => $widths[17], 'vmerge' => 'restart'],
            ...($hideBankColumns ? [] : [
                ['content' => $header("Bank A/C\nNumber"), 'width' => $widths[18], 'vmerge' => 'restart'],
                ['content' => $header("IFSC\nCode"), 'width' => $widths[19], 'vmerge' => 'restart'],
            ]),
            ['content' => $header('Remarks'), 'width' => $widths[20], 'vmerge' => 'restart'],
        ],
        [
            $continue($widths[0]),
            $continue($widths[1]),
            $continue($widths[2]),
            $continue($widths[3]),
            $continue($widths[4]),
            $continue($widths[5]),
            ['content' => $subheader("Name of\nExam"), 'width' => $widths[6]],
            ['content' => $subheader("Date &\nYear"), 'width' => $widths[7]],
            $continue($widths[8]),
            $continue($widths[9]),
            $continue($widths[10]),
            ['content' => $subheader("University\n/College"), 'width' => $widths[11]],
            ['content' => $subheader("Present\nCourse"), 'width' => $widths[12]],
            ['content' => $subheader("Present\nClass"), 'width' => $widths[13]],
            ['content' => $subheader("Graduate\nCourse"), 'width' => $widths[14]],
            ['content' => $subheader("P.G.\nCourse"), 'width' => $widths[15]],
            $continue($widths[16]),
            $continue($widths[17]),
            ...($hideBankColumns ? [] : [$continue($widths[18]), $continue($widths[19])]),
            $continue($widths[20]),
        ],
    ];

    for ($slot = 0; $slot < 7; $slot++) {
        $participant = $participants[$slot] ?? [];
        $program = trim((string)($participant['program'] ?? ''));
        $duration = trim((string)($participant['course_duration_years'] ?? ''));
        $studyYear = $participant['study_year'] ?? null;
        $isPG = $program !== '' && shivaji_docx_is_postgraduate($program);
        $prevYears = $participant === [] ? '' : implode(', ', (array)($participant['prev_years'] ?? []));
        // "Date & Year of First Admission to" — three YEAR sub-columns the
        // student fills explicitly in Step 2 of the wizard
        // (first_admission_university_year / _course_year / _class_year).
        // Legacy rows saved before v42 fall back to the old derivation:
        // University/College + Present Course from `admission_year`, Present
        // Class from the start year of the current academic year.
        $admissionYear = trim((string)($participant['admission_year'] ?? ''));
        $faUniversity  = trim((string)($participant['first_admission_university_year'] ?? '')) ?: $admissionYear;
        $faCourse      = trim((string)($participant['first_admission_course_year'] ?? '')) ?: $admissionYear;
        $faClass       = trim((string)($participant['first_admission_class_year'] ?? '')) ?: $presentClassYear;

        $values = $participant === [] ? array_fill(0, 21, '') : [
            (string)($startingNumber + $slot) . '.',
            trim((string)($participant['full_name'] ?? '')),
            trim((string)($participant['mother_name'] ?? '')),
            trim((string)($participant['enrollment_no'] ?? '')),
            trim((string)($participant['roll_no'] ?? '')),
            shivaji_docx_dob($participant['dob'] ?? null),
            $program !== '' ? 'HSC' : '',
            trim((string)($participant['hsc_passing_year'] ?? '')),
            shivaji_docx_class($studyYear, $duration),
            $program,
            $duration,
            $faUniversity,
            $faCourse,
            $faClass,
            !$isPG ? $prevYears : '',
            $isPG ? $prevYears : '',
            trim((string)($participant['aadhar_number'] ?? '')),
            trim((string)($participant['mobile'] ?? '')),
            trim((string)($participant['bank_account_number'] ?? '')),
            strtoupper(trim((string)($participant['bank_ifsc'] ?? ''))),
            shivaji_docx_remarks($participant),
        ];

        $row = [];
        foreach ($values as $index => $value) {
            if (!$keepBank($index)) continue;
            $row[] = [
                'content' => shivaji_docx_text($value, 21),
                'width' => $widths[$index],
                'padding' => 35,
            ];
        }
        $rows[] = $row;
    }

    $content .= shivaji_docx_table(
        $rows,
        $gridWidths,
        [0 => 1500, 1 => 760, 2 => 720, 3 => 720, 4 => 720, 5 => 720, 6 => 720, 7 => 720, 8 => 720]
    );

    $content .= poly_docx_paragraph(
        poly_docx_run('Certified that the above particulars are true as per records of the College', ['size' => 21]),
        ['before' => 150, 'after' => 30]
    );
    $content .= poly_docx_paragraph(
        poly_docx_run('Certified the above players are not employed on full time basis.', ['size' => 21]),
        ['after' => 120]
    );

    $content .= poly_docx_table([[
        poly_docx_paragraph(
            poly_docx_run('Date: ______________', ['bold' => true, 'size' => 21]),
            ['align' => 'left']
        ) . shivaji_docx_seal(),
        poly_docx_paragraph(
            poly_docx_run('Director of Physical Education', ['bold' => true, 'size' => 21])
            . poly_docx_run("\n\nSignature of the Director of Physical Education", ['size' => 21]),
            ['align' => 'center']
        ),
        poly_docx_paragraph(
            poly_docx_run('Principal', ['bold' => true, 'size' => 21])
            . poly_docx_run("\n\nSignature of the Principal", ['size' => 21]),
            ['align' => 'center']
        ),
    ]], [4400, 6500, 4700], ['row_heights' => [0 => 900]]);

    return $content;
}

/**
 * @param array<int,array<string,mixed>> $participants
 */
function build_shivaji_eligibility_proforma_docx(
    string $game,
    string $section,
    string $academicYear,
    string $departmentCode,
    string $departmentName,
    array $participants
): string {
    $pages = array_chunk($participants, 7);
    if ($pages === []) {
        $pages = [[]];
    }

    $body = '';
    foreach ($pages as $pageIndex => $pageRows) {
        if ($pageIndex > 0) {
            $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        }
        $body .= shivaji_docx_page(
            $game,
            $section,
            $academicYear,
            $departmentCode,
            $departmentName,
            $pageRows,
            ($pageIndex * 7) + 1
        );
    }

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:v="urn:schemas-microsoft-com:vml"><w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
        . '<w:pgMar w:top="300" w:right="420" w:bottom="300" w:left="420" w:header="0" w:footer="0" w:gutter="0"/>'
        . '<w:cols w:space="720"/><w:docGrid w:linePitch="360"/></w:sectPr>'
        . '</w:body></w:document>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $zip = new SimpleDocxZip();
    $zip->add('[Content_Types].xml', $contentTypes);
    $zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>');
    $zip->add('word/document.xml', $document);
    $zip->add('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/>'
        . '<w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="0"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/>'
        . '<w:qFormat/><w:pPr><w:spacing w:after="0"/></w:pPr></w:style></w:styles>');
    $zip->add('word/settings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:zoom w:percent="100"/><w:doNotTrackMoves/><w:doNotTrackFormatting/>'
        . '<w:compat><w:compatSetting w:name="compatibilityMode"'
        . ' w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>');
    $zip->add('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $zip->add('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>Shivaji University Eligibility Proforma - ' . poly_docx_xml($game) . '</dc:title>'
        . '<dc:creator>Sports Portal</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>');
    $zip->add('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
        . '<Application>Sports Portal</Application><AppVersion>1.0</AppVersion></Properties>');

    return $zip->finish();
}
