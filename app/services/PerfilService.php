<?php
/**
 * Lo que cada quien puede cambiar de su propia cuenta. Hoy, la contraseña.
 *
 * Va aparte de UsuarioService porque son dos cosas distintas: aquel administra cuentas ajenas y
 * es exclusivo del Admin Global; este lo usa cualquiera sobre la suya, y por eso nunca recibe
 * un id — trabaja siempre sobre el usuario de la sesión.
 */

declare(strict_types=1);

final class PerfilService
{
    public function __construct(
        private PDO $pdo,
        private UsuarioModel $usuarios,
        private AuthService $auth
    ) {
    }

    /**
     * Cambia la contraseña del usuario en sesión.
     *
     * Devuelve los errores por campo en vez de cortar la petición: esto lo usa una pantalla web
     * que tiene que volver a pintarse con lo escrito y el problema al lado del campo, no un API.
     *
     * @return array<string, string> campo => problema; vacío significa que ya se cambió.
     */
    public function cambiarPassword(array $user, string $actual, string $nueva, string $confirmacion): array
    {
        $errores = [];

        if ($actual === '') {
            $errores['actual'] = 'Escribe tu contraseña actual.';
        } elseif ($this->auth->attempt((string) $user['email'], $actual) === null) {
            // Se comprueba por el mismo camino del login: así una cuenta desactivada tampoco
            // puede cambiar su contraseña, sin tener que repetir esa regla aquí.
            $errores['actual'] = 'La contraseña actual no es correcta.';
        }

        if ($nueva === '') {
            $errores['nueva'] = 'Escribe la contraseña nueva.';
        } elseif (($faltan = password_faltantes($nueva)) !== []) {
            $errores['nueva'] = 'Le falta: ' . mb_strtolower(implode(', ', $faltan), 'UTF-8') . '.';
        } elseif ($nueva === $actual) {
            $errores['nueva'] = 'La contraseña nueva tiene que ser distinta de la actual.';
        }

        if ($confirmacion !== $nueva) {
            $errores['confirmacion'] = 'Las dos contraseñas no coinciden.';
        }

        if ($errores !== []) {
            return $errores;
        }

        $id = (int) $user['id'];
        tx($this->pdo, function () use ($id, $nueva, $user): void {
            $this->usuarios->actualizarPassword($id, password_hash($nueva, PASSWORD_DEFAULT));
            // En la bitácora queda el hecho y quién lo hizo; la contraseña no se registra nunca.
            registrar_bitacora($this->pdo, $user['id'], 'usuario', $id, AccionBitacora::EDITAR, [
                'despues' => ['password_cambiada' => true],
            ]);
        });

        return [];
    }
}
