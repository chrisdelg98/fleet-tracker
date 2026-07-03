<?php
/**
 * Acceso a datos de unidades (plan §5.5). PDO + prepared statements en el 100% de las
 * queries. Soft-delete con `activo`: nunca se borra físicamente (el histórico las referencia).
 */

declare(strict_types=1);

final class UnidadModel
{
    /** Columnas escribibles desde el formulario de alta/edición. */
    private const CAMPOS = [
        'placa_unidad', 'placa_furgon', 'marca', 'modelo', 'categoria_vehiculo_id',
        'en_disponibilidad', 'capacidad_id', 'tipo_equipo_id', 'estacion_id',
        'piloto_asignado_id', 'estado_vehiculo', 'estado_notas',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM unidades WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Lista con nombres resueltos (para la tabla de Flota). Filtra por estación y por los
     *  filtros opcionales (categoría, tipo de equipo, estado, clasificación, búsqueda de placa). */
    public function listar(?int $estacionId = null, array $filtros = [], bool $soloActivas = true): array
    {
        $sql = 'SELECT u.*, c.nombre AS categoria, c.es_flota_operativa, c.requiere_furgon,
                       e.codigo AS estacion_codigo, te.nombre AS tipo_equipo,
                       cap.nombre AS capacidad, p.nombre AS piloto_asignado
                  FROM unidades u
                  JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
                  JOIN estaciones e ON e.id = u.estacion_id
                  LEFT JOIN tipos_equipo te ON te.id = u.tipo_equipo_id
                  LEFT JOIN capacidades cap ON cap.id = u.capacidad_id
                  LEFT JOIN pilotos p ON p.id = u.piloto_asignado_id
                 WHERE 1 = 1';
        $params = [];
        if ($soloActivas) {
            $sql .= ' AND u.activo = 1';
        }
        if ($estacionId !== null) {
            $sql .= ' AND u.estacion_id = :estacion_id';
            $params[':estacion_id'] = $estacionId;
        }
        if (!empty($filtros['categoria_id'])) {
            $sql .= ' AND u.categoria_vehiculo_id = :cat';
            $params[':cat'] = (int) $filtros['categoria_id'];
        }
        if (!empty($filtros['tipo_equipo_id'])) {
            $sql .= ' AND u.tipo_equipo_id = :tipo';
            $params[':tipo'] = (int) $filtros['tipo_equipo_id'];
        }
        if (!empty($filtros['estado_vehiculo']) && in_array($filtros['estado_vehiculo'], EstadoVehiculo::values(), true)) {
            $sql .= ' AND u.estado_vehiculo = :ev';
            $params[':ev'] = $filtros['estado_vehiculo'];
        }
        if (isset($filtros['en_disponibilidad']) && $filtros['en_disponibilidad'] !== '') {
            $sql .= ' AND u.en_disponibilidad = :ed';
            $params[':ed'] = (int) (bool) $filtros['en_disponibilidad'];
        }
        if (!empty($filtros['q'])) {
            // CONCAT con un solo placeholder: los prepares nativos no permiten reusar :q.
            $sql .= " AND CONCAT(u.placa_unidad, ' ', COALESCE(u.placa_furgon, '')) LIKE :q";
            $params[':q'] = '%' . $filtros['q'] . '%';
        }
        $sql .= ' ORDER BY u.placa_unidad';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** True si la placa ya existe (opcionalmente excluyendo una unidad al editar). */
    public function placaExiste(string $placa, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM unidades WHERE placa_unidad = :placa';
        $params = [':placa' => $placa];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    public function crear(array $data, ?int $usuarioId): int
    {
        $cols = self::CAMPOS;
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $cols);
        $sql = 'INSERT INTO unidades (' . implode(', ', $cols) . ', created_by) VALUES ('
             . implode(', ', $placeholders) . ', :created_by)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bind($data) + [':created_by' => $usuarioId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $data): void
    {
        $sets = array_map(static fn(string $c): string => "{$c} = :{$c}", self::CAMPOS);
        $sql = 'UPDATE unidades SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bind($data) + [':id' => $id]);
    }

    /** Cambia solo el estado del vehículo y sus notas (diálogo poka-yoke). */
    public function actualizarEstado(int $id, string $estado, ?string $notas): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE unidades SET estado_vehiculo = :estado, estado_notas = :notas WHERE id = :id'
        );
        $stmt->execute([':estado' => $estado, ':notas' => $notas, ':id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->pdo->prepare('UPDATE unidades SET activo = 0 WHERE id = :id')->execute([':id' => $id]);
    }

    /** @return int[] ids de permisos especiales de la unidad. */
    public function permisoIds(int $unidadId): array
    {
        $stmt = $this->pdo->prepare('SELECT permiso_especial_id FROM unidad_permisos WHERE unidad_id = :id');
        $stmt->execute([':id' => $unidadId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Reemplaza el conjunto de permisos N:M de la unidad. Debe correr dentro de transacción. */
    public function setPermisos(int $unidadId, array $permisoIds, ?int $usuarioId): void
    {
        $this->pdo->prepare('DELETE FROM unidad_permisos WHERE unidad_id = :id')->execute([':id' => $unidadId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO unidad_permisos (unidad_id, permiso_especial_id, created_by)
             VALUES (:unidad_id, :permiso_id, :created_by)'
        );
        foreach (array_unique(array_map('intval', $permisoIds)) as $permisoId) {
            $ins->execute([':unidad_id' => $unidadId, ':permiso_id' => $permisoId, ':created_by' => $usuarioId]);
        }
    }

    /** Mapea los campos permitidos a parámetros nombrados. */
    private function bind(array $data): array
    {
        $params = [];
        foreach (self::CAMPOS as $campo) {
            $params[':' . $campo] = $data[$campo] ?? null;
        }
        return $params;
    }
}
