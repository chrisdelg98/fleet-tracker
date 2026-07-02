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
}
