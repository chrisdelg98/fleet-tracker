<?php
/**
 * Pilotos (plan §7.3). Página + API JSON. Alerta de licencia por vencer se calcula en la vista.
 */

declare(strict_types=1);

final class PilotoController
{
    public function __construct(
        private PilotoService $service,
        private PilotoModel $pilotos,
        private CatalogoModel $catalogos
    ) {
    }

    public function index(): void
    {
        $user = require_login_web();
        $verTodas = $user['rol'] === Rol::ADMIN_GLOBAL;
        $filtros = [
            'estacion_id'      => $verTodas ? (string) ($_GET['estacion_id'] ?? '') : '',
            'tipo_licencia_id' => $_GET['tipo_licencia_id'] ?? '',
            'licencia'         => $_GET['licencia'] ?? '',
            'q'                => trim((string) ($_GET['q'] ?? '')),
        ];
        $estacionFiltro = $verTodas
            ? (ctype_digit((string) $filtros['estacion_id']) ? (int) $filtros['estacion_id'] : null)
            : (int) $user['estacion_id'];
        $pilotos = $this->service->listar($user, $estacionFiltro, $filtros);

        render('pilotos/index', [
            'usuario'        => $user,
            'pilotos'        => $pilotos,
            'filtros'        => $filtros,
            'verTodas'       => $verTodas,
            'tiposLicencia'  => $this->catalogos->activos('tipos_licencia'),
            'estaciones'     => $this->catalogos->activos('estaciones'),
            'unidades'       => $this->pilotos->unidadesAsignables($user),
            // Las unidades de todos los pilotos de la página en una sola consulta.
            'porPiloto'      => $this->pilotos->unidadesPorPiloto(
                array_map(static fn(array $p): int => (int) $p['id'], $pilotos)
            ),
        ], 'Pilotos · Disponibilidad de Flota');
    }

    public function apiShow(array $params): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $piloto = $this->pilotos->find((int) $params['id']);
        if ($piloto === null || (int) $piloto['activo'] !== 1) {
            json_error('Piloto no encontrado', 404);
        }
        if (!can_write_station($user, (int) $piloto['estacion_id'])) {
            json_error('No autorizado sobre esta estación', 403);
        }
        // El formulario necesita saber qué unidades lleva ya, para marcarlas.
        $piloto['unidades'] = array_map(
            static fn(array $u): int => (int) $u['id'],
            $this->pilotos->unidadesAsignadas((int) $piloto['id'])
        );
        json_ok($piloto);
    }

    public function apiCreate(): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $id = $this->service->crear(request_body(), $user);
        json_ok(['id' => $id], 'Piloto creado.', 201);
    }

    public function apiUpdate(array $params): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $this->service->actualizar((int) $params['id'], request_body(), $user);
        json_ok(null, 'Piloto actualizado.');
    }

    public function apiDelete(array $params): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $this->service->eliminar((int) $params['id'], $user);
        json_ok(null, 'Piloto eliminado.');
    }
}
