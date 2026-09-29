<?php
/**
 * Datos de las hojas de gráficos.
 *
 * No devuelve totales ya calculados: devuelve la tabla de hechos en crudo, una fila por
 * movimiento o por intervención, con sus dimensiones ya resueltas a texto. El agregado lo hace
 * el navegador.
 *
 * Es lo que permite el filtro cruzado: al pulsar una barra hay que recalcular los otros seis
 * gráficos, y pedirle eso al servidor en cada clic haría la pantalla inservible. Un año de
 * operación son unos pocos miles de filas pequeñas, así que viaja bien en una sola petición.
 */

declare(strict_types=1);

final class GraficoService
{
    /** Hojas disponibles; cada una es una tabla de hechos distinta. */
    public const HOJAS = [
        'movimientos' => 'Movimientos',
        'costos'      => 'Costos de mantenimiento',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Rango por defecto: el año corriente. Un rango abierto traería todo el histórico a la
     * primera carga, que es justo lo que no hace falta para empezar a mirar.
     */
    public function rango(array $query): array
    {
        $hoy = new DateTimeImmutable('today');
        $desde = $this->fecha($query['desde'] ?? null) ?? $hoy->format('Y') . '-01-01';
        $hasta = $this->fecha($query['hasta'] ?? null) ?? $hoy->format('Y-m-d');
        return ['desde' => $desde, 'hasta' => $hasta];
    }

    /**
     * Una fila por movimiento con sus dimensiones resueltas.
     *
     * Entran también las reservas con proveedor, que no tienen unidad propia: son movimientos
     * igual y dejarlas fuera falsearía cualquier total.
     */
    public function movimientos(array $rango, ?int $estacionId): array
    {
        $sql = "SELECT m.id,
                       DATE(m.fecha_salida) AS fecha,
                       COALESCE(eu.codigo, ep.codigo, 'Sin estación') AS estacion,
                       COALESCE(c.nombre, 'De proveedor') AS categoria,
                       COALESCE(r.nombre, CONCAT_WS(' → ', po.codigo_iso, pd.codigo_iso), 'Sin ruta') AS ruta,
                       m.tipo_ruta AS tipo,
                       CASE WHEN m.unidad_id IS NULL THEN 'De proveedor' ELSE 'Propia' END AS flota,
                       m.estado,
                       COALESCE(u.placa_unidad, t.placa_motriz, 'Sin placa') AS unidad,
                       COALESCE(p.nombre, t.piloto, 'Sin piloto') AS piloto,
                       ROUND(TIMESTAMPDIFF(MINUTE, m.fecha_salida,
                             COALESCE(m.fecha_fin_real, m.fecha_fin_estimada)) / 60, 1) AS horas,
                       CASE WHEN m.fecha_fin_real IS NOT NULL
                             AND m.fecha_fin_real > m.fecha_fin_estimada THEN 1 ELSE 0 END AS demora,
                       CASE WHEN m.movimiento_regreso_id IS NOT NULL THEN 1 ELSE 0 END AS retorno
                  FROM movimientos m
                  LEFT JOIN unidades u ON u.id = m.unidad_id
                  LEFT JOIN estaciones eu ON eu.id = u.estacion_id
                  LEFT JOIN estaciones ep ON ep.id = m.estacion_id
                  LEFT JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
                  LEFT JOIN rutas r ON r.id = m.ruta_id
                  LEFT JOIN paises po ON po.id = m.pais_origen_id
                  LEFT JOIN paises pd ON pd.id = m.pais_destino_id
                  LEFT JOIN pilotos p ON p.id = m.piloto_id
                  LEFT JOIN movimiento_tercero t ON t.movimiento_id = m.id
                 WHERE m.fecha_salida >= :desde AND m.fecha_salida <= :hasta
                   AND m.estado <> 'CANCELADO'";
        $params = [':desde' => $rango['desde'] . ' 00:00:00', ':hasta' => $rango['hasta'] . ' 23:59:59'];

        if ($estacionId !== null) {
            $sql .= ' AND (u.estacion_id = :est OR (m.unidad_id IS NULL AND m.estacion_id = :est2))';
            $params[':est'] = $estacionId;
            $params[':est2'] = $estacionId;
        }
        $sql .= ' ORDER BY m.fecha_salida';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Una fila por intervención de mantenimiento, con su costo ya en dólares. */
    public function costos(array $rango, ?int $estacionId): array
    {
        $sql = "SELECT mt.id,
                       mt.fecha,
                       e.codigo AS estacion,
                       COALESCE(c.nombre, 'Sin categoría') AS categoria,
                       u.placa_unidad AS unidad,
                       COALESCE(u.marca, 'Sin marca') AS marca,
                       COALESCE(ta.nombre, 'Sin taller') AS taller,
                       ti.nombre AS tipo,
                       CASE WHEN ta.es_propio = 1 THEN 'Propio' ELSE 'Externo' END AS taller_tipo,
                       COALESCE(mt.costo_usd, 0) AS costo
                  FROM mantenimientos mt
                  JOIN unidades u ON u.id = mt.unidad_id
                  JOIN estaciones e ON e.id = u.estacion_id
                  LEFT JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
                  LEFT JOIN talleres ta ON ta.id = mt.taller_id
                  JOIN tipos_mantenimiento ti ON ti.id = mt.tipo_mantenimiento_id
                 WHERE mt.fecha >= :desde AND mt.fecha <= :hasta";
        $params = [':desde' => $rango['desde'], ':hasta' => $rango['hasta']];

        if ($estacionId !== null) {
            $sql .= ' AND u.estacion_id = :est';
            $params[':est'] = $estacionId;
        }
        $sql .= ' ORDER BY mt.fecha';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function fecha(mixed $valor): ?string
    {
        $v = substr(trim((string) $valor), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}
