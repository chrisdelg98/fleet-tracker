<?php
/**
 * Activos de apoyo de un movimiento (plan §5.7 ampliado): el cabezal y/o el chasis que
 * acompañan a la unidad reservada. La unidad protagonista sigue en movimientos.unidad_id;
 * aquí van los demás, cada uno con su rol y su marca de liberación.
 */

declare(strict_types=1);

final class MovimientoUnidadModel
{
    public function __construct(private PDO $pdo)
    {
    }

    public function agregar(int $movimientoId, int $unidadId, string $rol, ?int $usuarioId): void
    {
        $this->pdo->prepare(
            'INSERT INTO movimiento_unidades (movimiento_id, unidad_id, rol, created_by)
             VALUES (:mov, :unidad, :rol, :uid)'
        )->execute([':mov' => $movimientoId, ':unidad' => $unidadId, ':rol' => $rol, ':uid' => $usuarioId]);
    }

    /** Activos de apoyo del movimiento, con los datos que necesita la interfaz. */
    public function porMovimiento(int $movimientoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mu.unidad_id, mu.rol, mu.liberado_en, u.placa_unidad, c.nombre AS categoria
               FROM movimiento_unidades mu
               JOIN unidades u ON u.id = mu.unidad_id
               JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
              WHERE mu.movimiento_id = :mov
              ORDER BY mu.rol, u.placa_unidad'
        );
        $stmt->execute([':mov' => $movimientoId]);
        return $stmt->fetchAll();
    }

    public function find(int $movimientoId, int $unidadId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM movimiento_unidades WHERE movimiento_id = :mov AND unidad_id = :unidad LIMIT 1'
        );
        $stmt->execute([':mov' => $movimientoId, ':unidad' => $unidadId]);
        return $stmt->fetch() ?: null;
    }

    /** Suelta el activo del viaje: vuelve a estar disponible sin cerrar el movimiento. */
    public function liberar(int $movimientoId, int $unidadId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE movimiento_unidades
                SET liberado_en = :ahora
              WHERE movimiento_id = :mov AND unidad_id = :unidad AND liberado_en IS NULL'
        );
        $stmt->execute([':ahora' => now_utc(), ':mov' => $movimientoId, ':unidad' => $unidadId]);
        return $stmt->rowCount();
    }

    /**
     * Movimientos activos donde este activo va de apoyo y su ventana se traslapa con la nueva.
     * Bloquea las filas (FOR UPDATE) igual que la validación de la unidad protagonista.
     */
    public function conflictos(int $unidadId, string $salidaUtc, string $finUtc, ?int $exceptMovId = null): array
    {
        $sql = 'SELECT m.id, m.estado, m.fecha_salida, m.fecha_fin_estimada
                  FROM movimiento_unidades mu
                  JOIN movimientos m ON m.id = mu.movimiento_id
                 WHERE mu.unidad_id = :unidad
                   AND mu.liberado_en IS NULL
                   AND m.estado IN (\'RESERVADO\', \'PROGRAMADO\', \'EN_TRANSITO\')
                   AND m.fecha_salida < :fin
                   AND m.fecha_fin_estimada > :salida';
        $params = [':unidad' => $unidadId, ':fin' => $finUtc, ':salida' => $salidaUtc];
        if ($exceptMovId !== null) {
            $sql .= ' AND m.id <> :except';
            $params[':except'] = $exceptMovId;
        }
        $sql .= ' FOR UPDATE';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
