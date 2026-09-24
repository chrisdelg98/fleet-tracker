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

    <?php
    $opcionesDe = static function (array $filas, string $etiqueta, string $campo = 'nombre'): array {
    $out = ['' => $etiqueta];
    foreach ($filas as $f) { $out[(int) $f['id']] = (string) $f[$campo]; }
    return $out;
};
    $accion = '/mantenimientos/historial';
    // La página vuelve a 1 al filtrar: quedarse en la 4 de un resultado de 2 confunde.
    $ocultos = ['por_pagina' => $resultado['por_pagina']];
    $conRangos = true;
    $campos = [
        ['tipo' => 'buscar', 'name' => 'q', 'label' => 'Buscar', 'valor' => $filtros['q'],
         'placeholder' => 'Placa, descripción o factura…'],
        ['tipo' => 'fecha', 'name' => 'desde', 'label' => 'Desde', 'valor' => $filtros['desde']],
        ['tipo' => 'fecha', 'name' => 'hasta', 'label' => 'Hasta', 'valor' => $filtros['hasta']],
        ['tipo' => 'select', 'name' => 'estacion_id', 'label' => 'Estación', 'valor' => $filtros['estacion_id'],
         'opciones' => $opcionesDe($estaciones, 'Todas', 'codigo')],
        ['tipo' => 'select', 'name' => 'tipo_id', 'label' => 'Tipo', 'valor' => $filtros['tipo_id'],
         'opciones' => $opcionesDe($tiposMantenimiento, 'Todos')],
        ['tipo' => 'select', 'name' => 'taller_id', 'label' => 'Taller', 'valor' => $filtros['taller_id'],
         'opciones' => $opcionesDe($talleresParaModal, 'Todos')],
    ];
    ?>
    <?php require __DIR__ . '/_filtros.php'; ?>

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
