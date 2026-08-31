<?php
/**
 * Draw the Engineering players identity-card sheet.
 */

declare(strict_types=1);

function engineering_tournament_year(string $year): string
{
    $year = trim($year);
    if (preg_match('/^(\d{4})\s*-\s*(\d{2}|\d{4})$/', $year, $matches)) {
        return $matches[1] . "'" . substr($matches[2], -2);
    }

    return $year;
}

function engineering_class_label(?string $studyYear, ?string $program): string
{
    $yearMap = [
        'first'  => 'F.Y.',
        'second' => 'S.Y.',
        'third'  => 'T.Y.',
        'final'  => 'Final Year',
    ];
    $year = $yearMap[strtolower(trim((string)$studyYear))] ?? trim((string)$studyYear);
    $program = trim((string)$program);

    if ($program === '') {
        return $year;
    }

    $branch = '';
    $normalized = strtolower($program);
    $branchMap = [
        'computer science' => 'CSE',
        'computer' => 'CSE',
        'information technology' => 'IT',
        'mechanical' => 'ME',
        'civil' => 'CE',
        'electrical' => 'EE',
        'electronics' => 'E&TC',
        'artificial intelligence' => 'AI',
    ];
    foreach ($branchMap as $needle => $label) {
        if (str_contains($normalized, $needle)) {
            $branch = $label;
            break;
        }
    }
    if ($branch === '' && preg_match('/\b(CSE|IT|ME|CE|EE|AI|E&TC)\b/i', $program, $matches)) {
        $branch = strtoupper($matches[1]);
    }

    $degree = str_contains($normalized, 'b.e') || str_contains($normalized, 'bachelor of engineering')
        ? 'B.E.'
        : 'B.Tech';

    return trim($year . ($branch !== '' ? ' ' . $branch : '')) . "\n" . $degree;
}

function engineering_pdf_dob(?string $dob): string
{
    if ($dob === null || $dob === '' || $dob === '0000-00-00') {
        return '';
    }
    $timestamp = strtotime($dob);
    return $timestamp === false ? '' : date('d/m/Y', $timestamp);
}

function engineering_draw_photo(TCPDF $pdf, string $path, float $x, float $y, float $w, float $h): void
{
    $pdf->Rect($x, $y, $w, $h);
    if ($path === '' || !is_file($path)) {
        $pdf->SetTextColor(145, 145, 145);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY($x, $y + ($h / 2) - 4);
        $pdf->MultiCell($w, 4, "Paste\nPhoto", 0, 'C', false, 0, '', '', true, 0, false, true, 8, 'M');
        $pdf->SetTextColor(0, 0, 0);
        return;
    }

    $size = @getimagesize($path);
    if (!$size || $size[0] <= 0 || $size[1] <= 0) {
        return;
    }
    $scale = min(($w - 1.4) / $size[0], ($h - 1.4) / $size[1]);
    $imageW = $size[0] * $scale;
    $imageH = $size[1] * $scale;
    $pdf->Image($path, $x + (($w - $imageW) / 2), $y + (($h - $imageH) / 2), $imageW, $imageH);
}

/**
 * @param array<string,mixed> $data
 */
function draw_engineering_identity_card(TCPDF $pdf, array $data): void
{
    $players = array_slice((array)($data['participants'] ?? []), 0, 4);
    $game = trim((string)($data['game'] ?? ''));
    $year = engineering_tournament_year((string)($data['academic_year'] ?? ''));
    $projectRoot = rtrim((string)($data['project_root'] ?? ''), '/\\');

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.22);

    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetXY(20, 9);
    $pdf->Cell(170, 7, 'Dr. Babasaheb Ambedkar Technological University, Lonere.', 0, 0, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetXY(20, 18);
    $pdf->Cell(170, 7, 'PLAYERS IDENTITY CARD', 0, 0, 'C');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY(20, 27);
    $pdf->Cell(170, 7, "FOR ZONAL/ INTERZONAL TOURNAMENT {$year}", 0, 0, 'C');

    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetXY(10.5, 38);
    $pdf->Cell(31, 5, 'Name of College:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 9.5);
    $pdf->Cell(153, 5, 'Kolhapur Zone Yashoda Technical Campus, Wadhe, Satara.', 0, 0, 'L');

    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetXY(10.5, 48);
    $pdf->Cell(27, 5, 'Event/Game:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 9.5);
    $pdf->Cell(40, 5, $game, 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->Cell(13, 5, 'Date:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 9.5);
    $pdf->Cell(30, 5, '____________', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->Cell(14, 5, 'Place:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(60, 5, 'Yashoda Technical Campus, Wadhe, Satara.', 0, 0, 'L');

    $left = 10.5;
    $tableY = 59.2;
    $headerH = 20;
    $rowH = 46.8;
    $colW = [11.4, 53.0, 32.0, 32.0, 30.0, 38.6];
    $headers = ["Sr.\nNo.", "Player Name With\nMother Name", 'Class', 'Date of Birth', "Player\nSignature", 'Photo'];

    $pdf->SetFont('helvetica', 'B', 9.5);
    $x = $left;
    foreach ($colW as $index => $width) {
        $pdf->Rect($x, $tableY, $width, $headerH);
        $pdf->MultiCell($width, 5, $headers[$index], 0, 'C', false, 0, $x, $tableY, true, 0, false, true, $headerH, 'M');
        $x += $width;
    }

    foreach ($players as $index => $player) {
        $y = $tableY + $headerH + ($index * $rowH);
        $x = $left;
        foreach ($colW as $width) {
            $pdf->Rect($x, $y, $width, $rowH);
            $x += $width;
        }

        $pdf->SetFont('helvetica', '', 9.5);
        $pdf->SetXY($left, $y);
        $pdf->MultiCell($colW[0], 5, ($index + 1) . ')', 0, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');

        $nameText = trim((string)($player['full_name'] ?? ''));
        $motherName = trim((string)($player['mother_name'] ?? ''));
        if ($motherName !== '') {
            $nameText .= "\n" . $motherName;
        }
        $pdf->SetXY($left + $colW[0], $y);
        $pdf->MultiCell($colW[1], 5, $nameText, 0, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');

        $pdf->SetXY($left + $colW[0] + $colW[1], $y);
        $pdf->MultiCell(
            $colW[2],
            5,
            engineering_class_label($player['study_year'] ?? null, $player['program'] ?? null),
            0,
            'C',
            false,
            0,
            '',
            '',
            true,
            0,
            false,
            true,
            $rowH,
            'M'
        );

        $pdf->SetXY($left + $colW[0] + $colW[1] + $colW[2], $y);
        $pdf->MultiCell(
            $colW[3],
            5,
            engineering_pdf_dob($player['dob'] ?? null),
            0,
            'C',
            false,
            0,
            '',
            '',
            true,
            0,
            false,
            true,
            $rowH,
            'M'
        );

        $photoPath = trim((string)($player['photo_path'] ?? ''));
        if ($photoPath !== '' && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $photoPath)) {
            $photoPath = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $photoPath);
        }
        $photoX = $left + array_sum(array_slice($colW, 0, 5)) + 5.5;
        engineering_draw_photo($pdf, $photoPath, $photoX, $y + 6, 27.5, 34.8);
    }
}
