<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Classeur Excel (.xlsx) minimal, sans dépendance : l'image Docker n'a pas
 * l'extension zip, l'archive est donc écrite ici (compression deflate de zlib).
 *
 * Feuille : ['name' => 'Agents', 'rows' => [[cellule, …], …], 'widths' => [30, 12, …],
 *            'header' => n° de ligne (base 0) de l'en-tête du tableau (gras, filtre, ligne figée), ou null,
 *            'title' => nombre de lignes de titre en tête (première en gros caractères)]
 * Cellule : chaîne, entier ou flottant (numérique), null (vide).
 */
final class Xlsx
{
    /** Envoie le classeur au navigateur. */
    public static function send(string $filename, array $sheets): void
    {
        $data = self::build($sheets);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: private, no-store');
        echo $data;
    }

    /** Contenu binaire du fichier .xlsx. */
    public static function build(array $sheets): string
    {
        $files = [
            '[Content_Types].xml' => self::contentTypes(count($sheets)),
            '_rels/.rels'         => self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml'     => self::workbook($sheets),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(count($sheets)),
            'xl/styles.xml'       => self::STYLES,
        ];
        foreach (array_values($sheets) as $i => $sheet) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = self::sheet($sheet);
        }
        return self::zip($files);
    }

    private const XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    // Styles : 0 normal, 1 en-tête (blanc sur #273c88), 2 titre, 3 texte discret
    private const STYLES = self::XML . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FF273C88"/><name val="Calibri"/></font>'
        . '<font><sz val="10"/><color rgb="FF666666"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF273C88"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    private static function contentTypes(int $n): string
    {
        $sheets = '';
        for ($i = 1; $i <= $n; $i++) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return self::XML . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheets . '</Types>';
    }

    private static function workbook(array $sheets): string
    {
        $xml = '';
        $defined = '';
        foreach (array_values($sheets) as $i => $s) {
            // Nom de feuille : 31 caractères au plus, sans : \ / ? * [ ]
            $name = mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', (string) $s['name']), 0, 31);
            $xml .= '<sheet name="' . self::esc($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
            if (($range = self::filterRange($s)) !== null) {
                $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\''
                    . self::esc(str_replace("'", "''", $name)) . '\'!' . preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $range) . '</definedName>';
            }
        }
        return self::XML . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $xml . '</sheets>' . ($defined ? '<definedNames>' . $defined . '</definedNames>' : '') . '</workbook>';
    }

    private static function workbookRels(int $n): string
    {
        $xml = '';
        for ($i = 1; $i <= $n; $i++) {
            $xml .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $xml .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $xml . '</Relationships>';
    }

    /** Plage du filtre automatique (en-tête + données), ex. A5:J42. */
    private static function filterRange(array $s): ?string
    {
        $header = $s['header'] ?? null;
        if ($header === null || empty($s['rows'][$header])) {
            return null;
        }
        $cols = count($s['rows'][$header]);
        return 'A' . ($header + 1) . ':' . self::col($cols - 1) . max($header + 1, count($s['rows']));
    }

    private static function sheet(array $s): string
    {
        $header = $s['header'] ?? null;
        $titles = (int) ($s['title'] ?? 0);
        $xml = self::XML . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($header !== null) {
            // Lignes jusqu'à l'en-tête figées au défilement
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . ($header + 1) . '" topLeftCell="A' . ($header + 2)
                . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if (!empty($s['widths'])) {
            $xml .= '<cols>';
            foreach (array_values($s['widths']) as $i => $w) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach (array_values($s['rows']) as $r => $row) {
            $style = $r === $header ? 1 : ($r < $titles ? ($r === 0 ? 2 : 3) : 0);
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = self::col($c) . ($r + 1);
                $st = $style ? ' s="' . $style . '"' : '';
                $xml .= is_int($v) || is_float($v)
                    ? '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>'
                    : '<c r="' . $ref . '"' . $st . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if (($range = self::filterRange($s)) !== null) {
            $xml .= '<autoFilter ref="' . $range . '"/>';
        }
        return $xml . '</worksheet>';
    }

    /** Lettre de colonne (0 → A, 26 → AA). */
    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    /** Échappement XML, caractères de contrôle interdits retirés. */
    private static function esc(string $v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '';
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Archive ZIP (méthode deflate), noms en UTF-8. */
    private static function zip(array $files): string
    {
        $t = getdate();
        $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        $out = '';
        $central = '';
        foreach ($files as $name => $content) {
            $data = gzdeflate($content, 6);
            $head = pack('vvvvvVVVvv', 20, 0x0800, 8, $dosTime, $dosDate, crc32($content), strlen($data), strlen($content), strlen($name), 0);
            $central .= pack('V', 0x02014b50) . pack('v', 20) . $head . pack('vvvVV', 0, 0, 0, 0, strlen($out)) . $name;
            $out .= pack('V', 0x04034b50) . $head . $name . $data;
        }
        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
    }
}
