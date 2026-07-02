<?php
/**
 * Overrides de unidad (plan §5.8). En Fase 1 se usan solo para los que genera el cambio
 * de estado_vehiculo (origen AUTO_ESTADO). Los bloqueos manuales y su papel en el cálculo
 * de disponibilidad llegan en Fase 2.
 */

declare(strict_types=1);

final class OverrideModel
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Crea un override abierto vinculado a una unidad. Devuelve el id. */
    public function abrir(int $unidadId, string $tipo, string $origen, string $motivo, ?int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO overrides_unidad (unidad_id, tipo, origen, desde, motivo, cerrado, created_by)
             VALUES (:unidad_id, :tipo, :origen, :desde, :motivo, 0, :created_by)'
        );
        $stmt->execute([
            ':unidad_id'  => $unidadId,
            ':tipo'       => $tipo,
            ':origen'     => $origen,
            ':desde'      => now_utc(),
            ':motivo'     => $motivo,
            ':created_by' => $usuarioId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Cierra los overrides automáticos abiertos de una unidad (al volver a OPERATIVO). */
    public function cerrarAutomaticosAbiertos(int $unidadId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE overrides_unidad
                SET cerrado = 1, hasta = :hasta
              WHERE unidad_id = :unidad_id AND cerrado = 0 AND origen = :origen'
        );
        $stmt->execute([
            ':hasta'     => now_utc(),
            ':unidad_id' => $unidadId,
            ':origen'    => OrigenOverride::AUTO_ESTADO,
        ]);
        return $stmt->rowCount();
    }

    /** True si la unidad tiene algún override automático abierto. */
    public function tieneAutomaticoAbierto(int $unidadId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM overrides_unidad
              WHERE unidad_id = :unidad_id AND cerrado = 0 AND origen = :origen LIMIT 1'
        );
        $stmt->execute([':unidad_id' => $unidadId, ':origen' => OrigenOverride::AUTO_ESTADO]);
        return $stmt->fetchColumn() !== false;
    }
}
