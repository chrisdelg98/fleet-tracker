<?php
/**
 * Front controller (AGENTS.md §Estructura). Punto de entrada único: arranca el bootstrap
 * y despacha la petición al enrutador. Los assets se sirven directos desde public/assets.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

$pdo    = db();
$router = new Router();

$usuarioModel = new UsuarioModel($pdo);
$authService = new AuthService($usuarioModel);
$authController = new AuthController($authService);

// ── Autenticación (web) ──
$router->get('/login',  fn() => $authController->showLogin());
$router->post('/login', fn() => $authController->login());
$router->post('/logout', fn() => $authController->logout());

// ── Cuenta propia ──
// Cambiar la contraseña no es administrar usuarios: cualquiera lo hace sobre la suya, sin rol.
$perfilController = new PerfilController(new PerfilService($pdo, $usuarioModel, $authService));
$router->get('/perfil/password',  fn() => $perfilController->passwordPage());
$router->post('/perfil/password', fn() => $perfilController->passwordUpdate());

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
// El remitente admite los dos nombres: MAIL_FROM es el del proyecto, MAIL_FROM_ADDRESS el que
// usa medio mundo por Laravel. Quien copia un .env de otro sitio escribe el segundo, y el
// correo se caía sin decir cuál faltaba. Primero el propio; el alias cubre el resto.
$primero = static fn(array $env, string ...$claves): string => (string) array_reduce(
    $claves,
    static fn(?string $llevado, string $clave): ?string => $llevado !== null && $llevado !== ''
        ? $llevado
        : trim((string) ($env[$clave] ?? '')),
    null
);
$correoService = new CorreoService([
    'host' => $env['MAIL_HOST'] ?? '',
    'port' => $env['MAIL_PORT'] ?? 25,
    'username' => $env['MAIL_USERNAME'] ?? '',
    'password' => $env['MAIL_PASSWORD'] ?? '',
    'from' => $primero($env, 'MAIL_FROM', 'MAIL_FROM_ADDRESS'),
    'from_name' => $primero($env, 'MAIL_FROM_NAME', 'APP_NAME') ?: 'Flete Finder',
    'encryption' => $env['MAIL_ENCRYPTION'] ?? '',
]);
$correoLogService = new CorreoLogService();
$notificacionService = new NotificacionService(
    $pdo,
    $suscripcionCorreoModel,
    $correoService,
    (string) ($env['APP_URL'] ?? 'http://localhost:8000'),
    $correoLogService
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

// ── Proveedores de transporte (catálogo compartido) ──
$proveedorModel = new ProveedorModel($pdo);
$proveedorCamionModel = new ProveedorCamionModel($pdo);
$proveedorService = new ProveedorService($pdo, $proveedorModel, $proveedorCamionModel);
$proveedorController = new ProveedorController($proveedorService, $proveedorModel);
$proveedorImportController = new ImportController(
    new ProveedorImportService($pdo, $proveedorService),
    'proveedores',
    'camión',
    'camiones'
);
$router->get('/proveedores', fn() => $proveedorController->index());
$router->get('/proveedores/plantilla.xlsx', fn() => $proveedorImportController->plantilla());
$router->post('/api/proveedores/importar', fn() => $proveedorImportController->importar());
$router->get('/api/proveedores/parecidos', fn() => $proveedorController->apiParecidos());
$router->post('/api/proveedores', fn() => $proveedorController->apiCreate());
$router->put('/api/proveedores/{id}', fn($p) => $proveedorController->apiUpdate($p));
$router->post('/api/proveedores/{id}/activo', fn($p) => $proveedorController->apiActivo($p));
$router->post('/api/proveedores/{id}/fusionar', fn($p) => $proveedorController->apiFusionar($p));
$router->post('/api/proveedores/{id}/camiones', fn($p) => $proveedorController->apiCamionCreate($p));
$router->get('/api/proveedor-camiones/{id}', fn($p) => $proveedorController->apiCamionShow($p));
$router->put('/api/proveedor-camiones/{id}', fn($p) => $proveedorController->apiCamionUpdate($p));
$router->post('/api/proveedor-camiones/{id}/activo', fn($p) => $proveedorController->apiCamionActivo($p));

// ── Mantenimientos (un acceso en el menú; sus secciones viven dentro del módulo) ──
$tallerModel = new TallerModel($pdo);
$mantenimientoModel = new MantenimientoModel($pdo);
$lecturaModel = new LecturaOdometroModel($pdo);
$tallerService = new TallerService($pdo, $tallerModel);
$mantenimientoService = new MantenimientoService($pdo, $mantenimientoModel, $lecturaModel, $unidadModel, $tallerService);
$mantenimientoController = new MantenimientoController(
    $mantenimientoService,
    $mantenimientoModel,
    $tallerService,
    $tallerModel,
    $catalogoModel,
    $unidadModel,
    new CatalogoAdminService($pdo)
);
$router->get('/mantenimientos', fn() => $mantenimientoController->control());
$router->get('/mantenimientos/historial', fn() => $mantenimientoController->historial());
$router->get('/mantenimientos/historial.csv', fn() => $mantenimientoController->historialCsv());
$router->get('/mantenimientos/costos', fn() => $mantenimientoController->costos());
$router->get('/mantenimientos/talleres', fn() => $mantenimientoController->talleresPage());
$router->get('/mantenimientos/configuracion', fn() => $mantenimientoController->configuracion());

// Los dos años que hoy viven en la hoja entran por aquí: el historial de intervenciones y las
// lecturas de odómetro van por separado porque son dos archivos distintos y se corrigen aparte.
$mantenimientoImportController = new ImportController(
    new MantenimientoImportService($pdo, $mantenimientoService, $unidadModel, $tallerModel, $catalogoModel),
    'mantenimientos',
    'mantenimiento',
    'mantenimientos'
);
$router->get('/mantenimientos/plantilla.xlsx', fn() => $mantenimientoImportController->plantilla());
$router->post('/api/mantenimientos/importar', fn() => $mantenimientoImportController->importar());

$lecturaImportController = new ImportController(
    new LecturaImportService($pdo, $mantenimientoService, $unidadModel),
    'kilometraje',
    'lectura',
    'lecturas',
    true
);
$router->get('/mantenimientos/kilometraje/plantilla.xlsx', fn() => $lecturaImportController->plantilla());
$router->post('/api/mantenimientos/kilometraje/importar', fn() => $lecturaImportController->importar());

$router->post('/api/mantenimientos', fn() => $mantenimientoController->apiCreate());
$router->get('/api/mantenimientos/{id}', fn($p) => $mantenimientoController->apiShow($p));
$router->put('/api/mantenimientos/{id}', fn($p) => $mantenimientoController->apiUpdate($p));
$router->delete('/api/mantenimientos/{id}', fn($p) => $mantenimientoController->apiDelete($p));
$router->post('/api/mantenimientos/lectura', fn() => $mantenimientoController->apiLectura());
$router->post('/api/mantenimientos/lecturas', fn() => $mantenimientoController->apiLecturas());

$router->post('/api/talleres', fn() => $mantenimientoController->apiTallerCreate());
$router->get('/api/talleres/{id}', fn($p) => $mantenimientoController->apiTallerShow($p));
$router->put('/api/talleres/{id}', fn($p) => $mantenimientoController->apiTallerUpdate($p));
$router->post('/api/talleres/{id}/activo', fn($p) => $mantenimientoController->apiTallerActivo($p));

$router->post('/api/mantenimientos/config/{tabla}', fn($p) => $mantenimientoController->apiConfigCreate($p));
$router->put('/api/mantenimientos/config/{tabla}/{id}', fn($p) => $mantenimientoController->apiConfigUpdate($p));
$router->post('/api/mantenimientos/config/{tabla}/{id}/activo', fn($p) => $mantenimientoController->apiConfigActivo($p));

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
    $catalogoModel,
    $correoLogService
);
$router->get('/admin', fn() => $adminController->index());
$router->get('/admin/estaciones', fn() => $adminController->estacionesPage());
$router->get('/admin/usuarios', fn() => $adminController->usuariosPage());
$router->get('/admin/catalogos', fn() => $adminController->catalogosPage());
$router->get('/admin/correos', fn() => $adminController->correosPage());

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
$movimientoTerceroModel = new MovimientoTerceroModel($pdo);
$movimientoService = new MovimientoService($pdo, $movimientoModel, $movimientoUnidadModel, $unidadModel, $rutaModel, $pilotoModel, $notificacionService, $movimientoTerceroModel, $proveedorService);
$overrideService = new OverrideService($pdo, $overrideModel, $unidadModel, $notificacionService);
$movimientoController = new MovimientoController($movimientoService, $movimientoModel, $overrideService, $movimientoTerceroModel, $proveedorService);
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
$router->get('/api/movimientos/terceros', fn() => $movimientoController->apiTerceros());
$router->get('/api/movimientos/{id}', fn($p) => $movimientoController->apiShow($p));
$router->put('/api/movimientos/{id}', fn($p) => $movimientoController->apiUpdate($p));
$router->post('/api/movimientos/{id}/confirmar', fn($p) => $movimientoController->apiConfirmar($p));
$router->post('/api/movimientos/{id}/salida', fn($p) => $movimientoController->apiSalida($p));
$router->post('/api/movimientos/{id}/llegada', fn($p) => $movimientoController->apiLlegada($p));
$router->post('/api/movimientos/{id}/cancelar', fn($p) => $movimientoController->apiCancelar($p));
$router->post('/api/movimientos/{id}/reprogramar-fin', fn($p) => $movimientoController->apiReprogramarFin($p));
$router->post('/api/movimientos/{id}/liberar/{unidad}', fn($p) => $movimientoController->apiLiberarApoyo($p));
$router->post('/api/movimientos/{id}/apartar-retorno', fn($p) => $movimientoController->apiApartarRetorno($p));
$router->post('/api/movimientos/{id}/reenviar-aviso', fn($p) => $movimientoController->apiReenviarAviso($p));
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
