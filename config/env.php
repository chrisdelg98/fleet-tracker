<?php
/**
 * Cargador de .env — parser mínimo compartido por el runner de migraciones, el de
 * seeds y el bootstrap de la app. Sin dependencias externas.
 */

declare(strict_types=1);

/**
 * Lee un archivo .env y devuelve un arreglo asociativo clave => valor.
 * Ignora comentarios (# al inicio de línea o tras el valor) y comillas envolventes.
 */
function load_env(string $file): array
{
    if (!is_file($file)) {
        fwrite(STDERR, "No existe {$file} — copiar de .env.example y completar.\n");
        exit(1);
    }

    $vars = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$key, $val] = array_pad(explode('=', $line, 2), 2, '');
        $val = preg_replace('/\s+#.*$/', '', trim($val)); // comentario en línea
        $vars[trim($key)] = trim($val, "\"'");
    }

    return $vars;
}
