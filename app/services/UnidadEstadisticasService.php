<?php
/**
 * Ficha completa de una unidad: sus datos maestros más cómo se ha comportado.
 *
 * El inventario contesta "qué tengo"; esto contesta "qué tal me ha respondido". Todo se mide
 * sobre viajes COMPLETADOS, que son los únicos con resultado real: un viaje en curso todavía
 * no ha cumplido ni incumplido nada.
 */

declare(strict_types=1);

final class UnidadEstadisticasService
{
    /** Viajes recientes que se listan en la ficha. */
    private const ULTIMOS = 6;

    public function __construct(private PDO $pdo, private UnidadModel $unidades)
    {
    }

    /** @return array|null null si la unidad no existe */
    public function de(int $unidadId): ?array
    {
        $unidad = $this->unidades->find($unidadId);
        if ($unidad === null) {
            return null;
        }

        return [
            'unidad'        => $this->ficha($unidad),
            'actividad'     => $this->actividad($unidadId),
            'rutas'         => $this->rutasFrecuentes($unidadId),
            'ultimos'       => $this->ultimosViajes($unidadId),
            'indisponible'  => $this->tiempoFueraDeOperacion($unidadId),
            'pilotos'       => $this->pilotosQueLaHanLlevado($unidadId),
        ];
    }

    /** Datos maestros, ya resueltos a nombres. */
    private function ficha(array $u): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.codigo AS estacion_codigo, e.nombre AS estacion,
                    cap.nombre AS capacidad, te.nombre AS tipo_equipo,
                    comb.nombre AS tipo_combustible, p.nombre AS piloto_asignado,
                    (SELECT GROUP_CONCAT(pe.nombre ORDER BY pe.nombre SEPARATOR ", ")
                       FROM unidad_permisos up
                       JOIN permisos_especiales pe ON pe.id = up.permiso_especial_id
                      WHERE up.unidad_id = u.id AND pe.activo = 1) AS permisos,
                    EXISTS (SELECT 1 FROM unidad_permisos up2
                              JOIN permisos_especiales pe2 ON pe2.id = up2.permiso_especial_id
                             WHERE up2.unidad_id = u.id AND pe2.activo = 1
                               AND pe2.habilita_internacional = 1) AS puede_internacional
               FROM unidades u
               JOIN estaciones e ON e.id = u.estacion_id
               LEFT JOIN capacidades cap ON cap.id = u.capacidad_id
               LEFT JOIN tipos_equipo te ON te.id = u.tipo_equipo_id
               LEFT JOIN tipos_combustible comb ON comb.id = u.tipo_combustible_id
               LEFT JOIN pilotos p ON p.id = u.piloto_asignado_id
              WHERE u.id = :id'
        );
        $stmt->execute([':id' => $u['id']]);
        $extra = $stmt->fetch() ?: [];

        return [
            'id'                  => (int) $u['id'],
            'placa_unidad'        => $u['placa_unidad'],
            'categoria'           => $u['categoria'] ?? '',
            'marca'               => $u['marca'],
            'modelo'              => $u['modelo'],
            'anio'                => $u['anio'] !== null ? (int) $u['anio'] : null,
            'tipo_combustible'    => $extra['tipo_combustible'] ?? null,
            'capacidad'           => $extra['capacidad'] ?? null,
            'tipo_equipo'         => $extra['tipo_equipo'] ?? null,
            'estacion_id'         => (int) $u['estacion_id'],
            'estacion'            => trim(($extra['estacion_codigo'] ?? '') . ' · ' . ($extra['estacion'] ?? ''), ' ·'),
            'piloto_asignado'     => $extra['piloto_asignado'] ?? null,
            'permisos'            => $extra['permisos'] ?? null,
            'puede_internacional' => (int) ($extra['puede_internacional'] ?? 0) === 1,
            'estado_vehiculo'     => EstadoVehiculo::label($u['estado_vehiculo']),
            'estado_notas'        => $u['estado_notas'],
            'en_disponibilidad'   => (int) $u['en_disponibilidad'] === 1,
            'alta'                => $u['created_at'],
        ];
    }

    /** Cuánto ha trabajado y si cumplió. Histórico completo, sin recortar por rango. */
    private function actividad(int $unidadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS viajes,
                    SUM(m.tipo_ruta = :internacional) AS internacionales,
                    SUM(TIMESTAMPDIFF(SECOND, m.fecha_salida, m.fecha_fin_real)) AS segundos,
                    SUM(m.fecha_fin_real > m.fecha_fin_estimada) AS con_demora,
                    SUM(CASE WHEN m.fecha_fin_real > m.fecha_fin_estimada
                             THEN TIMESTAMPDIFF(SECOND, m.fecha_fin_estimada, m.fecha_fin_real)
                             ELSE 0 END) AS segundos_demora,
                    SUM(m.retorno_disponible = 1) AS con_retorno,
                    SUM(m.retorno_disponible = 1 AND m.movimiento_regreso_id IS NOT NULL) AS retorno_aprovechado,
                    MIN(m.fecha_salida) AS primera,
                    MAX(m.fecha_fin_real) AS ultima
               FROM movimientos m
              WHERE m.unidad_id = :id AND m.estado = :estado AND m.fecha_fin_real IS NOT NULL'
        );
        $stmt->execute([':id' => $unidadId, ':estado' => EstadoMovimiento::COMPLETADO, ':internacional' => TipoRuta::INTERNACIONAL]);
        $r = $stmt->fetch() ?: [];

        $viajes = (int) ($r['viajes'] ?? 0);
        $conDemora = (int) ($r['con_demora'] ?? 0);
        $conRetorno = (int) ($r['con_retorno'] ?? 0);
        $segundos = (int) ($r['segundos'] ?? 0);

        return [
            'viajes'          => $viajes,
            'internacionales' => (int) ($r['internacionales'] ?? 0),
            'dias_en_ruta'    => round($segundos / 86400, 1),
            // Media por viaje: dice cuánto dura típicamente un servicio de esta unidad.
            'duracion_media_h' => $viajes > 0 ? round($segundos / 3600 / $viajes, 1) : 0.0,
            'con_demora'      => $conDemora,
            'demora_media_h'  => $conDemora > 0 ? round(((int) $r['segundos_demora']) / 3600 / $conDemora, 1) : 0.0,
            'puntualidad'     => $viajes > 0 ? (int) round(($viajes - $conDemora) / $viajes * 100) : null,
            'con_retorno'     => $conRetorno,
            // Un retorno ofrecido que nadie tomó es un viaje de vuelta en vacío.
            'retorno_aprovechado' => (int) ($r['retorno_aprovechado'] ?? 0),
            'primera'         => $r['primera'] ?? null,
            'ultima'          => $r['ultima'] ?? null,
        ];
    }

    /** Las rutas que más repite: dice para qué se usa realmente esta unidad. */
    private function rutasFrecuentes(int $unidadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT CONCAT(COALESCE(NULLIF(m.ruta_custom_origen, ""), po.codigo_iso), " → ",
                           COALESCE(NULLIF(m.ruta_custom_destino, ""), pd.codigo_iso)) AS ruta,
                    COUNT(*) AS viajes
               FROM movimientos m
               LEFT JOIN paises po ON po.id = m.pais_origen_id
               LEFT JOIN paises pd ON pd.id = m.pais_destino_id
              WHERE m.unidad_id = :id AND m.estado = :estado
              GROUP BY ruta
              ORDER BY viajes DESC, ruta
              LIMIT 5'
        );
        $stmt->execute([':id' => $unidadId, ':estado' => EstadoMovimiento::COMPLETADO]);
        return array_map(
            static fn(array $r): array => ['ruta' => $r['ruta'], 'viajes' => (int) $r['viajes']],
            $stmt->fetchAll()
        );
    }

    /** Últimos viajes, terminados o en curso: el contexto inmediato de la unidad. */
    private function ultimosViajes(int $unidadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.estado, m.fecha_salida, m.fecha_fin_estimada, m.fecha_fin_real,
                    m.reservado_para,
                    CONCAT(COALESCE(NULLIF(m.ruta_custom_origen, ""), po.codigo_iso), " → ",
                           COALESCE(NULLIF(m.ruta_custom_destino, ""), pd.codigo_iso)) AS ruta,
                    p.nombre AS piloto,
                    TIMESTAMPDIFF(MINUTE, m.fecha_fin_estimada, m.fecha_fin_real) AS demora_min
               FROM movimientos m
               LEFT JOIN pilotos p ON p.id = m.piloto_id
               LEFT JOIN paises po ON po.id = m.pais_origen_id
               LEFT JOIN paises pd ON pd.id = m.pais_destino_id
              WHERE m.unidad_id = :id
              ORDER BY m.fecha_salida DESC
              LIMIT ' . self::ULTIMOS
        );
        $stmt->execute([':id' => $unidadId]);

        return array_map(static function (array $m): array {
            $min = $m['fecha_fin_real'] !== null ? (int) $m['demora_min'] : 0;
            return [
                'id'            => (int) $m['id'],
                'estado'        => $m['estado'],
                'ruta'          => $m['ruta'],
                'piloto'        => $m['piloto'],
                'cliente'       => $m['reservado_para'],
                'fecha_salida'  => $m['fecha_salida'],
                'fecha_fin_real' => $m['fecha_fin_real'],
                'demora_min'    => max(0, $min),
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Tiempo que la unidad NO estuvo disponible: taller y bloqueos manuales. Es la otra cara
     * de la utilización — una unidad con pocos viajes puede haber estado parada, no ociosa.
     */
    private function tiempoFueraDeOperacion(int $unidadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS episodios,
                    SUM(TIMESTAMPDIFF(SECOND, o.desde, COALESCE(o.hasta, NOW()))) AS segundos,
                    SUM(o.cerrado = 0) AS abiertos
               FROM overrides_unidad o
              WHERE o.unidad_id = :id'
        );
        $stmt->execute([':id' => $unidadId]);
        $r = $stmt->fetch() ?: [];

        return [
            'episodios' => (int) ($r['episodios'] ?? 0),
            'dias'      => round(((int) ($r['segundos'] ?? 0)) / 86400, 1),
            'abiertos'  => (int) ($r['abiertos'] ?? 0),
        ];
    }

    /** Quién la ha conducido y cuántas veces. Útil al revisar una unidad problemática. */
    private function pilotosQueLaHanLlevado(int $unidadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.nombre, COUNT(*) AS viajes
               FROM movimientos m
               JOIN pilotos p ON p.id = m.piloto_id
              WHERE m.unidad_id = :id AND m.estado = :estado
              GROUP BY p.id, p.nombre
              ORDER BY viajes DESC, p.nombre
              LIMIT 5'
        );
        $stmt->execute([':id' => $unidadId, ':estado' => EstadoMovimiento::COMPLETADO]);
        return array_map(
            static fn(array $r): array => ['nombre' => $r['nombre'], 'viajes' => (int) $r['viajes']],
            $stmt->fetchAll()
        );
    }
}
