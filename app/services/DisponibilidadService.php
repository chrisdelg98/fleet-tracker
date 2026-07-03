<?php
/**
 * Cálculo de disponibilidad (plan §2) — el concepto central. El estado NO se almacena:
 * se deriva de los movimientos y overrides de la unidad para el rango consultado.
 *
 * Prioridad para un rango [desde, hasta] (todo en UTC):
 *   1. Override abierto que solapa el rango  → TALLER_BLOQUEADA
 *   2. Movimiento EN_TRANSITO que solapa      → EN_TRANSITO
 *   3. Movimiento RESERVADO/PROGRAMADO solapa → RESERVADA
 *   4. En cualquier otro caso                 → DISPONIBLE
 *
 * Solo participan unidades con en_disponibilidad = 1 (regla 13). Consultar un rango a
 * futuro responde "¿qué hay libre mañana / la otra semana?" con la misma lógica.
 */

declare(strict_types=1);

final class DisponibilidadService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
    * @param string $desdeUtc 'Y-m-d H:i:s'
    * @param string $hastaUtc 'Y-m-d H:i:s'
    * @param array  $filtros  estacion_id?, tipo_equipo_id?, placa?, estados?(array), solo_retorno?, sin_retorno?, retorno_hacia?, ocultar_fuera_operacion?
     */
    public function calcular(string $desdeUtc, string $hastaUtc, array $filtros = []): array
    {
        $sql = 'SELECT
                    u.id, u.placa_unidad, u.placa_furgon, u.estacion_id,
                    e.codigo AS estacion_codigo, e.timezone,
                    te.nombre AS tipo_equipo, cap.nombre AS capacidad,
                    ap.nombre AS piloto_asignado,
                    m.id AS mov_id, m.estado AS mov_estado, m.fecha_salida, m.fecha_fin_estimada,
                    m.retorno_disponible, m.pais_solicita_retorno_id, m.reservado_para, m.pais_origen_id,
                    mo.codigo_iso AS mov_origen, md.codigo_iso AS mov_destino,
                    mr.codigo_iso AS retorno_iso, mp.nombre AS mov_piloto,
                    o.id AS override_id, o.tipo AS override_tipo, o.motivo AS override_motivo
                  FROM unidades u
                  JOIN estaciones e ON e.id = u.estacion_id
                  LEFT JOIN tipos_equipo te ON te.id = u.tipo_equipo_id
                  LEFT JOIN capacidades cap ON cap.id = u.capacidad_id
                  LEFT JOIN pilotos ap ON ap.id = u.piloto_asignado_id
                  LEFT JOIN movimientos m ON m.id = (
                        SELECT m2.id FROM movimientos m2
                         WHERE m2.unidad_id = u.id
                           AND m2.estado IN (\'RESERVADO\', \'PROGRAMADO\', \'EN_TRANSITO\')
                           AND m2.fecha_salida <= :hasta1 AND m2.fecha_fin_estimada >= :desde1
                         ORDER BY (m2.estado = \'EN_TRANSITO\') DESC, m2.fecha_salida ASC
                         LIMIT 1)
                  LEFT JOIN pilotos mp ON mp.id = m.piloto_id
                  LEFT JOIN paises mo ON mo.id = m.pais_origen_id
                  LEFT JOIN paises md ON md.id = m.pais_destino_id
                  LEFT JOIN paises mr ON mr.id = m.pais_solicita_retorno_id
                  LEFT JOIN overrides_unidad o ON o.id = (
                        SELECT o2.id FROM overrides_unidad o2
                         WHERE o2.unidad_id = u.id AND o2.cerrado = 0
                           AND o2.desde <= :hasta2 AND (o2.hasta IS NULL OR o2.hasta >= :desde2)
                         ORDER BY o2.desde DESC
                         LIMIT 1)
                 WHERE u.en_disponibilidad = 1 AND u.activo = 1';

        $params = [
            ':hasta1' => $hastaUtc, ':desde1' => $desdeUtc,
            ':hasta2' => $hastaUtc, ':desde2' => $desdeUtc,
        ];
        if (!empty($filtros['estacion_id'])) {
            $sql .= ' AND u.estacion_id = :estacion';
            $params[':estacion'] = (int) $filtros['estacion_id'];
        }
        if (!empty($filtros['tipo_equipo_id'])) {
            $sql .= ' AND u.tipo_equipo_id = :tipo';
            $params[':tipo'] = (int) $filtros['tipo_equipo_id'];
        }
        if (!empty($filtros['placa'])) {
            $sql .= ' AND u.placa_unidad LIKE :placa';
            $params[':placa'] = '%' . $filtros['placa'] . '%';
        }
        $sql .= ' ORDER BY e.codigo, u.placa_unidad';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $estadosFiltro = $filtros['estados'] ?? [];
        $soloRetorno   = !empty($filtros['solo_retorno']);
        $sinRetorno    = !empty($filtros['sin_retorno']);
        $retornoHacia  = !empty($filtros['retorno_hacia']) ? (int) $filtros['retorno_hacia'] : null;
        $ocultarFueraOperacion = !empty($filtros['ocultar_fuera_operacion']);
        $ahoraUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $out = [];
        foreach ($rows as $r) {
            $estado = $this->estadoDe($r);

            if ($ocultarFueraOperacion && $estado === EstadoDisponibilidad::TALLER_BLOQUEADA) {
                continue;
            }

            if ($estadosFiltro && !in_array($estado, $estadosFiltro, true)) {
                continue;
            }
            $tieneRetorno = $r['mov_id'] && (int) $r['retorno_disponible'] === 1;
            $conDemora = $estado === EstadoDisponibilidad::EN_TRANSITO
                && !empty($r['fecha_fin_estimada'])
                && $r['fecha_fin_estimada'] < $ahoraUtc;
            if ($soloRetorno && !$tieneRetorno) {
                continue;
            }
            if ($sinRetorno && $tieneRetorno) {
                continue;
            }
            if ($retornoHacia !== null && (!$tieneRetorno || (int) $r['pais_origen_id'] !== $retornoHacia)) {
                continue;
            }

            $out[] = [
                'unidad_id'       => (int) $r['id'],
                'placa_unidad'    => $r['placa_unidad'],
                'placa_furgon'    => $r['placa_furgon'],
                'tipo_equipo'     => $r['tipo_equipo'],
                'capacidad'       => $r['capacidad'],
                'estacion_codigo' => $r['estacion_codigo'],
                'timezone'        => $r['timezone'],
                'estado'          => $estado,
                'con_demora'      => $conDemora,
                'piloto'          => $r['mov_piloto'] ?? $r['piloto_asignado'],
                'movimiento'      => $r['mov_id'] ? [
                    'id'                      => (int) $r['mov_id'],
                    'estado'                  => $r['mov_estado'],
                    'origen'                  => $r['mov_origen'],
                    'destino'                 => $r['mov_destino'],
                    'fecha_salida'            => $r['fecha_salida'],
                    'fecha_fin_estimada'      => $r['fecha_fin_estimada'],
                    'retorno_disponible'      => (int) $r['retorno_disponible'] === 1,
                    'retorno_iso'             => $r['retorno_iso'],
                    'pais_solicita_retorno_id' => $r['pais_solicita_retorno_id'] !== null ? (int) $r['pais_solicita_retorno_id'] : null,
                    'reservado_para'          => $r['reservado_para'],
                ] : null,
                'override'        => $r['override_id'] ? [
                    'tipo'   => $r['override_tipo'],
                    'motivo' => $r['override_motivo'],
                ] : null,
            ];
        }

        // Orden por defecto: disponibles primero, luego por "se libera" más próximo (plan §7.1).
        usort($out, function (array $a, array $b): int {
            $pa = $a['estado'] === EstadoDisponibilidad::DISPONIBLE ? 0 : 1;
            $pb = $b['estado'] === EstadoDisponibilidad::DISPONIBLE ? 0 : 1;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $la = $a['movimiento']['fecha_fin_estimada'] ?? '9999';
            $lb = $b['movimiento']['fecha_fin_estimada'] ?? '9999';
            return $la <=> $lb;
        });

        return $out;
    }

    private function estadoDe(array $r): string
    {
        if ($r['override_id']) {
            return EstadoDisponibilidad::TALLER_BLOQUEADA;
        }
        if ($r['mov_estado'] === EstadoMovimiento::EN_TRANSITO) {
            return EstadoDisponibilidad::EN_TRANSITO;
        }
        if (in_array($r['mov_estado'], [EstadoMovimiento::RESERVADO, EstadoMovimiento::PROGRAMADO], true)) {
            return EstadoDisponibilidad::RESERVADA;
        }
        return EstadoDisponibilidad::DISPONIBLE;
    }
}
