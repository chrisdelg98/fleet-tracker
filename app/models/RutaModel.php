<?php
/**
 * Catálogo de rutas (plan §5.6). Rutas compartidas entre estaciones. PDO + prepared
 * statements; búsqueda por nombre/origen/destino.
 */

declare(strict_types=1);

final class RutaModel
{
    private const CAMPOS = [
        'nombre', 'pais_origen_id', 'ciudad_origen', 'pais_destino_id', 'ciudad_destino',
        'distancia_km', 'tipo_ruta', 'horas_transito_estimadas',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rutas WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Lista con países resueltos; búsqueda opcional por nombre, ciudades o país. */
    public function listar(?string $q = null): array
    {
        $sql = 'SELECT r.*, po.nombre AS pais_origen, pd.nombre AS pais_destino
                  FROM rutas r
                  JOIN paises po ON po.id = r.pais_origen_id
                  JOIN paises pd ON pd.id = r.pais_destino_id
                 WHERE r.activo = 1';
        $params = [];
        if ($q !== null && $q !== '') {
            $sql .= ' AND (r.nombre LIKE :q OR r.ciudad_origen LIKE :q OR r.ciudad_destino LIKE :q
                          OR po.nombre LIKE :q OR pd.nombre LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY r.nombre';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function crear(array $data, ?int $usuarioId): int
    {
        $cols = self::CAMPOS;
        $ph = array_map(static fn(string $c): string => ':' . $c, $cols);
        $stmt = $this->pdo->prepare(
            'INSERT INTO rutas (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', $ph) . ', :created_by)'
        );
        $stmt->execute($this->bind($data) + [':created_by' => $usuarioId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $data): void
    {
        $sets = array_map(static fn(string $c): string => "{$c} = :{$c}", self::CAMPOS);
        $stmt = $this->pdo->prepare('UPDATE rutas SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($this->bind($data) + [':id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->pdo->prepare('UPDATE rutas SET activo = 0 WHERE id = :id')->execute([':id' => $id]);
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
