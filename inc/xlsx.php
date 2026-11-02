<?php
// Einfache Excel-Dateien (.xlsx) schreiben und lesen, ohne Zusatz-Bibliotheken.
// Braucht die PHP-Erweiterung "zip" (bei den meisten Hostern aktiv). Ohne sie wird CSV verwendet.

declare(strict_types=1);

function xlsx_available(): bool
{
    return class_exists('ZipArchive');
}

/** Spaltenbuchstaben: 0 → A, 26 → AA */
function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/**
 * Datei zum Herunterladen senden.
 * $rows: erste Zeile = Überschriften. $widths: optionale Spaltenbreiten (Zeichen).
 */
function xlsx_download(string $filename, string $sheetName, array $rows, array $widths = []): never
{
    if (!xlsx_available()) {
        csv_download(preg_replace('/\.xlsx$/', '.csv', $filename), $rows);
    }
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    if ($widths) {
        $sheet .= '<cols>';
        foreach ($widths as $i => $w) {
            $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
        }
        $sheet .= '</cols>';
    }
    $sheet .= '<sheetData>';
    foreach (array_values($rows) as $r => $row) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $v) {
            $ref = xlsx_col($c) . ($r + 1);
            $style = $r === 0 ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) {
                $sheet .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
            } elseif ($v !== null && $v !== '') {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $x(mb_substr(trim(str_replace(['\\', '/', '[', ']', ':', '*', '?'], '-', $sheetName)), 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCE8F3"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $z->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function csv_download(string $filename, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $r) {
        fputcsv($out, $r, ';');
    }
    exit;
}

/**
 * Hochgeladene Tabelle lesen (.xlsx oder .csv). Gibt Zeilen als Arrays von Strings zurück,
 * erste Zeile = Überschriften. Wirft RuntimeException mit verständlicher Meldung.
 */
function table_read(array $upload): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Bitte eine Datei auswählen.');
    }
    if ($upload['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Die Datei ist zu gross (max. 5 MB).');
    }
    $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return xlsx_read($upload['tmp_name']);
    }
    if ($ext === 'csv' || $ext === 'txt') {
        return csv_read($upload['tmp_name']);
    }
    throw new RuntimeException('Bitte eine Excel-Datei (.xlsx) oder CSV-Datei hochladen.');
}

function csv_read(string $path): array
{
    $raw = (string)file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');   // Excel unter Windows
    }
    $first = strtok($raw, "\n");
    $sep = substr_count((string)$first, ';') >= substr_count((string)$first, ',') ? ';' : ',';
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
        $rows[] = array_map(fn($v) => trim((string)$v), $r);
    }
    return $rows;
}

function xlsx_read(string $path): array
{
    if (!xlsx_available()) {
        throw new RuntimeException('Excel-Dateien können auf diesem Server nicht gelesen werden. Bitte in Excel «Speichern unter → CSV» wählen.');
    }
    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        throw new RuntimeException('Die Excel-Datei konnte nicht geöffnet werden.');
    }
    $strings = [];
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($ss, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($xml->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string)$si->t;
            } else {
                foreach ($si->r as $run) $t .= (string)$run->t;
            }
            $strings[] = $t;
        }
    }
    // erstes Tabellenblatt finden
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $z->getFromName('xl/workbook.xml');
    $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb && $rels) {
        $wbx = simplexml_load_string($wb, 'SimpleXMLElement', LIBXML_NONET);
        $first = $wbx->sheets->sheet[0] ?? null;
        $rid = $first ? (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
        $rx = simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($rx->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $t = ltrim((string)$rel['Target'], '/');
                $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
            }
        }
    }
    $data = $z->getFromName($sheetPath);
    $z->close();
    if ($data === false) {
        throw new RuntimeException('In der Excel-Datei wurde kein Tabellenblatt gefunden.');
    }
    $xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NONET);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $col = 0;
            foreach (str_split(preg_replace('/\d+/', '', (string)$c['r'])) as $ch) $col = $col * 26 + ord($ch) - 64;
            $type = (string)$c['t'];
            if ($type === 's') {
                $v = $strings[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = (string)($c->is->t ?? '');
            } else {
                $v = (string)$c->v;
                if (is_numeric($v) && floor((float)$v) == (float)$v && abs((float)$v) < 1e15) $v = (string)(int)$v;
            }
            $r[$col - 1] = trim($v);
        }
        if ($r) {
            $max = max(array_keys($r));
            $rows[] = array_map(fn($i) => $r[$i] ?? '', range(0, $max));
        } else {
            $rows[] = [];
        }
    }
    return $rows;
}

/** Spaltenindex anhand möglicher Überschriften finden (gross/klein egal). */
function col_index(array $header, array $names): ?int
{
    $norm = fn($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$s)));
    foreach ($header as $i => $h) {
        if (in_array($norm($h), array_map($norm, $names), true)) return $i;
    }
    return null;
}
