<?php
/**
 * Acceso a datos de pilotos (plan §5.4). PDO + prepared statements. Soft-delete (activo):
 * el histórico de movimientos los referencia, nunca se borran físicamente.
 */

declare(strict_types=1);

final class PilotoModel
{
    private const CAMPOS = ['nombre', 'documento_identidad', 'telefonos', 'tipo_licencia_id',
                            'no_licencia', 'licencia_vence', 'codigo_nacional', 'codigo_internacional',
                            'estacion_id'];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pilotos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ¿Hay otro piloto activo con ese valor en esa columna? Sirve para el número de licencia y
     * el documento: sin esto, una carga masiva repetida duplicaría a la misma persona.
     */
    public function existeCon(string $columna, string $valor, ?int $exceptId = null): bool
    {
        return $this->quienTiene($columna, $valor, $exceptId) !== null;
    }

    /**
     * Piloto que ya usa ese valor, o null. Devuelve quién es (no solo si existe) para poder
     * decir "ya está registrado como X" en vez de repetir un choque por cada columna.
     *
     * @return array{id:int, nombre:string}|null
     */
    public function quienTiene(string $columna, string $valor, ?int $exceptId = null): ?array
    {
        if (!in_array($columna, ['no_licencia', 'documento_identidad'], true)) {
            throw new InvalidArgumentException("Columna no permitida: {$columna}");
        }
        $sql = "SELECT id, nombre FROM pilotos WHERE {$columna} = :valor AND activo = 1";
        $params = [':valor' => $valor];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $fila = $stmt->fetch();
        return $fila ? ['id' => (int) $fila['id'], 'nombre' => (string) $fila['nombre']] : null;
    }

    /**
     * Unidades cuyo piloto habitual es este. El vínculo vive en unidades.piloto_asignado_id;
     * desde el piloto se lee al revés, que es como lo tiene la gente en sus hojas de control:
     * una fila por motorista con su cabezal y su equipo de arrastre.
     *
     * @return array<int, array{id:int, placa_unidad:string, categoria:string}>
     */
    public function unidadesAsignadas(int $pilotoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.placa_unidad, c.nombre AS categoria
               FROM unidades u
               JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
              WHERE u.piloto_asignado_id = :p AND u.activo = 1
              ORDER BY c.orden, u.placa_unidad'
        );
        $stmt->execute([':p' => $pilotoId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array> unidades asignadas, indexadas por piloto (para listados) */
    public function unidadesPorPiloto(array $pilotoIds): array
    {
        if ($pilotoIds === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($pilotoIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT u.piloto_asignado_id AS piloto_id, u.placa_unidad, c.nombre AS categoria
               FROM unidades u
               JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
              WHERE u.piloto_asignado_id IN ({$marcas}) AND u.activo = 1
              ORDER BY c.orden, u.placa_unidad"
        );
        $stmt->execute($pilotoIds);
        $mapa = [];
        foreach ($stmt->fetchAll() as $u) {
            $mapa[(int) $u['piloto_id']][] = $u;
        }
        return $mapa;
    }

    /**
     * Unidades que se pueden asignar a un piloto, con su piloto actual si ya lo tienen: el
     * formulario las marca para que nadie se las quite a otro sin darse cuenta.
     */
    public function unidadesAsignables(array $user): array
    {
        $sql = 'SELECT u.id, u.placa_unidad, u.estacion_id, c.nombre AS categoria,
                       u.piloto_asignado_id, p.nombre AS piloto_actual
                  FROM unidades u
                  JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
                  LEFT JOIN pilotos p ON p.id = u.piloto_asignado_id
                 WHERE u.activo = 1';
        if ($user['rol'] !== Rol::ADMIN_GLOBAL) {
            $stmt = $this->pdo->prepare($sql . ' AND u.estacion_id = :e ORDER BY c.orden, u.placa_unidad');
            $stmt->execute([':e' => $user['estacion_id']]);
            return $stmt->fetchAll();
        }
        return $this->pdo->query($sql . ' ORDER BY c.orden, u.placa_unidad')->fetchAll();
    }

    /**
     * Fija exactamente qué unidades tiene asignadas el piloto: suelta las que ya no están y
     * toma las nuevas. Debe correr dentro de transacción.
     */
    public function setUnidadesAsignadas(int $pilotoId, array $unidadIds): void
    {
        $this->pdo->prepare('UPDATE unidades SET piloto_asignado_id = NULL WHERE piloto_asignado_id = :p')
            ->execute([':p' => $pilotoId]);

        if ($unidadIds === []) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($unidadIds), '?'));
        $stmt = $this->pdo->prepare("UPDATE unidades SET piloto_asignado_id = ? WHERE id IN ({$marcas})");
        $stmt->execute(array_merge([$pilotoId], array_map('intval', $unidadIds)));
    }

    /**
     * Unidades de la lista que ya tienen OTRO piloto asignado. Reasignar en silencio es la
     * clase de sorpresa que una carga masiva no debe dar: se avisa y decide la persona.
     *
     * @return array<int, array{placa_unidad:string, piloto:string}>
     */
    public function unidadesConOtroPiloto(array $unidadIds, ?int $pilotoId): array
    {
        if ($unidadIds === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($unidadIds), '?'));
        $sql = "SELECT u.placa_unidad, p.nombre AS piloto
                  FROM unidades u
                  JOIN pilotos p ON p.id = u.piloto_asignado_id
                 WHERE u.id IN ({$marcas}) AND u.piloto_asignado_id IS NOT NULL";
        $params = array_map('intval', $unidadIds);
        if ($pilotoId !== null) {
            $sql .= ' AND u.piloto_asignado_id <> ?';
            $params[] = $pilotoId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Lista con nombres resueltos (tipo de licencia, estación). Filtra por estación si se indica. */
    public function listar(?int $estacionId = null, array $filtros = [], bool $soloActivos = true): array
    {
        $sql = 'SELECT p.*, tl.nombre AS tipo_licencia, e.codigo AS estacion_codigo,
                       e.pais_id, pa.etiqueta_codigo_nacional, pa.etiqueta_codigo_internacional
                  FROM pilotos p
                  JOIN tipos_licencia tl ON tl.id = p.tipo_licencia_id
                  JOIN estaciones e ON e.id = p.estacion_id
                  JOIN paises pa ON pa.id = e.pais_id
                 WHERE 1 = 1';
        $params = [];
        if ($soloActivos) {
            $sql .= ' AND p.activo = 1';
        }
        if ($estacionId !== null) {
            $sql .= ' AND p.estacion_id = :e';
            $params[':e'] = $estacionId;
        }
        if (!empty($filtros['tipo_licencia_id'])) {
            $sql .= ' AND p.tipo_licencia_id = :tl';
            $params[':tl'] = (int) $filtros['tipo_licencia_id'];
        }
        if (!empty($filtros['q'])) {
            // CONCAT con un solo placeholder: los prepares nativos no permiten reusar :q.
            $sql .= " AND CONCAT(p.nombre, ' ', p.no_licencia, ' ',
                                 COALESCE(p.documento_identidad, ''), ' ',
                                 COALESCE(p.telefonos, '')) LIKE :q";
            $params[':q'] = '%' . $filtros['q'] . '%';
        }
        // Estado de licencia respecto a hoy (alerta de vencimiento, plan §7.3).
        switch ($filtros['licencia'] ?? '') {
            case 'vencida':
                $sql .= ' AND p.licencia_vence IS NOT NULL AND p.licencia_vence < CURDATE()';
                break;
            case 'por_vencer':
                $sql .= ' AND p.licencia_vence IS NOT NULL AND p.licencia_vence >= CURDATE() AND p.licencia_vence <= CURDATE() + INTERVAL 30 DAY';
                break;
            case 'vigente':
                $sql .= ' AND (p.licencia_vence IS NULL OR p.licencia_vence > CURDATE() + INTERVAL 30 DAY)';
                break;
        }
        $sql .= ' ORDER BY p.nombre';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function crear(array $data, ?int $usuarioId): int
    {
        $cols = self::CAMPOS;
        $ph = array_map(static fn(string $c): string => ':' . $c, $cols);
        $stmt = $this->pdo->prepare(
            'INSERT INTO pilotos (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', $ph) . ', :created_by)'
        );
        $stmt->execute($this->bind($data) + [':created_by' => $usuarioId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $data): void
    {
        $sets = array_map(static fn(string $c): string => "{$c} = :{$c}", self::CAMPOS);
        $stmt = $this->pdo->prepare('UPDATE pilotos SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($this->bind($data) + [':id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->pdo->prepare('UPDATE pilotos SET activo = 0 WHERE id = :id')->execute([':id' => $id]);
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
