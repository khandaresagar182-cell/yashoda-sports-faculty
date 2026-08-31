<?php
/**
 * Shivaji University, Kolhapur — "Eligibility Proforma for Zonal/Inter-Zonal
 * Tournaments" — PDF version of the exact table format used on the physical
 * college form (see includes/shivaji_eligibility_proforma_docx.php for the
 * editable Word version this mirrors).
 *
 * Used by admin/final_export_pdf.php for every department except
 * Polytechnic, which keeps its own separate eligibility form.
 */

declare(strict_types=1);

function shivaji_pdf_dob(?string $dob): string
{
    if ($dob === null || $dob === '' || $dob === '0000-00-00') {
        return '';
    }
    $timestamp = strtotime($dob);
    return $timestamp === false ? '' : date('d/m/Y', $timestamp);
}

function shivaji_pdf_ordinal(int $n): string
{
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    return $n . (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
}

/** Pull the leading integer out of a "5 Year" style duration string. */
function shivaji_pdf_duration_number(?string $duration): ?int
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
function shivaji_pdf_class(?string $studyYear, ?string $durationYears): string
{
    $key = strtolower(trim((string)$studyYear));
    $ordinalMap = ['first' => 1, 'second' => 2, 'third' => 3];
    if (isset($ordinalMap[$key])) {
        return shivaji_pdf_ordinal($ordinalMap[$key]) . ' Year';
    }
    if ($key === 'final') {
        $dur = shivaji_pdf_duration_number($durationYears);
        return $dur !== null ? shivaji_pdf_ordinal($dur) . ' Year' : 'Final Year';
    }
    return trim((string)$studyYear);
}

/** Roman-numeral year, used in the "Present Course" admission sub-column. */
function shivaji_pdf_roman(?string $studyYear, ?string $durationYears): string
{
    $roman = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI'];
    $key = strtolower(trim((string)$studyYear));
    $ordinalMap = ['first' => 1, 'second' => 2, 'third' => 3];
    if (isset($ordinalMap[$key])) {
        return $roman[$ordinalMap[$key]] ?? '';
    }
    if ($key === 'final') {
        $dur = shivaji_pdf_duration_number($durationYears);
        return $dur !== null ? ($roman[$dur] ?? '') : '';
    }
    return '';
}

/** Heuristic UG/PG split for a free-text Program value. */
function shivaji_pdf_is_postgraduate(string $program): bool
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

function shivaji_pdf_remarks(array $participant): string
{
    if ((int)($participant['has_gap_year'] ?? 0) !== 1) {
        return '';
    }
    $detail = trim((string)($participant['gap_year_detail'] ?? ''));
    if ($detail === '') return 'Gap';
    return stripos($detail, 'gap') !== false ? $detail : $detail . ' Gap';
}

function shivaji_pdf_participating_college(string $departmentCode, string $departmentName): string
{
    return match ($departmentCode) {
        'architecture' => 'Yashoda College of Architecture, Wadhe, Satara',
        'management'   => 'Yashoda College of Management, Satara',
        default        => 'Yashoda Technical Campus, ' . $departmentName . ', Satara',
    };
}

function shivaji_pdf_cell(
    TCPDF $pdf,
    float $x,
    float $y,
    float $width,
    float $height,
    string $text,
    float $fontSize = 7.5,
    bool $bold = false,
    string $align = 'C'
): void {
    $pdf->Rect($x, $y, $width, $height);
    $pdf->SetFont('times', $bold ? 'B' : '', $fontSize);
    $pdf->MultiCell($width, 3.2, $text, 0, $align, false, 0, $x, $y, true, 0, false, true, $height, 'M');
}

/**
 * @param TCPDF $pdf
 * @param array<string,mixed> $data {
 *     game: string, section: string, academic_year: string,
 *     department_code: string, department_name: string,
 *     participants: array<int,array<string,mixed>>, starting_number: int
 * }
 */
function draw_shivaji_eligibility_proforma(TCPDF $pdf, array $data): void
{
    $game            = trim((string)($data['game'] ?? ''));
    $section         = trim((string)($data['section'] ?? ''));
    $academicYear    = trim((string)($data['academic_year'] ?? ''));
    $departmentCode  = (string)($data['department_code'] ?? '');
    $departmentName  = (string)($data['department_name'] ?? '');
    $participants    = array_slice((array)($data['participants'] ?? []), 0, 7);
    $startingNumber  = max(1, (int)($data['starting_number'] ?? 1));

    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);

    // Header university line depends on the department's affiliating university:
    //   management / architecture (pharm_faculty) -> Shivaji University only
    //   engineering / pharmacy / ytc_pharmacy (eng_faculty) -> DBATU Lonere only
    //   everything else (e.g. dpharm) -> keep both, separated by the oblique
    if (in_array($departmentCode, ['management', 'architecture'], true)) {
        $pdf->SetFont('times', 'B', 18);
        $pdf->SetXY(10, 10);
        $pdf->Cell(277, 8, 'Shivaji University, Kolhapur.', 0, 0, 'C');
    } elseif (in_array($departmentCode, ['engineering', 'pharmacy', 'ytc_pharmacy'], true)) {
        $pdf->SetFont('times', 'B', 16);
        $pdf->SetXY(10, 10);
        $pdf->Cell(277, 8, 'Dr. Babasaheb Ambedkar Technological University, Lonere.', 0, 0, 'C');
    } else {
        $pdf->SetFont('times', 'B', 18);
        $pdf->SetXY(10, 7);
        $pdf->Cell(277, 8, 'Shivaji University, Kolhapur. /', 0, 0, 'C');

        $pdf->SetFont('times', 'B', 12);
        $pdf->SetTextColor(192, 0, 0);
        $pdf->SetXY(10, 15.0);
        $pdf->Cell(277, 6, 'Dr. Babasaheb Ambedkar Technological University, Lonere.', 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    $pdf->SetFont('times', 'B', 13);
    $pdf->SetXY(10, 21.5);
    $pdf->Cell(277, 7, 'Eligibility Proforma for Zonal/Inter-Zonal Tournaments', 0, 0, 'C');

    $pdf->SetFont('times', 'B', 9.5);
    $pdf->SetXY(10, 30.5);
    $pdf->Cell(95, 5, 'Name of the Tournament: ' . $game, 0, 0, 'L');
    $pdf->Cell(58, 5, 'Section: ' . ($section !== '' ? $section : '____________'), 0, 0, 'L');
    $pdf->Cell(124, 5, 'Name of the team manager & Mob No: ____________________', 0, 0, 'L');

    $pdf->SetXY(10, 36.5);
    $pdf->Cell(150, 5, 'Name of the Organizing College: ____________________________', 0, 0, 'L');
    $pdf->Cell(127, 5, "His/her Status: ____________________", 0, 0, 'L');

    $pdf->SetXY(10, 42.5);
    $pdf->Cell(277, 5, 'Name of the Participating College: ' . shivaji_pdf_participating_college($departmentCode, $departmentName), 0, 0, 'L');

    $pdf->SetFont('times', 'B', 11);
    $pdf->SetXY(10, 48.0);
    $pdf->Cell(277, 5.5, 'Year: A.Y. ' . ($academicYear !== '' ? $academicYear : '________'), 0, 0, 'C');

    // Print date — auto-filled with the day the proforma is generated, in the
    // same header slot as the college's reference format.
    $pdf->SetXY(45, 53.0);
    $pdf->Cell(120, 4.5, 'Date: ' . date('d/m/Y'), 0, 0, 'L');

    // --- Table ---
    // 21 columns. "Date & Year of First Admission to" is a 3-way split
    // (University/College, Present Course, Present Class) — all YEARS.
    // "Aadhar Number" plus "Mobile No.", "Bank A/C Number" and "IFSC Code"
    // sit just before "Remarks" (all existing widths were trimmed to make room).
    // eng_faculty departments drop the two bank columns (18 = Bank A/C Number,
    // 19 = IFSC Code) from this Final export — the wizard still collects them.
    $x = 10.0;
    $tableY = 58.0;
    $topHeaderH = 13.0;
    $subHeaderH = 7.5;
    $rowH = 10.0;
    $widths = [7.5, 24.0, 13.0, 16.0, 9.5, 15.0, 8.0, 10.0, 12.0, 13.0, 10.0, 11.0, 11.0, 11.0, 9.5, 9.5, 15.0, 15.0, 20.0, 15.0, 11.0];

    $hideBankColumns = in_array($departmentCode, ['engineering', 'pharmacy', 'ytc_pharmacy'], true);
    if ($hideBankColumns) {
        $fullWidth = array_sum($widths);
        $factor = $fullWidth / ($fullWidth - $widths[18] - $widths[19]);
        foreach ($widths as $i => $w) {
            $widths[$i] = ($i === 18 || $i === 19) ? 0.0 : round($w * $factor, 3);
        }
        // absorb rounding drift into Remarks so the table keeps its full width
        $widths[20] += $fullWidth - array_sum($widths);
    }

    $positions = [$x];
    foreach ($widths as $w) {
        $positions[] = end($positions) + $w;
    }

    $rowSpanH = $topHeaderH + $subHeaderH;
    $rowSpanHeaders = [
        0 => "Sr.\nNo.",
        1 => "Name of the Player\n(Beginning Surname)",
        2 => "Mother's\nName",
        3 => "University\nP.R.N. no.",
        4 => "Roll\nNo.",
        5 => "Date of\nBirth",
        8 => "Present\nClass",
        9 => "Name of the\nPresent Course",
        10 => "Duration\nof Course",
        16 => "Aadhar\nNumber",
        17 => "Mobile\nNo.",
        18 => "Bank A/C\nNumber",
        19 => "IFSC\nCode",
        20 => 'Remarks',
    ];
    if ($hideBankColumns) {
        unset($rowSpanHeaders[18], $rowSpanHeaders[19]);
    }
    foreach ($rowSpanHeaders as $index => $header) {
        shivaji_pdf_cell($pdf, $positions[$index], $tableY, $widths[$index], $rowSpanH, $header, 6.6, true);
    }

    shivaji_pdf_cell($pdf, $positions[6], $tableY, $widths[6] + $widths[7], $topHeaderH, "Date & Year of Passing\nH.S.C. Examination", 6.6, true);
    shivaji_pdf_cell($pdf, $positions[11], $tableY, $widths[11] + $widths[12] + $widths[13], $topHeaderH, "Date & Year of First\nAdmission to", 6.6, true);
    shivaji_pdf_cell($pdf, $positions[14], $tableY, $widths[14] + $widths[15], $topHeaderH, "No. of Years of Previous\nParticipation while pursuing", 6.2, true);

    $subY = $tableY + $topHeaderH;
    shivaji_pdf_cell($pdf, $positions[6], $subY, $widths[6], $subHeaderH, "Name of\nExam", 6.4);
    shivaji_pdf_cell($pdf, $positions[7], $subY, $widths[7], $subHeaderH, "Date &\nYear", 6.4);
    shivaji_pdf_cell($pdf, $positions[11], $subY, $widths[11], $subHeaderH, "University\n/College", 6.4);
    shivaji_pdf_cell($pdf, $positions[12], $subY, $widths[12], $subHeaderH, "Present\nCourse", 6.4);
    shivaji_pdf_cell($pdf, $positions[13], $subY, $widths[13], $subHeaderH, "Present\nClass", 6.4);
    shivaji_pdf_cell($pdf, $positions[14], $subY, $widths[14], $subHeaderH, "Graduate\nCourse", 6.4);
    shivaji_pdf_cell($pdf, $positions[15], $subY, $widths[15], $subHeaderH, "P.G.\nCourse", 6.4);

    $presentClassYear = $academicYear !== '' && preg_match('/^(\d{4})/', $academicYear, $ym)
        ? $ym[1]
        : '';

    for ($slot = 0; $slot < 7; $slot++) {
        $p = $participants[$slot] ?? [];
        $program = trim((string)($p['program'] ?? ''));
        $duration = trim((string)($p['course_duration_years'] ?? ''));
        $studyYear = $p['study_year'] ?? null;
        $isPG = $program !== '' && shivaji_pdf_is_postgraduate($program);
        $prevYears = $p === [] ? '' : implode(', ', (array)($p['prev_years'] ?? []));
        // "Date & Year of First Admission to" — three YEAR sub-columns the
        // student fills explicitly in Step 2 of the wizard
        // (first_admission_university_year / _course_year / _class_year).
        // Legacy rows saved before v42 fall back to the old derivation:
        // University/College + Present Course from `admission_year`, Present
        // Class from the start year of the current academic year.
        $admissionYear = trim((string)($p['admission_year'] ?? ''));
        $faUniversity  = trim((string)($p['first_admission_university_year'] ?? '')) ?: $admissionYear;
        $faCourse      = trim((string)($p['first_admission_course_year'] ?? '')) ?: $admissionYear;
        $faClass       = trim((string)($p['first_admission_class_year'] ?? '')) ?: $presentClassYear;

        $values = $p === [] ? array_fill(0, 21, '') : [
            (string)($startingNumber + $slot) . '.',
            trim((string)($p['full_name'] ?? '')),
            trim((string)($p['mother_name'] ?? '')),
            trim((string)($p['enrollment_no'] ?? '')),
            trim((string)($p['roll_no'] ?? '')),
            shivaji_pdf_dob($p['dob'] ?? null),
            $program !== '' ? 'HSC' : '',
            trim((string)($p['hsc_passing_year'] ?? '')),
            shivaji_pdf_class($studyYear, $duration),
            $program,
            $duration,
            $faUniversity,
            $faCourse,
            $faClass,
            !$isPG ? $prevYears : '',
            $isPG ? $prevYears : '',
            trim((string)($p['aadhar_number'] ?? '')),
            trim((string)($p['mobile'] ?? '')),
            trim((string)($p['bank_account_number'] ?? '')),
            strtoupper(trim((string)($p['bank_ifsc'] ?? ''))),
            shivaji_pdf_remarks($p),
        ];

        $y = $tableY + $rowSpanH + ($slot * $rowH);
        foreach ($values as $index => $value) {
            if ($hideBankColumns && ($index === 18 || $index === 19)) {
                continue;
            }
            shivaji_pdf_cell($pdf, $positions[$index], $y, $widths[$index], $rowH, (string)$value, 7.2);
        }
    }

    $tableBottom = $tableY + $rowSpanH + (7 * $rowH);
    $pdf->SetFont('times', '', 9);
    $pdf->SetXY(10, $tableBottom + 4.0);
    $pdf->Cell(277, 5, 'Certified that the above particulars are true as per records of the College', 0, 0, 'L');
    $pdf->SetXY(10, $tableBottom + 10.0);
    $pdf->Cell(277, 5, 'Certified the above players are not employed on full time basis.', 0, 0, 'L');

    $footerY = $tableBottom + 20.0;
    $pdf->SetXY(12, $footerY);
    $pdf->Cell(39, 5, 'Date: ______________', 0, 0, 'L');

    $pdf->SetLineStyle(['width' => 0.25, 'dash' => '2,2']);
    $pdf->Circle(52, $footerY + 12, 9);
    $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0]);
    $pdf->SetFont('times', '', 7.2);
    $pdf->SetXY(43, $footerY + 8.5);
    $pdf->MultiCell(18, 3.4, "Seal of the\nCollege", 0, 'C');

    $pdf->SetFont('times', 'B', 9);
    $pdf->SetXY(101, $footerY + 9);
    $pdf->Cell(85, 5, 'Director of Physical Education', 0, 0, 'C');
    $pdf->SetXY(208, $footerY + 9);
    $pdf->Cell(70, 5, 'Principal', 0, 0, 'C');
    $pdf->SetFont('times', '', 7.5);
    $pdf->SetXY(101, $footerY + 14);
    $pdf->Cell(85, 5, 'Signature of the Director of Physical Education', 0, 0, 'C');
    $pdf->SetXY(208, $footerY + 14);
    $pdf->Cell(70, 5, 'Signature of the Principal', 0, 0, 'C');
}
