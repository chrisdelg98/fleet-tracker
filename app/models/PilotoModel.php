<?php
/**
 * Acceso a datos de pilotos (plan §5.4). PDO + prepared statements. Soft-delete (activo):
 * el histórico de movimientos los referencia, nunca se borran físicamente.
 */

declare(strict_types=1);

final class PilotoModel
{
    private const CAMPOS = ['nombre', 'tipo_licencia_id', 'no_licencia', 'licencia_vence', 'estacion_id'];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pilotos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Lista con nombres resueltos (tipo de licencia, estación). Filtra por estación si se indica. */
    public function listar(?int $estacionId = null, array $filtros = [], bool $soloActivos = true): array
    {
        $sql = 'SELECT p.*, tl.nombre AS tipo_licencia, e.codigo AS estacion_codigo
                  FROM pilotos p
                  JOIN tipos_licencia tl ON tl.id = p.tipo_licencia_id
                  JOIN estaciones e ON e.id = p.estacion_id
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
            $sql .= " AND CONCAT(p.nombre, ' ', p.no_licencia) LIKE :q";
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
