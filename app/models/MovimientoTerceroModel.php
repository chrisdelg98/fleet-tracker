<?php
/**
 * Datos del camión de tercero que hizo un movimiento, tal como fueron en ese viaje.
 *
 * El proveedor y el camión viven en el catálogo (ProveedorModel, ProveedorCamionModel); aquí
 * queda el enlace al proveedor y una copia de lo de ese viaje. La copia no sobra: si mañana el
 * camión cambia de motorista en el catálogo, este viaje tiene que seguir diciendo quién lo hizo.
 */

declare(strict_types=1);

final class MovimientoTerceroModel
{
    private const CAMPOS = [
        'proveedor_id', 'proveedor', 'placa_motriz', 'placa_arrastre', 'piloto', 'licencia',
        'documento', 'telefonos', 'codigo_nacional', 'codigo_internacional',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $movimientoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM movimiento_tercero WHERE movimiento_id = :id');
        $stmt->execute([':id' => $movimientoId]);
        return $stmt->fetch() ?: null;
    }

    /** Crea o reemplaza los datos del tercero de un movimiento. */
    public function guardar(int $movimientoId, array $data): void
    {
        $cols = self::CAMPOS;
        $ph = array_map(static fn(string $c): string => ':' . $c, $cols);
        $sets = array_map(static fn(string $c): string => "{$c} = VALUES({$c})", $cols);

        $stmt = $this->pdo->prepare(
            'INSERT INTO movimiento_tercero (movimiento_id, ' . implode(', ', $cols) . ')'
            . ' VALUES (:movimiento_id, ' . implode(', ', $ph) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets)
        );

        $params = [':movimiento_id' => $movimientoId];
        foreach ($cols as $campo) {
            $valor = trim((string) ($data[$campo] ?? ''));
            $params[':' . $campo] = $valor === '' ? null : $valor;
        }
        $stmt->execute($params);
    }

    public function eliminar(int $movimientoId): void
    {
        $this->pdo->prepare('DELETE FROM movimiento_tercero WHERE movimiento_id = :id')
            ->execute([':id' => $movimientoId]);
    }
}
