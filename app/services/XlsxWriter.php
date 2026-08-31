<?php
/**
 * Escritor mínimo de .xlsx (OOXML) sin dependencias: un xlsx es un ZIP de XML, y aquí solo
 * se necesita lo justo para una plantilla — encabezado fijo, anchos de columna y listas
 * desplegables. No pretende ser una librería general; cubre lo que usa la carga masiva.
 *
 * Se usan cadenas en línea (inlineStr) en vez de la tabla de sharedStrings: una pieza menos
 * que mantener sincronizada, a cambio de un archivo algo más grande. Para una plantilla sobra.
 */

declare(strict_types=1);

final class XlsxWriter
{
    /** @var array<int, array{nombre:string, filas:array, opciones:array}> */
    private array $hojas = [];

    /**
     * @param string $nombre   Nombre de la pestaña (sin espacios si se referencia en validaciones).
     * @param array  $filas    Lista de filas; cada fila es una lista de valores escalares o null.
     * @param array  $opciones anchos:int[], congelar:bool, validaciones:[['col'=>int,'origen'=>string]]
     */
    public function hoja(string $nombre, array $filas, array $opciones = []): self
    {
        $this->hojas[] = ['nombre' => $nombre, 'filas' => $filas, 'opciones' => $opciones];
        return $this;
    }

    /** Devuelve el contenido binario del .xlsx. */
    public function generar(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo Excel.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->relsRaiz());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relsWorkbook());
        $zip->addFromString('xl/styles.xml', $this->estilos());
        foreach ($this->hojas as $i => $hoja) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->worksheet($hoja));
        }
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    /** Índice de columna (1) → letra de Excel (A). Soporta más allá de la Z. */
    public static function letra(int $indice): string
    {
        $letra = '';
        while ($indice > 0) {
            $resto = ($indice - 1) % 26;
            $letra = chr(65 + $resto) . $letra;
            $indice = intdiv($indice - $resto - 1, 26);
        }
        return $letra;
    }

    // ── Piezas del paquete ──

    private function contentTypes(): string
    {
        $overrides = '';
        foreach ($this->hojas as $i => $_) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function relsRaiz(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->hojas as $i => $hoja) {
            $sheets .= '<sheet name="' . $this->esc($hoja['nombre']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    private function relsWorkbook(): string
    {
        $rels = '';
        foreach ($this->hojas as $i => $_) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . (count($this->hojas) + 1) . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    /** Dos estilos: 0 normal y 1 encabezado (blanco sobre el azul de la aplicación). */
    private function estilos(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1D4B75"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs></styleSheet>';
    }

    /** El orden de los elementos importa: Excel rechaza el archivo si se altera. */
    private function worksheet(array $hoja): string
    {
        $opciones = $hoja['opciones'];

        $vistas = !empty($opciones['congelar'])
            ? '<sheetViews><sheetView workbookViewId="0">'
              . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
              . '</sheetView></sheetViews>'
            : '';

        $cols = '';
        if (!empty($opciones['anchos'])) {
            $cols = '<cols>';
            foreach (array_values($opciones['anchos']) as $i => $ancho) {
                $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $ancho . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        $filas = '';
        foreach (array_values($hoja['filas']) as $y => $fila) {
            $celdas = '';
            foreach (array_values($fila) as $x => $valor) {
                $celdas .= $this->celda(self::letra($x + 1) . ($y + 1), $valor, $y === 0);
            }
            $filas .= '<row r="' . ($y + 1) . '">' . $celdas . '</row>';
        }

        // Listas desplegables: atajan la mayoría de los errores de tecleo antes de subir nada.
        $validaciones = '';
        if (!empty($opciones['validaciones'])) {
            $items = '';
            foreach ($opciones['validaciones'] as $val) {
                $col = self::letra((int) $val['col']);
                $items .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
                    . ' sqref="' . $col . '2:' . $col . '1000">'
                    . '<formula1>' . $this->esc($val['origen']) . '</formula1></dataValidation>';
            }
            $validaciones = '<dataValidations count="' . count($opciones['validaciones']) . '">' . $items . '</dataValidations>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $vistas . $cols
            . '<sheetData>' . $filas . '</sheetData>'
            . $validaciones
            . '</worksheet>';
    }

    private function celda(string $ref, mixed $valor, bool $encabezado): string
    {
        $estilo = $encabezado ? ' s="1"' : '';
        if ($valor === null || $valor === '') {
            return '<c r="' . $ref . '"' . $estilo . '/>';
        }
        if (is_int($valor) || is_float($valor)) {
            return '<c r="' . $ref . '"' . $estilo . '><v>' . $valor . '</v></c>';
        }
        return '<c r="' . $ref . '"' . $estilo . ' t="inlineStr"><is><t xml:space="preserve">'
            . $this->esc((string) $valor) . '</t></is></c>';
    }

    private function esc(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
