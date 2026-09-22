<?php
/** Camiones de los proveedores, uno por placa de cabezal. Ver 041_proveedores.sql. */

declare(strict_types=1);

final class ProveedorCamionModel
{
    /** Datos del camión que se escriben desde la pantalla, el Excel o una reserva. */
    public const CAMPOS = [
        'placa_motriz', 'placa_arrastre', 'piloto', 'licencia', 'documento',
        'telefonos', 'codigo_nacional', 'codigo_internacional',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, p.nombre AS proveedor FROM proveedor_camiones c
               JOIN proveedores p ON p.id = c.proveedor_id
              WHERE c.id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function porPlaca(string $placa): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, p.nombre AS proveedor FROM proveedor_camiones c
               JOIN proveedores p ON p.id = c.proveedor_id
              WHERE c.placa_motriz = :placa'
        );
        $stmt->execute([':placa' => $placa]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Camiones de varios proveedores de una vez, agrupados por proveedor.
     *
     * @param int[] $proveedores
     * @return array<int, list<array>> proveedor_id => camiones
     */
    public function deProveedores(array $proveedores, bool $soloActivos): array
    {
        $ids = array_values(array_filter(array_map('intval', $proveedores)));
        if ($ids === []) {
            return [];
        }
        // Los ids ya son enteros: se pueden escribir en la consulta sin riesgo.
        $sql = 'SELECT * FROM proveedor_camiones WHERE proveedor_id IN (' . implode(',', $ids) . ')'
            . ($soloActivos ? ' AND activo = 1' : '')
            . ' ORDER BY activo DESC, placa_motriz';
        $out = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $c) {
            $out[(int) $c['proveedor_id']][] = $c;
        }
        return $out;
    }

    /**
     * Lo que el formulario de reserva ofrece al escribir: camiones activos de proveedores
     * activos, con el nombre del proveedor para completarlo junto con lo demás.
     */
    public function paraReservar(): array
    {
        return $this->pdo->query(
            'SELECT c.placa_motriz, c.placa_arrastre, c.piloto, c.licencia, c.documento,
                    c.telefonos, c.codigo_nacional, c.codigo_internacional, p.nombre AS proveedor
               FROM proveedor_camiones c
               JOIN proveedores p ON p.id = c.proveedor_id
              WHERE c.activo = 1 AND p.activo = 1 AND p.fusionado_en_id IS NULL
              ORDER BY c.placa_motriz'
        )->fetchAll();
    }

    public function crear(int $proveedorId, array $data, ?int $usuarioId): int
    {
        $cols = self::CAMPOS;
        $stmt = $this->pdo->prepare(
            'INSERT INTO proveedor_camiones (proveedor_id, ' . implode(', ', $cols) . ', activo, created_by)
             VALUES (:proveedor_id, :' . implode(', :', $cols) . ', 1, :por)'
        );
        $params = [':proveedor_id' => $proveedorId, ':por' => $usuarioId];
        foreach ($cols as $c) {
            $params[':' . $c] = $data[$c] ?? null;
        }
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    /** Reemplaza los datos del camión y, si viene, lo mueve de proveedor. */
    public function actualizar(int $id, int $proveedorId, array $data): void
    {
        $sets = array_map(static fn(string $c): string => "{$c} = :{$c}", self::CAMPOS);
        $stmt = $this->pdo->prepare(
            'UPDATE proveedor_camiones SET proveedor_id = :proveedor_id, ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $params = [':proveedor_id' => $proveedorId, ':id' => $id];
        foreach (self::CAMPOS as $c) {
            $params[':' . $c] = $data[$c] ?? null;
        }
        $stmt->execute($params);
    }

    public function setActivo(int $id, bool $activo): void
    {
        $this->pdo->prepare('UPDATE proveedor_camiones SET activo = :a WHERE id = :id')
            ->execute([':a' => $activo ? 1 : 0, ':id' => $id]);
    }
}
