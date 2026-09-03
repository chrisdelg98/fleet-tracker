<?php
/**
 * Inventario vehicular (plan §7.6). Solo lectura, con alcance por rol aplicado en la
 * consulta (InventarioService). Página con conteos + tabla filtrable y descarga en Excel.
 */

declare(strict_types=1);

final class InventarioController
{
    public function __construct(
        private InventarioService $service,
        private CatalogoModel $catalogos,
        private UnidadEstadisticasService $estadisticas
    ) {
    }

    public function index(): void
    {
        $user = require_login_web();
        if (!InventarioService::tieneAcceso($user)) {
            http_response_code(403);
            echo 'No tienes acceso al inventario.';
            return;
        }
        $filtros = $this->filtros($_GET);
        render('inventario/index', [
            'usuario'    => $user,
            'filtros'    => $filtros,
            'verTodas'   => $this->service->alcance($user) === null,
            'conteos'    => $this->service->conteos($user, $filtros),
            'unidades'   => $this->service->listar($user, $filtros),
            'estaciones' => $this->catalogos->activos('estaciones', 'codigo'),
            'categorias' => $this->catalogos->activos('categorias_vehiculo', 'orden'),
            'estados'    => EstadoVehiculo::values(),
        ], 'Inventario · Disponibilidad de Flota');
    }

    /** GET /api/unidades/{id}/estadisticas — ficha completa para el panel del inventario. */
    public function apiEstadisticas(array $params): void
    {
        $user = require_login_api();
        if (!InventarioService::tieneAcceso($user)) {
            json_error('No tienes acceso al inventario', 403);
        }
        $datos = $this->estadisticas->de((int) $params['id']);
        if ($datos === null) {
            json_error('Unidad no encontrada', 404);
        }
        // El mismo alcance que la lista: si el usuario está limitado a su estación, no puede
        // pedir la ficha de una unidad ajena escribiendo el id a mano.
        $alcance = $this->service->alcance($user);
        if ($alcance !== null && $datos['unidad']['estacion_id'] !== $alcance) {
            json_error('No autorizado sobre esta estación', 403);
        }
        json_ok($datos);
    }

    /**
     * GET /inventario/export.xlsx — el inventario completo en Excel de verdad.
     *
     * Frente al CSV: sin líos de separador ni de codificación (Excel en español abre el CSV
     * con punto y coma y a veces parte las tildes), y con encabezado fijo y anchos, que en
     * una tabla de 15 columnas es la diferencia entre consultarla y pelearse con ella.
     */
    public function exportExcel(): void
    {
        $user = require_login_web();
        if (!InventarioService::tieneAcceso($user)) {
            http_response_code(403);
            echo 'No tienes acceso al inventario.';
            return;
        }

        // Las mismas columnas que la pantalla, más lo que ahí no cabe pero sí interesa
        // llevarse: tipo de equipo, piloto, permisos y desde cuándo está registrada.
        $columnas = [
            ['Placa', 16], ['Alcance', 14], ['Categoría', 16], ['Marca', 16], ['Modelo', 16],
            ['Año', 8], ['Combustible', 14], ['Capacidad', 12], ['Tipo de equipo', 16],
            ['Estado', 18], ['Comentario del estado', 34], ['Flota operativa', 15],
            ['Piloto asignado', 26], ['Permisos especiales', 40],
            ['Estación', 10], ['Nombre de la estación', 26], ['Registrada el', 20],
        ];

        $filas = [array_column($columnas, 0)];
        foreach ($this->service->listar($user, $this->filtros($_GET)) as $u) {
            $filas[] = [
                $u['placa_unidad'],
                (int) $u['puede_internacional'] === 1 ? 'Internacional' : 'Nacional',
                $u['categoria'], $u['marca'], $u['modelo'],
                $u['anio'] !== null ? (int) $u['anio'] : '',
                $u['tipo_combustible'], $u['capacidad'], $u['tipo_equipo'],
                EstadoVehiculo::label($u['estado_vehiculo']), $u['estado_notas'],
                (int) $u['en_disponibilidad'] === 1 ? 'Sí' : 'No',
                $u['piloto_asignado'], $u['permisos'],
                $u['estacion_codigo'], $u['estacion'],
                substr((string) $u['created_at'], 0, 10),
            ];
        }

        $bytes = (new XlsxWriter())
            ->hoja('Inventario', $filas, ['anchos' => array_column($columnas, 1), 'congelar' => true])
            ->generar();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="inventario-' . date('Ymd-His') . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
        echo $bytes;
    }

    private function filtros(array $q): array
    {
        return [
            'estacion_id'       => $q['estacion_id'] ?? null,
            'categoria_id'      => $q['categoria_id'] ?? null,
            'estado_vehiculo'   => $q['estado_vehiculo'] ?? null,
            'en_disponibilidad' => $q['en_disponibilidad'] ?? '',
            'internacional'     => isset($q['internacional']) && $q['internacional'] !== '' ? (int) $q['internacional'] : null,
        ];
    }
}
