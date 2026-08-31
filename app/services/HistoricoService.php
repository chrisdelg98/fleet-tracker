<?php
/**
 * Histórico de actividad (plan §7.7). Se apoya en la bitácora (§5.9), que registra toda
 * escritura con su autor, momento y snapshot antes/después — permite ver el ciclo completo
 * de un movimiento (creación y cambios de estado, quién y cuándo). Filtros combinables y
 * export. Los timestamps están en UTC.
 */

declare(strict_types=1);

final class HistoricoService
{
    public const POR_PAGINA_OPCIONES = [10, 20, 50, 100];
    public const POR_PAGINA_DEFAULT = 20;

    public function __construct(private PDO $pdo)
    {
    }

    /** Normaliza el tamaño de página a una de las opciones permitidas. */
    public static function porPaginaValido(int $n): int
    {
        return in_array($n, self::POR_PAGINA_OPCIONES, true) ? $n : self::POR_PAGINA_DEFAULT;
    }

    /**
     * Historial de viajes: una fila por movimiento con lo que importa de él (unidad, ruta,
     * piloto, lo previsto frente a lo real) y su rastro de eventos.
     *
     * Es la vista principal porque un movimiento es la unidad de trabajo de la operación. El
     * registro crudo de la bitácora mezcla "se completó un viaje" con "alguien editó un
     * catálogo", y eso confunde más de lo que informa: vive aparte, en el registro del sistema.
     *
     * @return array{filas: array, eventos: array, total: int, pagina: int, paginas: int, por_pagina: int}
     */
    public function viajes(array $filtros, int $pagina = 1, int $porPagina = self::POR_PAGINA_DEFAULT): array
    {
        $porPagina = self::porPaginaValido($porPagina);
        [$where, $params] = $this->whereViajes($filtros);

        $conteo = $this->pdo->prepare("SELECT COUNT(*) FROM movimientos m JOIN unidades u ON u.id = m.unidad_id {$where}");
        $conteo->execute($params);
        $total = (int) $conteo->fetchColumn();

        $pagina = max(1, $pagina);
        $offset = ($pagina - 1) * $porPagina;

        $stmt = $this->pdo->prepare(
            "SELECT m.id, m.estado, m.tipo_ruta, m.reservado_para, m.notas,
                    m.fecha_salida, m.fecha_fin_estimada, m.fecha_fin_real,
                    m.retorno_disponible, m.movimiento_regreso_id,
                    u.placa_unidad, e.codigo AS estacion_codigo, e.timezone,
                    p.nombre AS piloto,
                    po.codigo_iso AS origen, pd.codigo_iso AS destino,
                    r.nombre AS ruta_nombre,
                    m.ruta_custom_origen, m.ruta_custom_destino,
                    TIMESTAMPDIFF(MINUTE, m.fecha_fin_estimada, m.fecha_fin_real) AS demora_min
               FROM movimientos m
               JOIN unidades u ON u.id = m.unidad_id
               JOIN estaciones e ON e.id = u.estacion_id
               LEFT JOIN pilotos p ON p.id = m.piloto_id
               LEFT JOIN paises po ON po.id = m.pais_origen_id
               LEFT JOIN paises pd ON pd.id = m.pais_destino_id
               LEFT JOIN rutas r ON r.id = m.ruta_id
               {$where}
              ORDER BY m.fecha_salida DESC, m.id DESC
              LIMIT " . $porPagina . " OFFSET " . $offset
        );
        $stmt->execute($params);
        $filas = $stmt->fetchAll();

        return [
            'filas'      => array_map([$this, 'normalizarViaje'], $filas),
            'eventos'    => $this->eventosDeMovimientos(array_column($filas, 'id')),
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => max(1, (int) ceil($total / $porPagina)),
            'por_pagina' => $porPagina,
        ];
    }

    /** Añade lo que se calcula igual en todas partes: la ruta legible y si llegó tarde. */
    private function normalizarViaje(array $m): array
    {
        $origen = $m['ruta_custom_origen'] ?: $m['origen'];
        $destino = $m['ruta_custom_destino'] ?: $m['destino'];
        $m['ruta'] = $m['ruta_nombre'] ?: trim(($origen ?? '?') . ' → ' . ($destino ?? '?'));

        // Solo hay demora cuando el viaje terminó: comparar contra un fin que aún no llega
        // convertiría en "tarde" a todo el que sigue en ruta.
        $minutos = $m['fecha_fin_real'] !== null ? (int) $m['demora_min'] : 0;
        $m['demora_min'] = $minutos > 0 ? $minutos : 0;
        $m['con_demora'] = $minutos > 0;
        return $m;
    }

    /** @return array<int, array> eventos de bitácora por id de movimiento, en orden */
    private function eventosDeMovimientos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT b.entidad_id, b.timestamp, b.accion, b.detalle, u.nombre AS usuario
               FROM bitacora b
               LEFT JOIN usuarios u ON u.id = b.usuario_id
              WHERE b.entidad = 'movimiento' AND b.entidad_id IN ({$marcas})
              ORDER BY b.id ASC"
        );
        $stmt->execute($ids);

        $mapa = [];
        foreach ($stmt->fetchAll() as $ev) {
            $mapa[(int) $ev['entidad_id']][] = $ev;
        }
        return $mapa;
    }

    private function whereViajes(array $f): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];

        // El rango se aplica a la salida: es la fecha por la que la gente busca un viaje.
        if (!empty($f['desde'])) {
            $where .= ' AND m.fecha_salida >= :desde';
            $params[':desde'] = substr((string) $f['desde'], 0, 10) . ' 00:00:00';
        }
        if (!empty($f['hasta'])) {
            $where .= ' AND m.fecha_salida <= :hasta';
            $params[':hasta'] = substr((string) $f['hasta'], 0, 10) . ' 23:59:59';
        }
        if (!empty($f['estacion_id'])) {
            $where .= ' AND u.estacion_id = :estacion';
            $params[':estacion'] = (int) $f['estacion_id'];
        }
        if (!empty($f['estado'])) {
            $where .= ' AND m.estado = :estado';
            $params[':estado'] = $f['estado'];
        }
        if (!empty($f['tipo_ruta'])) {
            $where .= ' AND m.tipo_ruta = :tipo_ruta';
            $params[':tipo_ruta'] = $f['tipo_ruta'];
        }
        if (!empty($f['solo_demora'])) {
            $where .= ' AND m.fecha_fin_real IS NOT NULL AND m.fecha_fin_real > m.fecha_fin_estimada';
        }
        if (!empty($f['q'])) {
            // Una sola búsqueda para placa, piloto y cliente: quien busca no sabe (ni le
            // importa) en qué columna está guardado lo que recuerda del viaje.
            $where .= " AND CONCAT(u.placa_unidad, ' ', COALESCE((SELECT nombre FROM pilotos WHERE id = m.piloto_id), ''),"
                . " ' ', COALESCE(m.reservado_para, '')) LIKE :q";
            $params[':q'] = '%' . $f['q'] . '%';
        }
        return [$where, $params];
    }

    /**
     * Lista la bitácora AGRUPADA por entidad (una fila por movimiento/unidad/… con su última
     * actividad), más el historial completo de cada grupo de la página para el modal.
     *
     * @return array{filas: array, eventos: array, total: int, pagina: int, paginas: int}
     */
    public function listar(array $filtros, int $pagina = 1, int $porPagina = self::POR_PAGINA_DEFAULT): array
    {
        $porPagina = self::porPaginaValido($porPagina);
        [$where, $params] = $this->where($filtros);

        $total = $this->contarGrupos($where, $params);
        $pagina = max(1, $pagina);
        $offset = ($pagina - 1) * $porPagina;

        // Un grupo por (entidad, entidad_id); se ordena por el evento más reciente.
        $stmt = $this->pdo->prepare(
            "SELECT g.entidad, g.entidad_id, g.eventos, g.primera, g.ultima,
                    ult.accion AS ultima_accion, ultu.nombre AS ultimo_usuario
               FROM (
                    SELECT b.entidad, b.entidad_id, COUNT(*) AS eventos,
                           MIN(b.timestamp) AS primera, MAX(b.timestamp) AS ultima, MAX(b.id) AS ultimo_id
                      FROM bitacora b
                      {$where}
                     GROUP BY b.entidad, b.entidad_id
                     ORDER BY ultimo_id DESC
                     LIMIT " . $porPagina . " OFFSET " . $offset . "
               ) g
               JOIN bitacora ult ON ult.id = g.ultimo_id
               LEFT JOIN usuarios ultu ON ultu.id = ult.usuario_id
              ORDER BY g.ultimo_id DESC"
        );
        $stmt->execute($params);
        $grupos = $stmt->fetchAll();

        return [
            'filas'      => $grupos,
            'etiquetas'  => $this->etiquetasDe($grupos),
            'eventos'    => $this->eventosDe($grupos),
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => max(1, (int) ceil($total / $porPagina)),
            'por_pagina' => $porPagina,
        ];
    }

    /**
     * Cómo se llama de verdad cada registro citado. La bitácora solo guarda entidad + id, y
     * "unidad #117" no le dice nada a nadie: hay que ir a buscar la placa a otra pantalla.
     *
     * Un id que ya no existe (registro borrado) se queda sin etiqueta y la vista muestra el
     * número: el histórico tiene que poder hablar de cosas que ya no están.
     *
     * @return array<string, string> "{entidad}#{id}" => etiqueta
     */
    private function etiquetasDe(array $grupos): array
    {
        // entidad => [tabla, expresión que produce la etiqueta]
        $fuentes = [
            'unidad'    => ['unidades', 'placa_unidad'],
            'piloto'    => ['pilotos', 'nombre'],
            'usuario'   => ['usuarios', 'nombre'],
            'ruta'      => ['rutas', 'nombre'],
            'estacion'  => ['estaciones', "CONCAT(codigo, ' · ', nombre)"],
        ];
        foreach (CatalogoAdminService::tablas() as $tabla) {
            $fuentes[$tabla] = [$tabla, 'nombre'];
        }

        $porEntidad = [];
        foreach ($grupos as $g) {
            $porEntidad[$g['entidad']][] = (int) $g['entidad_id'];
        }

        $etiquetas = [];
        foreach ($porEntidad as $entidad => $ids) {
            if ($entidad === 'movimiento') {
                $etiquetas += $this->etiquetasDeMovimientos($ids);
                continue;
            }
            if (!isset($fuentes[$entidad])) {
                continue;
            }
            [$tabla, $expr] = $fuentes[$entidad];
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("SELECT id, {$expr} AS etiqueta FROM {$tabla} WHERE id IN ({$marcas})");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $fila) {
                $etiquetas[$entidad . '#' . $fila['id']] = (string) $fila['etiqueta'];
            }
        }
        return $etiquetas;
    }

    /** Un movimiento se reconoce por su unidad y su ruta, no por su número. */
    private function etiquetasDeMovimientos(array $ids): array
    {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT m.id, u.placa_unidad,
                    po.codigo_iso AS origen, pd.codigo_iso AS destino
               FROM movimientos m
               JOIN unidades u ON u.id = m.unidad_id
               LEFT JOIN paises po ON po.id = m.pais_origen_id
               LEFT JOIN paises pd ON pd.id = m.pais_destino_id
              WHERE m.id IN ({$marcas})"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $fila) {
            $ruta = $fila['origen'] && $fila['destino'] ? " · {$fila['origen']} → {$fila['destino']}" : '';
            $out['movimiento#' . $fila['id']] = $fila['placa_unidad'] . $ruta;
        }
        return $out;
    }

    /**
     * Historial completo (todos los eventos, sin filtrar) de los grupos de la página, en orden
     * cronológico. Devuelve un mapa "{entidad}#{entidad_id}" => [eventos].
     */
    private function eventosDe(array $grupos): array
    {
        if ($grupos === []) {
            return [];
        }
        $pares = [];
        $params = [];
        foreach ($grupos as $i => $g) {
            $pares[] = "(:e{$i}, :i{$i})";
            $params[":e{$i}"] = $g['entidad'];
            $params[":i{$i}"] = (int) $g['entidad_id'];
        }
        $in = implode(', ', $pares);
        $stmt = $this->pdo->prepare(
            "SELECT b.entidad, b.entidad_id, b.timestamp, b.accion, b.detalle, u.nombre AS usuario
               FROM bitacora b
               LEFT JOIN usuarios u ON u.id = b.usuario_id
              WHERE (b.entidad, b.entidad_id) IN ({$in})
              ORDER BY b.id ASC"
        );
        $stmt->execute($params);

        $mapa = [];
        foreach ($stmt->fetchAll() as $ev) {
            $mapa[$ev['entidad'] . '#' . $ev['entidad_id']][] = $ev;
        }
        return $mapa;
    }

    /** Todas las filas que cumplen el filtro (para export), sin paginar. */
    public function exportar(array $filtros): array
    {
        [$where, $params] = $this->where($filtros);
        $stmt = $this->pdo->prepare(
            "SELECT b.timestamp, u.nombre AS usuario, b.entidad, b.entidad_id, b.accion, b.detalle
               FROM bitacora b LEFT JOIN usuarios u ON u.id = b.usuario_id
               {$where} ORDER BY b.id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function contarGrupos(string $where, array $params): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM bitacora b{$where} GROUP BY b.entidad, b.entidad_id
             ) g"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function where(array $f): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];
        if (!empty($f['desde'])) {
            $where .= ' AND b.timestamp >= :desde';
            $params[':desde'] = substr((string) $f['desde'], 0, 10) . ' 00:00:00';
        }
        if (!empty($f['hasta'])) {
            $where .= ' AND b.timestamp <= :hasta';
            $params[':hasta'] = substr((string) $f['hasta'], 0, 10) . ' 23:59:59';
        }
        if (!empty($f['entidad'])) {
            $where .= ' AND b.entidad = :entidad';
            $params[':entidad'] = $f['entidad'];
        }
        if (!empty($f['accion'])) {
            $where .= ' AND b.accion = :accion';
            $params[':accion'] = $f['accion'];
        }
        if (!empty($f['usuario_id'])) {
            $where .= ' AND b.usuario_id = :usuario';
            $params[':usuario'] = (int) $f['usuario_id'];
        }
        if (!empty($f['entidad_id'])) {
            $where .= ' AND b.entidad_id = :eid';
            $params[':eid'] = (int) $f['entidad_id'];
        }
        return [$where, $params];
    }
}
