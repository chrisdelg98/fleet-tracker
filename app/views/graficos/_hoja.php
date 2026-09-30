<?php
/**
 * El cuerpo de una hoja de gráficos: barra de periodo, cifras, paneles y detalle.
 *
 * Vive aparte porque la misma hoja se monta en dos sitios —Movimientos en Consulta y Costos
 * dentro del módulo de mantenimientos—, y el filtro cruzado tiene que comportarse igual en los
 * dos. Lo único que cambia entre ellos es a dónde apunta el formulario.
 *
 * La página no trae totales: trae la tabla de hechos del rango y el navegador agrega. Por eso
 * el filtro cruzado es instantáneo (ver GraficoService y graficos.js).
 *
 * @var string   $hoja        movimientos | costos — qué especificación usa graficos.js
 * @var string   $accion      ruta de esta hoja, para el formulario y los atajos
 * @var array    $filas       tabla de hechos del rango
 * @var array    $rango       desde, hasta
 * @var int|null $estacionSel
 * @var bool     $verTodas
 * @var array    $estaciones
 */
$hoy = new DateTimeImmutable('today');
$atajos = [
    'Este mes'   => [$hoy->modify('first day of this month')->format('Y-m-d'), $hoy->format('Y-m-d')],
    '3 meses'    => [$hoy->modify('-3 months')->format('Y-m-d'), $hoy->format('Y-m-d')],
    'Este año'   => [$hoy->format('Y') . '-01-01', $hoy->format('Y-m-d')],
    'Año pasado' => [($hoy->format('Y') - 1) . '-01-01', ($hoy->format('Y') - 1) . '-12-31'],
];
$comunes = array_filter(['estacion_id' => $estacionSel], static fn($v) => $v !== null);
?>
<form class="filters-panel filters-panel--split card" method="get" action="<?= e($accion) ?>">
    <div class="filters-panel__always">
        <div class="filters-panel__always-row">
            <div class="filters-panel__always-main">
                <div class="atajos" role="group" aria-label="Periodo">
                    <?php foreach ($atajos as $texto => [$d, $h]):
                        $activo = $rango['desde'] === $d && $rango['hasta'] === $h; ?>
                        <a class="chipbtn<?= $activo ? ' is-active' : '' ?>"
                           href="<?= e($accion) ?>?<?= e(http_build_query(['desde' => $d, 'hasta' => $h] + $comunes)) ?>"><?= e($texto) ?></a>
                    <?php endforeach; ?>
                </div>
                <label class="km-fecha"><span>Desde</span>
                    <input type="date" name="desde" value="<?= e($rango['desde']) ?>"></label>
                <label class="km-fecha"><span>Hasta</span>
                    <input type="date" name="hasta" value="<?= e($rango['hasta']) ?>"></label>
                <?php if ($verTodas): ?>
                    <label class="km-fecha"><span>Estación</span>
                        <select name="estacion_id" data-no-search>
                            <option value="">Todas</option>
                            <?php foreach ($estaciones as $es): ?>
                                <option value="<?= (int) $es['id'] ?>" <?= (string) $estacionSel === (string) $es['id'] ? 'selected' : '' ?>><?= e($es['codigo']) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                <?php endif; ?>
                <button type="submit" class="btn btn--ghost-dark">Aplicar</button>
            </div>
            <div class="dashboard__status">
                <strong><?= count($filas) ?> registro<?= count($filas) === 1 ? '' : 's' ?></strong>
            </div>
        </div>
    </div>
</form>

<!-- Lo que se ha pulsado, para poder deshacerlo sin adivinar qué está filtrando. -->
<div class="graf-activos" id="graf-filtros-activos"></div>

<div class="graf-kpis" id="graf-kpis"></div>
<div class="graf-paneles" id="graf-paneles"></div>

<section class="card card--table graf-detalle">
    <div class="graf-detalle__head">
        <h2>Detalle</h2>
        <input type="search" id="graf-tabla-buscar" placeholder="Buscar en el detalle…" data-no-search autocomplete="off">
    </div>
    <div id="graf-tabla"></div>
</section>

<script type="application/json" id="graf-datos" data-hoja="<?= e($hoja) ?>"><?= json_encode($filas, JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('/assets/js/graficos.js')) ?>" type="module"></script>
