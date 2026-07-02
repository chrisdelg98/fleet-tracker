<?php
/**
 * Acceso a datos de usuarios (plan §5.2). PDO + prepared statements en el 100% de las
 * queries (AGENTS.md §Seguridad 4).
 */

declare(strict_types=1);

final class UsuarioModel
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Busca por email (login). Devuelve la fila completa o null. */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        return $stmt->fetch() ?: null;
    }

    /** Busca por id. Devuelve la fila completa o null. */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Lista sin exponer el hash de contraseña. */
    public function listar(): array
    {
        return $this->pdo->query(
            'SELECT u.id, u.nombre, u.email, u.rol, u.estacion_id, u.activo, e.codigo AS estacion_codigo
               FROM usuarios u
               LEFT JOIN estaciones e ON e.id = u.estacion_id
              ORDER BY u.activo DESC, u.nombre'
        )->fetchAll();
    }

    public function emailExiste(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM usuarios WHERE email = :email';
        $params = [':email' => $email];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    public function crear(array $data, string $passwordHash, ?int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (nombre, email, password_hash, rol, estacion_id, created_by)
             VALUES (:nombre, :email, :hash, :rol, :estacion_id, :created_by)'
        );
        $stmt->execute([
            ':nombre'      => $data['nombre'],
            ':email'       => $data['email'],
            ':hash'        => $passwordHash,
            ':rol'         => $data['rol'],
            ':estacion_id' => $data['estacion_id'],
            ':created_by'  => $usuarioId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios SET nombre = :nombre, email = :email, rol = :rol, estacion_id = :estacion_id WHERE id = :id'
        );
        $stmt->execute([
            ':nombre'      => $data['nombre'],
            ':email'       => $data['email'],
            ':rol'         => $data['rol'],
            ':estacion_id' => $data['estacion_id'],
            ':id'          => $id,
        ]);
    }

    public function actualizarPassword(int $id, string $passwordHash): void
    {
        $this->pdo->prepare('UPDATE usuarios SET password_hash = :h WHERE id = :id')
            ->execute([':h' => $passwordHash, ':id' => $id]);
    }

    public function setActivo(int $id, bool $activo): void
    {
        $this->pdo->prepare('UPDATE usuarios SET activo = :a WHERE id = :id')
            ->execute([':a' => $activo ? 1 : 0, ':id' => $id]);
    }
}
