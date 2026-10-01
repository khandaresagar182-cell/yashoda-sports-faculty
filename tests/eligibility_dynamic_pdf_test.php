<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
require_once __DIR__ . '/../includes/shivaji_eligibility_proforma_pdf.php';

/** @return array<int,array<string,mixed>> */
function pdf_test_participants(int $count): array
{
    $rows = [];
    for ($number = 1; $number <= $count; $number++) {
        $rows[] = [
            'full_name' => sprintf('PDFSTUDENT_%02d', $number),
            'mother_name' => 'Mother',
            'enrollment_no' => 'PRN' . $number,
            'roll_no' => (string)$number,
            'dob' => '2005-01-01',
            'study_year' => 'Second',
            'program' => 'B.Tech',
            'course_duration_years' => '4 Year',
            'hsc_passing_year' => '2023',
            'mobile' => '9999999999',
        ];
    }
    return $rows;
}

$participants = pdf_test_participants(18);
$pages = array_chunk($participants, SHIVAJI_ELIGIBILITY_ROWS_PER_PAGE);
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(0);
$pdf->SetAutoPageBreak(false);
$pdf->SetPrintHeader(false);
$pdf->SetPrintFooter(false);
$pdf->SetCompression(false);

foreach ($pages as $pageIndex => $pageRows) {
    $pdf->AddPage();
    draw_shivaji_eligibility_proforma($pdf, [
        'game' => 'Cricket',
        'section' => "Men's",
        'academic_year' => '2026-27',
        'department_code' => 'engineering',
        'department_name' => 'Faculty of Engineering',
        'participants' => $pageRows,
        'starting_number' => ($pageIndex * SHIVAJI_ELIGIBILITY_ROWS_PER_PAGE) + 1,
        'include_certification' => $pageIndex === count($pages) - 1,
    ]);
}

if ($pdf->getNumPages() !== 3) {
    throw new RuntimeException('An 18-student Shivaji PDF must contain three eligibility pages.');
}

$pdfBytes = $pdf->Output('', 'S');
for ($number = 1; $number <= 18; $number++) {
    $name = sprintf('PDFSTUDENT_%02d', $number);
    if (substr_count($pdfBytes, $name) !== 1) {
        throw new RuntimeException($name . ' must print exactly once in the generated PDF.');
    }
}
if (substr_count($pdfBytes, 'Certified that the above particulars are true as per records of the College') !== 1) {
    throw new RuntimeException('The PDF must contain one final certification block.');
}

echo "Eligibility dynamic PDF test passed.\n";
