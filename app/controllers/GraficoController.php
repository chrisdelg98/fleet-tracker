<?php
/**
 * Gráficos: dos hojas que se exploran con filtro cruzado, como una página de BI.
 *
 * La pantalla no recibe totales sino la tabla de hechos del rango; los gráficos y sus filtros
 * se calculan en el navegador (ver GraficoService). Aquí solo viven el alcance por rol, el
 * rango y qué hoja se está viendo.
 */

declare(strict_types=1);

final class GraficoController
{
    /** Quién ve todas las estaciones, igual que en Inventario e Inteligencia. */
    private const ALCANCE_TOTAL = [Rol::ADMIN_GLOBAL, Rol::CONSULTA_REGIONAL];

    public function __construct(private GraficoService $service, private CatalogoModel $catalogos)
    {
    }

    public function index(array $params = []): void
    {
        $user = require_login_web();
        $hoja = (string) ($params['hoja'] ?? 'movimientos');
        if (!isset(GraficoService::HOJAS[$hoja])) {
            header('Location: /graficos');
            return;
        }

        $rango = $this->service->rango($_GET);
        $total = in_array($user['rol'], self::ALCANCE_TOTAL, true);
        // Un admin puede mirar una estación concreta; el resto ve la suya y no puede cambiarla.
        $estacion = $total
            ? (!empty($_GET['estacion_id']) ? (int) $_GET['estacion_id'] : null)
            : (int) $user['estacion_id'];

        $filas = $hoja === 'costos'
            ? $this->service->costos($rango, $estacion)
            : $this->service->movimientos($rango, $estacion);

        render('graficos/index', [
            'usuario'     => $user,
            'hoja'        => $hoja,
            'filas'       => $filas,
            'rango'       => $rango,
            'estacionSel' => $estacion,
            'verTodas'    => $total,
            'estaciones'  => $this->catalogos->activos('estaciones', 'codigo'),
        ], 'Gráficos · ' . GraficoService::HOJAS[$hoja] . ' · Flete Finder');
    }
}
