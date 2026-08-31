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
    * @param array  $filtros  estacion_id?, categoria_id?, tipo_equipo_id?, placa?, estados?(array), solo_retorno?, sin_retorno?, retorno_desde?, internacional?, ocultar_fuera_operacion?, solo_demora?
     */
    public function calcular(string $desdeUtc, string $hastaUtc, array $filtros = []): array
    {
        $sql = 'SELECT
                    u.id, u.placa_unidad, u.estacion_id,
                    e.codigo AS estacion_codigo, e.timezone,
                    te.nombre AS tipo_equipo, cap.nombre AS capacidad,
                    cat.nombre AS categoria, cat.es_motriz,
                    ap.nombre AS piloto_asignado,
                    m.id AS mov_id, m.unidad_id AS mov_unidad_id, m.estado AS mov_estado, m.fecha_salida, m.fecha_fin_estimada,
                    m.retorno_disponible, m.pais_solicita_retorno_id, m.reservado_para, m.pais_origen_id,
                    m.pais_destino_id AS mov_pais_destino_id,
                    mo.codigo_iso AS mov_origen, md.codigo_iso AS mov_destino,
                    mr.codigo_iso AS retorno_iso, mp.nombre AS mov_piloto,
                    m.movimiento_regreso_id,
                    rgo.codigo_iso AS regreso_origen, rgd.codigo_iso AS regreso_destino,
                    rg.reservado_para AS regreso_para,
                    rg.fecha_salida AS regreso_salida, rg.fecha_fin_estimada AS regreso_fin,
                    m.queda_con_cliente,
                    (SELECT COUNT(*) FROM movimiento_unidades mu
                      WHERE mu.movimiento_id = m.id AND mu.rol = \'MOTRIZ\' AND mu.liberado_en IS NULL) AS motriz_activo,
                    (SELECT COUNT(*) FROM movimiento_unidades mu
                      WHERE mu.movimiento_id = m.id AND mu.rol = \'MOTRIZ\') AS motriz_total,
                    (SELECT GROUP_CONCAT(u2.placa_unidad ORDER BY u2.placa_unidad SEPARATOR \', \')
                       FROM movimiento_unidades mu
                       JOIN unidades u2 ON u2.id = mu.unidad_id
                      WHERE mu.movimiento_id = m.id AND mu.liberado_en IS NULL AND mu.unidad_id <> u.id) AS acompanantes_apoyo,
                    (SELECT CONCAT(up.id, \':\', up.placa_unidad) FROM unidades up WHERE up.id = m.unidad_id AND m.unidad_id <> u.id) AS acompanante_principal,
                    o.id AS override_id, o.tipo AS override_tipo, o.motivo AS override_motivo,
                    EXISTS (SELECT 1 FROM unidad_permisos up2
                              JOIN permisos_especiales pe2 ON pe2.id = up2.permiso_especial_id
                             WHERE up2.unidad_id = u.id
                               AND pe2.activo = 1
                               AND pe2.habilita_internacional = 1) AS puede_internacional
                  FROM unidades u
                  JOIN estaciones e ON e.id = u.estacion_id
                  JOIN categorias_vehiculo cat ON cat.id = u.categoria_vehiculo_id
                  LEFT JOIN tipos_equipo te ON te.id = u.tipo_equipo_id
                  LEFT JOIN capacidades cap ON cap.id = u.capacidad_id
                  LEFT JOIN pilotos ap ON ap.id = u.piloto_asignado_id
                  LEFT JOIN movimientos m ON m.id = (
                        SELECT m2.id FROM movimientos m2
                         WHERE (m2.unidad_id = u.id
                                OR EXISTS (SELECT 1 FROM movimiento_unidades mu2
                                            WHERE mu2.movimiento_id = m2.id
                                              AND mu2.unidad_id = u.id
                                              AND mu2.liberado_en IS NULL))
                           AND m2.estado IN (\'RESERVADO\', \'PROGRAMADO\', \'EN_TRANSITO\')
                           AND m2.fecha_salida <= :hasta1 AND m2.fecha_fin_estimada >= :desde1
                         ORDER BY (m2.estado = \'EN_TRANSITO\') DESC, m2.fecha_salida ASC
                         LIMIT 1)
                  LEFT JOIN pilotos mp ON mp.id = m.piloto_id
                  LEFT JOIN paises mo ON mo.id = m.pais_origen_id
                  LEFT JOIN paises md ON md.id = m.pais_destino_id
                  LEFT JOIN paises mr ON mr.id = m.pais_solicita_retorno_id
                  LEFT JOIN movimientos rg ON rg.id = m.movimiento_regreso_id
                  LEFT JOIN paises rgo ON rgo.id = rg.pais_origen_id
                  LEFT JOIN paises rgd ON rgd.id = rg.pais_destino_id
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
        if (!empty($filtros['categoria_id'])) {
            $sql .= ' AND u.categoria_vehiculo_id = :categoria';
            $params[':categoria'] = (int) $filtros['categoria_id'];
        }
        if (!empty($filtros['tipo_equipo_id'])) {
            $sql .= ' AND u.tipo_equipo_id = :tipo';
            $params[':tipo'] = (int) $filtros['tipo_equipo_id'];
        }
        if (isset($filtros['internacional']) && $filtros['internacional'] !== null) {
            // Filtrar por la capacidad, no por el permiso concreto: mañana puede habilitarla otro.
            $tiene = 'EXISTS (SELECT 1 FROM unidad_permisos up3
                                JOIN permisos_especiales pe3 ON pe3.id = up3.permiso_especial_id
                               WHERE up3.unidad_id = u.id AND pe3.activo = 1 AND pe3.habilita_internacional = 1)';
            $sql .= (int) $filtros['internacional'] === 1 ? ' AND ' . $tiene : ' AND NOT ' . $tiene;
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
        // "Desde" = donde quedará el equipo, que es el destino de la ida. El destino del
        // retorno no se sabe hasta que alguien lo aparta (puede ir a un tercer país).
        $retornoDesde  = !empty($filtros['retorno_desde']) ? (int) $filtros['retorno_desde'] : null;
        $ocultarFueraOperacion = !empty($filtros['ocultar_fuera_operacion']);
        $soloDemora = !empty($filtros['solo_demora']);
        $ahoraUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $out = [];
        foreach ($rows as $r) {
            $estado = $this->estadoDe($r);

            // El tablero oculta lo que está fuera de operación, salvo que se pida ver
            // justamente ese estado (única forma de llegar a una unidad para desbloquearla).
            $pidenBloqueadas = in_array(EstadoDisponibilidad::TALLER_BLOQUEADA, $estadosFiltro, true);
            if ($ocultarFueraOperacion && !$pidenBloqueadas && $estado === EstadoDisponibilidad::TALLER_BLOQUEADA) {
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
            if ($soloDemora && !$conDemora) {
                continue;
            }
            if ($retornoDesde !== null && (!$tieneRetorno || (int) $r['mov_pais_destino_id'] !== $retornoDesde)) {
                continue;
            }

            $out[] = [
                'unidad_id'       => (int) $r['id'],
                'placa_unidad'    => $r['placa_unidad'],
                'categoria'       => $r['categoria'],
                'es_motriz'       => (int) $r['es_motriz'] === 1,
                'tipo_equipo'     => $r['tipo_equipo'],
                'capacidad'       => $r['capacidad'],
                'estacion_codigo' => $r['estacion_codigo'],
                'timezone'        => $r['timezone'],
                'estado'          => $estado,
                'con_demora'      => $conDemora,
                'puede_internacional' => (int) $r['puede_internacional'] === 1,
                'piloto'          => $r['mov_piloto'] ?? $r['piloto_asignado'],
                'movimiento'      => $r['mov_id'] ? [
                    'id'                      => (int) $r['mov_id'],
                    'unidad_id'               => (int) $r['mov_unidad_id'],
                    'estado'                  => $r['mov_estado'],
                    'origen'                  => $r['mov_origen'],
                    'destino'                 => $r['mov_destino'],
                    'pais_origen_id'          => (int) $r['pais_origen_id'],
                    'pais_destino_id'         => (int) $r['mov_pais_destino_id'],
                    'fecha_salida'            => $r['fecha_salida'],
                    'fecha_fin_estimada'      => $r['fecha_fin_estimada'],
                    'retorno_disponible'      => (int) $r['retorno_disponible'] === 1,
                    'retorno_iso'             => $r['retorno_iso'],
                    'pais_solicita_retorno_id' => $r['pais_solicita_retorno_id'] !== null ? (int) $r['pais_solicita_retorno_id'] : null,
                    'regreso_id'              => $r['movimiento_regreso_id'] !== null ? (int) $r['movimiento_regreso_id'] : null,
                    'regreso_ruta'            => $r['regreso_origen'] ? $r['regreso_origen'] . ' → ' . $r['regreso_destino'] : null,
                    'regreso_para'            => $r['regreso_para'],
                    'regreso_salida'          => $r['regreso_salida'],
                    'regreso_fin'             => $r['regreso_fin'],
                    'reservado_para'          => $r['reservado_para'],
                    'queda_con_cliente'       => (int) ($r['queda_con_cliente'] ?? 0) === 1,
                    'acompanantes'            => $this->acompanantes($r),
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

    /**
     * Compañeros de viaje de esta unidad, ya separados en id y placa. `apoyo` distingue a los
     * que se pueden liberar (el protagonista del movimiento no se libera: se cancela).
     *
     * @return array<int, array{id:int, placa:string, apoyo:bool}>
     */
    private function acompanantes(array $r): array
    {
        $out = [];
        $parse = static function (?string $crudo, bool $apoyo) use (&$out): void {
            foreach (array_filter(explode(', ', (string) $crudo)) as $item) {
                [$id, $placa] = array_pad(explode(':', $item, 2), 2, '');
                if ($placa !== '') {
                    $out[] = ['id' => (int) $id, 'placa' => $placa, 'apoyo' => $apoyo];
                }
            }
        };
        $parse($r['acompanante_principal'], false);
        $parse($r['acompanantes_apoyo'], true);
        return $out;
    }

    /** El equipo quedó en poder del cliente: declarado al reservar o al soltar el motriz. */
    private function conElCliente(array $r): bool
    {
        if ($r['mov_id'] === null) {
            return false;
        }
        // El motriz nunca se queda: es justamente el que regresa a base.
        if ((int) $r['es_motriz'] === 1) {
            return false;
        }
        if ((int) ($r['queda_con_cliente'] ?? 0) === 1) {
            return true;
        }
        // Hubo un cabezal y ya se liberó: lo que queda está detenido con el cliente.
        return (int) ($r['motriz_total'] ?? 0) > 0 && (int) ($r['motriz_activo'] ?? 0) === 0;
    }

    private function estadoDe(array $r): string
    {
        if ($r['override_id']) {
            return $r['override_tipo'] === TipoOverride::EN_CLIENTE
                ? EstadoDisponibilidad::EN_CLIENTE
                : EstadoDisponibilidad::TALLER_BLOQUEADA;
        }
        // Con el cliente: se declaró al reservar, o el motriz ya se liberó y el equipo se
        // quedó allá. En ambos casos sigue ocupado, pero no está viajando.
        if ($this->conElCliente($r)) {
            return EstadoDisponibilidad::EN_CLIENTE;
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
