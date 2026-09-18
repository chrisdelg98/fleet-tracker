<?php
/**
 * Reglas de la contraseña, en un solo sitio.
 *
 * Las valida el servidor y las pinta la pantalla de cambio a partir de esta misma lista: si lo
 * que se exige y lo que se explica vivieran separados, tarde o temprano dirían cosas distintas.
 *
 * El carácter especial se recomienda pero no se obliga: sube la fuerza de la contraseña, y
 * exigirlo empuja a la gente a rematar todo con un "!" —que no agrega nada— o a escribirla en
 * un papel. El largo es lo que más pesa, y ese sí es obligatorio.
 */

declare(strict_types=1);

const PASSWORD_LARGO_MINIMO = 8;

/**
 * Qué debe cumplir una contraseña, en el orden en que se le muestra a quien la escribe.
 *
 * @return list<array{clave: string, texto: string, obligatorio: bool}>
 */
function password_reglas(): array
{
    return [
        ['clave' => 'largo',     'texto' => 'Al menos ' . PASSWORD_LARGO_MINIMO . ' caracteres', 'obligatorio' => true],
        ['clave' => 'mayuscula', 'texto' => 'Una letra mayúscula',                               'obligatorio' => true],
        ['clave' => 'minuscula', 'texto' => 'Una letra minúscula',                               'obligatorio' => true],
        ['clave' => 'numero',    'texto' => 'Un número',                                         'obligatorio' => true],
        ['clave' => 'especial',  'texto' => 'Un carácter especial, como ! @ # $ %',              'obligatorio' => false],
    ];
}

/**
 * Qué reglas cumple una contraseña.
 *
 * @return array<string, bool> clave de la regla => si la cumple
 */
function password_cumple(string $password): array
{
    return [
        'largo'     => mb_strlen($password) >= PASSWORD_LARGO_MINIMO,
        'mayuscula' => preg_match('/\p{Lu}/u', $password) === 1,
        'minuscula' => preg_match('/\p{Ll}/u', $password) === 1,
        'numero'    => preg_match('/\p{Nd}/u', $password) === 1,
        'especial'  => preg_match('/[^\p{L}\p{Nd}]/u', $password) === 1,
    ];
}

/**
 * Lo que le falta a una contraseña para ser aceptable; vacío si ya lo es.
 *
 * @return list<string>
 */
function password_faltantes(string $password): array
{
    $cumple = password_cumple($password);
    $faltan = [];
    foreach (password_reglas() as $regla) {
        if ($regla['obligatorio'] && !$cumple[$regla['clave']]) {
            $faltan[] = $regla['texto'];
        }
    }
    return $faltan;
}
