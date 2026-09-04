<?php
/**
 * Listas de notificación: destinatarios con nombre que se eligen al reservar.
 *
 * Cada estación gestiona las suyas; estacion_id NULL es una lista corporativa, visible desde
 * cualquier estación. El nombre solo es único dentro de su estación: "SV TEAM" puede
 * existir en SV y en GT y ser gente distinta.
 */

declare(strict_types=1);

final class ListaNotificacionModel
{
    private const CAMPOS = ['estacion_id', 'nombre', 'correos'];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM listas_notificacion WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Listas visibles para una estación: las suyas más las corporativas.
     *
     * @param int|null $estacionId null = todas (admin sin filtro)
     */
    public function listar(?int $estacionId, ?string $q = null): array
    {
        $sql = 'SELECT l.*, e.codigo AS estacion_codigo
                  FROM listas_notificacion l
                  LEFT JOIN estaciones e ON e.id = l.estacion_id
                 WHERE l.activo = 1';
        $params = [];
        if ($estacionId !== null) {
            $sql .= ' AND (l.estacion_id = :e OR l.estacion_id IS NULL)';
            $params[':e'] = $estacionId;
        }
        if ($q !== null && $q !== '') {
            $sql .= ' AND (l.nombre LIKE :q OR l.correos LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        // Las corporativas al final: primero lo propio, que es lo que más se usa.
        $sql .= ' ORDER BY l.estacion_id IS NULL, l.nombre';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** ¿Ya hay otra lista con ese nombre en la misma estación? */
    public function nombreRepetido(?int $estacionId, string $nombre, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM listas_notificacion
                 WHERE nombre = :n AND activo = 1
                   AND ' . ($estacionId === null ? 'estacion_id IS NULL' : 'estacion_id = :e');
        $params = [':n' => $nombre];
        if ($estacionId !== null) {
            $params[':e'] = $estacionId;
        }
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
        $ph = array_map(static fn(string $c): string => ':' . $c, $cols);
        $stmt = $this->pdo->prepare(
            'INSERT INTO listas_notificacion (' . implode(', ', $cols) . ', created_by) VALUES ('
            . implode(', ', $ph) . ', :created_by)'
        );
        $stmt->execute($this->bind($data) + [':created_by' => $usuarioId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $data): void
    {
        $sets = array_map(static fn(string $c): string => "{$c} = :{$c}", self::CAMPOS);
        $stmt = $this->pdo->prepare('UPDATE listas_notificacion SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($this->bind($data) + [':id' => $id]);
    }

    /** Baja lógica: los movimientos ya notificados conservan sus correos, no la referencia. */
    public function softDelete(int $id): void
    {
        $this->pdo->prepare('UPDATE listas_notificacion SET activo = 0 WHERE id = :id')->execute([':id' => $id]);
    }

    private function bind(array $data): array
    {
        $params = [];
        foreach (self::CAMPOS as $campo) {
            $params[':' . $campo] = $data[$campo] ?? null;
        }
        return $params;
    }
}
