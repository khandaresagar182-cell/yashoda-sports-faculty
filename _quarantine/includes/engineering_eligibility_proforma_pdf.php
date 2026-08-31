<?php
/**
 * Draw the Engineering zonal/inter-zonal eligibility proforma.
 */

declare(strict_types=1);

function engineering_proforma_pdf_dob(?string $dob): string
{
    if ($dob === null || $dob === '' || $dob === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($dob);
    return $timestamp === false ? '' : date('d/m/Y', $timestamp);
}

function engineering_proforma_pdf_class(?string $studyYear): string
{
    return [
        'first' => 'F.Y',
        'second' => 'S.Y',
        'third' => 'T.Y',
        'final' => 'Final Year',
    ][strtolower(trim((string)$studyYear))] ?? trim((string)$studyYear);
}

function engineering_proforma_pdf_cell(
    TCPDF $pdf,
    float $x,
    float $y,
    float $width,
    float $height,
    string $text,
    float $fontSize = 7.0,
    bool $bold = false,
    string $align = 'C'
): void {
    $pdf->Rect($x, $y, $width, $height);
    $pdf->SetFont('times', $bold ? 'B' : '', $fontSize);
    $pdf->MultiCell(
        $width,
        3.2,
        $text,
        0,
        $align,
        false,
        0,
        $x,
        $y,
        true,
        0,
        false,
        true,
        $height,
        'M'
    );
}

/**
 * @param array<string,mixed> $data
 */
function draw_engineering_eligibility_proforma(TCPDF $pdf, array $data): void
{
    $game = trim((string)($data['game'] ?? ''));
    $event = trim((string)($data['event'] ?? ''));
    $participants = array_slice((array)($data['participants'] ?? []), 0, 3);
    $startingNumber = max(1, (int)($data['starting_number'] ?? 1));

    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetLineWidth(0.22);

    $pdf->SetFont('times', 'B', 14);
    $pdf->SetXY(9, 7);
    $pdf->Cell(279, 7, 'ELIGIBILITY PROFORMA FOR ZONAL /INTER ZONAL GAMES', 0, 0, 'C');

    $pdf->SetFont('times', 'B', 9);
    $pdf->SetXY(10, 20);
    $pdf->Cell(61, 5, 'Name of the Participating College -', 0, 0, 'L');
    $pdf->SetFont('times', '', 9);
    $pdf->Cell(80, 5, '________________________________', 0, 0, 'L');
    $pdf->SetXY(194, 20);
    $pdf->Cell(40, 5, 'Zone - Kolhapur', 0, 0, 'L');
    $pdf->SetXY(244, 20);
    $pdf->Cell(43, 5, 'Section - ' . $event, 0, 0, 'L');

    $pdf->SetFont('times', 'B', 9);
    $pdf->SetXY(10, 28);
    $pdf->Cell(43, 5, 'Name of the Tournament -', 0, 0, 'L');
    $pdf->SetFont('times', '', 9);
    $pdf->Cell(43, 5, $game, 0, 0, 'L');
    $pdf->SetFont('times', 'B', 9);
    $pdf->Cell(38, 5, 'Name of the Manager -', 0, 0, 'L');
    $pdf->SetFont('times', '', 9);
    $pdf->Cell(45, 5, '____________________', 0, 0, 'L');
    $pdf->SetXY(194, 28);
    $pdf->Cell(93, 5, 'His/Her status - ____________________', 0, 0, 'L');

    $pdf->SetFont('times', 'B', 9);
    $pdf->SetXY(10, 36);
    $pdf->Cell(45, 5, 'Name Of The Host Institute -', 0, 0, 'L');
    $pdf->SetFont('times', '', 9);
    $pdf->Cell(85, 5, '________________________________________', 0, 0, 'L');
    $pdf->SetXY(224, 36);
    $pdf->Cell(63, 5, 'Date : ______________', 0, 0, 'L');

    $x = 9.0;
    $tableY = 48.0;
    $topHeaderH = 24.0;
    $subHeaderH = 11.0;
    $rowH = 20.5;
    $widths = [
        9.17, 25.56, 17.62, 17.98, 25.56,
        19.03, 15.51, 15.51, 19.03, 18.07,
        18.07, 17.98, 17.98, 22.21, 19.74,
    ];

    $positions = [$x];
    foreach ($widths as $width) {
        $positions[] = end($positions) + $width;
    }

    $rowSpanH = $topHeaderH + $subHeaderH;
    $rowSpanHeaders = [
        0 => "Sr\nNo",
        1 => "Name Of\nSports\nPerson",
        2 => "Father's\nName",
        3 => "Date of\nBirth",
        4 => 'PRN. No',
        7 => "Present\nClass",
        8 => "Name Of\nthe\nPresent\ncourse",
        13 => 'Aadhar card No',
        14 => 'Mobile No',
    ];
    foreach ($rowSpanHeaders as $index => $header) {
        engineering_proforma_pdf_cell(
            $pdf,
            $positions[$index],
            $tableY,
            $widths[$index],
            $rowSpanH,
            $header,
            6.7,
            true
        );
    }

    engineering_proforma_pdf_cell(
        $pdf,
        $positions[5],
        $tableY,
        $widths[5] + $widths[6],
        $topHeaderH,
        "Date & Year of\nPassing\nqualifying\nExamination or\nFirst Admission\nto\nCollege/University",
        6.2,
        true
    );
    engineering_proforma_pdf_cell(
        $pdf,
        $positions[9],
        $tableY,
        $widths[9] + $widths[10],
        $topHeaderH,
        "Date & Year of\nFirst Admission to",
        6.7,
        true
    );
    engineering_proforma_pdf_cell(
        $pdf,
        $positions[11],
        $tableY,
        $widths[11] + $widths[12],
        $topHeaderH,
        "Name of years of\nprevious\nparticipation\nwhile pursuing",
        6.5,
        true
    );

    $subY = $tableY + $topHeaderH;
    engineering_proforma_pdf_cell($pdf, $positions[5], $subY, $widths[5], $subHeaderH, "Name\nof\nExam", 6.5);
    engineering_proforma_pdf_cell($pdf, $positions[6], $subY, $widths[6], $subHeaderH, "Date &\nYear", 6.5);
    engineering_proforma_pdf_cell($pdf, $positions[9], $subY, $widths[9], $subHeaderH, 'University', 6.5);
    engineering_proforma_pdf_cell($pdf, $positions[10], $subY, $widths[10], $subHeaderH, "Present\nCourse", 6.5);
    engineering_proforma_pdf_cell($pdf, $positions[11], $subY, $widths[11], $subHeaderH, "Graduate\ncourse", 6.5);
    engineering_proforma_pdf_cell($pdf, $positions[12], $subY, $widths[12], $subHeaderH, "PG\ncourse", 6.5);

    for ($slot = 0; $slot < 3; $slot++) {
        $participant = $participants[$slot] ?? [];
        $values = $participant === [] ? array_fill(0, 15, '') : [
            (string)($startingNumber + $slot),
            trim((string)($participant['full_name'] ?? '')),
            trim((string)($participant['mother_name'] ?? '')),
            engineering_proforma_pdf_dob($participant['dob'] ?? null),
            trim((string)($participant['enrollment_no'] ?? '')),
            '',
            '',
            engineering_proforma_pdf_class($participant['study_year'] ?? null),
            trim((string)($participant['program'] ?? '')),
            '',
            '',
            '',
            '',
            '',
            trim((string)($participant['mobile'] ?? '')),
        ];

        $rowY = $tableY + $rowSpanH + ($slot * $rowH);
        foreach ($values as $index => $value) {
            engineering_proforma_pdf_cell(
                $pdf,
                $positions[$index],
                $rowY,
                $widths[$index],
                $rowH,
                $value,
                7.2
            );
        }
    }

    $tableBottom = $tableY + $rowSpanH + (3 * $rowH);
    $pdf->SetFont('times', '', 9);
    $pdf->SetXY(10.5, $tableBottom + 4.0);
    $pdf->Cell(276, 5, 'Certified that above particulars are correct and true as per record of the University.', 0, 0, 'L');
    $pdf->SetXY(10.5, $tableBottom + 12.0);
    $pdf->Cell(276, 5, 'Certified that above players are not employed on full time basis.', 0, 0, 'L');

    $footerY = $tableBottom + 26.0;
    $pdf->SetXY(12, $footerY);
    $pdf->Cell(39, 5, 'Date :- ______________', 0, 0, 'L');

    $pdf->SetLineStyle(['width' => 0.25, 'dash' => '2,2']);
    $pdf->Circle(52, $footerY + 12, 10);
    $pdf->SetLineStyle(['width' => 0.22, 'dash' => 0]);
    $pdf->SetFont('times', '', 7.5);
    $pdf->SetXY(42, $footerY + 8.5);
    $pdf->MultiCell(20, 3.5, "Institute\nSeal", 0, 'C');

    $pdf->SetFont('times', 'B', 9);
    $pdf->SetXY(101, $footerY + 9);
    $pdf->Cell(85, 5, 'Signature of the Director of Physical Education', 0, 0, 'C');
    $pdf->SetXY(208, $footerY + 9);
    $pdf->Cell(70, 5, 'Signature of the Principal', 0, 0, 'C');
}
