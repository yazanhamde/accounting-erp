<?php
/**
 * كاتب XLSX خفيف بدون أي مكتبة خارجية (بيستخدم فقط ZipArchive المدمجة بـ PHP)
 * يدعم عدة شيتات (Sheets)، ودعم النص العربي RTL كامل.
 * الاستخدام:
 *   $xlsx = new SimpleXlsxWriter();
 *   $xlsx->addSheet('اسم الشيت', $headers, $rows);
 *   $xlsx->output('اسم-الملف.xlsx');
 */
class SimpleXlsxWriter {
    private $sheets = []; // كل عنصر: ['name'=>.., 'headers'=>.., 'rows'=>..]

    public function addSheet($name, $headers, $rows) {
        $this->sheets[] = ['name' => $this->sanitizeSheetName($name), 'headers' => $headers, 'rows' => $rows];
    }

    private function sanitizeSheetName($name) {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name);
        return mb_substr($name, 0, 31);
    }

    private function esc($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function colLetter($index) {
        // index بيبدأ من 0
        $letters = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $index = intval(($index - $mod) / 26);
        }
        return $letters;
    }

    private function buildSheetXml($sheet) {
        $rowsXml = '';
        $rowIndex = 1;

        // صف العناوين
        $cellsXml = '';
        foreach ($sheet['headers'] as $ci => $h) {
            $ref = $this->colLetter($ci) . $rowIndex;
            $cellsXml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . $this->esc($h) . '</t></is></c>';
        }
        $rowsXml .= '<row r="' . $rowIndex . '">' . $cellsXml . '</row>';
        $rowIndex++;

        foreach ($sheet['rows'] as $row) {
            $cellsXml = '';
            foreach (array_values($row) as $ci => $val) {
                $ref = $this->colLetter($ci) . $rowIndex;
                if (is_numeric($val)) {
                    $cellsXml .= '<c r="' . $ref . '"><v>' . $this->esc($val) . '</v></c>';
                } else {
                    $cellsXml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $this->esc($val) . '</t></is></c>';
                }
            }
            $rowsXml .= '<row r="' . $rowIndex . '">' . $cellsXml . '</row>';
            $rowIndex++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews>' .
            '<sheetData>' . $rowsXml . '</sheetData>' .
            '</worksheet>';
    }

    public function output($filename) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmpFile, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($this->sheets))) .
            '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>');

        $sheetEntries = '';
        $relEntries = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheetEntries .= '<sheet name="' . $this->esc($sheet['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
            $relEntries .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' . $sheetEntries . '</sheets>' .
            '</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relEntries . '</Relationships>');

        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' .
            '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
            '<borders count="1"><border/></borders>' .
            '<cellStyleXfs count="1"><xf/></cellStyleXfs>' .
            '<cellXfs count="2"><xf fontId="0"/><xf fontId="1"/></cellXfs>' .
            '</styleSheet>');

        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->buildSheetXml($sheet));
        }

        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
        unlink($tmpFile);
    }
}
