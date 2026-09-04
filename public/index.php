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

// ── API protegida ──
// Devuelve el usuario en sesión; sin sesión responde 401 (demuestra el guard de API).
$router->get('/api/me', function (): void {
    json_ok(require_login_api());
});

// ── Flota / Unidades (Fase 1) ──
$unidadModel = new UnidadModel($pdo);
$catalogoModel = new CatalogoModel($pdo);
$overrideModel = new OverrideModel($pdo);
$suscripcionCorreoModel = new SuscripcionCorreoModel($pdo);
$correoService = new CorreoService([
    'host' => $env['MAIL_HOST'] ?? '',
    'port' => $env['MAIL_PORT'] ?? 25,
    'username' => $env['MAIL_USERNAME'] ?? '',
    'password' => $env['MAIL_PASSWORD'] ?? '',
    'from' => $env['MAIL_FROM'] ?? '',
    'from_name' => $env['APP_NAME'] ?? 'Disponibilidad de Flota',
    'encryption' => $env['MAIL_ENCRYPTION'] ?? '',
]);
$notificacionService = new NotificacionService(
    $pdo,
    $suscripcionCorreoModel,
    $correoService,
    (string) ($env['APP_URL'] ?? 'http://localhost:8000')
);
$unidadService = new UnidadService($pdo, $unidadModel, $overrideModel, $catalogoModel, $notificacionService);
$unidadController = new UnidadController($pdo, $unidadService, $unidadModel, $catalogoModel);
$flotaImportController = new ImportController(
    new FlotaImportService($pdo, $unidadService, $unidadModel, $overrideModel, $catalogoModel),
    'flota',
    'unidad',
    'unidades',
    true
);

$router->get('/flota', fn() => $unidadController->index());
$router->get('/api/unidades', fn() => $unidadController->apiList());
$router->post('/api/unidades', fn() => $unidadController->apiCreate());
$router->get('/api/unidades/{id}', fn($p) => $unidadController->apiShow($p));
$router->put('/api/unidades/{id}', fn($p) => $unidadController->apiUpdate($p));
$router->post('/api/unidades/{id}/estado', fn($p) => $unidadController->apiEstado($p));
$router->delete('/api/unidades/{id}', fn($p) => $unidadController->apiDelete($p));

// Carga masiva: la plantilla se descarga con los catálogos vigentes y la subida va en dos
// pasos (analizar, luego confirmar) para no escribir nada hasta que el archivo esté limpio.
$router->get('/flota/plantilla.xlsx', fn() => $flotaImportController->plantilla());
$router->post('/api/flota/importar', fn() => $flotaImportController->importar());

// ── Pilotos (Fase 1) ──
$pilotoModel = new PilotoModel($pdo);
$pilotoService = new PilotoService($pdo, $pilotoModel, $catalogoModel);
$pilotoController = new PilotoController($pilotoService, $pilotoModel, $catalogoModel);
$pilotoImportController = new ImportController(
    new PilotoImportService($pdo, $pilotoService, $pilotoModel, $catalogoModel),
    'pilotos',
    'piloto',
    'pilotos'
);

$listaNotificacionModel = new ListaNotificacionModel($pdo);
$contactoController = new ListaNotificacionController(
    new ListaNotificacionService($pdo, $listaNotificacionModel),
    $listaNotificacionModel,
    $catalogoModel
);
$router->get('/contactos', fn() => $contactoController->index());
$router->get('/api/contactos/{id}', fn($p) => $contactoController->apiShow($p));
$router->post('/api/contactos', fn() => $contactoController->apiCreate());
$router->put('/api/contactos/{id}', fn($p) => $contactoController->apiUpdate($p));
$router->delete('/api/contactos/{id}', fn($p) => $contactoController->apiDelete($p));

$router->get('/pilotos', fn() => $pilotoController->index());
$router->get('/pilotos/plantilla.xlsx', fn() => $pilotoImportController->plantilla());
$router->post('/api/pilotos/importar', fn() => $pilotoImportController->importar());
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

// ── Administración (solo Admin Global) ──
$estacionModel = new EstacionModel($pdo);
$usuarioModel = new UsuarioModel($pdo);
$adminController = new AdminController(
    new EstacionService($pdo, $estacionModel),
    $estacionModel,
    new UsuarioService($pdo, $usuarioModel, $estacionModel),
    $usuarioModel,
    new CatalogoAdminService($pdo),
    $catalogoModel
);
$router->get('/admin', fn() => $adminController->index());
$router->get('/admin/estaciones', fn() => $adminController->estacionesPage());
$router->get('/admin/usuarios', fn() => $adminController->usuariosPage());
$router->get('/admin/catalogos', fn() => $adminController->catalogosPage());

$router->post('/api/estaciones', fn() => $adminController->estacionCreate());
$router->get('/api/estaciones/{id}', fn($p) => $adminController->estacionShow($p));
$router->put('/api/estaciones/{id}', fn($p) => $adminController->estacionUpdate($p));
$router->post('/api/estaciones/{id}/activo', fn($p) => $adminController->estacionActivo($p));

$router->post('/api/usuarios', fn() => $adminController->usuarioCreate());
$router->get('/api/usuarios/{id}', fn($p) => $adminController->usuarioShow($p));
$router->put('/api/usuarios/{id}', fn($p) => $adminController->usuarioUpdate($p));
$router->post('/api/usuarios/{id}/activo', fn($p) => $adminController->usuarioActivo($p));

$router->post('/api/catalogos/{tabla}', fn($p) => $adminController->catalogoCreate($p));
$router->put('/api/catalogos/{tabla}/{id}', fn($p) => $adminController->catalogoUpdate($p));
$router->post('/api/catalogos/{tabla}/{id}/activo', fn($p) => $adminController->catalogoActivo($p));

// ── Fase 2: Motor de disponibilidad (movimientos, dashboard, overrides) ──
$movimientoModel = new MovimientoModel($pdo);
$movimientoUnidadModel = new MovimientoUnidadModel($pdo);
$movimientoUnidadModel = new MovimientoUnidadModel($pdo);
$movimientoService = new MovimientoService($pdo, $movimientoModel, $movimientoUnidadModel, $unidadModel, $rutaModel, $pilotoModel, $notificacionService);
$overrideService = new OverrideService($pdo, $overrideModel, $unidadModel, $notificacionService);
$movimientoController = new MovimientoController($movimientoService, $movimientoModel, $overrideService);
$disponibilidadController = new DisponibilidadController(
    new DisponibilidadService($pdo),
    $catalogoModel,
    $unidadModel,
    $rutaModel,
    $pilotoModel,
    new ListaNotificacionService($pdo, new ListaNotificacionModel($pdo))
);

// Dashboard (visible para todos los roles) + endpoint de disponibilidad
$router->get('/', fn() => $disponibilidadController->dashboard());
$router->get('/live', fn() => $disponibilidadController->live());
$router->get('/api/disponibilidad', fn() => $disponibilidadController->apiDisponibilidad());

// Movimientos y máquina de estados
$router->post('/api/movimientos', fn() => $movimientoController->apiCreate());
$router->get('/api/movimientos/conflictos', fn() => $movimientoController->apiConflictos());
$router->get('/api/movimientos/{id}', fn($p) => $movimientoController->apiShow($p));
$router->put('/api/movimientos/{id}', fn($p) => $movimientoController->apiUpdate($p));
$router->post('/api/movimientos/{id}/confirmar', fn($p) => $movimientoController->apiConfirmar($p));
$router->post('/api/movimientos/{id}/salida', fn($p) => $movimientoController->apiSalida($p));
$router->post('/api/movimientos/{id}/llegada', fn($p) => $movimientoController->apiLlegada($p));
$router->post('/api/movimientos/{id}/cancelar', fn($p) => $movimientoController->apiCancelar($p));
$router->post('/api/movimientos/{id}/reprogramar-fin', fn($p) => $movimientoController->apiReprogramarFin($p));
$router->post('/api/movimientos/{id}/liberar/{unidad}', fn($p) => $movimientoController->apiLiberarApoyo($p));
$router->post('/api/movimientos/{id}/apartar-retorno', fn($p) => $movimientoController->apiApartarRetorno($p));
$router->get('/api/unidades/{id}/movimientos', fn($p) => $movimientoController->apiPorUnidad($p));

// Overrides manuales (bloquear/desbloquear)
$router->post('/api/unidades/{id}/bloquear', fn($p) => $movimientoController->apiBloquear($p));
$router->post('/api/unidades/{id}/desbloquear', fn($p) => $movimientoController->apiDesbloquear($p));

// ── Fase 3: Inventario (alcance por rol) ──
$inventarioController = new InventarioController(
    new InventarioService($pdo),
    $catalogoModel,
    new UnidadEstadisticasService($pdo, $unidadModel)
);
$router->get('/inventario', fn() => $inventarioController->index());
$router->get('/inventario/export.xlsx', fn() => $inventarioController->exportExcel());
$router->get('/api/unidades/{id}/estadisticas', fn($p) => $inventarioController->apiEstadisticas($p));

// ── Fase 3: Histórico ──
$historicoController = new HistoricoController(new HistoricoService($pdo), $usuarioModel, $catalogoModel);
$router->get('/historico', fn() => $historicoController->index());
$router->get('/historico/sistema', fn() => $historicoController->sistema());
$router->get('/historico/export.csv', fn() => $historicoController->export());

// ── Fase 3: Timeline por unidad ──
$timelineController = new TimelineController($pdo, $catalogoModel);
$router->get('/timeline', fn() => $timelineController->index());

// ── Fase 4: Inteligencia (reportes + notificaciones) ──
$inteligenciaController = new InteligenciaController(
    new InteligenciaService($pdo, $catalogoModel, $estacionModel, $suscripcionCorreoModel, $notificacionService)
);
$router->get('/inteligencia', fn() => $inteligenciaController->index());
$router->post('/inteligencia/suscripciones', fn() => $inteligenciaController->crearSuscripcion());
$router->post('/inteligencia/suscripciones/{id}/eliminar', fn($p) => $inteligenciaController->eliminarSuscripcion($p));
$router->post('/inteligencia/suscripciones/{id}/probar', fn($p) => $inteligenciaController->probarSuscripcion($p));

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
