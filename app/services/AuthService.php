<?php
/**
 * Reglas de autenticación (plan §10). Verifica credenciales con password_verify sin
 * revelar si el email existe o si la contraseña era la incorrecta (plan Fase 0, val. #3).
 */

declare(strict_types=1);

final class AuthService
{
    public function __construct(private UsuarioModel $usuarios)
    {
    }

    /**
     * Intenta autenticar. Devuelve la fila del usuario si las credenciales son válidas y
     * la cuenta está activa; null en cualquier otro caso (mismo resultado genérico).
     */
    public function attempt(string $email, string $password): ?array
    {
        $usuario = $this->usuarios->findByEmail($email);

        if ($usuario === null || (int) $usuario['activo'] !== 1) {
            // Ejecutar un hash igualmente para no filtrar la existencia por tiempo de respuesta.
            password_verify($password, '$2y$10$usuarioinexistenteusuarioinexistentexxxxxxxxxxxxxxxxxxxxx');
            return null;
        }

        if (!password_verify($password, $usuario['password_hash'])) {
            return null;
        }

        return $usuario;
    }
}
