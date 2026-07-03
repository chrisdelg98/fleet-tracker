<?php
/**
 * Movimientos y reservas (plan §7.5) + overrides manuales (§7.2). API JSON. La autorización
 * por rol se valida aquí; la de estación y la máquina de estados, en los servicios.
 */

declare(strict_types=1);

final class MovimientoController
{
    public function __construct(
        private MovimientoService $service,
        private MovimientoModel $movimientos,
        private OverrideService $overrides
    ) {
    }

    private const ESCRITURA = [Rol::ADMIN_GLOBAL, Rol::ENCARGADO];

    public function apiShow(array $p): void
    {
        require_role_api(self::ESCRITURA);
        $mov = $this->movimientos->find((int) $p['id']);
        $mov === null ? json_error('Movimiento no encontrado', 404) : json_ok($mov);
    }

    public function apiPorUnidad(array $p): void
    {
        require_login_api();
        json_ok($this->movimientos->listarPorUnidad((int) $p['id']));
    }

    public function apiCreate(): void
    {
        $user = require_role_api(self::ESCRITURA);
        $id = $this->service->crear(request_body(), $user);
        json_ok(['id' => $id], 'Movimiento creado.', 201);
    }

    public function apiUpdate(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $this->service->editar((int) $p['id'], request_body(), $user);
        json_ok(null, 'Movimiento actualizado.');
    }

    public function apiConfirmar(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $this->service->confirmar((int) $p['id'], $user);
        json_ok(null, 'Movimiento confirmado (PROGRAMADO).');
    }

    public function apiSalida(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $this->service->marcarSalida((int) $p['id'], request_body(), $user);
        json_ok(null, 'Salida marcada (EN_TRANSITO).');
    }

    public function apiLlegada(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $this->service->marcarLlegada((int) $p['id'], $user);
        json_ok(null, 'Llegada marcada (COMPLETADO).');
    }

    public function apiCancelar(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $this->service->cancelar((int) $p['id'], request_body(), $user);
        json_ok(null, 'Movimiento cancelado.');
    }

    public function apiApartarRetorno(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $id = $this->service->apartarRetorno((int) $p['id'], request_body(), $user);
        json_ok(['id' => $id], 'Retorno apartado; movimiento de regreso creado.', 201);
    }

    // ── Overrides manuales por unidad ──

    public function apiBloquear(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $id = $this->overrides->bloquear((int) $p['id'], request_body(), $user);
        json_ok(['id' => $id], 'Unidad bloqueada.', 201);
    }

    public function apiDesbloquear(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $this->overrides->desbloquear((int) $p['id'], $user);
        json_ok(null, 'Unidad desbloqueada.');
    }
}
