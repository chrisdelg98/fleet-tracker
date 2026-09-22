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
    /** Tamaños de página ofrecidos; el primero es el que se usa si no se pide otro. */
    public const POR_PAGINA_OPCIONES = [10, 15, 20];
    public const POR_PAGINA_DEFAULT = 15;

    public static function porPaginaValido(int $n): int
    {
        return in_array($n, self::POR_PAGINA_OPCIONES, true) ? $n : self::POR_PAGINA_DEFAULT;
    }

    /**
     * Usuarios que cumplen los filtros, de a página.
     *
     * @param array $filtros q, rol, estacion_id ('sin' = sin estación), activo ('1'|'0')
     * @return array{filas: array, total: int, pagina: int, paginas: int, por_pagina: int}
     */
    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = self::POR_PAGINA_DEFAULT): array
    {
        $porPagina = self::porPaginaValido($porPagina);
        [$where, $params] = $this->filtros($filtros);

        $conteo = $this->pdo->prepare("SELECT COUNT(*) FROM usuarios u {$where}");
        $conteo->execute($params);
        $total = (int) $conteo->fetchColumn();

        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min(max(1, $pagina), $paginas);
        $offset = ($pagina - 1) * $porPagina;

        // El tamaño y el desplazamiento ya son enteros validados: van en la consulta porque
        // MySQL no admite marcadores en LIMIT con prepares nativos.
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.nombre, u.email, u.rol, u.estacion_id, u.activo, e.codigo AS estacion_codigo
               FROM usuarios u
               LEFT JOIN estaciones e ON e.id = u.estacion_id
               {$where}
              ORDER BY u.activo DESC, u.nombre
              LIMIT {$porPagina} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'filas' => $stmt->fetchAll(),
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => $paginas,
            'por_pagina' => $porPagina,
        ];
    }

    /**
     * Todos los usuarios, en una lista plana.
     *
     * Para los desplegables que filtran por autor, donde paginar no tendría sentido: el que se
     * busca puede estar en cualquier página.
     */
    public function todos(): array
    {
        return $this->pdo->query(
            'SELECT id, nombre, email, rol, activo FROM usuarios ORDER BY nombre'
        )->fetchAll();
    }

    /** @return array{0: string, 1: array<string, mixed>} [WHERE, parámetros] */
    private function filtros(array $f): array
    {
        $cond = [];
        $params = [];

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            // Dos marcadores para el mismo texto: con prepares nativos uno no se enlaza dos veces.
            $cond[] = '(u.nombre LIKE :q1 OR u.email LIKE :q2)';
            $params[':q1'] = '%' . $q . '%';
            $params[':q2'] = '%' . $q . '%';
        }
        if (in_array($f['rol'] ?? '', Rol::values(), true)) {
            $cond[] = 'u.rol = :rol';
            $params[':rol'] = $f['rol'];
        }
        $estacion = (string) ($f['estacion_id'] ?? '');
        if ($estacion === 'sin') {
            $cond[] = 'u.estacion_id IS NULL';
        } elseif (ctype_digit($estacion)) {
            $cond[] = 'u.estacion_id = :estacion';
            $params[':estacion'] = (int) $estacion;
        }
        if (($f['activo'] ?? '') === '1' || ($f['activo'] ?? '') === '0') {
            $cond[] = 'u.activo = :activo';
            $params[':activo'] = (int) $f['activo'];
        }

        return [$cond === [] ? '' : 'WHERE ' . implode(' AND ', $cond), $params];
    }

    /**
     * Cuántos Admin Global activos quedarían sin contar a uno.
     *
     * Sirve para no dejar el sistema sin nadie que pueda administrarlo: quitarle el rol al
     * último o desactivarlo dejaría la puerta cerrada por dentro.
     */
    public function adminsActivosSalvo(int $exceptId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM usuarios WHERE rol = :rol AND activo = 1 AND id <> :id'
        );
        $stmt->execute([':rol' => Rol::ADMIN_GLOBAL, ':id' => $exceptId]);
        return (int) $stmt->fetchColumn();
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
