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

        $desde = !empty($_GET['desde']) ? substr((string) $_GET['desde'], 0, 10) : (new DateTimeImmutable('now'))->format('Y-m-d');
        $inicio = new DateTimeImmutable($desde . ' 00:00:00', new DateTimeZone('UTC'));
        $finVentana = $inicio->modify('+' . self::DIAS . ' days');

        // Estación en alcance
        $estacion = $user['rol'] === Rol::ADMIN_GLOBAL
            ? (!empty($_GET['estacion_id']) ? (int) $_GET['estacion_id'] : null)
            : (int) $user['estacion_id'];

        $unidades = $this->unidadesConMovimientos($estacion, $inicio, $finVentana);

        // Cabecera de días
        $dias = [];
        for ($i = 0; $i < self::DIAS; $i++) {
            $d = $inicio->modify("+{$i} days");
            $dias[] = ['n' => $d->format('j'), 'm' => $d->format('M')];
        }

        render('timeline/index', [
            'usuario'    => $user,
            'dias'       => $dias,
            'unidades'   => $unidades,
            'desde'      => $desde,
            'diasTotal'  => self::DIAS,
            'estacionSel' => $estacion,
            'verTodas'   => $user['rol'] === Rol::ADMIN_GLOBAL,
            'estaciones' => $this->catalogos->activos('estaciones', 'codigo'),
        ], 'Timeline · Disponibilidad de Flota');
    }

    /** Unidades operativas en alcance con sus bloques de movimiento dentro de la ventana. */
    private function unidadesConMovimientos(?int $estacion, DateTimeImmutable $inicio, DateTimeImmutable $fin): array
    {
        $sql = 'SELECT u.id, u.placa_unidad, e.timezone
                  FROM unidades u JOIN estaciones e ON e.id = u.estacion_id
                 WHERE u.activo = 1 AND u.en_disponibilidad = 1';
        $params = [];
        if ($estacion !== null) {
            $sql .= ' AND u.estacion_id = :e';
            $params[':e'] = $estacion;
        }
        $sql .= ' ORDER BY u.placa_unidad';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $unidades = $stmt->fetchAll();

        $movStmt = $this->pdo->prepare(
            "SELECT m.id, m.estado, m.fecha_salida, m.fecha_fin_estimada,
                    po.codigo_iso AS origen, pd.codigo_iso AS destino
               FROM movimientos m
               LEFT JOIN paises po ON po.id = m.pais_origen_id
               LEFT JOIN paises pd ON pd.id = m.pais_destino_id
              WHERE m.unidad_id = :u
                AND m.estado IN ('RESERVADO','PROGRAMADO','EN_TRANSITO')
                AND m.fecha_salida < :fin AND m.fecha_fin_estimada > :ini
              ORDER BY m.fecha_salida"
        );

        $totalSeg = $fin->getTimestamp() - $inicio->getTimestamp();
        foreach ($unidades as &$u) {
            $movStmt->execute([':u' => $u['id'], ':fin' => $fin->format('Y-m-d H:i:s'), ':ini' => $inicio->format('Y-m-d H:i:s')]);
            $u['bloques'] = [];
            foreach ($movStmt->fetchAll() as $m) {
                $s = max($inicio->getTimestamp(), (new DateTimeImmutable($m['fecha_salida'], new DateTimeZone('UTC')))->getTimestamp());
                $e = min($fin->getTimestamp(), (new DateTimeImmutable($m['fecha_fin_estimada'], new DateTimeZone('UTC')))->getTimestamp());
                $u['bloques'][] = [
                    'left'   => round(($s - $inicio->getTimestamp()) / $totalSeg * 100, 3),
                    'width'  => max(1.5, round(($e - $s) / $totalSeg * 100, 3)),
                    'estado' => $m['estado'],
                    'label'  => ($m['origen'] ?? '?') . '→' . ($m['destino'] ?? '?'),
                    'title'  => "#{$m['id']} {$m['estado']} · " . format_local($m['fecha_salida'], $u['timezone'], 'd M H:i')
                                . ' → ' . format_local($m['fecha_fin_estimada'], $u['timezone'], 'd M H:i'),
                ];
            }
        }
        return $unidades;
    }
}
