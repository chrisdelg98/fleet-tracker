<?php
/**
 * Autenticación y autorización (AGENTS.md §Seguridad, plan §4/§10).
 * La seguridad vive en el backend: cada endpoint de escritura valida sesión + rol +
 * estación. Ocultar botones en la UI NO es seguridad.
 */

declare(strict_types=1);

/** Usuario autenticado (arreglo con id, nombre, email, rol, estacion_id) o null. */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

/**
 * Deja en sesión al usuario recién autenticado, regenerando el ID (anti fixation).
 * Solo se guardan datos no sensibles (nunca el hash de contraseña).
 */
function login_user(array $usuario): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'          => (int) $usuario['id'],
        'nombre'      => $usuario['nombre'],
        'email'       => $usuario['email'],
        'rol'         => $usuario['rol'],
        'estacion_id' => $usuario['estacion_id'] !== null ? (int) $usuario['estacion_id'] : null,
    ];
}

/** Cierra la sesión por completo. */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── Guards para rutas API (responden JSON) ──

/** Exige sesión; si no hay, corta con 401. Devuelve el usuario. */
function require_login_api(): array
{
    if (!is_logged_in()) {
        json_error('No autenticado', 401);
    }
    return current_user();
}

/** Exige que el rol esté entre los permitidos; si no, corta con 403. */
function require_role_api(array $rolesPermitidos): array
{
    $user = require_login_api();
    if (!in_array($user['rol'], $rolesPermitidos, true)) {
        json_error('No autorizado para esta acción', 403);
    }
    return $user;
}

/** Exige rol ADMIN_GLOBAL (administración del sistema: estaciones, usuarios, catálogos). */
function require_admin_api(): array
{
    return require_role_api([Rol::ADMIN_GLOBAL]);
}

/** True si el usuario puede escribir sobre recursos de la estación dada (plan §4). */
function can_write_station(array $user, ?int $estacionRecurso): bool
{
    if ($user['rol'] === Rol::ADMIN_GLOBAL) {
        return true;
    }
    return $estacionRecurso !== null && $user['estacion_id'] === $estacionRecurso;
}

/**
 * Exige que el usuario pueda escribir sobre un recurso de la estación dada.
 * ADMIN_GLOBAL escribe sobre cualquier estación; el resto solo sobre la suya (plan §4).
 */
function require_station_write_api(int $estacionRecurso): array
{
    $user = require_login_api();
    if ($user['rol'] === Rol::ADMIN_GLOBAL) {
        return $user;
    }
    if ($user['estacion_id'] === null || $user['estacion_id'] !== $estacionRecurso) {
        json_error('No autorizado sobre esta estación', 403);
    }
    return $user;
}

// ── Guard para rutas web (redirige al login) ──

/** Exige sesión en una pantalla; si no hay, redirige a /login. */
function require_login_web(): array
{
    if (!is_logged_in()) {
        header('Location: /login');
        exit;
    }
    return current_user();
}

// ── CSRF para formularios que hacen POST ──

/** Token CSRF de la sesión (se crea una vez por sesión). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Campo oculto listo para incrustar en un <form>. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Verifica el token recibido en un POST; comparación en tiempo constante. */
function csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}
