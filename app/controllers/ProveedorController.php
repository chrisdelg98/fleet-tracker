<?php
/**
 * Catálogo de proveedores de transporte: pantalla y API.
 *
 * Es compartido entre estaciones, así que lo gestiona quien gestiona flota (Admin Global y
 * Encargado) sin restricción de estación: un proveedor no pertenece a ninguna.
 */

declare(strict_types=1);

final class ProveedorController
{
    private const GESTION = [Rol::ADMIN_GLOBAL, Rol::ENCARGADO];

    public function __construct(
        private ProveedorService $service,
        private ProveedorModel $proveedores
    ) {
    }

    /** GET /proveedores */
    public function index(): void
    {
        $user = require_login_web();
        if (!in_array($user['rol'], self::GESTION, true)) {
            http_response_code(403);
            echo 'No tienes acceso a esta sección.';
            return;
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        $estado = (string) ($_GET['estado'] ?? 'activos');
        render('proveedores/index', [
            'usuario'     => $user,
            'lista'       => $this->service->listar($q, $estado),
            'q'           => $q,
            'estado'      => $estado,
            'paraMover'   => $this->proveedores->activos(),
        ], 'Proveedores · Flete Finder');
    }

    /** GET /api/proveedores/parecidos?nombre= — ¿ya existe, o hay uno parecido? */
    public function apiParecidos(): void
    {
        require_role_api(self::GESTION);
        json_ok($this->service->parecidos((string) ($_GET['nombre'] ?? '')));
    }

    /** POST /api/proveedores */
    public function apiCreate(): void
    {
        $user = require_role_api(self::GESTION);
        $id = $this->service->crear(request_body(), $user);
        json_ok(['id' => $id], 'Proveedor creado.', 201);
    }

    /** PUT /api/proveedores/{id} — cambia el nombre. */
    public function apiUpdate(array $p): void
    {
        $user = require_role_api(self::GESTION);
        $this->service->renombrar((int) $p['id'], request_body(), $user);
        json_ok(null, 'Proveedor actualizado.');
    }

    /** POST /api/proveedores/{id}/activo */
    public function apiActivo(array $p): void
    {
        $user = require_role_api(self::GESTION);
        $activo = !empty(request_body()['activo']);
        $this->service->cambiarActivo((int) $p['id'], $activo, $user);
        json_ok(null, $activo ? 'Proveedor activado.' : 'Proveedor desactivado.');
    }

    /** POST /api/proveedores/{id}/fusionar */
    public function apiFusionar(array $p): void
    {
        $user = require_role_api(self::GESTION);
        $this->service->fusionar((int) $p['id'], (int) (request_body()['destino_id'] ?? 0), $user);
        json_ok(null, 'Proveedores fusionados.');
    }

    /** POST /api/proveedores/{id}/camiones */
    public function apiCamionCreate(array $p): void
    {
        $user = require_role_api(self::GESTION);
        $id = $this->service->crearCamion((int) $p['id'], request_body(), $user);
        json_ok(['id' => $id], 'Camión agregado.', 201);
    }

    /** GET /api/proveedor-camiones/{id} */
    public function apiCamionShow(array $p): void
    {
        require_role_api(self::GESTION);
        json_ok($this->service->camion((int) $p['id']));
    }

    /** PUT /api/proveedor-camiones/{id} */
    public function apiCamionUpdate(array $p): void
    {
        $user = require_role_api(self::GESTION);
        $this->service->editarCamion((int) $p['id'], request_body(), $user);
        json_ok(null, 'Camión actualizado.');
    }

    /** POST /api/proveedor-camiones/{id}/activo */
    public function apiCamionActivo(array $p): void
    {
        $user = require_role_api(self::GESTION);
        $activo = !empty(request_body()['activo']);
        $this->service->cambiarActivoCamion((int) $p['id'], $activo, $user);
        json_ok(null, $activo ? 'Camión activado.' : 'Camión desactivado.');
    }
}
