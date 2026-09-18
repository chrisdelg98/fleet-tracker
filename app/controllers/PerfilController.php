<?php
/**
 * La cuenta propia. Pantalla web (no API): en error se repinta el formulario con el problema
 * al lado de su campo, y en éxito se repinta con el aviso de que ya está cambiada.
 */

declare(strict_types=1);

final class PerfilController
{
    public function __construct(private PerfilService $perfil)
    {
    }

    /** GET /perfil/password — formulario de cambio de contraseña. */
    public function passwordPage(): void
    {
        $user = require_login_web();
        $this->render($user);
    }

    /** POST /perfil/password — valida y cambia la contraseña del usuario en sesión. */
    public function passwordUpdate(): void
    {
        $user = require_login_web();

        if (!csrf_valid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            $this->render($user, ['actual' => 'La sesión expiró. Vuelve a intentarlo.']);
            return;
        }

        $errores = $this->perfil->cambiarPassword(
            $user,
            (string) ($_POST['actual'] ?? ''),
            (string) ($_POST['nueva'] ?? ''),
            (string) ($_POST['confirmacion'] ?? '')
        );

        if ($errores !== []) {
            http_response_code(422);
            $this->render($user, $errores);
            return;
        }

        $this->render($user, [], true);
    }

    /**
     * Los campos nunca se repintan con lo escrito, ni siquiera tras un error: son contraseñas,
     * y quedarían en el HTML de una pantalla que puede verse por encima del hombro.
     *
     * @param array<string, string> $errores
     */
    private function render(array $user, array $errores = [], bool $cambiada = false): void
    {
        render('perfil/password', [
            'usuario'  => $user,
            'errores'  => $errores,
            'cambiada' => $cambiada,
            'reglas'   => password_reglas(),
        ], 'Cambiar contraseña · Flete Finder');
    }
}
