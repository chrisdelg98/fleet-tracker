<?php
/**
 * Acceso a datos de movimientos (plan §5.7). PDO + prepared statements. Las fechas se
 * guardan y consultan SIEMPRE en UTC; la conversión a hora local es de presentación.
 */

declare(strict_types=1);

final class MovimientoModel
{
    /** Columnas escribibles al crear/editar el plan del movimiento (no el estado). */
    private const CAMPOS = [
        'unidad_id', 'piloto_id', 'ruta_id', 'ruta_custom_origen', 'ruta_custom_destino',
        'pais_origen_id', 'pais_destino_id', 'tipo_ruta', 'fecha_salida', 'fecha_fin_estimada',
        'referencia_cw', 'retorno_disponible', 'reservado_para', 'notas', 'estado',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM movimientos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Movimientos activos de la unidad cuyo rango [salida, fin] se traslapa con el nuevo.
     * Bloquea las filas (FOR UPDATE) para resistir reservas simultáneas (integridad #2).
     * Debe ejecutarse dentro de una transacción.
     */
    public function conflictos(int $unidadId, string $salidaUtc, string $finUtc, ?int $exceptId = null): array
    {
        $sql = 'SELECT m.*, po.codigo_iso AS origen, pd.codigo_iso AS destino
                  FROM movimientos m
                  LEFT JOIN paises po ON po.id = m.pais_origen_id
                  LEFT JOIN paises pd ON pd.id = m.pais_destino_id
                 WHERE m.unidad_id = :unidad
                   AND m.estado IN (\'RESERVADO\', \'PROGRAMADO\', \'EN_TRANSITO\')
                   AND m.fecha_salida < :fin
                   AND m.fecha_fin_estimada > :salida';
        $params = [':unidad' => $unidadId, ':fin' => $finUtc, ':salida' => $salidaUtc];
        if ($exceptId !== null) {
            $sql .= ' AND m.id <> :except';
            $params[':except'] = $exceptId;
        }
        $sql .= ' FOR UPDATE';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function crear(array $data, ?int $usuarioId): int
    {
        $cols = self::CAMPOS;
        $ph = array_map(static fn(string $c): string => ':' . $c, $cols);
        $stmt = $this->pdo->prepare(
            'INSERT INTO movimientos (' . implode(', ', $cols) . ', created_by) VALUES ('
            . implode(', ', $ph) . ', :created_by)'
        );
        $stmt->execute($this->bind($data) + [':created_by' => $usuarioId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Actualiza el plan del movimiento (ruta/fechas/flags), sin tocar el estado. */
    public function actualizarPlan(int $id, array $data): void
    {
        $cols = array_filter(self::CAMPOS, static fn(string $c): bool => $c !== 'estado' && $c !== 'unidad_id');
        $sets = array_map(static fn(string $c): string => "{$c} = :{$c}", $cols);
        $params = [':id' => $id];
        foreach ($cols as $c) {
            $params[':' . $c] = $data[$c] ?? null;
        }
        $stmt = $this->pdo->prepare('UPDATE movimientos SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /** Cambia estado y campos asociados (piloto al salir, fecha_fin_real al llegar, motivo al cancelar). */
    public function cambiarEstado(int $id, string $estado, array $extra = []): void
    {
        $sets = ['estado = :estado'];
        $params = [':estado' => $estado, ':id' => $id];
        foreach (['piloto_id', 'fecha_fin_real', 'motivo_cancelacion'] as $campo) {
            if (array_key_exists($campo, $extra)) {
                $sets[] = "{$campo} = :{$campo}";
                $params[':' . $campo] = $extra[$campo];
            }
        }
        $stmt = $this->pdo->prepare('UPDATE movimientos SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /** Historial de movimientos de una unidad, con nombres resueltos. */
    public function listarPorUnidad(int $unidadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, po.codigo_iso AS origen_iso, pd.codigo_iso AS destino_iso, pl.nombre AS piloto
               FROM movimientos m
               LEFT JOIN paises po ON po.id = m.pais_origen_id
               LEFT JOIN paises pd ON pd.id = m.pais_destino_id
               LEFT JOIN pilotos pl ON pl.id = m.piloto_id
              WHERE m.unidad_id = :u
              ORDER BY m.fecha_salida DESC'
        );
        $stmt->execute([':u' => $unidadId]);
        return $stmt->fetchAll();
    }

    private function bind(array $data): array
    {
        $params = [];
        foreach (self::CAMPOS as $c) {
            $params[':' . $c] = $data[$c] ?? null;
        }
        return $params;
    }
}
