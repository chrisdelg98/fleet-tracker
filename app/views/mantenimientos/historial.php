<?php
/**
 * Mantenimientos › Historial. La bitácora de intervenciones: la única tabla de hechos del módulo.
 *
 * @var array $usuario
 * @var array $resultado  filas, total, costo_usd, pagina, paginas, por_pagina
 * @var array $filtros
 * @var array $estaciones
 * @var array $tiposMantenimiento
 * @var array $talleresParaModal
 * @var bool  $puedeRegistrar
 */
set_page_meta(
    'Historial de mantenimientos',
    'Todo lo que se le ha hecho a la flota, con su costo y su taller.',
    ['accion' => !empty($puedeRegistrar)
        ? '<button type="button" class="btn btn--primary" data-action="nuevo-mantenimiento">＋ Registrar mantenimiento</button>'
        : '',
     'acciones' => !empty($puedeRegistrar)
        ? '<button type="button" class="btn btn--ghost-dark" data-action="carga-masiva">Subir historial (Excel)</button>'
        : '']
);
$seccion = 'historial';

$sel = static fn($a, $b): string => (string) $a === (string) $b ? 'selected' : '';
$hayFiltros = implode('', $filtros) !== '';
$comunes = $filtros + ['por_pagina' => $resultado['por_pagina']];
?>
<section class="module">
    <?php require __DIR__ . '/_nav.php'; ?>

    <form class="filters-panel" method="get" action="/mantenimientos/historial" data-filters-panel data-initial-open="<?= $hayFiltros ? 'true' : 'false' ?>">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Fechas, estación, tipo, taller y búsqueda por placa, descripción o factura</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="hist-mant-filtros">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="hist-mant-filtros" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Desde</span>
                    <input type="date" name="desde" value="<?= e($filtros['desde']) ?>"></label>
                <label class="field"><span class="field__label">Hasta</span>
                    <input type="date" name="hasta" value="<?= e($filtros['hasta']) ?>"></label>
                <label class="field"><span class="field__label">Estación</span>
                    <select name="estacion_id">
                        <option value="">Todas</option>
                        <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= $sel($filtros['estacion_id'], $es['id']) ?>><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Tipo</span>
                    <select name="tipo_id">
                        <option value="">Todos</option>
                        <?php foreach ($tiposMantenimiento as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel($filtros['tipo_id'], $t['id']) ?>><?= e($t['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Taller</span>
                    <select name="taller_id">
                        <option value="">Todos</option>
                        <?php foreach ($talleresParaModal as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel($filtros['taller_id'], $t['id']) ?>><?= e($t['nombre']) ?> · <?= e($t['estacion_codigo']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Buscar</span>
                    <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Placa, descripción o factura…" data-no-search></label>
                <label class="field"><span class="field__label">Por página</span>
                    <select name="por_pagina" data-no-search>
                        <?php foreach ([15, 30, 50] as $op): ?><option value="<?= $op ?>" <?= $sel($resultado['por_pagina'], $op) ?>><?= $op ?></option><?php endforeach; ?>
                    </select></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/mantenimientos/historial" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <p class="dashboard__meta">
        <span><?= (int) $resultado['total'] ?> intervencion<?= (int) $resultado['total'] === 1 ? '' : 'es' ?></span>
        · <span>$<?= number_format((float) $resultado['costo_usd'], 2) ?> en total</span>
        <?php if ($resultado['paginas'] > 1): ?>
            · <span class="muted">página <?= (int) $resultado['pagina'] ?> de <?= (int) $resultado['paginas'] ?></span>
        <?php endif; ?>
        · <a class="link" href="/mantenimientos/historial.csv?<?= e(http_build_query($filtros)) ?>">Descargar CSV</a>
    </p>

    <?php if ($resultado['filas'] === []): ?>
        <div class="card empty"><div class="card__empty">
            <p>Sin intervenciones para estos filtros. <a href="/mantenimientos/historial" class="link">Limpiar filtros</a></p>
        </div></div>
    <?php else: ?>
    <div class="card card--table">
        <table class="table">
            <thead><tr>
                <th>Fecha</th><th>Unidad</th><th>Tipo</th><th>Descripción</th><th>Taller</th>
                <th>Km</th><th>Costo</th><th>Factura</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($resultado['filas'] as $m): ?>
                <tr>
                    <td><?= e($m['fecha']) ?></td>
                    <td><strong><?= e($m['placa_unidad']) ?></strong>
                        <small class="muted block"><?= e($m['estacion_codigo']) ?></small></td>
                    <td><?= e($m['tipo']) ?>
                        <?php if ((int) $m['reinicia_ciclo'] === 1): ?><span class="badge badge--ok" title="Reinicia el ciclo del próximo servicio">ciclo</span><?php endif; ?></td>
                    <td><?= $m['descripcion'] ? e($m['descripcion']) : '<span class="muted">—</span>' ?>
                        <?php if ($m['observaciones']): ?><small class="muted block"><?= e($m['observaciones']) ?></small><?php endif; ?></td>
                    <td><?= $m['taller'] ? e($m['taller']) : '<span class="muted">—</span>' ?>
                        <?php if ($m['taller'] && (int) $m['es_propio'] === 1): ?><small class="muted block">propio</small><?php endif; ?></td>
                    <td><?= $m['km'] !== null ? number_format((float) $m['km']) : '<span class="muted">—</span>' ?></td>
                    <td>
                        <?php if ($m['costo_usd'] === null): ?>
                            <span class="badge badge--warn" title="Registrado sin factura">Sin costo</span>
                        <?php else: ?>
                            $<?= number_format((float) $m['costo_usd'], 2) ?>
                            <?php if ($m['moneda'] && $m['moneda'] !== 'USD'): ?>
                                <small class="muted block"><?= e($m['moneda']) ?> <?= number_format((float) $m['costo'], 2) ?> · tasa <?= e(rtrim(rtrim((string) $m['tasa_usada'], '0'), '.')) ?></small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $m['factura'] ? e($m['factura']) : '<span class="muted">—</span>' ?></td>
                    <td class="row-actions">
                        <?= empty($puedeRegistrar) ? '' : row_menu([
                            ['label' => 'Editar', 'attrs' => ['data-action' => 'editar-mantenimiento', 'data-id' => (int) $m['id']]],
                            ['label' => 'Eliminar', 'danger' => true, 'attrs' => [
                                'data-action' => 'eliminar-mantenimiento', 'data-id' => (int) $m['id'],
                                'data-nombre' => $m['placa_unidad'] . ' · ' . $m['fecha']]],
                        ]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($resultado['paginas'] > 1): ?>
    <nav class="pager">
        <?php for ($p = 1; $p <= $resultado['paginas']; $p++): $pq = http_build_query(array_merge($comunes, ['pagina' => $p])); ?>
            <a href="/mantenimientos/historial?<?= e($pq) ?>" class="pager__link<?= $p === (int) $resultado['pagina'] ? ' is-active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_modales.php'; ?>

<?php if (!empty($puedeRegistrar)): ?>
<?= dialogo_import([
    'titulo'    => 'Subir historial de mantenimientos',
    'lede'      => 'Una fila por intervención. El taller tiene que existir ya en la estación de la unidad; '
                 . 'si falta, créalo antes en Talleres. Si una fila falla, no se carga ninguna.',
    'plantilla' => '/mantenimientos/plantilla.xlsx',
    'url'       => '/api/mantenimientos/importar',
    'singular'  => 'mantenimiento',
    'plural'    => 'mantenimientos',
    'columnas'  => [
        ['clave' => 'fecha',       'label' => 'Fecha'],
        ['clave' => 'placa',       'label' => 'Placa'],
        ['clave' => 'tipo',        'label' => 'Tipo'],
        ['clave' => 'descripcion', 'label' => 'Descripción', 'clase' => 'col col--text'],
        ['clave' => 'costo',       'label' => 'Costo'],
    ],
]) ?>
<script src="<?= e(asset('/assets/js/import-excel.js')) ?>" type="module"></script>
<?php endif; ?>
