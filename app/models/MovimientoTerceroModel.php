<?php
/**
 * Datos del camión de tercero que hizo un movimiento.
 *
 * No hay catálogo de proveedores a propósito: el dato vive con el movimiento y el formulario
 * lo recuerda por placa. Así la "base de datos de flota externa" emerge del uso —es la lista
 * de lo que ya se usó— en vez de ser algo que alguien tiene que mantener, y no quedan fichas
 * de un solo uso ni camiones ajenos ensuciando el inventario.
 */

declare(strict_types=1);

final class MovimientoTerceroModel
{
    private const CAMPOS = [
        'proveedor', 'placa_motriz', 'placa_arrastre', 'piloto',
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

    /**
     * Lo último que se registró de esa placa, para rellenar el formulario sin retipear.
     *
     * Es lo que convierte "no tengo al tercero guardado" en "no tengo que volver a escribirlo":
     * se busca por placa y se devuelve el resto del juego de datos.
     */
    public function ultimoPorPlaca(string $placa): ?array
    {
        $placa = trim($placa);
        if ($placa === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT t.* FROM movimiento_tercero t
               JOIN movimientos m ON m.id = t.movimiento_id
              WHERE t.placa_motriz = :placa
              ORDER BY m.fecha_salida DESC
              LIMIT 1'
        );
        $stmt->execute([':placa' => $placa]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Camiones de proveedor ya usados, para las sugerencias del formulario.
     *
     * Devuelve el juego completo de cada placa y no solo el nombre: así elegirla rellena
     * proveedor, motorista y códigos sin un segundo viaje al servidor, que es lo que haría
     * notar la espera justo mientras se teclea.
     *
     * @return list<array<string, string|null>>
     */
    public function usados(?int $estacionId = null, int $limite = 200): array
    {
        // De cada placa, su registro más reciente: el subselect elige el último movimiento en
        // que apareció, y el JOIN trae esa fila entera.
        $sql = 'SELECT t.placa_motriz, t.proveedor, t.placa_arrastre, t.piloto,
                       t.documento, t.telefonos, t.codigo_nacional, t.codigo_internacional
                  FROM movimiento_tercero t
                  JOIN movimientos m ON m.id = t.movimiento_id
                  JOIN (SELECT placa_motriz, MAX(movimiento_id) AS ultimo
                          FROM movimiento_tercero
                         WHERE placa_motriz IS NOT NULL AND placa_motriz <> ""
                         GROUP BY placa_motriz) u
                    ON u.placa_motriz = t.placa_motriz AND u.ultimo = t.movimiento_id
                 WHERE 1 = 1';
        $params = [];
        if ($estacionId !== null) {
            $sql .= ' AND m.estacion_id = :e';
            $params[':e'] = $estacionId;
        }
        // Por la más reciente: lo que se usó ayer es lo que se va a volver a usar.
        $sql .= ' ORDER BY m.fecha_salida DESC LIMIT ' . max(1, $limite);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
