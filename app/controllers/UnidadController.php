<?php
/**
 * Unidades / Flota (plan §7.2). Página server-rendered + API JSON que consume el JS del
 * módulo. La autorización por rol se valida aquí; la de estación, en el servicio (plan §4).
 */

declare(strict_types=1);

final class UnidadController
{
    public function __construct(
        private PDO $pdo,
        private UnidadService $service,
        private UnidadModel $unidades,
        private CatalogoModel $catalogos
    ) {
    }

    /** GET /flota — pantalla de gestión de flota. */
    public function index(): void
    {
        $user = require_login_web();

        $verTodas = $user['rol'] === Rol::ADMIN_GLOBAL;
        $filtros = [
            'estacion_id'       => $verTodas ? (string) ($_GET['estacion_id'] ?? '') : '',
            'categoria_id'      => $_GET['categoria_id'] ?? '',
            'tipo_equipo_id'    => $_GET['tipo_equipo_id'] ?? '',
            'estado_vehiculo'   => $_GET['estado_vehiculo'] ?? '',
            'en_disponibilidad' => $_GET['en_disponibilidad'] ?? '',
            'q'                 => trim((string) ($_GET['q'] ?? '')),
        ];
        $estacionFiltro = $verTodas
            ? (ctype_digit((string) $filtros['estacion_id']) ? (int) $filtros['estacion_id'] : null)
            : (int) $user['estacion_id'];

        render('flota/index', [
            'usuario'    => $user,
            'unidades'   => $this->service->listar($user, $estacionFiltro, $filtros),
            'filtros'    => $filtros,
            'verTodas'   => $verTodas,
            'categorias' => $this->catalogos->activos('categorias_vehiculo', 'orden'),
            'tiposEquipo' => $this->catalogos->activos('tipos_equipo', 'orden'),
            'capacidades' => $this->catalogos->activos('capacidades', 'orden'),
            'permisos'   => $this->catalogos->activos('permisos_especiales'),
            'estaciones' => $this->catalogos->activos('estaciones'),
            'pilotos'    => $this->pilotosParaSelect($user),
            'estados'    => EstadoVehiculo::values(),
        ], 'Flota · Disponibilidad de Flota');
    }

    /** GET /api/unidades */
    public function apiList(): void
    {
        $user = require_login_api();
        $filtro = isset($_GET['estacion_id']) && ctype_digit((string) $_GET['estacion_id'])
            ? (int) $_GET['estacion_id'] : null;
        json_ok($this->service->listar($user, $filtro));
    }

    /** GET /api/unidades/{id} — datos para poblar el formulario de edición. */
    public function apiShow(array $params): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $unidad = $this->unidades->find((int) $params['id']);
        if ($unidad === null || (int) $unidad['activo'] !== 1) {
            json_error('Unidad no encontrada', 404);
        }
        if (!can_write_station($user, (int) $unidad['estacion_id'])) {
            json_error('No autorizado sobre esta estación', 403);
        }
        $unidad['permisos'] = $this->unidades->permisoIds((int) $unidad['id']);
        json_ok($unidad);
    }

    /** POST /api/unidades */
    public function apiCreate(): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $id = $this->service->crear(request_body(), $user);
        json_ok(['id' => $id], 'Unidad creada.', 201);
    }

    /** PUT /api/unidades/{id} */
    public function apiUpdate(array $params): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $this->service->actualizar((int) $params['id'], request_body(), $user);
        json_ok(null, 'Unidad actualizada.');
    }

    /** POST /api/unidades/{id}/estado — diálogo de cambio de estado. */
    public function apiEstado(array $params): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $this->service->cambiarEstado((int) $params['id'], request_body(), $user);
        json_ok(null, 'Estado actualizado.');
    }

    /** DELETE /api/unidades/{id} */
    public function apiDelete(array $params): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $this->service->eliminar((int) $params['id'], $user);
        json_ok(null, 'Unidad eliminada.');
    }

    /** Pilotos activos disponibles para asignar (los de la estación del usuario o todos si admin). */
    private function pilotosParaSelect(array $user): array
    {
        if ($user['rol'] === Rol::ADMIN_GLOBAL) {
            return $this->pdo->query('SELECT id, nombre, estacion_id FROM pilotos WHERE activo = 1 ORDER BY nombre')->fetchAll();
        }
        $stmt = $this->pdo->prepare('SELECT id, nombre, estacion_id FROM pilotos WHERE activo = 1 AND estacion_id = :e ORDER BY nombre');
        $stmt->execute([':e' => $user['estacion_id']]);
        return $stmt->fetchAll();
    }
}
