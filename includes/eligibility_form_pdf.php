<?php
/**
 * Draw the college eligibility form onto an initialized A4 TCPDF page.
 */

declare(strict_types=1);

/**
 * Convert values such as "2025-2026" to the reference form's "2025-26".
 */
function eligibility_academic_year(string $year): string
{
    $year = trim($year);
    if (preg_match('/^(\d{4})\s*-\s*(\d{4})$/', $year, $matches)) {
        return $matches[1] . '-' . substr($matches[2], -2);
    }

    return $year;
}

function eligibility_year_course(?string $studyYear, ?string $program): string
{
    $yearMap = [
        'first'  => 'F.Y.',
        'second' => 'S.Y.',
        'third'  => 'T.Y.',
        'final'  => 'Final.',
    ];
    $year = $yearMap[strtolower(trim((string)$studyYear))] ?? trim((string)$studyYear);

    $program = trim((string)$program);
    $normalized = strtolower(preg_replace('/[^a-z0-9&]+/i', ' ', $program) ?? $program);
    $course = '';

    $courseMap = [
        'computer'                  => 'CO',
        'mechanical'                => 'ME',
        'civil'                     => 'Civil',
        'information technology'    => 'IF',
        'electronics telecommunication' => 'E&TC',
        'electronics & telecommunication' => 'E&TC',
        'electrical'                => 'EE',
        'automobile'                => 'AN',
        'architecture'              => 'AN',
    ];

    if ($program !== '' && preg_match('/^[A-Za-z&]{1,8}$/', $program)) {
        $course = strtoupper($program);
    } else {
        foreach ($courseMap as $needle => $abbreviation) {
            if (str_contains($normalized, $needle)) {
                $course = $abbreviation;
                break;
            }
        }
    }

    if ($course === '' && $program !== '') {
        $words = preg_split('/\s+/', $program) ?: [];
        $ignore = ['diploma', 'engineering', 'technology', 'and', 'of'];
        $initials = '';
        foreach ($words as $word) {
            if ($word === '' || in_array(strtolower($word), $ignore, true)) {
                continue;
            }
            $initials .= strtoupper(substr($word, 0, 1));
        }
        $course = substr($initials, 0, 5);
    }

    return trim($year . $course);
}

function eligibility_dob(?string $dob): string
{
    if ($dob === null || $dob === '' || $dob === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($dob);
    return $timestamp === false ? '' : date('d/m/Y', $timestamp);
}

/**
 * @param TCPDF               $pdf
 * @param array<string,mixed> $data
 */
function draw_eligibility_form(TCPDF $pdf, array $data): void
{
    $game          = trim((string)($data['game'] ?? ''));
    $event         = trim((string)($data['event'] ?? 'IEDSSA Zonal Tournaments'));
    $academicYear  = eligibility_academic_year((string)($data['academic_year'] ?? ''));
    $logoPath      = (string)($data['logo_path'] ?? '');
    $rowCount      = max(0, min(16, (int)($data['row_count'] ?? count((array)($data['participants'] ?? [])))));
    $participants  = array_slice((array)($data['participants'] ?? []), 0, $rowCount);

    $pageW = 210.0;
    $left  = 14.7;
    $right = 14.7;
    $width = $pageW - $left - $right;

    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetLineWidth(0.22);

    // Letterhead: dimensions and spacing follow the supplied physical form.
    if ($logoPath !== '' && is_file($logoPath)) {
        $pdf->Image($logoPath, 20.2, 18.5, 25, 25, '', '', '', false, 300);
    }

    $pdf->SetTextColor(20, 51, 112);
    $pdf->SetFont('times', 'B', 14);
    $pdf->SetXY(16.5, 47.8);
    $pdf->Cell(31, 6, 'NAAC B+', 0, 0, 'C');

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('times', 'B', 11.5);
    $pdf->SetXY(48, 10.3);
    $pdf->Cell(120, 5.5, "Yashoda Shikshan Prasarak Mandal's", 0, 0, 'C');

    $pdf->SetTextColor(132, 43, 43);
    $pdf->SetFont('times', 'B', 17);
    $pdf->SetXY(45, 16.2);
    $pdf->Cell(125, 7, 'YASHODA TECHNICAL CAMPUS, SATARA.', 0, 0, 'C');

    $pdf->SetFont('times', 'B', 13.5);
    $pdf->SetXY(57, 23.8);
    $pdf->Cell(101, 6, 'Faculty of Polytechnic,', 0, 0, 'C');

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('times', 'B', 11.5);
    $pdf->SetXY(51, 31.2);
    $pdf->Cell(112, 5, 'NH-4, Wadhe Phata, Satara. Tele Fax:- 02162-271238/39/40', 0, 0, 'C');
    $pdf->SetXY(51, 38.3);
    $pdf->Cell(112, 5, 'Website- www.yes.edu.in   Email- principalengg_ytc@yes.edu.in', 0, 0, 'C');
    $pdf->SetXY(51, 45.4);
    $pdf->Cell(112, 5, 'AICTE- New Delhi. Govt. of Maharashtra (DTE), Mumbai', 0, 0, 'C');

    $pdf->SetFont('times', 'B', 9.7);
    $pdf->SetXY(48, 52.2);
    $pdf->Cell(118, 5, 'Affiliated to DBATU Lonere, Shivaji University, Kolhapur & MSBTE, Mumbai', 0, 0, 'C');

    $pdf->SetTextColor(130, 130, 130);
    $pdf->SetFont('times', '', 9);
    $pdf->SetXY(171.5, 29.2);
    $pdf->Cell(20, 5, 'Approved by', 0, 0, 'C');

    $pdf->SetDrawColor(27, 48, 60);
    $pdf->Line($left, 62.4, $pageW - $right, 62.4);
    $pdf->Line($left, 73.1, $pageW - $right, 73.1);

    $pdf->SetTextColor(132, 43, 43);
    $pdf->SetFont('times', 'B', 11.5);
    $pdf->SetXY(20, 63.2);
    $pdf->Cell(46, 5, 'Prof. Dasharath Sagare', 0, 0, 'C');
    $pdf->SetXY(130, 63.2);
    $pdf->Cell(46, 5, 'Dr. Pravin Gavade', 0, 0, 'C');

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('times', 'B', 11.5);
    $pdf->SetXY(20, 67.5);
    $pdf->Cell(46, 5, 'Founder, President', 0, 0, 'C');
    $pdf->SetXY(130, 67.5);
    $pdf->Cell(46, 5, 'Principal', 0, 0, 'C');

    // Recipient/date area. Printed values are left blank for handwriting.
    $pdf->SetFont('times', 'B', 11.5);
    $pdf->SetXY(138, 74.2);
    $pdf->Cell(37, 5, 'Date:- ______________', 0, 0, 'L');

    $pdf->SetFont('times', 'B', 12.5);
    $pdf->SetXY(23.5, 82.8);
    $pdf->Cell(55, 6, 'To,', 0, 0, 'L');
    $pdf->SetXY(23.5, 90.5);
    $pdf->Cell(55, 6, 'The Principal,', 0, 0, 'L');
    $pdf->SetXY(23.5, 98.2);
    $pdf->Cell(66, 6, '________________________', 0, 0, 'L');
    $pdf->SetXY(23.5, 105.9);
    $pdf->Cell(66, 6, '________________________', 0, 0, 'L');

    // Form title and tournament details.
    $pdf->SetFont('times', 'B', 16);
    $pdf->SetXY(73, 116.8);
    $pdf->Cell(64, 7, 'Eligibility Form', 0, 0, 'C');
    $pdf->Line(87.9, 123.2, 122.1, 123.2);

    $pdf->SetFont('times', 'B', 11.5);
    $pdf->SetXY(38.7, 128.7);
    $pdf->Cell(150, 6, 'Participating Institute: - Yashoda Technical Campus, Faculty of Polytechnic, Satara.', 0, 0, 'L');

    $eventLabel = $event !== '' ? $event : 'IEDSSA Zonal Tournaments';
    $pdf->SetXY(38.7, 139.4);
    $pdf->Cell(100, 6, $eventLabel, 0, 0, 'L');
    $pdf->SetXY(149.5, 139.4);
    $pdf->Cell(36, 6, 'Year:  ' . $academicYear, 0, 0, 'L');

    $pdf->SetXY(38.7, 150.1);
    $pdf->Cell(146, 6, 'Name of Tournament (Event): - ' . $game, 0, 0, 'L');
    $pdf->SetXY(38.7, 160.8);
    $pdf->Cell(146, 6, 'Venue: ________________________________', 0, 0, 'L');
    $pdf->SetXY(38.7, 171.5);
    $pdf->Cell(101, 6, 'Name of Team Manager: ________________________', 0, 0, 'L');
    $pdf->SetXY(149.5, 171.5);
    $pdf->Cell(36, 6, 'Date: ____________', 0, 0, 'L');
    $pdf->SetXY(38.7, 179.5);
    $pdf->Cell(146, 6, 'Status of Team Manager: ______________________', 0, 0, 'L');

    // Participant grid. Final-roster records fill the first rows; unused rows stay blank.
    $tableY  = 187.6;
    $headerH = 9.9;
    $rowH    = 4.72;
    // 10 columns — "Mobile No.", "Bank A/C Number" and "IFSC Code" were
    // inserted before "Signature of Participant"; existing widths trimmed
    // to keep the table on the portrait page.
    $colW    = [10.0, 36.0, 16.0, 13.0, 24.0, 17.0, 14.0, 20.0, 15.0, 20.0];
    $headers = [
        "Sr.\nNo",
        'Name of Participant',
        "Year &\nCourse",
        "Roll\nNo",
        "Enrollment\nNumber",
        "Date Of\nBirth",
        "Mobile\nNo.",
        "Bank A/C\nNumber",
        "IFSC\nCode",
        "Signature of\nParticipant",
    ];

    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.20);
    $pdf->SetFont('times', 'B', 9.2);
    $x = $left;
    foreach ($colW as $index => $columnWidth) {
        $pdf->MultiCell(
            $columnWidth,
            $headerH / 2,
            $headers[$index],
            1,
            $index === 0 ? 'C' : 'L',
            false,
            0,
            $x,
            $tableY,
            true,
            0,
            false,
            true,
            $headerH,
            'M'
        );
        $x += $columnWidth;
    }

    $pdf->SetFont('times', 'B', 8.2);
    for ($row = 1; $row <= $rowCount; $row++) {
        $y = $tableY + $headerH + (($row - 1) * $rowH);
        $participant = $participants[$row - 1] ?? [];
        $values = [
            (string)$row,
            trim((string)($participant['full_name'] ?? '')),
            eligibility_year_course(
                isset($participant['study_year']) ? (string)$participant['study_year'] : null,
                isset($participant['program']) ? (string)$participant['program'] : null
            ),
            trim((string)($participant['roll_no'] ?? '')),
            trim((string)($participant['enrollment_no'] ?? '')),
            eligibility_dob(isset($participant['dob']) ? (string)$participant['dob'] : null),
            trim((string)($participant['mobile'] ?? '')),
            trim((string)($participant['bank_account_number'] ?? '')),
            strtoupper(trim((string)($participant['bank_ifsc'] ?? ''))),
            '',
        ];
        $x = $left;
        foreach ($colW as $index => $columnWidth) {
            $pdf->SetXY($x, $y);
            $alignment = in_array($index, [1, 2], true) ? 'L' : 'C';
            $pdf->Cell(
                $columnWidth,
                $rowH,
                $values[$index],
                1,
                0,
                $alignment,
                false,
                '',
                1
            );
            $x += $columnWidth;
        }
    }

    $tableBottom = $tableY + $headerH + ($rowCount * $rowH);
    $pdf->SetFont('times', 'B', 10.5);
    $pdf->SetXY(17.2, $tableBottom + 3.0);
    $pdf->Cell(
        176,
        6,
        'This is to certify that the above participants are eligible as per records of the Institute',
        0,
        0,
        'L'
    );

    $pdf->SetFont('times', 'B', 10.5);
    $footerY = min(287.0, $tableBottom + 16.0);
    $pdf->SetXY(22.5, $footerY);
    $pdf->Cell(35, 5, 'Date: ____________', 0, 0, 'L');
    $pdf->SetXY(67.5, $footerY);
    $pdf->Cell(40, 5, 'Seal of Institute', 0, 0, 'C');
    $pdf->SetXY(112.5, $footerY);
    $pdf->Cell(40, 5, 'Sports Incharge', 0, 0, 'C');
    $pdf->SetXY(165.0, $footerY);
    $pdf->Cell(25, 5, 'Principal', 0, 0, 'C');
}
