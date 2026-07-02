<?php
/**
 * Administración del sistema (plan §9.1): Estaciones, Usuarios y Catálogos. Sección
 * exclusiva del Admin Global — las páginas usan require_admin_web y el API require_admin_api.
 */

declare(strict_types=1);

final class AdminController
{
    public function __construct(
        private EstacionService $estacionService,
        private EstacionModel $estaciones,
        private UsuarioService $usuarioService,
        private UsuarioModel $usuarios,
        private CatalogoAdminService $catalogoService,
        private CatalogoModel $catalogos
    ) {
    }

    // ── Páginas ──

    public function index(): void
    {
        $user = require_admin_web();
        render('admin/index', ['usuario' => $user], 'Administración · Disponibilidad de Flota');
    }

    public function estacionesPage(): void
    {
        $user = require_admin_web();
        render('admin/estaciones', [
            'usuario'    => $user,
            'estaciones' => $this->estaciones->listar(),
        ], 'Estaciones · Administración');
    }

    public function usuariosPage(): void
    {
        $user = require_admin_web();
        render('admin/usuarios', [
            'usuario'    => $user,
            'usuarios'   => $this->usuarios->listar(),
            'estaciones' => $this->catalogos->activos('estaciones'),
            'roles'      => Rol::values(),
            'rolesSinEstacion' => Rol::SIN_ESTACION,
        ], 'Usuarios · Administración');
    }

    public function catalogosPage(): void
    {
        $user = require_admin_web();
        $datos = [];
        foreach (CatalogoAdminService::tablas() as $tabla) {
            $datos[$tabla] = [
                'spec'  => CatalogoAdminService::spec($tabla),
                'items' => $this->catalogos->activos($tabla, in_array($tabla, ['categorias_vehiculo', 'paises'], true) ? 'orden' : 'nombre'),
            ];
        }
        render('admin/catalogos', ['usuario' => $user, 'catalogos' => $datos], 'Catálogos · Administración');
    }

    // ── API Estaciones ──

    public function estacionCreate(): void
    {
        $user = require_admin_api();
        json_ok(['id' => $this->estacionService->crear(request_body(), $user)], 'Estación creada.', 201);
    }

    public function estacionShow(array $p): void
    {
        require_admin_api();
        $e = $this->estaciones->find((int) $p['id']);
        $e === null ? json_error('Estación no encontrada', 404) : json_ok($e);
    }

    public function estacionUpdate(array $p): void
    {
        $user = require_admin_api();
        $this->estacionService->actualizar((int) $p['id'], request_body(), $user);
        json_ok(null, 'Estación actualizada.');
    }

    public function estacionActivo(array $p): void
    {
        $user = require_admin_api();
        $this->estacionService->cambiarActivo((int) $p['id'], (bool) (request_body()['activo'] ?? false), $user);
        json_ok(null, 'Estación actualizada.');
    }

    // ── API Usuarios ──

    public function usuarioCreate(): void
    {
        $user = require_admin_api();
        json_ok(['id' => $this->usuarioService->crear(request_body(), $user)], 'Usuario creado.', 201);
    }

    public function usuarioShow(array $p): void
    {
        require_admin_api();
        $u = $this->usuarios->findById((int) $p['id']);
        if ($u === null) {
            json_error('Usuario no encontrado', 404);
        }
        unset($u['password_hash']); // nunca exponer el hash
        json_ok($u);
    }

    public function usuarioUpdate(array $p): void
    {
        $user = require_admin_api();
        $this->usuarioService->actualizar((int) $p['id'], request_body(), $user);
        json_ok(null, 'Usuario actualizado.');
    }

    public function usuarioActivo(array $p): void
    {
        $user = require_admin_api();
        $this->usuarioService->cambiarActivo((int) $p['id'], (bool) (request_body()['activo'] ?? false), $user);
        json_ok(null, 'Usuario actualizado.');
    }

    // ── API Catálogos ──

    public function catalogoCreate(array $p): void
    {
        $user = require_admin_api();
        json_ok(['id' => $this->catalogoService->crear($p['tabla'], request_body(), $user)], 'Registro creado.', 201);
    }

    public function catalogoUpdate(array $p): void
    {
        $user = require_admin_api();
        $this->catalogoService->actualizar($p['tabla'], (int) $p['id'], request_body(), $user);
        json_ok(null, 'Registro actualizado.');
    }

    public function catalogoActivo(array $p): void
    {
        $user = require_admin_api();
        $this->catalogoService->cambiarActivo($p['tabla'], (int) $p['id'], (bool) (request_body()['activo'] ?? false), $user);
        json_ok(null, 'Registro actualizado.');
    }
}
