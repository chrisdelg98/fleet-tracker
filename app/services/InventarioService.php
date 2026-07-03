<?php
/**
 * Inventario vehicular (plan §7.6) — vista de solo lectura sobre TODA la tabla unidades
 * (operativas y solo-inventario). Es el único módulo con restricción por alcance de rol
 * (plan §4/§7.6):
 *   - CONSULTA_BASICO           → sin acceso.
 *   - ENCARGADO, CONSULTA_INVENTARIO → su propia estación.
 *   - CONSULTA_REGIONAL, ADMIN_GLOBAL → todas las estaciones.
 * El alcance se aplica en la consulta (no solo en UI): el export descarga solo lo permitido.
 */

declare(strict_types=1);

final class InventarioService
{
    private const ALCANCE_TOTAL = [Rol::ADMIN_GLOBAL, Rol::CONSULTA_REGIONAL];

    public function __construct(private PDO $pdo)
    {
    }

    public static function tieneAcceso(array $user): bool
    {
        return $user['rol'] !== Rol::CONSULTA_BASICO;
    }

    /** Estación a la que se limita el usuario, o null si ve todas. */
    public function alcance(array $user): ?int
    {
        return in_array($user['rol'], self::ALCANCE_TOTAL, true) ? null : (int) $user['estacion_id'];
    }

    /** Filas del inventario según alcance + filtros. */
    public function listar(array $user, array $filtros): array
    {
        [$where, $params] = $this->where($user, $filtros);
        $sql = 'SELECT u.id, u.placa_unidad, u.placa_furgon, u.marca, u.modelo, u.en_disponibilidad,
                       u.estado_vehiculo, u.estado_notas, c.nombre AS categoria, e.codigo AS estacion_codigo, e.nombre AS estacion
                  FROM unidades u
                  JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
                  JOIN estaciones e ON e.id = u.estacion_id'
             . $where . ' ORDER BY e.codigo, c.orden, u.placa_unidad';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Conteos por categoría y por estado del vehículo, dentro del alcance + filtros. */
    public function conteos(array $user, array $filtros): array
    {
        [$where, $params] = $this->where($user, $filtros);

        $porCategoria = $this->pdo->prepare(
            'SELECT c.nombre, COUNT(*) AS n FROM unidades u
               JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
               JOIN estaciones e ON e.id = u.estacion_id'
            . $where . ' GROUP BY c.id ORDER BY c.orden'
        );
        $porCategoria->execute($params);
        $categorias = $porCategoria->fetchAll();

        $porEstado = $this->pdo->prepare(
            'SELECT u.estado_vehiculo AS nombre, COUNT(*) AS n FROM unidades u
               JOIN estaciones e ON e.id = u.estacion_id'
            . $where . ' GROUP BY u.estado_vehiculo'
        );
        $porEstado->execute($params);

        return [
            'por_categoria' => $categorias,
            'por_estado'    => $porEstado->fetchAll(),
            'total'         => array_sum(array_map(static fn($r) => (int) $r['n'], $categorias)),
        ];
    }

    /** Construye el WHERE con el alcance de rol y los filtros. Devuelve [sql, params]. */
    private function where(array $user, array $filtros): array
    {
        $where = ' WHERE u.activo = 1';
        $params = [];

        $alcance = $this->alcance($user);
        if ($alcance !== null) {
            $where .= ' AND u.estacion_id = :alcance';
            $params[':alcance'] = $alcance;
        } elseif (!empty($filtros['estacion_id'])) {
            $where .= ' AND u.estacion_id = :estacion';
            $params[':estacion'] = (int) $filtros['estacion_id'];
        }
        if (!empty($filtros['categoria_id'])) {
            $where .= ' AND u.categoria_vehiculo_id = :cat';
            $params[':cat'] = (int) $filtros['categoria_id'];
        }
        if (!empty($filtros['estado_vehiculo']) && in_array($filtros['estado_vehiculo'], EstadoVehiculo::values(), true)) {
            $where .= ' AND u.estado_vehiculo = :ev';
            $params[':ev'] = $filtros['estado_vehiculo'];
        }
        if (isset($filtros['en_disponibilidad']) && $filtros['en_disponibilidad'] !== '') {
            $where .= ' AND u.en_disponibilidad = :ed';
            $params[':ed'] = (int) (bool) $filtros['en_disponibilidad'];
        }
        return [$where, $params];
    }
}
