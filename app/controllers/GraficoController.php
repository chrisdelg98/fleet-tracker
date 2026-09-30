<?php
/**
 * Las hojas de gráficos: filtro cruzado sobre la tabla de hechos, como una página de BI.
 *
 * La pantalla no recibe totales sino la tabla de hechos del rango; los gráficos y sus filtros
 * se calculan en el navegador (ver GraficoService). Aquí solo viven el alcance por rol y el
 * rango.
 *
 * Son dos pantallas en dos módulos distintos —movimientos en Consulta, costos dentro de
 * Mantenimientos— pero comparten alcance, rango y dibujo, así que comparten clase.
 */

declare(strict_types=1);

final class GraficoController
{
    /** Quién ve todas las estaciones, igual que en Inventario e Inteligencia. */
    private const ALCANCE_TOTAL = [Rol::ADMIN_GLOBAL, Rol::CONSULTA_REGIONAL];

    public function __construct(private GraficoService $service, private CatalogoModel $catalogos)
    {
    }

    /** GET /graficos — la hoja de movimientos. */
    public function index(): void
    {
        $user = require_login_web();
        [$rango, $estacion, $total] = $this->contexto($user);

        render('graficos/index', [
            'usuario'     => $user,
            'filas'       => $this->service->movimientos($rango, $estacion),
            'rango'       => $rango,
            'estacionSel' => $estacion,
            'verTodas'    => $total,
            'estaciones'  => $this->catalogos->activos('estaciones', 'codigo'),
        ], 'Gráficos · Movimientos · Flete Finder');
    }

    /** GET /mantenimientos/graficos — la misma hoja, sobre el gasto de taller. */
    public function costos(): void
    {
        $user = require_login_web();
        [$rango, $estacion, $total] = $this->contexto($user);

        render('mantenimientos/graficos', [
            'usuario'     => $user,
            'filas'       => $this->service->costos($rango, $estacion),
            'rango'       => $rango,
            'estacionSel' => $estacion,
            'verTodas'    => $total,
            'estaciones'  => $this->catalogos->activos('estaciones', 'codigo'),
        ], 'Costos de mantenimiento · Flete Finder');
    }

    /** Rango pedido y hasta dónde llega este usuario. */
    private function contexto(array $user): array
    {
        $total = in_array($user['rol'], self::ALCANCE_TOTAL, true);
        // Un admin puede mirar una estación concreta; el resto ve la suya y no puede cambiarla.
        $estacion = $total
            ? (!empty($_GET['estacion_id']) ? (int) $_GET['estacion_id'] : null)
            : (int) $user['estacion_id'];

        return [$this->service->rango($_GET), $estacion, $total];
    }
}
