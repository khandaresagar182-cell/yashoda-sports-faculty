<?php
/**
 * Editable DOCX builder for the Engineering zonal/inter-zonal eligibility proforma.
 */

declare(strict_types=1);

require_once __DIR__ . '/polytechnic_eligibility_docx.php';

function eng_proforma_dob(?string $dob): string
{
    if ($dob === null || $dob === '' || $dob === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($dob);
    return $timestamp === false ? '' : date('d/m/Y', $timestamp);
}

function eng_proforma_class(?string $studyYear): string
{
    return [
        'first' => 'F.Y',
        'second' => 'S.Y',
        'third' => 'T.Y',
        'final' => 'Final Year',
    ][strtolower(trim((string)$studyYear))] ?? trim((string)$studyYear);
}

/**
 * @param array{span?:int,vmerge?:string,vertical?:string,padding?:int} $options
 */
function eng_proforma_cell(string $content, int $width, array $options = []): string
{
    $span = max(1, (int)($options['span'] ?? 1));
    $vertical = poly_docx_xml($options['vertical'] ?? 'center');
    $padding = max(0, (int)($options['padding'] ?? 45));
    $vmerge = '';
    if (isset($options['vmerge'])) {
        $vmerge = $options['vmerge'] === 'restart'
            ? '<w:vMerge w:val="restart"/>'
            : '<w:vMerge/>';
    }

    return '<w:tc><w:tcPr>'
        . '<w:tcW w:w="' . $width . '" w:type="dxa"/>'
        . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '')
        . $vmerge
        . '<w:vAlign w:val="' . $vertical . '"/>'
        . '<w:tcMar><w:top w:w="' . $padding . '" w:type="dxa"/>'
        . '<w:left w:w="' . $padding . '" w:type="dxa"/>'
        . '<w:bottom w:w="' . $padding . '" w:type="dxa"/>'
        . '<w:right w:w="' . $padding . '" w:type="dxa"/></w:tcMar>'
        . poly_docx_borders(true)
        . '</w:tcPr>' . $content . '</w:tc>';
}

/**
 * @param array<int,array<int,array{content:string,width:int,span?:int,vmerge?:string,vertical?:string,padding?:int}>> $rows
 * @param array<int,int> $gridWidths
 * @param array<int,int> $rowHeights
 */
function eng_proforma_table(array $rows, array $gridWidths, array $rowHeights): string
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
            $xml .= eng_proforma_cell($cell['content'], $cell['width'], $options);
        }
        $xml .= '</w:tr>';
    }

    return $xml . '</w:tbl>';
}

function eng_proforma_text(
    string $text,
    int $size = 16,
    bool $bold = false,
    string $align = 'center'
): string {
    return poly_docx_paragraph(
        poly_docx_run($text, ['bold' => $bold, 'size' => $size, 'font' => 'Times New Roman']),
        ['align' => $align, 'line' => 190]
    );
}

function eng_proforma_seal(): string
{
    return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="80" w:after="0"/></w:pPr>'
        . '<w:r><w:pict><v:oval style="width:58pt;height:58pt" fillcolor="white" strokecolor="black">'
        . '<v:stroke dashstyle="dash"/>'
        . '<v:textbox inset="0,0,0,0"><w:txbxContent><w:p><w:pPr>'
        . '<w:jc w:val="center"/><w:spacing w:before="300" w:after="0" w:line="180" w:lineRule="auto"/>'
        . '</w:pPr>' . poly_docx_run("Institute\nSeal", ['size' => 16, 'font' => 'Times New Roman'])
        . '</w:p></w:txbxContent></v:textbox></v:oval></w:pict></w:r></w:p>';
}

/**
 * @param array<int,array<string,mixed>> $participants
 */
function eng_proforma_page(string $game, string $event, array $participants, int $startingNumber): string
{
    $content = poly_docx_paragraph(
        poly_docx_run(
            'ELIGIBILITY PROFORMA FOR ZONAL /INTER ZONAL GAMES',
            ['bold' => true, 'size' => 28, 'font' => 'Times New Roman']
        ),
        ['align' => 'center', 'after' => 260, 'keep' => true]
    );

    $blank = '________________________________';
    $content .= poly_docx_table([[
        poly_docx_paragraph(
            poly_docx_run('Name of the Participating College - ', ['bold' => true, 'size' => 19])
            . poly_docx_run($blank, ['size' => 19]),
            ['line' => 230]
        ),
        poly_docx_paragraph(
            poly_docx_run('Zone - ', ['size' => 19])
            . poly_docx_run('Kolhapur', ['size' => 19]),
            ['align' => 'center', 'line' => 230]
        ),
        poly_docx_paragraph(
            poly_docx_run('Section - ', ['size' => 19])
            . poly_docx_run($event, ['size' => 19]),
            ['align' => 'center', 'line' => 230]
        ),
    ]], [9400, 3000, 3000], ['row_heights' => [0 => 350]]);

    $content .= poly_docx_table([[
        poly_docx_paragraph(
            poly_docx_run('Name of the Tournament - ', ['bold' => true, 'size' => 19])
            . poly_docx_run($game, ['size' => 19]),
            ['line' => 230]
        ),
        poly_docx_paragraph(
            poly_docx_run('Name of the Manager - ', ['bold' => true, 'size' => 19])
            . poly_docx_run('____________________', ['size' => 19]),
            ['line' => 230]
        ),
        poly_docx_paragraph(
            poly_docx_run('His/Her status - ', ['size' => 19])
            . poly_docx_run('____________________', ['size' => 19]),
            ['line' => 230]
        ),
    ]], [5000, 5000, 4400], ['row_heights' => [0 => 350]]);

    $content .= poly_docx_table([[
        poly_docx_paragraph(
            poly_docx_run('Name Of The Host Institute - ', ['bold' => true, 'size' => 19])
            . poly_docx_run('________________________________________', ['size' => 19]),
            ['line' => 230]
        ),
        poly_docx_paragraph(
            poly_docx_run('Date : ', ['size' => 19])
            . poly_docx_run('______________', ['size' => 19]),
            ['align' => 'center', 'line' => 230]
        ),
    ]], [11300, 3100], ['row_heights' => [0 => 400]]);

    $content .= poly_docx_paragraph('', ['after' => 150]);

    $widths = [520, 1450, 1000, 1020, 1450, 1080, 880, 880, 1080, 1025, 1025, 1020, 1020, 1260, 1120];
    $header = static fn(string $text): string => eng_proforma_text($text, 14, true);
    $subheader = static fn(string $text): string => eng_proforma_text($text, 14);
    $continue = static fn(int $width): array => [
        'content' => poly_docx_paragraph(''),
        'width' => $width,
        'vmerge' => 'continue',
    ];

    $rows = [
        [
            ['content' => $header("Sr\nNo"), 'width' => $widths[0], 'vmerge' => 'restart'],
            ['content' => $header("Name Of\nSports\nPerson"), 'width' => $widths[1], 'vmerge' => 'restart'],
            ['content' => $header("Father's\nName"), 'width' => $widths[2], 'vmerge' => 'restart'],
            ['content' => $header("Date of\nBirth"), 'width' => $widths[3], 'vmerge' => 'restart'],
            ['content' => $header('PRN. No'), 'width' => $widths[4], 'vmerge' => 'restart'],
            [
                'content' => $header("Date & Year of\nPassing\nqualifying\nExamination or\nFirst Admission\nto\nCollege/University"),
                'width' => $widths[5] + $widths[6],
                'span' => 2,
            ],
            ['content' => $header("Present\nClass"), 'width' => $widths[7], 'vmerge' => 'restart'],
            ['content' => $header("Name Of\nthe\nPresent\ncourse"), 'width' => $widths[8], 'vmerge' => 'restart'],
            [
                'content' => $header("Date & Year of\nFirst Admission to"),
                'width' => $widths[9] + $widths[10],
                'span' => 2,
            ],
            [
                'content' => $header("Name of years of\nprevious\nparticipation\nwhile pursuing"),
                'width' => $widths[11] + $widths[12],
                'span' => 2,
            ],
            ['content' => $header('Aadhar card No'), 'width' => $widths[13], 'vmerge' => 'restart'],
            ['content' => $header('Mobile No'), 'width' => $widths[14], 'vmerge' => 'restart'],
        ],
        [
            $continue($widths[0]),
            $continue($widths[1]),
            $continue($widths[2]),
            $continue($widths[3]),
            $continue($widths[4]),
            ['content' => $subheader("Name\nof\nExam"), 'width' => $widths[5]],
            ['content' => $subheader("Date &\nYear"), 'width' => $widths[6]],
            $continue($widths[7]),
            $continue($widths[8]),
            ['content' => $subheader('University'), 'width' => $widths[9]],
            ['content' => $subheader("Present\nCourse"), 'width' => $widths[10]],
            ['content' => $subheader("Graduate\ncourse"), 'width' => $widths[11]],
            ['content' => $subheader("PG\ncourse"), 'width' => $widths[12]],
            $continue($widths[13]),
            $continue($widths[14]),
        ],
    ];

    for ($slot = 0; $slot < 3; $slot++) {
        $participant = $participants[$slot] ?? [];
        $number = $participant === [] ? '' : (string)($startingNumber + $slot);
        $values = [
            $number,
            trim((string)($participant['full_name'] ?? '')),
            trim((string)($participant['mother_name'] ?? '')),
            eng_proforma_dob($participant['dob'] ?? null),
            trim((string)($participant['enrollment_no'] ?? '')),
            '',
            '',
            eng_proforma_class($participant['study_year'] ?? null),
            trim((string)($participant['program'] ?? '')),
            '',
            '',
            '',
            '',
            '',
            trim((string)($participant['mobile'] ?? '')),
        ];

        $row = [];
        foreach ($values as $index => $value) {
            $row[] = [
                'content' => eng_proforma_text($value, 16),
                'width' => $widths[$index],
                'padding' => 55,
            ];
        }
        $rows[] = $row;
    }

    $content .= eng_proforma_table($rows, $widths, [0 => 1050, 1 => 650, 2 => 1050, 3 => 1050, 4 => 1050]);
    $content .= poly_docx_paragraph(
        poly_docx_run(
            'Certified that above particulars are correct and true as per record of the University.',
            ['size' => 19]
        ),
        ['before' => 240, 'after' => 220]
    );
    $content .= poly_docx_paragraph(
        poly_docx_run('Certified that above players are not employed on full time basis.', ['size' => 19]),
        ['after' => 380]
    );

    $content .= poly_docx_table([[
        poly_docx_paragraph(
            poly_docx_run('Date :- ______________', ['size' => 18]),
            ['line' => 220]
        ) . eng_proforma_seal(),
        poly_docx_paragraph(
            poly_docx_run('Signature of the Director of Physical Education', ['bold' => true, 'size' => 19]),
            ['align' => 'center']
        ),
        poly_docx_paragraph(
            poly_docx_run('Signature of the Principal', ['bold' => true, 'size' => 19]),
            ['align' => 'center']
        ),
    ]], [4800, 5600, 4000], ['row_heights' => [0 => 900]]);

    return $content;
}

/**
 * @param array<int,array<string,mixed>> $participants
 */
function build_engineering_identity_card_docx(
    string $game,
    string $academicYear,
    array $participants,
    string $projectRoot,
    string $event = ''
): string {
    unset($academicYear, $projectRoot);
    $pages = array_chunk($participants, 3);
    if ($pages === []) {
        $pages = [[]];
    }

    $body = '';
    foreach ($pages as $pageIndex => $pageRows) {
        if ($pageIndex > 0) {
            $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        }
        $body .= eng_proforma_page($game, $event, $pageRows, ($pageIndex * 3) + 1);
    }

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:v="urn:schemas-microsoft-com:vml">'
        . '<w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
        . '<w:pgMar w:top="420" w:right="600" w:bottom="420" w:left="600" w:header="0" w:footer="0" w:gutter="0"/>'
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
        . '<w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="0"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/>'
        . '<w:qFormat/><w:pPr><w:spacing w:after="0"/></w:pPr></w:style></w:styles>');
    $zip->add('word/settings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:zoom w:percent="75"/><w:doNotTrackMoves/><w:doNotTrackFormatting/>'
        . '<w:compat><w:compatSetting w:name="compatibilityMode"'
        . ' w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>');
    $zip->add('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $zip->add('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>Eligibility Proforma - ' . poly_docx_xml($game) . '</dc:title>'
        . '<dc:creator>Sports Portal</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>');
    $zip->add('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
        . '<Application>Sports Portal</Application><AppVersion>1.0</AppVersion></Properties>');

    return $zip->finish();
}
