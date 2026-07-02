<?php
/**
 * Front controller (AGENTS.md §Estructura). Punto de entrada único: arranca el bootstrap
 * y despacha la petición al enrutador. Los assets se sirven directos desde public/assets.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

$pdo    = db();
$router = new Router();

$authController = new AuthController(new AuthService(new UsuarioModel($pdo)));

// ── Autenticación (web) ──
$router->get('/login',  fn() => $authController->showLogin());
$router->post('/login', fn() => $authController->login());
$router->post('/logout', fn() => $authController->logout());

// ── Pantalla protegida (redirige a /login sin sesión) ──
$router->get('/', function (): void {
    $usuario = require_login_web();
    render('dashboard', ['usuario' => $usuario]);
});

// ── API protegida ──
// Devuelve el usuario en sesión; sin sesión responde 401 (demuestra el guard de API).
$router->get('/api/me', function (): void {
    json_ok(require_login_api());
});

// Ruta de ESCRITURA reservada para Fase 1. El guard corre primero: sin sesión 401,
// rol sin permiso (ej. CONSULTA_BASICO) 403. El CRUD real llega en Fase 1.
$router->post('/api/unidades', function (): void {
    require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
    json_error('No implementado', 501, 'El CRUD de unidades se implementa en Fase 1.');
});

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
