<?php
/**
 * Inventario vehicular (plan §7.6). Solo lectura, con alcance por rol aplicado en la
 * consulta (InventarioService). Página con conteos + tabla filtrable y export CSV
 * (UTF-8 con BOM para que Excel muestre tildes y ñ correctamente).
 */

declare(strict_types=1);

final class InventarioController
{
    public function __construct(private InventarioService $service, private CatalogoModel $catalogos)
    {
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
        fputcsv($out, ['Placa', 'Furgón', 'Categoría', 'Marca', 'Modelo', 'Estación', 'Flota operativa', 'Estado', 'Notas']);
        foreach ($unidades as $u) {
            fputcsv($out, [
                $u['placa_unidad'], $u['placa_furgon'], $u['categoria'], $u['marca'], $u['modelo'],
                $u['estacion_codigo'], ((int) $u['en_disponibilidad'] === 1 ? 'Sí' : 'No'),
                $u['estado_vehiculo'], $u['estado_notas'],
            ]);
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
