<?php
/**
 * A small xlsx reader and writer: tables, dropdowns, and rich text.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param array<int, array{name: string, rows: array<int, array<int, mixed>>, validations?: array<int, array{sqref: string, formula: string}>, table?: bool}> $sheets
 */
function ops_xlsx_write(string $path, array $sheets): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not available.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The spreadsheet could not be created.');
    }
    $overrides = [
        '[Content_Types].xml',
        '_rels/.rels',
        'xl/workbook.xml',
        'xl/_rels/workbook.xml.rels',
        'xl/styles.xml',
    ];
    unset($overrides);
    $content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    foreach ($sheets as $index => $sheet) {
        $n = $index + 1;
        $safe = ops_xlsx_sheet_name($sheet['name']);
        $content .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbook .= '<sheet name="' . ops_xml($safe) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', ops_xlsx_sheet_xml($sheet, $n));
        if (!empty($sheet['table'])) {
            $content .= '<Override PartName="/xl/tables/table' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>';
            $zip->addFromString('xl/tables/table' . $n . '.xml', ops_xlsx_table_xml($sheet, $n));
            $zip->addFromString(
                'xl/worksheets/_rels/sheet' . $n . '.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rIdTable" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table' . $n . '.xml"/>'
                . '</Relationships>'
            );
        }
    }
    $content .= '</Types>';
    $workbook .= '</sheets></workbook>';
    $rels .= '</Relationships>';
    $zip->addFromString('[Content_Types].xml', $content);
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/styles.xml', ops_xlsx_styles());
    $zip->close();
}

/**
 * @return array<string, array<int, array<int, array{text: string, formula: string, runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}>>>
 */
function ops_xlsx_read(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not available.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('This spreadsheet could not be opened.');
    }
    $shared = ops_xlsx_shared_strings($zip);
    $workbook = ops_xlsx_xml($zip->getFromName('xl/workbook.xml'));
    $rels = ops_xlsx_xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $targets = [];
    if ($rels) {
        $rel_nodes = $rels->children('http://schemas.openxmlformats.org/package/2006/relationships');
        if (!count($rel_nodes)) {
            $rel_nodes = $rels->children();
        }
        foreach ($rel_nodes as $rel) {
            $attrs = $rel->attributes();
            $rel_id = (string) ($attrs['Id'] ?? $rel['Id'] ?? '');
            $rel_target = (string) ($attrs['Target'] ?? $rel['Target'] ?? '');
            if ($rel_id !== '' && $rel_target !== '') {
                $targets[$rel_id] = $rel_target;
            }
        }
    }
    $sheets = [];
    if ($workbook) {
        $sheet_nodes = $workbook->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheets->sheet ?? null;
        if (!$sheet_nodes || !count($sheet_nodes)) {
            $sheet_nodes = $workbook->sheets->sheet ?? [];
        }
        foreach ($sheet_nodes as $sheet) {
            $sattrs = $sheet->attributes();
            $name = (string) ($sattrs['name'] ?? $sheet['name'] ?? '');
            $id = '';
            foreach ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') as $attr_name => $attr_value) {
                if ($attr_name === 'id') {
                    $id = (string) $attr_value;
                }
            }
            $target = ltrim($targets[$id] ?? '', '/');
            if ($target === '') {
                continue;
            }
            if (!str_starts_with($target, 'xl/')) {
                $target = 'xl/' . $target;
            }
            $xml = ops_xlsx_xml($zip->getFromName($target));
            $sheets[$name] = $xml ? ops_xlsx_rows($xml, $shared) : [];
        }
    }
    $zip->close();
    return $sheets;
}

function ops_xlsx_sheet_name(string $name): string
{
    $name = str_replace(['\\', '/', '*', '?', ':', '[', ']'], ' ', $name);
    $name = trim($name);
    if ($name === '') {
        $name = 'Sheet';
    }
    return mb_substr($name, 0, 31, 'UTF-8');
}

/**
 * @param array{name: string, rows: array<int, array<int, mixed>>, validations?: array<int, array{sqref: string, formula: string}>, table?: bool} $sheet
 */
function ops_xlsx_sheet_xml(array $sheet, int $index): string
{
    $rows = $sheet['rows'];
    $width = 1;
    $height = max(1, count($rows));
    foreach ($rows as $row) {
        $width = max($width, count($row));
    }
    $ref = 'A1:' . ops_xlsx_col($width) . $height;
    $freeze = max(0, (int) ($sheet['freeze_columns'] ?? 0));
    if ($freeze > 0) {
        $pane = '<pane xSplit="' . $freeze . '" ySplit="1" topLeftCell="' . ops_xlsx_col($freeze + 1) . '2" activePane="bottomRight" state="frozen"/>';
    } else {
        $pane = '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
    }
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0">' . $pane . '</sheetView></sheetViews>';
    if (!empty($sheet['widths']) && is_array($sheet['widths'])) {
        $xml .= '<cols>';
        $hidden = [];
        foreach ($sheet['hidden_columns'] ?? [] as $column) {
            $hidden[(int) $column] = true;
        }
        foreach ($sheet['widths'] as $index => $col_width) {
            $number = (int) $index + 1;
            $xml .= '<col min="' . $number . '" max="' . $number . '" width="' . (float) $col_width . '" customWidth="1"' . (isset($hidden[$number]) ? ' hidden="1"' : '') . '/>';
        }
        $xml .= '</cols>';
    }
    $xml .= '<sheetData>';
    $row_height = (int) ($sheet['data_row_height'] ?? 0);
    foreach ($rows as $r => $row) {
        $line = $r + 1;
        $height_attr = ($row_height > 0 && $line > 1) ? ' ht="' . $row_height . '" customHeight="1"' : '';
        $xml .= '<row r="' . $line . '"' . $height_attr . '>';
        foreach ($row as $c => $cell) {
            $xml .= ops_xlsx_cell(ops_xlsx_col($c + 1) . $line, $cell, $line === 1);
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    if (!empty($sheet['validations'])) {
        $xml .= '<dataValidations count="' . count($sheet['validations']) . '">';
        foreach ($sheet['validations'] as $rule) {
            $error = empty($rule['strict']) ? ' showErrorMessage="0"' : ' showErrorMessage="1"';
            $xml .= '<dataValidation type="list" allowBlank="1"' . $error . ' sqref="' . ops_xml($rule['sqref']) . '"><formula1>' . ops_xml_formula($rule['formula']) . '</formula1></dataValidation>';
        }
        $xml .= '</dataValidations>';
    }
    if (!empty($sheet['table'])) {
        $xml .= '<tableParts count="1"><tablePart r:id="rIdTable"/></tableParts>';
    }
    $xml .= '</worksheet>';
    return $xml;
}

/**
 * @param array{rows: array<int, array<int, mixed>>} $sheet
 */
function ops_xlsx_table_xml(array $sheet, int $index): string
{
    $rows = $sheet['rows'];
    $width = 1;
    foreach ($rows as $row) {
        $width = max($width, count($row));
    }
    $height = max(1, count($rows));
    $ref = 'A1:' . ops_xlsx_col($width) . $height;
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="' . $index . '" name="Table' . $index . '" displayName="Table' . $index . '" ref="' . $ref . '" totalsRowCount="0">'
        . '<autoFilter ref="' . $ref . '"/>'
        . '<tableColumns count="' . $width . '">';
    $header = $rows[0] ?? [];
    for ($c = 0; $c < $width; $c++) {
        $name = isset($header[$c]) ? ops_cell_plain($header[$c]) : ('Column' . ($c + 1));
        if ($name === '') {
            $name = 'Column' . ($c + 1);
        }
        $xml .= '<tableColumn id="' . ($c + 1) . '" name="' . ops_xml($name) . '"/>';
    }
    $xml .= '</tableColumns><tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/></table>';
    return $xml;
}

/**
 * @param mixed $cell
 */
function ops_xlsx_cell(string $ref, $cell, bool $header): string
{
    if (is_array($cell) && isset($cell['formula'])) {
        return '<c r="' . $ref . '"><f>' . ops_xml_formula($cell['formula']) . '</f></c>';
    }
    $runs = is_array($cell) && isset($cell['runs']) ? $cell['runs'] : null;
    $text = is_array($cell) ? (string) ($cell['text'] ?? ops_cell_plain($cell)) : (string) $cell;
    if ($text === '' && !$runs) {
        return '';
    }
    $style = $header ? ' s="1"' : '';
    if ($runs && count($runs) > 1 || ($runs && (!empty($runs[0]['bold']) || !empty($runs[0]['italic']) || !empty($runs[0]['underline'])) && !$header)) {
        $inner = '';
        foreach ($runs as $run) {
            $props = '';
            if (!empty($run['bold'])) {
                $props .= '<b/>';
            }
            if (!empty($run['italic'])) {
                $props .= '<i/>';
            }
            if (!empty($run['underline'])) {
                $props .= '<u/>';
            }
            $inner .= '<r>' . ($props !== '' ? '<rPr>' . $props . '</rPr>' : '') . '<t xml:space="preserve">' . ops_xml($run['text']) . '</t></r>';
        }
        return '<c r="' . $ref . '" t="inlineStr"' . $style . '><is>' . $inner . '</is></c>';
    }
    return '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . ops_xml($text) . '</t></is></c>';
}

function ops_xlsx_styles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>';
}

function ops_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function ops_xlsx_col_index(string $ref): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '';
    $n = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $n = ($n * 26) + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

function ops_xml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function ops_xml_formula(string $text): string
{
    return htmlspecialchars($text, ENT_XML1, 'UTF-8');
}

/**
 * @param mixed $cell
 */
function ops_cell_plain($cell): string
{
    if (is_array($cell)) {
        if (isset($cell['runs']) && is_array($cell['runs'])) {
            $text = '';
            foreach ($cell['runs'] as $run) {
                $text .= (string) ($run['text'] ?? '');
            }
            return $text;
        }
        return (string) ($cell['text'] ?? '');
    }
    return (string) $cell;
}

function ops_xlsx_xml(string|false $xml): ?SimpleXMLElement
{
    if (!is_string($xml) || $xml === '') {
        return null;
    }
    $previous = libxml_use_internal_errors(true);
    $parsed = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $parsed instanceof SimpleXMLElement ? $parsed : null;
}

/**
 * @return array<int, array{text: string, runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}>
 */
function ops_xlsx_shared_strings(ZipArchive $zip): array
{
    $xml = ops_xlsx_xml($zip->getFromName('xl/sharedStrings.xml'));
    if (!$xml) {
        return [];
    }
    $items = [];
    foreach ($xml->children() as $si) {
        if ($si->getName() !== 'si') {
            continue;
        }
        $items[] = ops_xlsx_rich_element($si);
    }
    return $items;
}

/**
 * @param array<int, array{text: string, runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}> $shared
 * @return array<int, array<int, array{text: string, formula: string, runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}>>
 */
function ops_xlsx_rows(SimpleXMLElement $worksheet, array $shared): array
{
    $rows = [];
    $data = null;
    foreach ($worksheet->children() as $child) {
        if ($child->getName() === 'sheetData') {
            $data = $child;
            break;
        }
    }
    if (!$data) {
        return [];
    }
    foreach ($data->children() as $row) {
        if ($row->getName() !== 'row') {
            continue;
        }
        $line = max(1, (int) $row['r']);
        $cells = [];
        foreach ($row->children() as $cell) {
            if ($cell->getName() !== 'c') {
                continue;
            }
            $index = ops_xlsx_col_index((string) $cell['r']);
            $cells[$index] = ops_xlsx_read_cell($cell, $shared);
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $filled = [];
            for ($i = 0; $i <= $max; $i++) {
                $filled[$i] = $cells[$i] ?? ['text' => '', 'formula' => '', 'runs' => [ops_plain_run('')]];
            }
            $rows[$line - 1] = $filled;
        }
    }
    if (!$rows) {
        return [];
    }
    ksort($rows);
    $last = array_key_last($rows);
    $dense = [];
    for ($i = 0; $i <= $last; $i++) {
        $dense[] = $rows[$i] ?? [];
    }
    return $dense;
}

/**
 * @param array<int, array{text: string, runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}> $shared
 * @return array{text: string, formula: string, runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}
 */
function ops_xlsx_read_cell(SimpleXMLElement $cell, array $shared): array
{
    $formula = '';
    $type = (string) $cell['t'];
    foreach ($cell->children() as $child) {
        if ($child->getName() === 'f') {
            $formula = (string) $child;
        }
    }
    if ($type === 's') {
        $value = '';
        foreach ($cell->children() as $child) {
            if ($child->getName() === 'v') {
                $value = (string) $child;
            }
        }
        $item = $shared[(int) $value] ?? ['text' => '', 'runs' => [ops_plain_run('')]];
        $item['formula'] = $formula;
        return $item;
    }
    if ($type === 'inlineStr') {
        foreach ($cell->children() as $child) {
            if ($child->getName() === 'is') {
                $item = ops_xlsx_rich_element($child);
                $item['formula'] = $formula;
                return $item;
            }
        }
    }
    $value = '';
    foreach ($cell->children() as $child) {
        if ($child->getName() === 'v') {
            $value = (string) $child;
        }
    }
    return ['text' => $value, 'formula' => $formula, 'runs' => [ops_plain_run($value)]];
}

/**
 * @return array{text: string, runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}
 */
function ops_xlsx_rich_element(SimpleXMLElement $node): array
{
    $runs = [];
    foreach ($node->children() as $child) {
        if ($child->getName() === 't') {
            $runs[] = ops_plain_run((string) $child);
        }
        if ($child->getName() !== 'r') {
            continue;
        }
        $text = '';
        $bold = false;
        $italic = false;
        $underline = false;
        foreach ($child->children() as $part) {
            if ($part->getName() === 't') {
                $text .= (string) $part;
            }
            if ($part->getName() === 'rPr') {
                foreach ($part->children() as $prop) {
                    $prop_name = $part_name = $prop->getName();
                    $val = (string) $prop['val'];
                    $on = $val === '' || $val === '1' || $val === 'true' || $val === 'single';
                    if ($prop_name === 'b' && $on) {
                        $bold = true;
                    }
                    if ($prop_name === 'i' && $on) {
                        $italic = true;
                    }
                    if ($prop_name === 'u' && $on) {
                        $underline = true;
                    }
                }
            }
        }
        $runs[] = ['text' => $text, 'bold' => $bold, 'italic' => $italic, 'underline' => $underline];
    }
    if (!$runs) {
        $runs[] = ops_plain_run('');
    }
    $text = '';
    foreach ($runs as $run) {
        $text .= $run['text'];
    }
    return ['text' => $text, 'runs' => $runs];
}
