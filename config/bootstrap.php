<?php
/**
 * Bootstrap de la aplicación: carga configuración, enums, helpers y arranca la sesión.
 * Lo requiere el front controller (public/index.php) antes de despachar cualquier ruta.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/env.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/enums.php';

require_once BASE_PATH . '/app/helpers/response.php';
require_once BASE_PATH . '/app/helpers/dates.php';
require_once BASE_PATH . '/app/helpers/auth.php';
require_once BASE_PATH . '/app/helpers/bitacora.php';
require_once BASE_PATH . '/app/helpers/view.php';
require_once BASE_PATH . '/app/helpers/validation.php';
require_once BASE_PATH . '/app/helpers/db.php';
require_once BASE_PATH . '/app/helpers/paises.php';

// Autoloader simple para clases (controladores, servicios, modelos, Router).
spl_autoload_register(static function (string $class): void {
    foreach (['/app/controllers/', '/app/services/', '/app/models/', '/app/'] as $dir) {
        $file = BASE_PATH . $dir . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

$env = load_env(BASE_PATH . '/.env');

// El servidor PHP trabaja en UTC; la conversión a hora local es solo de presentación (plan §3.2).
date_default_timezone_set($env['APP_TIMEZONE'] ?? 'UTC');

// Errores visibles solo en local; en producción se registran sin exponerse.
$debug = ($env['APP_DEBUG'] ?? 'false') === 'true';
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

// Plataforma interna: nunca indexar en buscadores (refuerza robots.txt y la meta robots).
// Cubre también respuestas no-HTML (API JSON, exports CSV).
if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
}

// ── Sesión con cookie endurecida (AGENTS.md §Seguridad 5) ──
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => (int) ($env['SESSION_LIFETIME'] ?? 480) * 60,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => ($env['APP_ENV'] ?? 'local') === 'production',
    ]);
    session_name($env['SESSION_NAME'] ?? 'flota_session');
    session_start();
}
