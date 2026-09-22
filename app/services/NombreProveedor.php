<?php
/**
 * Cómo se escribe y cómo se reconoce un proveedor.
 *
 * Dos cosas distintas: el nombre que se muestra (mayúsculas, espacios limpios, la puntuación
 * como la escribió quien lo creó) y la clave con que se compara. La clave es la que evita que
 * «Transportes López, S.A. de C.V.» y «TRANSPORTES LOPEZ SA DE CV» sean dos proveedores.
 */

declare(strict_types=1);

final class NombreProveedor
{
    /**
     * Formas jurídicas que se quitan del final para comparar. Van ya sin puntuación, que es
     * como quedan después de limpiar: «S.A. de C.V.» llega aquí como «S A DE C V».
     */
    private const FORMAS_JURIDICAS = [
        'S A DE C V', 'SA DE CV', 'S A DE CV', 'SA DE C V',
        'S DE R L DE C V', 'S DE RL DE CV', 'S DE R L', 'S DE RL',
        'S A S', 'SAS', 'S A', 'SA', 'S R L', 'SRL', 'E I R L', 'EIRL',
        'LTDA', 'LTD', 'INC', 'CORP', 'CIA', 'LLC', 'Y CIA',
    ];

    /** Nombre para mostrar y guardar: mayúsculas y espacios limpios, sin tocar lo demás. */
    public static function mostrar(string $nombre): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $nombre)), 'UTF-8');
    }

    /**
     * Clave de comparación: sin tildes, sin puntuación, sin forma jurídica y sin espacios.
     *
     * Los espacios también se van: «TRANSPORTES LOPEZ» y «TRANSPORTESLOPEZ» son el mismo
     * proveedor escrito con prisa, no dos.
     */
    public static function clave(string $nombre): string
    {
        $texto = strtr(self::mostrar($nombre), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U', '&' => ' Y ',
        ]);
        $texto = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Z0-9]+/', ' ', $texto)));

        $sinForma = $texto;
        do {
            $antes = $sinForma;
            foreach (self::FORMAS_JURIDICAS as $forma) {
                if (str_ends_with($sinForma, ' ' . $forma)) {
                    $sinForma = rtrim(substr($sinForma, 0, -strlen($forma)));
                }
            }
        } while ($sinForma !== $antes);

        // Un nombre que era solo forma jurídica («S.A.») se queda con ella: vaciarlo haría que
        // cualquier otro nombre igual de raro chocara con este.
        $clave = str_replace(' ', '', $sinForma !== '' ? $sinForma : $texto);
        return $clave;
    }

    /**
     * ¿Se parecen lo suficiente para preguntar «¿es este?»?
     *
     * No decide nada: solo propone. Una letra de diferencia en un nombre corto, o unas pocas en
     * uno largo, o uno contenido en el otro («LOPEZ» dentro de «TRANSPORTESLOPEZ»).
     */
    public static function parecidas(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || $a === $b) {
            return false;
        }
        $corta = min(strlen($a), strlen($b));
        if ($corta >= 5 && (str_contains($a, $b) || str_contains($b, $a))) {
            return true;
        }
        $tolerancia = max(1, intdiv(max(strlen($a), strlen($b)), 8));
        return levenshtein($a, $b) <= $tolerancia;
    }

    /** Placa como se guarda: mayúsculas y sin espacios, que en una placa siempre son ruido. */
    public static function placa(string $placa): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $placa), 'UTF-8');
    }
}
