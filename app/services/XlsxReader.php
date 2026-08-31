<?php
/**
 * Lector mínimo de .xlsx (OOXML) sin dependencias. Devuelve la primera hoja como una matriz
 * de cadenas, respetando los huecos: si una fila trae A y C pero no B, B llega como ''.
 *
 * Cubre lo que produce Excel al guardar una plantilla: cadenas compartidas, cadenas en línea,
 * números, booleanos y resultados de fórmula. No interpreta fechas ni formatos: las columnas
 * de la carga masiva son texto y números enteros, así que no hace falta.
 */

declare(strict_types=1);

final class XlsxReader
{
    /**
     * @param  string $ruta Archivo .xlsx en disco.
     * @return array<int, array<int, string>> Filas indexadas por su número real en Excel menos 1.
     *         La clave NO se reindexa: si el usuario deja una fila en blanco a media hoja, los
     *         errores tienen que citar la fila que él ve, no la posición en el array.
     * @throws RuntimeException si el archivo no es un xlsx legible.
     */
    public static function leer(string $ruta): array
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('El archivo no es un Excel válido (.xlsx).');
        }

        try {
            $compartidas = self::cadenasCompartidas($zip);
            $hoja = self::xml($zip, self::rutaPrimeraHoja($zip));

            $filas = [];
            foreach ($hoja->sheetData->row as $row) {
                $indice = ((int) $row['r']) - 1;
                $celdas = [];
                foreach ($row->c as $c) {
                    $col = self::columnaDe((string) $c['r']);
                    $celdas[$col] = self::valor($c, $compartidas);
                }
                if ($celdas === []) {
                    continue;
                }
                // Relleno de huecos: quien consume espera una fila densa por índice de columna.
                $filas[$indice] = array_replace(array_fill(0, max(array_keys($celdas)) + 1, ''), $celdas);
            }
            ksort($filas);
            return $filas;
        } finally {
            $zip->close();
        }
    }

    /** Ruta interna de la primera hoja, siguiendo las relaciones del libro. */
    private static function rutaPrimeraHoja(ZipArchive $zip): string
    {
        $workbook = self::xml($zip, 'xl/workbook.xml');
        $primera = $workbook->sheets->sheet[0] ?? null;
        if ($primera === null) {
            throw new RuntimeException('El archivo Excel no tiene hojas.');
        }
        $rid = (string) $primera->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

        foreach (self::xml($zip, 'xl/_rels/workbook.xml.rels')->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $destino = (string) $rel['Target'];
                return str_starts_with($destino, '/')
                    ? ltrim($destino, '/')
                    : 'xl/' . ltrim($destino, './');
            }
        }
        throw new RuntimeException('No se encontró la primera hoja del Excel.');
    }

    /** @return string[] */
    private static function cadenasCompartidas(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $out = [];
        foreach (self::xml($zip, 'xl/sharedStrings.xml')->si as $si) {
            // Una cadena con formato mixto se parte en varios <r>: se concatenan sus <t>.
            $out[] = $si->t !== null && count($si->r) === 0
                ? (string) $si->t
                : implode('', array_map(static fn($r): string => (string) $r->t, iterator_to_array($si->r)));
        }
        return $out;
    }

    /** @param string[] $compartidas */
    private static function valor(SimpleXMLElement $c, array $compartidas): string
    {
        $tipo = (string) $c['t'];

        if ($tipo === 's') {
            return $compartidas[(int) $c->v] ?? '';
        }
        if ($tipo === 'inlineStr') {
            return trim((string) ($c->is->t ?? ''));
        }
        if ($tipo === 'b') {
            return ((string) $c->v) === '1' ? 'Sí' : 'No';
        }
        return trim((string) ($c->v ?? ''));
    }

    /** "BC12" → 54 (índice base 0 de la columna). */
    private static function columnaDe(string $ref): int
    {
        $letras = rtrim($ref, '0123456789');
        $n = 0;
        foreach (str_split($letras) as $letra) {
            $n = $n * 26 + (ord(strtoupper($letra)) - 64);
        }
        return $n - 1;
    }

    private static function xml(ZipArchive $zip, string $ruta): SimpleXMLElement
    {
        $contenido = $zip->getFromName($ruta);
        if ($contenido === false) {
            throw new RuntimeException("El Excel no contiene {$ruta}.");
        }
        $previo = libxml_use_internal_errors(true);
        // El archivo lo sube el usuario: sin red y sin sustituir entidades (XXE). En PHP 8 la
        // carga de entidades externas ya viene desactivada; LIBXML_NOENT la activaría.
        $xml = simplexml_load_string($contenido, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previo);
        if ($xml === false) {
            throw new RuntimeException('El Excel está dañado o no se puede leer.');
        }
        return $xml;
    }
}
