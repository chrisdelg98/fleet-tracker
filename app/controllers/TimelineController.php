<?php
/**
 * Timeline/calendario por unidad (plan §7.5) — Gantt simple: filas = unidades de la
 * estación, eje X = días, bloques de color por movimiento activo. Sirve para gestionar
 * reservas a futuro viendo las ventanas ocupadas (evita traslapes de un vistazo; el backend
 * los rechaza igual). Área de gestión: Admin Global y Encargados.
 */

declare(strict_types=1);

final class TimelineController
{
    /** Ventanas ofrecidas. Cerradas a propósito: cada día añade una columna, y un rango
        libre acaba en vistas de 200 días que no se pueden leer ni caben en pantalla. */
    public const RANGOS = [7 => '1 semana', 14 => '2 semanas', 30 => '1 mes', 60 => '2 meses'];

    /** Estados que ocupan la unidad; los demás no pintan bloque. */
    private const ESTADOS_VISIBLES = [EstadoMovimiento::RESERVADO, EstadoMovimiento::PROGRAMADO, EstadoMovimiento::EN_TRANSITO];
    private const DIAS = 14;
    private const ACCESO = [Rol::ADMIN_GLOBAL, Rol::ENCARGADO];

    public function __construct(private PDO $pdo, private CatalogoModel $catalogos)
    {
    }

    public function index(): void
    {
        $user = require_login_web();
        if (!in_array($user['rol'], self::ACCESO, true)) {
            http_response_code(403);
            echo 'No tienes acceso al timeline.';
            return;
        }

        // Por defecto la ventana arranca el domingo de esta semana, no hoy: en viernes,
        // empezar hoy escondería lo que ya pasó en la semana, que es justo lo que se consulta
        // para saber dónde está cada unidad. Quien quiera otro punto de partida pone la fecha.
        $hoy = new DateTimeImmutable('now');
        $desde = !empty($_GET['desde'])
            ? substr((string) $_GET['desde'], 0, 10)
            : $hoy->modify('-' . (int) $hoy->format('w') . ' days')->format('Y-m-d');
        $diasTotal = isset($_GET['dias']) && isset(self::RANGOS[(int) $_GET['dias']])
            ? (int) $_GET['dias']
            : self::DIAS;

        $inicio = new DateTimeImmutable($desde . ' 00:00:00', new DateTimeZone('UTC'));
        $finVentana = $inicio->modify('+' . $diasTotal . ' days');

        // Estación en alcance
        $estacion = $user['rol'] === Rol::ADMIN_GLOBAL
            ? (!empty($_GET['estacion_id']) ? (int) $_GET['estacion_id'] : null)
            : (int) $user['estacion_id'];

        $filtros = [
            'categoria_id'   => !empty($_GET['categoria_id']) ? (int) $_GET['categoria_id'] : null,
            'placa'          => trim((string) ($_GET['placa'] ?? '')) ?: null,
            'estado'         => in_array($_GET['estado'] ?? '', self::ESTADOS_VISIBLES, true) ? $_GET['estado'] : null,
            'solo_ocupadas'  => !empty($_GET['solo_ocupadas']),
        ];

        $unidades = $this->unidadesConMovimientos($estacion, $inicio, $finVentana, $filtros);

        // Cabecera de días
        $dias = [];
        for ($i = 0; $i < $diasTotal; $i++) {
            $d = $inicio->modify("+{$i} days");
            $dias[] = ['n' => $d->format('j'), 'm' => $d->format('M'), 'finde' => (int) $d->format('N') >= 6];
        }

        render('timeline/index', [
            'usuario'    => $user,
            'dias'       => $dias,
            'unidades'   => $unidades,
            'desde'      => $desde,
            'diasTotal'  => $diasTotal,
            'filtros'    => $filtros,
            'estacionSel' => $estacion,
            'verTodas'   => $user['rol'] === Rol::ADMIN_GLOBAL,
            'estaciones' => $this->catalogos->activos('estaciones', 'codigo'),
            'categorias' => $this->catalogos->activos('categorias_vehiculo', 'orden'),
        ], 'Timeline · Disponibilidad de Flota');
    }

    /** Unidades operativas en alcance con sus bloques de movimiento dentro de la ventana. */
    private function unidadesConMovimientos(?int $estacion, DateTimeImmutable $inicio, DateTimeImmutable $fin, array $filtros = []): array
    {
        $sql = 'SELECT u.id, u.placa_unidad, e.timezone, c.nombre AS categoria, c.es_motriz
                  FROM unidades u
                  JOIN estaciones e ON e.id = u.estacion_id
                  JOIN categorias_vehiculo c ON c.id = u.categoria_vehiculo_id
                 WHERE u.activo = 1 AND u.en_disponibilidad = 1';
        $params = [];
        if ($estacion !== null) {
            $sql .= ' AND u.estacion_id = :e';
            $params[':e'] = $estacion;
        }
        if (!empty($filtros['categoria_id'])) {
            $sql .= ' AND u.categoria_vehiculo_id = :cat';
            $params[':cat'] = $filtros['categoria_id'];
        }
        if (!empty($filtros['placa'])) {
            $sql .= ' AND u.placa_unidad LIKE :placa';
            $params[':placa'] = '%' . $filtros['placa'] . '%';
        }
        $sql .= ' ORDER BY u.placa_unidad';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $unidades = $stmt->fetchAll();

        // Un estado concreto, o los tres que ocupan la unidad.
        $estados = $filtros['estado'] !== null ? [$filtros['estado']] : self::ESTADOS_VISIBLES;
        $marcas = implode(',', array_fill(0, count($estados), '?'));
        $movStmt = $this->pdo->prepare(
            "SELECT m.id, m.estado, m.fecha_salida, m.fecha_fin_estimada,
                    po.codigo_iso AS origen, pd.codigo_iso AS destino,
                    m.queda_con_cliente,
                    (SELECT COUNT(*) FROM movimiento_unidades mu
                      WHERE mu.movimiento_id = m.id AND mu.rol = 'MOTRIZ') AS motriz_total,
                    (SELECT COUNT(*) FROM movimiento_unidades mu
                      WHERE mu.movimiento_id = m.id AND mu.rol = 'MOTRIZ' AND mu.liberado_en IS NULL) AS motriz_activo
               FROM movimientos m
               LEFT JOIN paises po ON po.id = m.pais_origen_id
               LEFT JOIN paises pd ON pd.id = m.pais_destino_id
              WHERE m.unidad_id = ?
                AND m.estado IN ({$marcas})
                AND m.fecha_salida < ? AND m.fecha_fin_estimada > ?
              ORDER BY m.fecha_salida"
        );

        $totalSeg = $fin->getTimestamp() - $inicio->getTimestamp();
        foreach ($unidades as &$u) {
            $movStmt->execute(array_merge(
                [$u['id']],
                $estados,
                [$fin->format('Y-m-d H:i:s'), $inicio->format('Y-m-d H:i:s')]
            ));
            $u['bloques'] = [];
            foreach ($movStmt->fetchAll() as $m) {
                $s = max($inicio->getTimestamp(), (new DateTimeImmutable($m['fecha_salida'], new DateTimeZone('UTC')))->getTimestamp());
                $e = min($fin->getTimestamp(), (new DateTimeImmutable($m['fecha_fin_estimada'], new DateTimeZone('UTC')))->getTimestamp());
                // Ojo: $fin es la ventana del timeline; las fechas locales usan otro nombre.
                $salidaLocal = format_local($m['fecha_salida'], $u['timezone'], 'd/m/Y H:i');
                $finLocal = format_local($m['fecha_fin_estimada'], $u['timezone'], 'd/m/Y H:i');
                // Una unidad que se queda con el cliente no está viajando: se pinta distinto,
                // con la MISMA regla que usa el tablero (DisponibilidadService::conElCliente).
                $conCliente = (int) $u['es_motriz'] !== 1 && (
                    (int) $m['queda_con_cliente'] === 1
                    || ((int) $m['motriz_total'] > 0 && (int) $m['motriz_activo'] === 0)
                );

                $u['bloques'][] = [
                    'id'     => (int) $m['id'],
                    'con_cliente' => $conCliente,
                    'left'   => round(($s - $inicio->getTimestamp()) / $totalSeg * 100, 3),
                    'width'  => max(1.5, round(($e - $s) / $totalSeg * 100, 3)),
                    'estado' => $m['estado'],
                    'ruta'   => ($m['origen'] ?? '?') . ' → ' . ($m['destino'] ?? '?'),
                    'label'  => ($m['origen'] ?? '?') . '→' . ($m['destino'] ?? '?'),
                    'salida' => $salidaLocal,
                    'fin'    => $finLocal,
                    // Respaldo nativo: si el JS no carga, el tooltip del navegador sigue informando.
                    'title'  => "#{$m['id']} " . ($conCliente ? 'CON CLIENTE' : $m['estado']) . " · {$salidaLocal} → {$finLocal}",
                ];
            }
        }
        unset($u);

        // Sin esto, una flota de 47 unidades muestra 45 filas en blanco para ver dos viajes.
        if (!empty($filtros['solo_ocupadas'])) {
            $unidades = array_values(array_filter($unidades, static fn(array $u): bool => $u['bloques'] !== []));
        }
        return $unidades;
    }
}
