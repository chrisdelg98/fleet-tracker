<?php
/**
 * Mantenimientos › Costos. La hoja de análisis, derivada del historial: no hay ningún total
 * guardado, así que esta pantalla y el historial no pueden discrepar.
 *
 * La barra se dibuja con .int-bar, que ya existe en Inteligencia: sin librería de gráficos.
 *
 * @var array  $usuario
 * @var string $por      unidad | marca | taller
 * @var array  $filas
 * @var array  $filtros
 * @var array  $estaciones
 */
set_page_meta('Costos de mantenimiento', 'En qué se va el dinero: por unidad, por marca o por taller.');
$seccion = 'costos';

$sel = static fn($a, $b): string => (string) $a === (string) $b ? 'selected' : '';
$total = array_sum(array_map(static fn(array $f): float => (float) $f['total_usd'], $filas));
$mayor = $filas === [] ? 0.0 : max(array_map(static fn(array $f): float => (float) $f['total_usd'], $filas));
$sinCosto = array_sum(array_map(static fn(array $f): int => (int) $f['sin_costo'], $filas));
$agrupaciones = ['unidad' => 'Por unidad', 'marca' => 'Por marca', 'taller' => 'Por taller'];
?>
<section class="module">
    <?php require __DIR__ . '/_nav.php'; ?>

    <form class="card prov-buscar" method="get" action="/mantenimientos/costos">
        <label class="field"><span class="field__label">Agrupar</span>
            <select name="por" data-no-search>
                <?php foreach ($agrupaciones as $clave => $texto): ?><option value="<?= e($clave) ?>" <?= $sel($por, $clave) ?>><?= e($texto) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span class="field__label">Desde</span>
            <input type="date" name="desde" value="<?= e($filtros['desde']) ?>"></label>
        <label class="field"><span class="field__label">Hasta</span>
            <input type="date" name="hasta" value="<?= e($filtros['hasta']) ?>"></label>
        <label class="field"><span class="field__label">Estación</span>
            <select name="estacion_id">
                <option value="">Todas</option>
                <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= $sel($filtros['estacion_id'], $es['id']) ?>><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
            </select></label>
        <div class="prov-buscar__acciones">
            <button type="submit" class="btn btn--ghost-dark">Aplicar</button>
            <a href="/mantenimientos/costos" class="link">Limpiar</a>
        </div>
    </form>

    <p class="dashboard__meta">
        <span>$<?= number_format($total, 2) ?> en total</span>
        · <span class="muted"><?= count($filas) ?> <?= $por === 'unidad' ? 'unidades' : ($por === 'marca' ? 'marcas' : 'talleres') ?></span>
        <?php if ($sinCosto > 0): ?>
            · <span class="badge badge--warn" title="Registradas sin factura: no se suman"><?= $sinCosto ?> sin costo</span>
        <?php endif; ?>
    </p>

    <?php if ($filas === []): ?>
        <div class="card empty"><div class="card__empty">
            <p>No hay gastos registrados con estos filtros.</p>
        </div></div>
    <?php else: ?>
    <div class="card card--table">
        <table class="table">
            <thead><tr><th><?= e(rtrim($agrupaciones[$por], 's')) ?></th><th>Intervenciones</th><th>Promedio</th><th>Total</th><th>Participación</th></tr></thead>
            <tbody>
            <?php foreach ($filas as $f): $pct = $mayor > 0 ? ((float) $f['total_usd'] / $mayor) * 100 : 0; ?>
                <tr>
                    <td><strong><?= e($f['etiqueta']) ?></strong>
                        <?php if (!empty($f['detalle'])): ?><small class="muted block"><?= e($f['detalle']) ?></small><?php endif; ?></td>
                    <td><?= (int) $f['intervenciones'] ?>
                        <?php if ((int) $f['sin_costo'] > 0): ?><small class="muted block"><?= (int) $f['sin_costo'] ?> sin costo</small><?php endif; ?></td>
                    <td>$<?= number_format((float) $f['promedio_usd'], 2) ?></td>
                    <td><strong>$<?= number_format((float) $f['total_usd'], 2) ?></strong></td>
                    <td>
                        <div class="int-bar" title="<?= number_format($total > 0 ? ((float) $f['total_usd'] / $total) * 100 : 0, 1) ?>% del gasto">
                            <span style="width: <?= number_format($pct, 1) ?>%"></span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_modales.php'; ?>
