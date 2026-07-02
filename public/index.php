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

// ── Flota / Unidades (Fase 1) ──
$unidadModel = new UnidadModel($pdo);
$catalogoModel = new CatalogoModel($pdo);
$unidadController = new UnidadController(
    $pdo,
    new UnidadService($pdo, $unidadModel, new OverrideModel($pdo), $catalogoModel),
    $unidadModel,
    $catalogoModel
);

$router->get('/flota', fn() => $unidadController->index());
$router->get('/api/unidades', fn() => $unidadController->apiList());
$router->post('/api/unidades', fn() => $unidadController->apiCreate());
$router->get('/api/unidades/{id}', fn($p) => $unidadController->apiShow($p));
$router->put('/api/unidades/{id}', fn($p) => $unidadController->apiUpdate($p));
$router->post('/api/unidades/{id}/estado', fn($p) => $unidadController->apiEstado($p));
$router->delete('/api/unidades/{id}', fn($p) => $unidadController->apiDelete($p));

// ── Pilotos (Fase 1) ──
$pilotoModel = new PilotoModel($pdo);
$pilotoController = new PilotoController(
    new PilotoService($pdo, $pilotoModel, $catalogoModel),
    $pilotoModel,
    $catalogoModel
);
$router->get('/pilotos', fn() => $pilotoController->index());
$router->post('/api/pilotos', fn() => $pilotoController->apiCreate());
$router->get('/api/pilotos/{id}', fn($p) => $pilotoController->apiShow($p));
$router->put('/api/pilotos/{id}', fn($p) => $pilotoController->apiUpdate($p));
$router->delete('/api/pilotos/{id}', fn($p) => $pilotoController->apiDelete($p));

// ── Rutas (Fase 1) ──
$rutaModel = new RutaModel($pdo);
$rutaController = new RutaController(new RutaService($pdo, $rutaModel), $rutaModel);
$router->get('/rutas', fn() => $rutaController->index());
$router->post('/api/rutas', fn() => $rutaController->apiCreate());
$router->get('/api/rutas/{id}', fn($p) => $rutaController->apiShow($p));
$router->put('/api/rutas/{id}', fn($p) => $rutaController->apiUpdate($p));
$router->delete('/api/rutas/{id}', fn($p) => $rutaController->apiDelete($p));

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
