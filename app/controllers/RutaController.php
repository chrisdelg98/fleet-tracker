<?php
/**
 * Rutas (plan §7.4). Página con búsqueda + API JSON. Rutas compartidas: cualquier
 * Encargado o Admin puede crearlas/editarlas.
 */

declare(strict_types=1);

final class RutaController
{
    public function __construct(private RutaService $service, private RutaModel $rutas)
    {
    }

    public function index(): void
    {
        $user = require_login_web();
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        render('rutas/index', [
            'usuario' => $user,
            'rutas'   => $this->rutas->listar($q !== '' ? $q : null),
            'q'       => $q,
        ], 'Rutas · Disponibilidad de Flota');
    }

    public function apiShow(array $params): void
    {
        require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $ruta = $this->rutas->find((int) $params['id']);
        if ($ruta === null || (int) $ruta['activo'] !== 1) {
            json_error('Ruta no encontrada', 404);
        }
        json_ok($ruta);
    }

    public function apiCreate(): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $id = $this->service->crear(request_body(), $user);
        json_ok(['id' => $id], 'Ruta creada.', 201);
    }

    public function apiUpdate(array $params): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $this->service->actualizar((int) $params['id'], request_body(), $user);
        json_ok(null, 'Ruta actualizada.');
    }

    public function apiDelete(array $params): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $this->service->eliminar((int) $params['id'], $user);
        json_ok(null, 'Ruta eliminada.');
    }
}
