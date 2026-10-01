<?php
/**
 * Editable DOCX builder for the Polytechnic eligibility form.
 */

declare(strict_types=1);

final class SimpleDocxZip
{
    /** @var array<int,array{name:string,crc:int,size:int,compressed:string,offset:int}> */
    private array $entries = [];
    private string $body = '';

    public function add(string $name, string $data): void
    {
        $compressed = gzdeflate($data, 9);
        if ($compressed === false) {
            throw new RuntimeException('Unable to compress DOCX content.');
        }

        $name = str_replace('\\', '/', $name);
        $offset = strlen($this->body);
        $crc = crc32($data);
        $this->body .= pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            8,
            0,
            0,
            $crc,
            strlen($compressed),
            strlen($data),
            strlen($name),
            0
        ) . $name . $compressed;

        $this->entries[] = [
            'name' => $name,
            'crc' => $crc,
            'size' => strlen($data),
            'compressed' => $compressed,
            'offset' => $offset,
        ];
    }

    public function finish(): string
    {
        $central = '';
        foreach ($this->entries as $entry) {
            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                8,
                0,
                0,
                $entry['crc'],
                strlen($entry['compressed']),
                $entry['size'],
                strlen($entry['name']),
                0,
                0,
                0,
                0,
                0,
                $entry['offset']
            ) . $entry['name'];
        }

        $centralOffset = strlen($this->body);
        $count = count($this->entries);
        return $this->body
            . $central
            . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $centralOffset, 0);
    }
}

function poly_docx_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * @param array{bold?:bool,italic?:bool,underline?:bool,size?:int,color?:string,font?:string} $options
 */
function poly_docx_run(string $text, array $options = []): string
{
    $font = poly_docx_xml($options['font'] ?? 'Times New Roman');
    $size = (int)($options['size'] ?? 20);
    $props = '<w:rPr>'
        . '<w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/>'
        . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>'
        . (!empty($options['bold']) ? '<w:b/>' : '')
        . (!empty($options['italic']) ? '<w:i/>' : '')
        . (!empty($options['underline']) ? '<w:u w:val="single"/>' : '')
        . (isset($options['color']) ? '<w:color w:val="' . poly_docx_xml($options['color']) . '"/>' : '')
        . '</w:rPr>';

    $parts = explode("\n", $text);
    $xml = '<w:r>' . $props;
    foreach ($parts as $index => $part) {
        if ($index > 0) {
            $xml .= '<w:br/>';
        }
        $xml .= '<w:t xml:space="preserve">' . poly_docx_xml($part) . '</w:t>';
    }
    return $xml . '</w:r>';
}

/**
 * @param array{align?:string,before?:int,after?:int,line?:int,keep?:bool} $options
 */
function poly_docx_paragraph(string $runs, array $options = []): string
{
    $align = poly_docx_xml($options['align'] ?? 'left');
    $before = (int)($options['before'] ?? 0);
    $after = (int)($options['after'] ?? 0);
    $line = (int)($options['line'] ?? 240);
    return '<w:p><w:pPr>'
        . '<w:jc w:val="' . $align . '"/>'
        . '<w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="' . $line . '" w:lineRule="auto"/>'
        . (!empty($options['keep']) ? '<w:keepNext/>' : '')
        . '</w:pPr>' . $runs . '</w:p>';
}

function poly_docx_borders(bool $visible): string
{
    $value = $visible ? 'single' : 'nil';
    $size = $visible ? '6' : '0';
    return '<w:tcBorders>'
        . '<w:top w:val="' . $value . '" w:sz="' . $size . '" w:color="000000"/>'
        . '<w:left w:val="' . $value . '" w:sz="' . $size . '" w:color="000000"/>'
        . '<w:bottom w:val="' . $value . '" w:sz="' . $size . '" w:color="000000"/>'
        . '<w:right w:val="' . $value . '" w:sz="' . $size . '" w:color="000000"/>'
        . '</w:tcBorders>';
}

function poly_docx_cell(string $content, int $width, bool $border = false, string $vertical = 'center'): string
{
    return '<w:tc><w:tcPr>'
        . '<w:tcW w:w="' . $width . '" w:type="dxa"/>'
        . '<w:vAlign w:val="' . poly_docx_xml($vertical) . '"/>'
        . '<w:tcMar><w:top w:w="50" w:type="dxa"/><w:left w:w="70" w:type="dxa"/>'
        . '<w:bottom w:w="50" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tcMar>'
        . poly_docx_borders($border)
        . '</w:tcPr>' . $content . '</w:tc>';
}

/**
 * @param array<int,array<int,string>> $rows
 * @param array<int,int> $widths
 * @param array{border?:bool,row_heights?:array<int,int>,cant_split?:bool} $options
 */
function poly_docx_table(array $rows, array $widths, array $options = []): string
{
    $total = array_sum($widths);
    $grid = '';
    foreach ($widths as $width) {
        $grid .= '<w:gridCol w:w="' . $width . '"/>';
    }

    $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . $total . '" w:type="dxa"/>'
        . '<w:tblLayout w:type="fixed"/><w:tblCellMar>'
        . '<w:top w:w="0" w:type="dxa"/><w:left w:w="0" w:type="dxa"/>'
        . '<w:bottom w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/>'
        . '</w:tblCellMar></w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>';

    foreach ($rows as $rowIndex => $cells) {
        $height = (int)($options['row_heights'][$rowIndex] ?? 0);
        $xml .= '<w:tr><w:trPr>'
            . ($height > 0 ? '<w:trHeight w:val="' . $height . '" w:hRule="atLeast"/>' : '')
            . (!empty($options['cant_split']) ? '<w:cantSplit/>' : '')
            . '</w:trPr>';
        foreach ($cells as $cellIndex => $content) {
            $xml .= poly_docx_cell(
                $content,
                $widths[$cellIndex],
                (bool)($options['border'] ?? false)
            );
        }
        $xml .= '</w:tr>';
    }
    return $xml . '</w:tbl>';
}

function poly_docx_year_course(?string $studyYear, ?string $program): string
{
    $yearMap = ['first' => 'F.Y.', 'second' => 'S.Y.', 'third' => 'T.Y.', 'final' => 'Final.'];
    $year = $yearMap[strtolower(trim((string)$studyYear))] ?? trim((string)$studyYear);
    $program = trim((string)$program);
    $normalized = strtolower($program);
    $course = '';
    $map = [
        'computer' => 'CO',
        'mechanical' => 'ME',
        'civil' => 'Civil',
        'information technology' => 'IF',
        'electronics' => 'E&TC',
        'electrical' => 'EE',
        'automobile' => 'AN',
    ];
    if ($program !== '' && preg_match('/^[A-Za-z&]{1,8}$/', $program)) {
        $course = strtoupper($program);
    } else {
        foreach ($map as $needle => $label) {
            if (str_contains($normalized, $needle)) {
                $course = $label;
                break;
            }
        }
    }
    return trim($year . $course);
}

function poly_docx_dob(?string $dob): string
{
    if ($dob === null || $dob === '' || $dob === '0000-00-00') {
        return '';
    }
    $timestamp = strtotime($dob);
    return $timestamp === false ? '' : date('d/m/Y', $timestamp);
}

/**
 * @param array<int,array<string,mixed>> $participants
 */
function poly_docx_page(
    string $game,
    string $event,
    string $academicYear,
    array $participants,
    bool $includeLogo,
    int $startingNumber = 1,
    bool $includeCertification = true
): string {
    $year = preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $matches)
        ? $matches[1] . '-' . substr($matches[2], -2)
        : $academicYear;

    $logo = $includeLogo
        ? poly_docx_paragraph(
            '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="900000" cy="900000"/><wp:docPr id="1" name="YTC Logo"/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="0" name="ytc-logo.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="900000" cy="900000"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>',
            ['align' => 'center', 'after' => 40]
        )
        . poly_docx_paragraph(poly_docx_run('NAAC B+', ['bold' => true, 'size' => 24, 'color' => '143370']), ['align' => 'center'])
        : '';

    $headerText = poly_docx_paragraph(poly_docx_run("Yashoda Shikshan Prasarak Mandal's", ['bold' => true, 'size' => 20]), ['align' => 'center'])
        . poly_docx_paragraph(poly_docx_run('YASHODA TECHNICAL CAMPUS, SATARA.', ['bold' => true, 'size' => 30, 'color' => '842B2B']), ['align' => 'center'])
        . poly_docx_paragraph(poly_docx_run('Faculty of Polytechnic,', ['bold' => true, 'size' => 23, 'color' => '842B2B']), ['align' => 'center'])
        . poly_docx_paragraph(poly_docx_run('NH-4, Wadhe Phata, Satara. Tele Fax:- 02162-271238/39/40', ['bold' => true, 'size' => 18]), ['align' => 'center'])
        . poly_docx_paragraph(poly_docx_run('Website- www.yes.edu.in   Email- principalengg_ytc@yes.edu.in', ['bold' => true, 'size' => 18]), ['align' => 'center'])
        . poly_docx_paragraph(poly_docx_run('AICTE- New Delhi. Govt. of Maharashtra (DTE), Mumbai', ['bold' => true, 'size' => 18]), ['align' => 'center'])
        . poly_docx_paragraph(poly_docx_run('Affiliated to DBATU Lonere, Shivaji University, Kolhapur & MSBTE, Mumbai', ['bold' => true, 'size' => 16]), ['align' => 'center']);

    $content = poly_docx_table([[
        $logo,
        $headerText,
        poly_docx_paragraph(poly_docx_run('Approved by', ['size' => 16, 'color' => '808080']), ['align' => 'center']),
    ]], [2100, 7500, 1320]);

    $content .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="8" w:space="1" w:color="1B303C"/></w:pBdr>'
        . '<w:spacing w:before="0" w:after="0"/></w:pPr></w:p>';

    $content .= poly_docx_table([[
        poly_docx_paragraph(poly_docx_run('Prof. Dasharath Sagare', ['bold' => true, 'size' => 20, 'color' => '842B2B']), ['align' => 'center'])
            . poly_docx_paragraph(poly_docx_run('Founder, President', ['bold' => true, 'size' => 19]), ['align' => 'center']),
        poly_docx_paragraph(poly_docx_run('Dr. Pravin Gavade', ['bold' => true, 'size' => 20, 'color' => '842B2B']), ['align' => 'center'])
            . poly_docx_paragraph(poly_docx_run('Principal', ['bold' => true, 'size' => 19]), ['align' => 'center']),
    ]], [5460, 5460]);

    $content .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="8" w:space="1" w:color="1B303C"/></w:pBdr>'
        . '<w:spacing w:before="0" w:after="50"/></w:pPr></w:p>';

    $content .= poly_docx_table([[
        poly_docx_paragraph(poly_docx_run("To,\nThe Principal,\n________________________\n________________________", ['bold' => true, 'size' => 20]), ['line' => 330]),
        poly_docx_paragraph(poly_docx_run('Date:- ______________', ['bold' => true, 'size' => 19]), ['align' => 'center']),
    ]], [6200, 4720], ['row_heights' => [0 => 1400]]);

    $content .= poly_docx_paragraph(poly_docx_run('Eligibility Form', ['bold' => true, 'underline' => true, 'size' => 28]), ['align' => 'center', 'before' => 70, 'after' => 130, 'keep' => true]);
    $content .= poly_docx_paragraph(poly_docx_run('Participating Institute: - Yashoda Technical Campus, Faculty of Polytechnic, Satara.', ['bold' => true, 'size' => 18]), ['align' => 'center', 'after' => 110]);

    $content .= poly_docx_table([
        [
            poly_docx_paragraph(poly_docx_run($event, ['bold' => true, 'size' => 18])),
            poly_docx_paragraph(poly_docx_run('Year:  ' . $year, ['bold' => true, 'size' => 18]), ['align' => 'center']),
        ],
        [poly_docx_paragraph(poly_docx_run('Name of Tournament (Event): - ' . $game, ['bold' => true, 'size' => 18])), poly_docx_paragraph('')],
        [poly_docx_paragraph(poly_docx_run('Venue: ________________________________', ['bold' => true, 'size' => 18])), poly_docx_paragraph('')],
        [
            poly_docx_paragraph(poly_docx_run('Name of Team Manager: ________________________', ['bold' => true, 'size' => 18])),
            poly_docx_paragraph(poly_docx_run('Date: ____________', ['bold' => true, 'size' => 18]), ['align' => 'center']),
        ],
        [poly_docx_paragraph(poly_docx_run('Status of Team Manager: ______________________', ['bold' => true, 'size' => 18])), poly_docx_paragraph('')],
    ], [7500, 3420], ['row_heights' => [0 => 310, 1 => 310, 2 => 310, 3 => 310, 4 => 310]]);

    // 10 columns — "Mobile No.", "Bank A/C Number" and "IFSC Code" were
    // inserted before "Signature of Participant"; existing widths trimmed
    // to keep the 10-column table inside the portrait page margins.
    $widths = [520, 1600, 950, 750, 1400, 1050, 950, 1350, 950, 700];
    $tableRows = [[
        poly_docx_paragraph(poly_docx_run("Sr.\nNo", ['bold' => true, 'size' => 15]), ['align' => 'center']),
        poly_docx_paragraph(poly_docx_run('Name of Participant', ['bold' => true, 'size' => 15]), ['align' => 'center']),
        poly_docx_paragraph(poly_docx_run("Year &\nCourse", ['bold' => true, 'size' => 15]), ['align' => 'center']),
        poly_docx_paragraph(poly_docx_run("Roll\nNo", ['bold' => true, 'size' => 15]), ['align' => 'center']),
        poly_docx_paragraph(poly_docx_run("Enrollment\nNumber", ['bold' => true, 'size' => 15]), ['align' => 'center']),
        poly_docx_paragraph(poly_docx_run("Date Of\nBirth", ['bold' => true, 'size' => 15]), ['align' => 'center']),
        poly_docx_paragraph(poly_docx_run("Mobile\nNo.", ['bold' => true, 'size' => 15]), ['align' => 'center']),
        poly_docx_paragraph(poly_docx_run("Bank A/C\nNumber", ['bold' => true, 'size' => 15]), ['align' => 'center']),
        poly_docx_paragraph(poly_docx_run("IFSC\nCode", ['bold' => true, 'size' => 15]), ['align' => 'center']),
        poly_docx_paragraph(poly_docx_run("Signature of\nParticipant", ['bold' => true, 'size' => 15]), ['align' => 'center']),
    ]];
    foreach ($participants as $index => $participant) {
        $tableRows[] = [
            poly_docx_paragraph(poly_docx_run((string)($startingNumber + $index), ['bold' => true, 'size' => 14]), ['align' => 'center']),
            poly_docx_paragraph(poly_docx_run(trim((string)($participant['full_name'] ?? '')), ['bold' => true, 'size' => 14])),
            poly_docx_paragraph(poly_docx_run(poly_docx_year_course($participant['study_year'] ?? null, $participant['program'] ?? null), ['bold' => true, 'size' => 14])),
            poly_docx_paragraph(poly_docx_run(trim((string)($participant['roll_no'] ?? '')), ['bold' => true, 'size' => 12]), ['align' => 'center']),
            poly_docx_paragraph(poly_docx_run(trim((string)($participant['enrollment_no'] ?? '')), ['bold' => true, 'size' => 12]), ['align' => 'center']),
            poly_docx_paragraph(poly_docx_run(poly_docx_dob($participant['dob'] ?? null), ['bold' => true, 'size' => 13]), ['align' => 'center']),
            poly_docx_paragraph(poly_docx_run(trim((string)($participant['mobile'] ?? '')), ['bold' => true, 'size' => 12]), ['align' => 'center']),
            poly_docx_paragraph(poly_docx_run(trim((string)($participant['bank_account_number'] ?? '')), ['bold' => true, 'size' => 12]), ['align' => 'center']),
            poly_docx_paragraph(poly_docx_run(strtoupper(trim((string)($participant['bank_ifsc'] ?? ''))), ['bold' => true, 'size' => 12]), ['align' => 'center']),
            poly_docx_paragraph(''),
        ];
    }
    $rowHeights = [0 => 560];
    for ($i = 1; $i < count($tableRows); $i++) {
        $rowHeights[$i] = 300;
    }
    $content .= poly_docx_table($tableRows, $widths, ['border' => true, 'row_heights' => $rowHeights, 'cant_split' => true]);

    if ($includeCertification) {
        $content .= poly_docx_paragraph(
            poly_docx_run('This is to certify that the above participants are eligible as per records of the Institute', ['bold' => true, 'size' => 18]),
            ['before' => 100, 'after' => 180]
        );
        $content .= poly_docx_table([[
            poly_docx_paragraph(poly_docx_run('Date: ____________', ['bold' => true, 'size' => 18]), ['align' => 'center']),
            poly_docx_paragraph(poly_docx_run('Seal of Institute', ['bold' => true, 'size' => 18]), ['align' => 'center']),
            poly_docx_paragraph(poly_docx_run('Sports Incharge', ['bold' => true, 'size' => 18]), ['align' => 'center']),
            poly_docx_paragraph(poly_docx_run('Principal', ['bold' => true, 'size' => 18]), ['align' => 'center']),
        ]], [2730, 2730, 2730, 2730]);
    }

    return $content;
}

/**
 * @param array<int,array<string,mixed>> $participants
 */
function build_polytechnic_eligibility_docx(
    string $game,
    string $event,
    string $academicYear,
    array $participants,
    string $logoPath
): string {
    $pages = array_chunk($participants, 16);
    if ($pages === []) {
        $pages = [[]];
    }

    $body = '';
    foreach ($pages as $index => $pageRows) {
        if ($index > 0) {
            $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        }
        $body .= poly_docx_page(
            $game,
            $event,
            $academicYear,
            $pageRows,
            is_file($logoPath),
            ($index * 16) + 1,
            $index === count($pages) - 1
        );
    }

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
        . '<w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="400" w:right="833" w:bottom="400" w:left="833" w:header="0" w:footer="0" w:gutter="0"/>'
        . '<w:cols w:space="720"/><w:docGrid w:linePitch="360"/></w:sectPr>'
        . '</w:body></w:document>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="png" ContentType="image/png"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/>'
        . '<w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="0"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/>'
        . '<w:qFormat/><w:pPr><w:spacing w:after="0"/></w:pPr></w:style></w:styles>';

    $zip = new SimpleDocxZip();
    $zip->add('[Content_Types].xml', $contentTypes);
    $zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>');
    $zip->add('word/document.xml', $document);
    $zip->add('word/styles.xml', $styles);
    $zip->add('word/settings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:zoom w:percent="90"/><w:doNotTrackMoves/><w:doNotTrackFormatting/>'
        . '<w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>'
        . '</w:settings>');
    $zip->add('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . (is_file($logoPath)
            ? '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
            : '')
        . '</Relationships>');
    if (is_file($logoPath)) {
        $logo = file_get_contents($logoPath);
        if ($logo !== false) {
            $zip->add('word/media/image1.png', $logo);
        }
    }
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $zip->add('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>Eligibility Form - ' . poly_docx_xml($game) . '</dc:title>'
        . '<dc:creator>Yashoda Technical Campus</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>');
    $zip->add('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
        . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Sports Portal</Application><AppVersion>1.0</AppVersion></Properties>');

    return $zip->finish();
}
