<?php
/**
 * Catálogo de proveedores de transporte. Ver database/migrations/041_proveedores.sql.
 *
 * Un proveedor fusionado sigue en la tabla, desactivado y apuntando al que sobrevivió: no se
 * lista en ningún sitio, pero su clave sigue reconociéndose para llevar al correcto.
 */

declare(strict_types=1);

final class ProveedorModel
{
    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM proveedores WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function porClave(string $clave): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM proveedores WHERE clave = :clave');
        $stmt->execute([':clave' => $clave]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Proveedores para la pantalla, con lo que ayuda a decidir: cuántos camiones activos
     * tienen, cuántos viajes hicieron y cuándo se usaron por última vez.
     *
     * @param string $estado 'activos' | 'desactivados' | 'todos'
     */
    public function listar(string $q, string $estado): array
    {
        $sql = 'SELECT p.id, p.nombre, p.activo,
                       (SELECT COUNT(*) FROM proveedor_camiones c
                         WHERE c.proveedor_id = p.id AND c.activo = 1) AS camiones_activos,
                       (SELECT COUNT(*) FROM movimiento_tercero t WHERE t.proveedor_id = p.id) AS viajes,
                       (SELECT MAX(m.fecha_salida) FROM movimiento_tercero t
                          JOIN movimientos m ON m.id = t.movimiento_id
                         WHERE t.proveedor_id = p.id) AS ultimo_uso
                  FROM proveedores p
                 WHERE p.fusionado_en_id IS NULL';
        $params = [];
        if ($estado === 'activos') {
            $sql .= ' AND p.activo = 1';
        } elseif ($estado === 'desactivados') {
            $sql .= ' AND p.activo = 0';
        }
        if ($q !== '') {
            // Por nombre o por la placa de cualquiera de sus camiones: quien busca suele tener
            // la placa delante, no el nombre de la empresa. Tres marcadores: con prepares
            // nativos uno solo no puede enlazarse dos veces.
            $sql .= ' AND (p.nombre LIKE :q1 OR p.clave LIKE :q2
                      OR EXISTS (SELECT 1 FROM proveedor_camiones c
                                  WHERE c.proveedor_id = p.id AND c.placa_motriz LIKE :q3))';
            $params[':q1'] = '%' . $q . '%';
            $params[':q2'] = '%' . NombreCatalogo::clave($q) . '%';
            $params[':q3'] = '%' . NombreCatalogo::placa($q) . '%';
        }
        $sql .= ' ORDER BY p.activo DESC, p.nombre';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Activos y no fusionados, para los desplegables. */
    public function activos(): array
    {
        return $this->pdo->query(
            'SELECT id, nombre FROM proveedores
              WHERE activo = 1 AND fusionado_en_id IS NULL
              ORDER BY nombre'
        )->fetchAll();
    }

    /** Todos, con su clave: el universo donde buscar parecidos. */
    public function todosConClave(): array
    {
        return $this->pdo->query(
            'SELECT id, nombre, clave, activo, fusionado_en_id FROM proveedores'
        )->fetchAll();
    }

    public function crear(string $nombre, string $clave, ?int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO proveedores (nombre, clave, activo, created_by) VALUES (:nombre, :clave, 1, :por)'
        );
        $stmt->execute([':nombre' => $nombre, ':clave' => $clave, ':por' => $usuarioId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function renombrar(int $id, string $nombre, string $clave): void
    {
        $this->pdo->prepare('UPDATE proveedores SET nombre = :nombre, clave = :clave WHERE id = :id')
            ->execute([':nombre' => $nombre, ':clave' => $clave, ':id' => $id]);
    }

    public function setActivo(int $id, bool $activo): void
    {
        $this->pdo->prepare('UPDATE proveedores SET activo = :a WHERE id = :id')
            ->execute([':a' => $activo ? 1 : 0, ':id' => $id]);
    }

    /**
     * Pasa todo lo de `origen` a `destino` y deja a `origen` como alias desactivado.
     *
     * Los viajes cambian también su copia del nombre: la estadística hacia atrás tiene que
     * salir con un solo proveedor, no con el correcto y su variante.
     */
    public function fusionar(int $origen, int $destino, string $nombreDestino): void
    {
        $this->pdo->prepare('UPDATE proveedor_camiones SET proveedor_id = :d WHERE proveedor_id = :o')
            ->execute([':d' => $destino, ':o' => $origen]);
        $this->pdo->prepare('UPDATE movimiento_tercero SET proveedor_id = :d, proveedor = :n WHERE proveedor_id = :o')
            ->execute([':d' => $destino, ':n' => $nombreDestino, ':o' => $origen]);
        // Los que ya eran alias de `origen` pasan a serlo de `destino`: sin esto, una fusión
        // encadenada dejaría nombres apuntando a un proveedor desactivado.
        $this->pdo->prepare('UPDATE proveedores SET fusionado_en_id = :d WHERE fusionado_en_id = :o')
            ->execute([':d' => $destino, ':o' => $origen]);
        $this->pdo->prepare('UPDATE proveedores SET activo = 0, fusionado_en_id = :d WHERE id = :o')
            ->execute([':d' => $destino, ':o' => $origen]);
    }

    // ── Reservas anteriores al catálogo ──

    /** Viajes con camión de proveedor que todavía no están enlazados a uno del catálogo. */
    public function viajesSinEnlazar(): array
    {
        return $this->pdo->query(
            'SELECT t.*, m.fecha_salida
               FROM movimiento_tercero t
               JOIN movimientos m ON m.id = t.movimiento_id
              WHERE t.proveedor_id IS NULL
              ORDER BY m.fecha_salida, t.movimiento_id'
        )->fetchAll();
    }

    public function enlazarViaje(int $movimientoId, int $proveedorId, string $nombre): void
    {
        $this->pdo->prepare(
            'UPDATE movimiento_tercero SET proveedor_id = :p, proveedor = :n WHERE movimiento_id = :m'
        )->execute([':p' => $proveedorId, ':n' => $nombre, ':m' => $movimientoId]);
    }
}
