<?php
/**
 * Inventario vehicular (plan §7.6). Solo lectura, con alcance por rol aplicado en la
 * consulta (InventarioService). Página con conteos + tabla filtrable y export CSV
 * (UTF-8 con BOM para que Excel muestre tildes y ñ correctamente).
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

    /** GET /inventario/export.csv — descarga el inventario permitido con los filtros aplicados. */
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

    public function export(): void
    {
        $user = require_login_web();
        if (!InventarioService::tieneAcceso($user)) {
            http_response_code(403);
            echo 'No tienes acceso al inventario.';
            return;
        }
        $unidades = $this->service->listar($user, $this->filtros($_GET));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inventario-' . date('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
        // Las mismas columnas que la tabla, en el mismo orden: quien exporta espera
        // llevarse lo que está viendo, no una versión recortada de hace tres cambios.
        fputcsv($out, [
            'Placa', 'Alcance', 'Categoría', 'Marca', 'Modelo', 'Año', 'Combustible',
            'Capacidad', 'Estado', 'Notas', 'Flota operativa', 'Estación',
        ], ',', '"', '');
        foreach ($unidades as $u) {
            fputcsv($out, [
                $u['placa_unidad'],
                ((int) $u['puede_internacional'] === 1 ? 'Internacional' : 'Nacional'),
                $u['categoria'], $u['marca'], $u['modelo'], $u['anio'],
                $u['tipo_combustible'], $u['capacidad'],
                EstadoVehiculo::label($u['estado_vehiculo']), $u['estado_notas'],
                ((int) $u['en_disponibilidad'] === 1 ? 'Sí' : 'No'),
                $u['estacion_codigo'],
            ], ',', '"', '');
        }
        fclose($out);
    }

    private function filtros(array $q): array
    {
        return [
            'estacion_id'       => $q['estacion_id'] ?? null,
            'categoria_id'      => $q['categoria_id'] ?? null,
            'estado_vehiculo'   => $q['estado_vehiculo'] ?? null,
            'en_disponibilidad' => $q['en_disponibilidad'] ?? '',
        ];
    }
}
