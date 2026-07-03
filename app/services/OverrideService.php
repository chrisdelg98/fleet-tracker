<?php
/**
 * Overrides manuales (plan §5.8, §7.2): bloqueos administrativos puntuales (aduana,
 * reserva de gerencia…). Los overrides automáticos por estado_vehiculo los gestiona
 * UnidadService. Solo sobre flota operativa (regla 13). Escritura en transacción + bitácora.
 */

declare(strict_types=1);

final class OverrideService
{
    public function __construct(
        private PDO $pdo,
        private OverrideModel $overrides,
        private UnidadModel $unidades
    ) {
    }

    public function bloquear(int $unidadId, array $input, array $user): int
    {
        $unidad = $this->unidadOperable($unidadId, $user);
        $motivo = trim((string) ($input['motivo'] ?? ''));
        if ($motivo === '') {
            json_unprocessable(['motivo' => 'El motivo del bloqueo es obligatorio.']);
        }

        return tx($this->pdo, function () use ($unidadId, $motivo, $user): int {
            $id = $this->overrides->abrir($unidadId, TipoOverride::BLOQUEADA, OrigenOverride::MANUAL, $motivo, $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'override', $id, AccionBitacora::CREAR, [
                'despues' => ['unidad_id' => $unidadId, 'tipo' => TipoOverride::BLOQUEADA, 'origen' => OrigenOverride::MANUAL, 'motivo' => $motivo],
            ]);
            return $id;
        });
    }

    public function desbloquear(int $unidadId, array $user): void
    {
        $this->unidadOperable($unidadId, $user);
        tx($this->pdo, function () use ($unidadId, $user): void {
            $n = $this->overrides->cerrarManualesAbiertos($unidadId);
            registrar_bitacora($this->pdo, $user['id'], 'override', $unidadId, AccionBitacora::CAMBIO_ESTADO, [
                'despues' => ['unidad_id' => $unidadId, 'bloqueos_cerrados' => $n],
            ]);
        });
    }

    private function unidadOperable(int $unidadId, array $user): array
    {
        $unidad = $this->unidades->find($unidadId);
        if ($unidad === null || (int) $unidad['activo'] !== 1) {
            json_error('Unidad no encontrada', 404);
        }
        if (!can_write_station($user, (int) $unidad['estacion_id'])) {
            json_error('No autorizado sobre esta estación', 403);
        }
        if ((int) $unidad['en_disponibilidad'] !== 1) {
            json_unprocessable(['unidad_id' => 'La unidad es solo inventario; no admite overrides (regla 13).']);
        }
        return $unidad;
    }
}
