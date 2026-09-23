<?php
/**
 * Talleres de cada estación. Ver database/migrations/042_mantenimientos.sql.
 *
 * Propio o subcontratado da igual para registrar: los dos son un taller de la lista. La marca
 * `es_propio` solo existe para poder separar después cuánto se hizo adentro y cuánto se pagó afuera.
 */

declare(strict_types=1);

final class TallerModel
{
    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, e.codigo AS estacion_codigo FROM talleres t
               JOIN estaciones e ON e.id = t.estacion_id
              WHERE t.id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** El taller de esa estación con esa clave, para no crear dos veces el mismo nombre. */
    public function porClave(int $estacionId, string $clave): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM talleres WHERE estacion_id = :e AND clave = :c LIMIT 1'
        );
        $stmt->execute([':e' => $estacionId, ':c' => $clave]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Talleres para la pantalla, con cuánto se les ha llevado.
     *
     * @param array $filtros estacion_id, q, estado ('activos'|'desactivados'|'todos')
     */
    public function listar(array $filtros): array
    {
        $sql = 'SELECT t.*, e.codigo AS estacion_codigo,
                       (SELECT COUNT(*) FROM mantenimientos m WHERE m.taller_id = t.id) AS intervenciones,
                       (SELECT SUM(m.costo_usd) FROM mantenimientos m WHERE m.taller_id = t.id) AS costo_usd,
                       (SELECT MAX(m.fecha) FROM mantenimientos m WHERE m.taller_id = t.id) AS ultimo_uso
                  FROM talleres t
                  JOIN estaciones e ON e.id = t.estacion_id
                 WHERE 1 = 1';
        $params = [];
        if (!empty($filtros['estacion_id'])) {
            $sql .= ' AND t.estacion_id = :estacion';
            $params[':estacion'] = (int) $filtros['estacion_id'];
        }
        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (t.nombre LIKE :q1 OR t.clave LIKE :q2)';
            $params[':q1'] = '%' . $q . '%';
            $params[':q2'] = '%' . NombreCatalogo::clave($q) . '%';
        }
        $estado = (string) ($filtros['estado'] ?? 'activos');
        if ($estado === 'activos') {
            $sql .= ' AND t.activo = 1';
        } elseif ($estado === 'desactivados') {
            $sql .= ' AND t.activo = 0';
        }
        $sql .= ' ORDER BY t.activo DESC, e.codigo, t.nombre';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Activos de las estaciones dadas, para el desplegable del formulario de registro. */
    public function activos(array $estaciones): array
    {
        $ids = array_values(array_filter(array_map('intval', $estaciones)));
        $sql = 'SELECT t.id, t.nombre, t.estacion_id, t.es_propio, e.codigo AS estacion_codigo
                  FROM talleres t
                  JOIN estaciones e ON e.id = t.estacion_id
                 WHERE t.activo = 1'
            . ($ids === [] ? '' : ' AND t.estacion_id IN (' . implode(',', $ids) . ')')
            . ' ORDER BY e.codigo, t.nombre';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function crear(array $data, ?int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO talleres (estacion_id, nombre, clave, es_propio, telefonos, notas, activo, created_by)
             VALUES (:estacion_id, :nombre, :clave, :es_propio, :telefonos, :notas, 1, :por)'
        );
        $stmt->execute([
            ':estacion_id' => $data['estacion_id'],
            ':nombre' => $data['nombre'],
            ':clave' => $data['clave'],
            ':es_propio' => $data['es_propio'],
            ':telefonos' => $data['telefonos'],
            ':notas' => $data['notas'],
            ':por' => $usuarioId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $data): void
    {
        $this->pdo->prepare(
            'UPDATE talleres SET nombre = :nombre, clave = :clave, es_propio = :es_propio,
                    telefonos = :telefonos, notas = :notas
              WHERE id = :id'
        )->execute([
            ':nombre' => $data['nombre'],
            ':clave' => $data['clave'],
            ':es_propio' => $data['es_propio'],
            ':telefonos' => $data['telefonos'],
            ':notas' => $data['notas'],
            ':id' => $id,
        ]);
    }

    public function setActivo(int $id, bool $activo): void
    {
        $this->pdo->prepare('UPDATE talleres SET activo = :a WHERE id = :id')
            ->execute([':a' => $activo ? 1 : 0, ':id' => $id]);
    }
}
