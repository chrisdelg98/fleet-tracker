<?php
/**
 * Acceso a datos de estaciones (plan §5.1). PDO + prepared statements. Soft-delete
 * (activo): los usuarios/flota/histórico las referencian.
 */

declare(strict_types=1);

final class EstacionModel
{
    private const CAMPOS = ['nombre', 'pais_id', 'codigo', 'timezone'];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM estaciones WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function listar(): array
    {
        return $this->pdo->query(
            'SELECT e.*, p.nombre AS pais FROM estaciones e
               JOIN paises p ON p.id = e.pais_id
              ORDER BY e.activo DESC, e.codigo'
        )->fetchAll();
    }

    public function codigoExiste(string $codigo, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM estaciones WHERE codigo = :codigo';
        $params = [':codigo' => $codigo];
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
        $stmt = $this->pdo->prepare(
            'INSERT INTO estaciones (nombre, pais_id, codigo, timezone, created_by)
             VALUES (:nombre, :pais_id, :codigo, :timezone, :created_by)'
        );
        $stmt->execute($this->bind($data) + [':created_by' => $usuarioId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE estaciones SET nombre = :nombre, pais_id = :pais_id, codigo = :codigo, timezone = :timezone WHERE id = :id'
        );
        $stmt->execute($this->bind($data) + [':id' => $id]);
    }

    public function setActivo(int $id, bool $activo): void
    {
        $this->pdo->prepare('UPDATE estaciones SET activo = :a WHERE id = :id')
            ->execute([':a' => $activo ? 1 : 0, ':id' => $id]);
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
