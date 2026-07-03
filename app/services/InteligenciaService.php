<?php
/**
 * Reportes de Fase 4 y reglas de acceso/suscripción asociadas. Todas las consultas
 * aplican el alcance por rol directamente en SQL.
 */

declare(strict_types=1);

final class InteligenciaService
{
    private const ACCESO_TOTAL = [Rol::ADMIN_GLOBAL, Rol::CONSULTA_REGIONAL];

    public function __construct(
        private PDO $pdo,
        private CatalogoModel $catalogos,
        private EstacionModel $estaciones,
        private SuscripcionCorreoModel $suscripciones,
        private NotificacionService $notificaciones
    ) {
    }

    public static function tieneAcceso(array $user): bool
    {
        return $user['rol'] !== Rol::CONSULTA_BASICO;
    }

    public function alcance(array $user): ?int
    {
        return in_array($user['rol'], self::ACCESO_TOTAL, true) ? null : (int) $user['estacion_id'];
    }

    public function filtros(array $query, array $user): array
    {
        $desde = $this->fechaValida($query['desde'] ?? null)
            ? (string) $query['desde']
            : (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $hasta = $this->fechaValida($query['hasta'] ?? null)
            ? (string) $query['hasta']
            : (new DateTimeImmutable('today'))->format('Y-m-d');

        if ($hasta < $desde) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $estacionId = null;
        if ($this->alcance($user) === null && !empty($query['estacion_id']) && ctype_digit((string) $query['estacion_id'])) {
            $estacionId = (int) $query['estacion_id'];
        }

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'estacion_id' => $estacionId,
        ];
    }

    public function reportes(array $user, array $filtros): array
    {
        return [
            'utilizacion' => $this->utilizacionPorEstacion($user, $filtros),
            'dias_transito' => $this->diasEnTransitoPorUnidad($user, $filtros),
            'rutas' => $this->rutasMasUsadas($user, $filtros),
            'retornos' => $this->retornos($user, $filtros),
        ];
    }

    public function listarSuscripciones(array $user): array
    {
        return $this->suscripciones->listarDeUsuario((int) $user['id']);
    }

    public function opcionesEstaciones(array $user): array
    {
        if ($this->alcance($user) === null) {
            return $this->catalogos->activos('estaciones', 'codigo');
        }

        $estacion = $this->estaciones->find((int) $user['estacion_id']);
        return $estacion ? [$estacion] : [];
    }

    public function opcionesPaises(array $user): array
    {
        if ($this->alcance($user) === null) {
            return $this->catalogos->activos('paises', 'orden');
        }

        $estacion = $this->estaciones->find((int) $user['estacion_id']);
        if ($estacion === null) {
            return [];
        }
        $pais = $this->catalogos->find('paises', (int) $estacion['pais_id']);
        return $pais ? [$pais] : [];
    }

    public function crearSuscripcion(array $input, array $user): void
    {
        $tipo = trim((string) ($input['tipo'] ?? ''));
        if (!in_array($tipo, SuscripcionCorreoModel::tipos(), true)) {
            throw new InvalidArgumentException('Selecciona un tipo de suscripción válido.');
        }

        [$estacionId, $paisId] = $this->resolverObjetivoSuscripcion($tipo, $input, $user);
        if ($this->suscripciones->existe((int) $user['id'], $tipo, $estacionId, $paisId)) {
            throw new RuntimeException('Esa suscripción ya existe para tu usuario.');
        }

        $this->suscripciones->crear([
            'user_id' => (int) $user['id'],
            'tipo' => $tipo,
            'estacion_id' => $estacionId,
            'pais_id' => $paisId,
        ], (int) $user['id']);
    }

    public function eliminarSuscripcion(int $id, array $user): void
    {
        $suscripcion = $this->suscripciones->find($id);
        if ($suscripcion === null || (int) $suscripcion['user_id'] !== (int) $user['id']) {
            throw new RuntimeException('La suscripción no existe o no pertenece a tu usuario.');
        }
        $this->suscripciones->eliminar($id);
    }

    public function enviarPrueba(int $id, array $user): void
    {
        $suscripcion = $this->suscripciones->find($id);
        if ($suscripcion === null || (int) $suscripcion['user_id'] !== (int) $user['id']) {
            throw new RuntimeException('La suscripción no existe o no pertenece a tu usuario.');
        }
        $this->notificaciones->enviarPrueba($suscripcion, $user);
    }

    private function utilizacionPorEstacion(array $user, array $filtros): array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($user, $filtros, 'u.estacion_id');

        $periodoSegundos = max(1, strtotime($filtros['hasta'] . ' 23:59:59') - strtotime($filtros['desde'] . ' 00:00:00'));
        $estaciones = $this->pdo->prepare(
            'SELECT e.id, e.codigo, e.nombre, COUNT(u.id) AS unidades
               FROM estaciones e
               JOIN unidades u ON u.estacion_id = e.id
              WHERE e.activo = 1
                AND u.activo = 1
                AND u.en_disponibilidad = 1
                AND u.estado_vehiculo <> :de_baja'
            . $scopeSql . '
              GROUP BY e.id, e.codigo, e.nombre
              ORDER BY e.codigo'
        );
        $estaciones->execute($scopeParams + [':de_baja' => EstadoVehiculo::DE_BAJA]);

        $ocupacion = $this->pdo->prepare(
            'SELECT u.estacion_id,
                    SUM(TIMESTAMPDIFF(SECOND,
                        GREATEST(m.fecha_salida, :desde_calc),
                        LEAST(COALESCE(m.fecha_fin_real, m.fecha_fin_estimada), :hasta_calc)
                    )) AS segundos
               FROM movimientos m
               JOIN unidades u ON u.id = m.unidad_id
              WHERE m.estado = :estado
                AND u.activo = 1
                AND u.en_disponibilidad = 1
                AND m.fecha_salida < :hasta_limite
                AND COALESCE(m.fecha_fin_real, m.fecha_fin_estimada) > :desde_limite'
            . $scopeSql . '
              GROUP BY u.estacion_id'
        );
        $ocupacion->execute($scopeParams + [
            ':estado' => EstadoMovimiento::COMPLETADO,
            ':desde_calc' => $filtros['desde'] . ' 00:00:00',
            ':hasta_calc' => $filtros['hasta'] . ' 23:59:59',
            ':desde_limite' => $filtros['desde'] . ' 00:00:00',
            ':hasta_limite' => $filtros['hasta'] . ' 23:59:59',
        ]);
        $segundosPorEstacion = [];
        foreach ($ocupacion->fetchAll() as $row) {
            $segundosPorEstacion[(int) $row['estacion_id']] = (int) $row['segundos'];
        }

        $filas = [];
        foreach ($estaciones->fetchAll() as $row) {
            $unidades = max(0, (int) $row['unidades']);
            $ocupado = $segundosPorEstacion[(int) $row['id']] ?? 0;
            $capacidad = max(1, $unidades * $periodoSegundos);
            $filas[] = [
                'estacion_id' => (int) $row['id'],
                'codigo' => $row['codigo'],
                'nombre' => $row['nombre'],
                'unidades' => $unidades,
                'horas_ocupadas' => round($ocupado / 3600, 1),
                'utilizacion_pct' => round(($ocupado / $capacidad) * 100, 1),
            ];
        }

        return $filas;
    }

    private function diasEnTransitoPorUnidad(array $user, array $filtros): array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($user, $filtros, 'u.estacion_id');
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.placa_unidad, u.placa_furgon, e.codigo AS estacion_codigo,
                    COUNT(m.id) AS movimientos,
                    SUM(TIMESTAMPDIFF(SECOND,
                        GREATEST(m.fecha_salida, :desde_calc),
                        LEAST(COALESCE(m.fecha_fin_real, m.fecha_fin_estimada), :hasta_calc)
                    )) AS segundos
               FROM movimientos m
               JOIN unidades u ON u.id = m.unidad_id
               JOIN estaciones e ON e.id = u.estacion_id
              WHERE m.estado = :estado
                AND u.activo = 1
                AND u.en_disponibilidad = 1
                AND m.fecha_salida < :hasta_limite
                AND COALESCE(m.fecha_fin_real, m.fecha_fin_estimada) > :desde_limite'
            . $scopeSql . '
              GROUP BY u.id, u.placa_unidad, u.placa_furgon, e.codigo
              ORDER BY segundos DESC, movimientos DESC, u.placa_unidad'
        );
        $stmt->execute($scopeParams + [
            ':estado' => EstadoMovimiento::COMPLETADO,
            ':desde_calc' => $filtros['desde'] . ' 00:00:00',
            ':hasta_calc' => $filtros['hasta'] . ' 23:59:59',
            ':desde_limite' => $filtros['desde'] . ' 00:00:00',
            ':hasta_limite' => $filtros['hasta'] . ' 23:59:59',
        ]);

        return array_map(static function (array $row): array {
            $segundos = (int) $row['segundos'];
            return [
                'unidad_id' => (int) $row['id'],
                'placa_unidad' => $row['placa_unidad'],
                'placa_furgon' => $row['placa_furgon'],
                'estacion_codigo' => $row['estacion_codigo'],
                'movimientos' => (int) $row['movimientos'],
                'dias' => round($segundos / 86400, 2),
                'horas' => round($segundos / 3600, 1),
            ];
        }, $stmt->fetchAll());
    }

    private function rutasMasUsadas(array $user, array $filtros): array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($user, $filtros, 'u.estacion_id');
        $stmt = $this->pdo->prepare(
            'SELECT CASE
                        WHEN m.ruta_id IS NOT NULL THEN r.nombre
                        ELSE CONCAT(
                            COALESCE(NULLIF(m.ruta_custom_origen, ""), po.codigo_iso),
                            " → ",
                            COALESCE(NULLIF(m.ruta_custom_destino, ""), pd.codigo_iso)
                        )
                    END AS ruta,
                    COUNT(m.id) AS movimientos,
                    SUM(TIMESTAMPDIFF(SECOND,
                        GREATEST(m.fecha_salida, :desde_calc),
                        LEAST(COALESCE(m.fecha_fin_real, m.fecha_fin_estimada), :hasta_calc)
                    )) AS segundos
               FROM movimientos m
               JOIN unidades u ON u.id = m.unidad_id
               LEFT JOIN rutas r ON r.id = m.ruta_id
               LEFT JOIN paises po ON po.id = m.pais_origen_id
               LEFT JOIN paises pd ON pd.id = m.pais_destino_id
              WHERE m.estado = :estado
                AND u.activo = 1
                AND u.en_disponibilidad = 1
                AND m.fecha_salida < :hasta_limite
                AND COALESCE(m.fecha_fin_real, m.fecha_fin_estimada) > :desde_limite'
            . $scopeSql . '
              GROUP BY ruta
              ORDER BY movimientos DESC, segundos DESC, ruta
              LIMIT 15'
        );
        $stmt->execute($scopeParams + [
            ':estado' => EstadoMovimiento::COMPLETADO,
            ':desde_calc' => $filtros['desde'] . ' 00:00:00',
            ':hasta_calc' => $filtros['hasta'] . ' 23:59:59',
            ':desde_limite' => $filtros['desde'] . ' 00:00:00',
            ':hasta_limite' => $filtros['hasta'] . ' 23:59:59',
        ]);

        return array_map(static function (array $row): array {
            return [
                'ruta' => $row['ruta'],
                'movimientos' => (int) $row['movimientos'],
                'horas' => round(((int) $row['segundos']) / 3600, 1),
            ];
        }, $stmt->fetchAll());
    }

    private function retornos(array $user, array $filtros): array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($user, $filtros, 'u.estacion_id');
        $stmt = $this->pdo->prepare(
            'SELECT m.id, u.placa_unidad, e.codigo AS estacion_codigo,
                    po.codigo_iso AS origen_iso, pd.codigo_iso AS destino_iso,
                    CASE
                        WHEN m.retorno_disponible = 1 AND m.pais_solicita_retorno_id IS NOT NULL THEN "APROVECHADO"
                        WHEN m.retorno_disponible = 1 THEN "VACIO"
                        ELSE "SIN_RETORNO"
                    END AS clasificacion
               FROM movimientos m
               JOIN unidades u ON u.id = m.unidad_id
               JOIN estaciones e ON e.id = u.estacion_id
               JOIN paises po ON po.id = m.pais_origen_id
               JOIN paises pd ON pd.id = m.pais_destino_id
              WHERE m.estado = :estado
                AND m.tipo_ruta = :tipo
                AND u.activo = 1
                AND u.en_disponibilidad = 1
                                AND m.fecha_salida < :hasta_limite
                                AND COALESCE(m.fecha_fin_real, m.fecha_fin_estimada) > :desde_limite'
            . $scopeSql . '
              ORDER BY m.fecha_salida DESC, m.id DESC'
        );
        $stmt->execute($scopeParams + [
            ':estado' => EstadoMovimiento::COMPLETADO,
            ':tipo' => TipoRuta::INTERNACIONAL,
                        ':desde_limite' => $filtros['desde'] . ' 00:00:00',
                        ':hasta_limite' => $filtros['hasta'] . ' 23:59:59',
        ]);

        $detalle = [];
        $conteos = ['APROVECHADO' => 0, 'VACIO' => 0, 'SIN_RETORNO' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $conteos[$row['clasificacion']]++;
            $detalle[] = [
                'id' => (int) $row['id'],
                'placa_unidad' => $row['placa_unidad'],
                'estacion_codigo' => $row['estacion_codigo'],
                'ruta' => $row['origen_iso'] . ' → ' . $row['destino_iso'],
                'clasificacion' => $row['clasificacion'],
            ];
        }

        return [
            'conteos' => $conteos,
            'total' => array_sum($conteos),
            'detalle' => $detalle,
        ];
    }

    private function resolverObjetivoSuscripcion(string $tipo, array $input, array $user): array
    {
        if ($this->alcance($user) !== null) {
            $estacion = $this->estaciones->find((int) $user['estacion_id']);
            if ($estacion === null) {
                throw new RuntimeException('La estación del usuario no existe.');
            }
            if ($tipo === SuscripcionCorreoModel::TIPO_UNIDAD_LIBERADA) {
                return [(int) $estacion['id'], null];
            }
            return [null, (int) $estacion['pais_id']];
        }

        if ($tipo === SuscripcionCorreoModel::TIPO_UNIDAD_LIBERADA) {
            $estacionId = !empty($input['estacion_id']) && ctype_digit((string) $input['estacion_id'])
                ? (int) $input['estacion_id']
                : 0;
            if ($estacionId < 1 || $this->estaciones->find($estacionId) === null) {
                throw new InvalidArgumentException('Selecciona una estación válida para esta suscripción.');
            }
            return [$estacionId, null];
        }

        $paisId = !empty($input['pais_id']) && ctype_digit((string) $input['pais_id'])
            ? (int) $input['pais_id']
            : 0;
        if ($paisId < 1 || $this->catalogos->find('paises', $paisId) === null) {
            throw new InvalidArgumentException('Selecciona un país válido para esta suscripción.');
        }
        return [null, $paisId];
    }

    private function scopeClause(array $user, array $filtros, string $columna): array
    {
        $scope = '';
        $params = [];

        $alcance = $this->alcance($user);
        if ($alcance !== null) {
            $scope .= ' AND ' . $columna . ' = :alcance';
            $params[':alcance'] = $alcance;
            return [$scope, $params];
        }

        if (!empty($filtros['estacion_id'])) {
            $scope .= ' AND ' . $columna . ' = :estacion';
            $params[':estacion'] = (int) $filtros['estacion_id'];
        }

        return [$scope, $params];
    }

    private function fechaValida(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return $d instanceof DateTime && $d->format('Y-m-d') === $value;
    }
}