<?php
/**
 * Login / logout (plan §10). Pantallas web: en éxito redirige, en error re-renderiza el
 * formulario con un mensaje genérico (no revela si el usuario existe — Fase 0 val. #3).
 */

declare(strict_types=1);

final class AuthController
{
    public function __construct(private AuthService $auth)
    {
    }

    /** GET /login — muestra el formulario (o redirige al inicio si ya hay sesión). */
    public function showLogin(): void
    {
        if (is_logged_in()) {
            header('Location: /');
            exit;
        }
        render('auth/login', ['error' => null], 'Ingresar · Disponibilidad de Flota');
    }

    /** POST /login — valida credenciales y crea la sesión. */
    public function login(): void
    {
        if (!csrf_valid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            render('auth/login', ['error' => 'La sesión expiró. Intenta de nuevo.'], 'Ingresar · Disponibilidad de Flota');
            return;
        }

        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $usuario = $email !== '' && $password !== '' ? $this->auth->attempt($email, $password) : null;

        if ($usuario === null) {
            http_response_code(401);
            render('auth/login', ['error' => 'Credenciales inválidas.', 'email' => $email], 'Ingresar · Disponibilidad de Flota');
            return;
        }

        login_user($usuario);
        header('Location: /');
    }

    /** POST /logout — destruye la sesión. */
    public function logout(): void
    {
        if (csrf_valid($_POST['_csrf'] ?? null)) {
            logout_user();
        }
        header('Location: /login');
    }
}
