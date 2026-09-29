<?php
/**
 * Gráficos. Dos hojas —movimientos y costos— que se exploran pulsando las barras: cada una
 * filtra a las demás, como una página de BI.
 *
 * La página no trae totales: trae la tabla de hechos del rango y el navegador agrega. Por eso
 * el filtro cruzado es instantáneo (ver GraficoService y graficos.js).
 *
 * @var array  $usuario
 * @var string $hoja        movimientos | costos
 * @var array  $filas       tabla de hechos del rango
 * @var array  $rango       desde, hasta
 * @var int|null $estacionSel
 * @var bool   $verTodas
 * @var array  $estaciones
 */
// El título es «Gráficos» y no el de la hoja: «Movimientos» ya es otra entrada del menú y
// verlo arriba en dos pantallas distintas hace dudar de dónde está uno.
set_page_meta(
    'Gráficos',
    'Pulsa cualquier barra para filtrar el resto de la hoja. Se pueden combinar.',
);

$hoy = new DateTimeImmutable('today');
$atajos = [
    'Este mes'  => [$hoy->modify('first day of this month')->format('Y-m-d'), $hoy->format('Y-m-d')],
    '3 meses'   => [$hoy->modify('-3 months')->format('Y-m-d'), $hoy->format('Y-m-d')],
    'Este año'  => [$hoy->format('Y') . '-01-01', $hoy->format('Y-m-d')],
    'Año pasado' => [($hoy->format('Y') - 1) . '-01-01', ($hoy->format('Y') - 1) . '-12-31'],
];
$comunes = array_filter(['estacion_id' => $estacionSel], static fn($v) => $v !== null);
?>
<section class="module">
    <nav class="modnav" aria-label="Hojas">
        <?php foreach (GraficoService::HOJAS as $clave => $texto): ?>
            <a href="/graficos/<?= e($clave) ?>?<?= e(http_build_query($rango + $comunes)) ?>"
               class="modnav__link<?= $clave === $hoja ? ' is-active' : '' ?>"
               <?= $clave === $hoja ? 'aria-current="page"' : '' ?>><?= e($texto) ?></a>
        <?php endforeach; ?>
    </nav>

    <form class="filters-panel filters-panel--split card" method="get" action="/graficos/<?= e($hoja) ?>">
        <div class="filters-panel__always">
            <div class="filters-panel__always-row">
                <div class="filters-panel__always-main">
                    <div class="atajos" role="group" aria-label="Periodo">
                        <?php foreach ($atajos as $texto => [$d, $h]):
                            $activo = $rango['desde'] === $d && $rango['hasta'] === $h; ?>
                            <a class="chipbtn<?= $activo ? ' is-active' : '' ?>"
                               href="/graficos/<?= e($hoja) ?>?<?= e(http_build_query(['desde' => $d, 'hasta' => $h] + $comunes)) ?>"><?= e($texto) ?></a>
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
    <div class="graf-activos" id="graf-filtros-activos" hidden></div>

    <div class="graf-kpis" id="graf-kpis"></div>
    <div class="graf-paneles" id="graf-paneles"></div>

    <section class="card card--table graf-detalle">
        <h2>Detalle</h2>
        <div id="graf-tabla"></div>
    </section>
</section>

<script type="application/json" id="graf-datos" data-hoja="<?= e($hoja) ?>"><?= json_encode($filas, JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('/assets/js/graficos.js')) ?>" type="module"></script>
