<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/shivaji_eligibility_proforma_docx.php';

/** Read one deflated entry from the small ZIP writer used by the DOCX builders. */
function docx_test_entry(string $archive, string $wantedName): string
{
    $offset = 0;
    $length = strlen($archive);

    while ($offset + 30 <= $length) {
        $header = unpack(
            'Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength',
            substr($archive, $offset, 30)
        );
        if (!is_array($header) || $header['signature'] !== 0x04034b50) {
            break;
        }

        $nameOffset = $offset + 30;
        $name = substr($archive, $nameOffset, $header['nameLength']);
        $dataOffset = $nameOffset + $header['nameLength'] + $header['extraLength'];
        $compressed = substr($archive, $dataOffset, $header['compressed']);

        if ($name === $wantedName) {
            if ($header['method'] !== 8) {
                throw new RuntimeException('Unexpected DOCX compression method.');
            }
            $contents = gzinflate($compressed);
            if ($contents === false) {
                throw new RuntimeException('Unable to inflate ' . $wantedName . '.');
            }
            return $contents;
        }

        $offset = $dataOffset + $header['compressed'];
    }

    throw new RuntimeException($wantedName . ' was not found in the generated DOCX.');
}

function docx_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<int,array<string,mixed>> */
function docx_test_participants(int $count, string $prefix): array
{
    $rows = [];
    for ($number = 1; $number <= $count; $number++) {
        $rows[] = [
            'full_name' => sprintf('%s_%02d', $prefix, $number),
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

foreach ([7, 18] as $count) {
    $prefix = 'SHIVAJI' . $count;
    $docx = build_shivaji_eligibility_proforma_docx(
        'Cricket',
        "Men's",
        '2026-27',
        'engineering',
        'Faculty of Engineering',
        docx_test_participants($count, $prefix)
    );
    $documentXml = docx_test_entry($docx, 'word/document.xml');

    for ($number = 1; $number <= $count; $number++) {
        $name = sprintf('%s_%02d', $prefix, $number);
        docx_test_assert(substr_count($documentXml, $name) === 1, $name . ' must print exactly once.');
    }

    $expectedBreaks = (int)ceil($count / SHIVAJI_ELIGIBILITY_ROWS_PER_PAGE) - 1;
    docx_test_assert(
        substr_count($documentXml, '<w:br w:type="page"/>') === $expectedBreaks,
        "The {$count}-student Shivaji export has an incorrect page count."
    );
    docx_test_assert(
        str_contains($documentXml, '<w:t xml:space="preserve">' . $count . '.</w:t>'),
        "The {$count}-student Shivaji export must keep serial numbering through {$count}."
    );
    docx_test_assert(
        substr_count($documentXml, 'Certified that the above particulars are true as per records of the College') === 1,
        "The {$count}-student Shivaji export must contain one final certification block."
    );
}

$polyDocx = build_polytechnic_eligibility_docx(
    'Cricket',
    'Zonal',
    '2026-27',
    docx_test_participants(18, 'POLY18'),
    __DIR__ . '/missing-logo.png'
);
$polyXml = docx_test_entry($polyDocx, 'word/document.xml');
for ($number = 1; $number <= 18; $number++) {
    $name = sprintf('POLY18_%02d', $number);
    docx_test_assert(substr_count($polyXml, $name) === 1, $name . ' must print exactly once.');
}
docx_test_assert(substr_count($polyXml, '<w:br w:type="page"/>') === 1, 'The 18-student Polytechnic export must use two pages.');
docx_test_assert(str_contains($polyXml, '<w:t xml:space="preserve">18</w:t>'), 'Polytechnic serial numbering must continue to 18.');
docx_test_assert(
    substr_count($polyXml, 'This is to certify that the above participants are eligible as per records of the Institute') === 1,
    'The Polytechnic export must contain one final certification block.'
);

echo "Eligibility dynamic export tests passed.\n";
