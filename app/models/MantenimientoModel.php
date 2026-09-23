<?php
/**
 * Intervenciones de mantenimiento: la única tabla de hechos del módulo.
 *
 * El "control" y el "análisis de costos" no son tablas: son consultas sobre esta. Por eso aquí
 * viven, además del CRUD, las dos consultas que los derivan.
 */

declare(strict_types=1);

final class MantenimientoModel
{
    /** Columnas que escribe el formulario. */
    public const CAMPOS = [
        'unidad_id', 'fecha', 'tipo_mantenimiento_id', 'descripcion', 'taller_id',
        'costo', 'moneda_id', 'tasa_usada', 'costo_usd', 'factura', 'km',
        'reinicia_ciclo', 'observaciones',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, u.placa_unidad, u.estacion_id, t.nombre AS taller, tm.nombre AS tipo,
                    mo.codigo AS moneda
               FROM mantenimientos m
               JOIN unidades u ON u.id = m.unidad_id
               JOIN tipos_mantenimiento tm ON tm.id = m.tipo_mantenimiento_id
               LEFT JOIN talleres t ON t.id = m.taller_id
               LEFT JOIN monedas mo ON mo.id = m.moneda_id
              WHERE m.id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function crear(array $data, ?int $usuarioId): int
    {
        $cols = self::CAMPOS;
        $stmt = $this->pdo->prepare(
            'INSERT INTO mantenimientos (' . implode(', ', $cols) . ', created_by)
             VALUES (:' . implode(', :', $cols) . ', :por)'
        );
        $params = [':por' => $usuarioId];
        foreach ($cols as $c) {
            $params[':' . $c] = $data[$c] ?? null;
        }
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $data): void
    {
        $sets = array_map(static fn(string $c): string => "{$c} = :{$c}", self::CAMPOS);
        $stmt = $this->pdo->prepare('UPDATE mantenimientos SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $params = [':id' => $id];
        foreach (self::CAMPOS as $c) {
            $params[':' . $c] = $data[$c] ?? null;
        }
        $stmt->execute($params);
    }

    public function eliminar(int $id): void
    {
        $this->pdo->prepare('DELETE FROM mantenimientos WHERE id = :id')->execute([':id' => $id]);
    }

    // ── Historial (la hoja 1) ──

    /**
     * Intervenciones que cumplen los filtros, de a página.
     *
     * @param array $filtros desde, hasta, estacion_id, unidad_id, tipo_id, taller_id, q
     * @return array{filas: array, total: int, pagina: int, paginas: int, por_pagina: int, costo_usd: float}
     */
    public function historial(array $filtros, int $pagina, int $porPagina): array
    {
        [$where, $params] = $this->filtros($filtros);

        $conteo = $this->pdo->prepare(
            "SELECT COUNT(*), COALESCE(SUM(m.costo_usd), 0)
               FROM mantenimientos m
               JOIN unidades u ON u.id = m.unidad_id
               {$where}"
        );
        $conteo->execute($params);
        [$total, $costo] = array_values($conteo->fetch(PDO::FETCH_NUM) ?: [0, 0]);

        $porPagina = max(1, $porPagina);
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min(max(1, $pagina), $paginas);
        $offset = ($pagina - 1) * $porPagina;

        $stmt = $this->pdo->prepare(
            "SELECT m.*, u.placa_unidad, u.marca, u.modelo, e.codigo AS estacion_codigo,
                    tm.nombre AS tipo, t.nombre AS taller, t.es_propio, mo.codigo AS moneda
               FROM mantenimientos m
               JOIN unidades u ON u.id = m.unidad_id
               JOIN estaciones e ON e.id = u.estacion_id
               JOIN tipos_mantenimiento tm ON tm.id = m.tipo_mantenimiento_id
               LEFT JOIN talleres t ON t.id = m.taller_id
               LEFT JOIN monedas mo ON mo.id = m.moneda_id
               {$where}
              ORDER BY m.fecha DESC, m.id DESC
              LIMIT {$porPagina} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'filas' => $stmt->fetchAll(),
            'total' => (int) $total,
            'costo_usd' => (float) $costo,
            'pagina' => $pagina,
            'paginas' => $paginas,
            'por_pagina' => $porPagina,
        ];
    }

    /** Las mismas filas del historial sin paginar, para exportar. */
    public function historialCompleto(array $filtros): array
    {
        return $this->historial($filtros, 1, 5000)['filas'];
    }

    /** Intervenciones de una unidad, para su ficha. */
    public function deUnidad(int $unidadId, int $limite = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, tm.nombre AS tipo, t.nombre AS taller
               FROM mantenimientos m
               JOIN tipos_mantenimiento tm ON tm.id = m.tipo_mantenimiento_id
               LEFT JOIN talleres t ON t.id = m.taller_id
              WHERE m.unidad_id = :u
              ORDER BY m.fecha DESC, m.id DESC
              LIMIT ' . max(1, $limite)
        );
        $stmt->execute([':u' => $unidadId]);
        return $stmt->fetchAll();
    }

    // ── Control (la hoja 2, ya calculada) ──

    /**
     * Una fila por unidad con todo lo que hace falta para el semáforo.
     *
     * Lo que se lee de la base son hechos: la última lectura, el último servicio que reinicia el
     * ciclo y el plan que aplica. Las restas —cuánto falta, en qué estado está— las hace el
     * servicio: son reglas de negocio, no de almacenamiento.
     *
     * `km_ref` y `fecha_ref` son la lectura más vieja de los últimos 90 días: con ella y la
     * actual sale el ritmo de la unidad, y con el ritmo se puede estimar la fecha del servicio.
     */
    public function control(array $filtros): array
    {
        $sql = 'SELECT u.id, u.placa_unidad, u.marca, u.modelo, u.estado_vehiculo,
                       e.codigo AS estacion_codigo, e.id AS estacion_id,
                       p.nombre AS piloto,
                       (SELECT l.km FROM lecturas_odometro l WHERE l.unidad_id = u.id
                         ORDER BY l.fecha DESC, l.id DESC LIMIT 1) AS km_actual,
                       (SELECT l.fecha FROM lecturas_odometro l WHERE l.unidad_id = u.id
                         ORDER BY l.fecha DESC, l.id DESC LIMIT 1) AS km_fecha,
                       (SELECT l.km FROM lecturas_odometro l WHERE l.unidad_id = u.id
                          AND l.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         ORDER BY l.fecha ASC, l.id ASC LIMIT 1) AS km_ref,
                       (SELECT l.fecha FROM lecturas_odometro l WHERE l.unidad_id = u.id
                          AND l.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         ORDER BY l.fecha ASC, l.id ASC LIMIT 1) AS fecha_ref,
                       (SELECT m.fecha FROM mantenimientos m WHERE m.unidad_id = u.id AND m.reinicia_ciclo = 1
                         ORDER BY m.fecha DESC, m.id DESC LIMIT 1) AS ultimo_fecha,
                       (SELECT m.km FROM mantenimientos m WHERE m.unidad_id = u.id AND m.reinicia_ciclo = 1
                         ORDER BY m.fecha DESC, m.id DESC LIMIT 1) AS ultimo_km,
                       (SELECT COALESCE(SUM(m.costo_usd), 0) FROM mantenimientos m WHERE m.unidad_id = u.id) AS costo_usd,
                       c.nombre AS categoria, c.plan_no_aplica,
                       CASE WHEN u.plan_mantenimiento_id IS NOT NULL THEN pl.intervalo_km
                            WHEN c.plan_no_aplica = 1 THEN NULL
                            ELSE COALESCE(pc.intervalo_km, pd.intervalo_km) END     AS intervalo_km,
                       CASE WHEN u.plan_mantenimiento_id IS NOT NULL THEN pl.intervalo_dias
                            WHEN c.plan_no_aplica = 1 THEN NULL
                            ELSE COALESCE(pc.intervalo_dias, pd.intervalo_dias) END AS intervalo_dias,
                       CASE WHEN u.plan_mantenimiento_id IS NOT NULL THEN pl.umbral_km
                            WHEN c.plan_no_aplica = 1 THEN NULL
                            ELSE COALESCE(pc.umbral_km, pd.umbral_km) END           AS umbral_km,
                       CASE WHEN u.plan_mantenimiento_id IS NOT NULL THEN pl.umbral_dias
                            WHEN c.plan_no_aplica = 1 THEN NULL
                            ELSE COALESCE(pc.umbral_dias, pd.umbral_dias) END       AS umbral_dias,
                       CASE WHEN u.plan_mantenimiento_id IS NOT NULL THEN pl.nombre
                            WHEN c.plan_no_aplica = 1 THEN NULL
                            ELSE COALESCE(pc.nombre, pd.nombre) END                 AS plan
                  FROM unidades u
                  JOIN estaciones e ON e.id = u.estacion_id
                  JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
                  LEFT JOIN pilotos p ON p.id = u.piloto_asignado_id
                  LEFT JOIN planes_mantenimiento pl ON pl.id = u.plan_mantenimiento_id
                  LEFT JOIN planes_mantenimiento pc ON pc.id = c.plan_mantenimiento_id
                  LEFT JOIN (SELECT * FROM planes_mantenimiento WHERE por_defecto = 1 AND activo = 1 LIMIT 1) pd ON 1 = 1
                 WHERE u.activo = 1';
        $params = [];
        if (!empty($filtros['estacion_id'])) {
            $sql .= ' AND u.estacion_id = :estacion';
            $params[':estacion'] = (int) $filtros['estacion_id'];
        }
        if (!empty($filtros['categoria_id'])) {
            $sql .= ' AND u.categoria_vehiculo_id = :categoria';
            $params[':categoria'] = (int) $filtros['categoria_id'];
        }
        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (u.placa_unidad LIKE :q1 OR u.marca LIKE :q2 OR u.modelo LIKE :q3)';
            $params[':q1'] = '%' . $q . '%';
            $params[':q2'] = '%' . $q . '%';
            $params[':q3'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY u.placa_unidad';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Costos (la hoja 3) ──

    /**
     * Gasto agrupado. `por` decide la agrupación: unidad, marca o taller.
     *
     * Solo suma lo que tiene costo en dólares: una intervención sin factura todavía no es gasto,
     * y contarla como cero haría que el promedio mintiera.
     */
    public function costos(string $por, array $filtros): array
    {
        $grupos = [
            'unidad' => ['u.placa_unidad', "CONCAT_WS(' · ', u.marca, u.modelo)"],
            'marca'  => ['COALESCE(u.marca, :sin_marca)', 'NULL'],
            'taller' => ['COALESCE(t.nombre, :sin_taller)', "CASE WHEN t.es_propio = 1 THEN 'Propio' ELSE 'Externo' END"],
        ];
        [$etiqueta, $detalle] = $grupos[$por] ?? $grupos['unidad'];

        [$where, $params] = $this->filtros($filtros);
        if ($por === 'marca') {
            $params[':sin_marca'] = 'Sin marca';
        }
        if ($por === 'taller') {
            $params[':sin_taller'] = 'Sin taller';
        }

        $stmt = $this->pdo->prepare(
            "SELECT {$etiqueta} AS etiqueta, {$detalle} AS detalle,
                    COUNT(*) AS intervenciones,
                    COALESCE(SUM(m.costo_usd), 0) AS total_usd,
                    COALESCE(AVG(m.costo_usd), 0) AS promedio_usd,
                    SUM(CASE WHEN m.costo_usd IS NULL THEN 1 ELSE 0 END) AS sin_costo
               FROM mantenimientos m
               JOIN unidades u ON u.id = m.unidad_id
               JOIN estaciones e ON e.id = u.estacion_id
               LEFT JOIN talleres t ON t.id = m.taller_id
               {$where}
              GROUP BY etiqueta, detalle
              ORDER BY total_usd DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array{0: string, 1: array<string, mixed>} [WHERE, parámetros] */
    private function filtros(array $f): array
    {
        $cond = [];
        $params = [];
        foreach ([
            'desde'       => ['m.fecha >= :desde', 'desde'],
            'hasta'       => ['m.fecha <= :hasta', 'hasta'],
            'estacion_id' => ['u.estacion_id = :estacion', 'estacion'],
            'unidad_id'   => ['m.unidad_id = :unidad', 'unidad'],
            'tipo_id'     => ['m.tipo_mantenimiento_id = :tipo', 'tipo'],
            'taller_id'   => ['m.taller_id = :taller', 'taller'],
        ] as $clave => [$expresion, $marcador]) {
            $valor = trim((string) ($f[$clave] ?? ''));
            if ($valor !== '') {
                $cond[] = $expresion;
                $params[':' . $marcador] = in_array($clave, ['desde', 'hasta'], true) ? $valor : (int) $valor;
            }
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $cond[] = '(u.placa_unidad LIKE :q1 OR m.descripcion LIKE :q2 OR m.factura LIKE :q3)';
            $params[':q1'] = '%' . $q . '%';
            $params[':q2'] = '%' . $q . '%';
            $params[':q3'] = '%' . $q . '%';
        }
        return [$cond === [] ? '' : 'WHERE ' . implode(' AND ', $cond), $params];
    }
}
