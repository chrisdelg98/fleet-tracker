<?php
/**
 * Mantenimientos › Control. La hoja de control, ya calculada: nada de lo que se ve aquí está
 * guardado. Es la portada del módulo porque es la accionable: qué unidad hay que meter al taller.
 *
 * @var array $usuario
 * @var array $filas      unidades con su estado ya derivado
 * @var array $conteo     cuántas hay en cada estado
 * @var array $filtros    estacion_id, categoria_id, estado, q
 * @var array $estaciones
 * @var array $categorias
 * @var bool  $puedeRegistrar
 */
set_page_meta(
    'Mantenimientos',
    'Qué unidad toca servicio, cuánto le falta y en qué se ha gastado.',
    ['accion' => !empty($puedeRegistrar)
        ? '<button type="button" class="btn btn--primary" data-action="nuevo-mantenimiento">＋ Registrar mantenimiento</button>'
        : '',
     'acciones' => !empty($puedeRegistrar)
        ? '<button type="button" class="btn btn--ghost-dark" data-action="capturar-km">Capturar kilometraje</button>'
          . '<button type="button" class="btn btn--ghost-dark" data-action="carga-masiva">Subir kilometraje (Excel)</button>'
        : '']
);
$seccion = 'control';

$sel = static fn($a, $b): string => (string) $a === (string) $b ? 'selected' : '';
$hayFiltros = implode('', $filtros) !== '';
$num = static fn($v): string => $v === null ? '—' : number_format((float) $v);
$chip = [
    MantenimientoService::VENCIDO        => 'chip--transito',
    MantenimientoService::PROXIMO        => 'chip--reservada',
    MantenimientoService::AL_DIA         => 'chip--disponible',
    MantenimientoService::SIN_LINEA_BASE => 'chip--taller',
];
?>
<section class="module">
    <?php require __DIR__ . '/_nav.php'; ?>

    <!-- Las tarjetas son el filtro: se mira "cuántas vencidas hay" y se hace clic para verlas. -->
    <div class="mant-tarjetas">
        <?php foreach (MantenimientoService::estados() as $clave => $texto):
            $activa = $filtros['estado'] === $clave;
            $qs = http_build_query(array_merge($filtros, ['estado' => $activa ? '' : $clave]));
        ?>
            <a class="mant-tarjeta<?= $activa ? ' is-active' : '' ?>" href="/mantenimientos?<?= e($qs) ?>">
                <span class="mant-tarjeta__n"><?= (int) ($conteo[$clave] ?? 0) ?></span>
                <span class="mant-tarjeta__t"><?= e($texto) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="filters-panel" method="get" action="/mantenimientos" data-filters-panel data-initial-open="<?= $hayFiltros ? 'true' : 'false' ?>">
        <input type="hidden" name="estado" value="<?= e($filtros['estado']) ?>">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Estación, categoría y búsqueda por placa o marca</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="mant-filtros">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="mant-filtros" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Buscar</span>
                    <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Placa, marca o modelo…" data-no-search></label>
                <label class="field"><span class="field__label">Estación</span>
                    <select name="estacion_id">
                        <option value="">Todas</option>
                        <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= $sel($filtros['estacion_id'], $es['id']) ?>><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Categoría</span>
                    <select name="categoria_id">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel($filtros['categoria_id'], $c['id']) ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/mantenimientos" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <?php if ($filas === []): ?>
        <div class="card empty"><div class="card__empty">
            <p>Nada que mostrar con estos filtros. <a href="/mantenimientos" class="link">Ver todo</a></p>
        </div></div>
    <?php else: ?>
    <div class="card card--table">
        <table class="table">
            <thead><tr>
                <th>Unidad</th><th>Estación</th><th>Piloto</th><th>Km actual</th>
                <th>Último servicio</th><th>Próximo</th><th>Faltan</th><th>Estado</th><th>Costo</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $f): ?>
                <tr>
                    <td><strong><?= e($f['placa_unidad']) ?></strong>
                        <?php if ($f['marca'] || $f['modelo']): ?><small class="muted block"><?= e(trim($f['marca'] . ' ' . $f['modelo'])) ?></small><?php endif; ?></td>
                    <td><?= e($f['estacion_codigo']) ?></td>
                    <td><?= $f['piloto'] ? e($f['piloto']) : '<span class="muted">—</span>' ?></td>
                    <td><?= $num($f['km_actual']) ?>
                        <?php if ($f['km_fecha']): ?><small class="muted block"><?= e($f['km_fecha']) ?></small><?php endif; ?></td>
                    <td><?= $f['ultimo_fecha'] ? e($f['ultimo_fecha']) : '<span class="muted">—</span>' ?>
                        <?php if ($f['ultimo_km'] !== null): ?><small class="muted block"><?= $num($f['ultimo_km']) ?> km</small><?php endif; ?></td>
                    <td><?= $num($f['proximo_km']) ?></td>
                    <td>
                        <?php if ($f['faltan_km'] === null): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <?= $f['faltan_km'] < 0 ? '−' . $num(abs((int) $f['faltan_km'])) : $num($f['faltan_km']) ?> km
                            <?php if ($f['fecha_estimada']): ?>
                                <small class="muted block" title="Estimado con el ritmo de los últimos 90 días">≈ <?= e($f['fecha_estimada']) ?></small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><span class="chip <?= e($chip[$f['estado']]) ?>"><?= e(MantenimientoService::estados()[$f['estado']]) ?></span></td>
                    <td><?= $f['costo_usd'] > 0 ? '$' . number_format((float) $f['costo_usd'], 2) : '<span class="muted">—</span>' ?></td>
                    <td class="row-actions">
                        <?= row_menu(array_filter([
                            empty($puedeRegistrar) ? null : ['label' => 'Registrar mantenimiento', 'attrs' => [
                                'data-action' => 'nuevo-mantenimiento', 'data-unidad' => (int) $f['id']]],
                            empty($puedeRegistrar) ? null : ['label' => 'Anotar kilometraje', 'attrs' => [
                                'data-action' => 'capturar-km', 'data-unidad' => (int) $f['id']]],
                            ['label' => 'Ver historial', 'attrs' => [
                                'data-action' => 'ir-historial', 'data-href' => '/mantenimientos/historial?unidad_id=' . (int) $f['id']]],
                        ])) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_modales.php'; ?>

<?php if (!empty($puedeRegistrar)): ?>
<?= dialogo_import([
    'titulo'    => 'Subir kilometraje',
    'lede'      => 'Una fila por lectura: placa, fecha y odómetro. Sirve tanto para el mes corriente '
                 . 'como para traer de golpe lo que está en la hoja. Si una fila falla, no se carga ninguna.',
    'plantilla' => '/mantenimientos/kilometraje/plantilla.xlsx',
    'url'       => '/api/mantenimientos/kilometraje/importar',
    'singular'  => 'lectura',
    'plural'    => 'lecturas',
    'columnas'  => [
        ['clave' => 'placa', 'label' => 'Placa'],
        ['clave' => 'fecha', 'label' => 'Fecha'],
        ['clave' => 'km',    'label' => 'Kilometraje'],
    ],
]) ?>
<script src="<?= e(asset('/assets/js/import-excel.js')) ?>" type="module"></script>
<?php endif; ?>
