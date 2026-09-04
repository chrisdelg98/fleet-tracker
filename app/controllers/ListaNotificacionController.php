<?php
/**
 * Listas de notificación: página con búsqueda + API JSON.
 *
 * Cada estación gestiona las suyas, así que vive en Operación y no en Administración: un
 * encargado tiene que poder mantener sus contactos sin depender de un administrador central.
 */

declare(strict_types=1);

final class ListaNotificacionController
{
    private const ACCESO = [Rol::ADMIN_GLOBAL, Rol::ENCARGADO];

    public function __construct(
        private ListaNotificacionService $service,
        private ListaNotificacionModel $listas,
        private CatalogoModel $catalogos
    ) {
    }

    public function index(): void
    {
        $user = require_login_web();
        if (!in_array($user['rol'], self::ACCESO, true)) {
            http_response_code(403);
            echo 'No tienes acceso a las listas de notificación.';
            return;
        }
        $q = trim((string) ($_GET['q'] ?? ''));

        render('contactos/index', [
            'usuario'    => $user,
            'listas'     => $this->service->listar($user, $q !== '' ? $q : null),
            'q'          => $q,
            'esAdmin'    => $user['rol'] === Rol::ADMIN_GLOBAL,
            'estaciones' => $this->catalogos->activos('estaciones', 'codigo'),
        ], 'Contactos · Disponibilidad de Flota');
    }

    public function apiShow(array $params): void
    {
        $user = require_role_api(self::ACCESO);
        $lista = $this->listas->find((int) $params['id']);
        if ($lista === null || (int) $lista['activo'] !== 1) {
            json_error('Lista no encontrada', 404);
        }
        // Una lista de otra estación no es suya ni para leerla: el formulario la cargaría.
        if ($lista['estacion_id'] !== null
            && $user['rol'] !== Rol::ADMIN_GLOBAL
            && (int) $lista['estacion_id'] !== (int) $user['estacion_id']) {
            json_error('No autorizado sobre esta estación', 403);
        }
        json_ok($lista);
    }

    public function apiCreate(): void
    {
        $user = require_role_api(self::ACCESO);
        $id = $this->service->crear(request_body(), $user);
        json_ok(['id' => $id], 'Lista creada.', 201);
    }

    public function apiUpdate(array $params): void
    {
        $user = require_role_api(self::ACCESO);
        $this->service->actualizar((int) $params['id'], request_body(), $user);
        json_ok(null, 'Lista actualizada.');
    }

    public function apiDelete(array $params): void
    {
        $user = require_role_api(self::ACCESO);
        $this->service->eliminar((int) $params['id'], $user);
        json_ok(null, 'Lista eliminada.');
    }
}
