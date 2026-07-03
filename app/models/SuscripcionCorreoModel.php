<?php
/**
 * Suscripciones de correo para Fase 4. Cada usuario puede suscribirse a avisos de
 * unidad liberada por estación y retornos disponibles hacia un país.
 */

declare(strict_types=1);

final class SuscripcionCorreoModel
{
    public const TIPO_UNIDAD_LIBERADA = 'UNIDAD_LIBERADA';
    public const TIPO_RETORNO_DISPONIBLE = 'RETORNO_DISPONIBLE';

    public function __construct(private PDO $pdo)
    {
    }

    public static function tipos(): array
    {
        return [self::TIPO_UNIDAD_LIBERADA, self::TIPO_RETORNO_DISPONIBLE];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sc.*, e.codigo AS estacion_codigo, e.nombre AS estacion_nombre,
                    p.nombre AS pais_nombre, p.codigo_iso AS pais_codigo
               FROM suscripciones_correo sc
               LEFT JOIN estaciones e ON e.id = sc.estacion_id
               LEFT JOIN paises p ON p.id = sc.pais_id
              WHERE sc.id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function listarDeUsuario(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sc.*, e.codigo AS estacion_codigo, e.nombre AS estacion_nombre,
                    p.nombre AS pais_nombre, p.codigo_iso AS pais_codigo
               FROM suscripciones_correo sc
               LEFT JOIN estaciones e ON e.id = sc.estacion_id
               LEFT JOIN paises p ON p.id = sc.pais_id
              WHERE sc.user_id = :user
                AND sc.activo = 1
              ORDER BY sc.tipo, e.codigo, p.nombre, sc.id'
        );
        $stmt->execute([':user' => $userId]);
        return $stmt->fetchAll();
    }

    public function existe(int $userId, string $tipo, ?int $estacionId, ?int $paisId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
               FROM suscripciones_correo
              WHERE user_id = :user
                AND tipo = :tipo
                AND activo = 1
                AND ((estacion_id IS NULL AND :estacion_null IS NULL) OR estacion_id = :estacion_val)
                AND ((pais_id IS NULL AND :pais_null IS NULL) OR pais_id = :pais_val)
              LIMIT 1'
        );
        $stmt->execute([
            ':user' => $userId,
            ':tipo' => $tipo,
            ':estacion_null' => $estacionId,
            ':estacion_val' => $estacionId,
            ':pais_null' => $paisId,
            ':pais_val' => $paisId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    public function crear(array $data, ?int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suscripciones_correo (user_id, tipo, estacion_id, pais_id, activo, created_by)
             VALUES (:user_id, :tipo, :estacion_id, :pais_id, 1, :created_by)'
        );
        $stmt->execute([
            ':user_id' => $data['user_id'],
            ':tipo' => $data['tipo'],
            ':estacion_id' => $data['estacion_id'],
            ':pais_id' => $data['pais_id'],
            ':created_by' => $usuarioId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function eliminar(int $id): void
    {
        $this->pdo->prepare('DELETE FROM suscripciones_correo WHERE id = :id')->execute([':id' => $id]);
    }

    public function destinatariosUnidadLiberada(int $estacionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT u.id, u.nombre, u.email
               FROM suscripciones_correo sc
               JOIN usuarios u ON u.id = sc.user_id
              WHERE sc.tipo = :tipo
                AND sc.estacion_id = :estacion
                AND sc.activo = 1
                AND u.activo = 1'
        );
        $stmt->execute([
            ':tipo' => self::TIPO_UNIDAD_LIBERADA,
            ':estacion' => $estacionId,
        ]);
        return array_values(array_filter(
            $stmt->fetchAll(),
            static fn(array $row): bool => trim((string) ($row['email'] ?? '')) !== ''
        ));
    }

    public function destinatariosRetorno(int $paisId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT u.id, u.nombre, u.email
               FROM suscripciones_correo sc
               JOIN usuarios u ON u.id = sc.user_id
              WHERE sc.tipo = :tipo
                AND sc.pais_id = :pais
                AND sc.activo = 1
                AND u.activo = 1'
        );
        $stmt->execute([
            ':tipo' => self::TIPO_RETORNO_DISPONIBLE,
            ':pais' => $paisId,
        ]);
        return array_values(array_filter(
            $stmt->fetchAll(),
            static fn(array $row): bool => trim((string) ($row['email'] ?? '')) !== ''
        ));
    }
}