<?php
/**
 * Inventario vehicular (plan §7.6). Solo lectura.
 *
 * @var array $conteos
 * @var array $unidades
 * @var bool $verTodas
 * @var array $filtros
 * @var array $estaciones
 * @var array $categorias
 * @var array $estados
 */
$labelEstado = EstadoVehiculo::labels();
$qs = http_build_query(array_filter($filtros, static fn($v) => $v !== null && $v !== ''));

/**
 * Enlace que aplica (o quita) un filtro conservando los demás. El resumen deja de ser un
 * cartel y pasa a ser el atajo: se ve "Cabezal 14" y se hace clic para verlos.
 */
$enlaceFiltro = static function (string $campo, $valor) use ($filtros): string {
    $activo = (string) ($filtros[$campo] ?? '') === (string) $valor;
    $nuevos = array_filter(
        [$campo => $activo ? null : $valor] + $filtros,          // volver a pulsar lo quita
        static fn($v) => $v !== null && $v !== ''
    );
    $query = http_build_query($nuevos);
    return '/inventario' . ($query ? '?' . $query : '');
};
$filtroActivo = static fn(string $campo, $valor): bool => (string) ($filtros[$campo] ?? '') === (string) $valor;

/** Proporción sobre el total, para leer el reparto sin comparar números a ojo. */
$total = max(1, (int) $conteos['total']);
$porcentaje = static fn(int $n): float => round($n / $total * 100, 1);
set_page_meta(
    'Inventario vehicular',
    'Visualiza la composición de la flota e inventario por categoría, estado y estación con filtros de solo lectura.',
    ['accion' => '<a class="btn btn--primary" href="/inventario/export.csv' . ($qs ? '?' . e($qs) : '') . '">⬇ Exportar CSV</a>']
);
?>
<section class="module">
    <form class="filters-panel" method="get" action="/inventario" data-filters-panel data-initial-open="false">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Estación, categoría, estado y clasificación</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="inventario-filters-more">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="inventario-filters-more" data-filters-more hidden>
            <div class="filters-grid">
                <?php if ($verTodas): ?>
                <label class="field"><span class="field__label">Estación</span>
                    <select name="estacion_id">
                        <option value="">Todas</option>
                        <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= (string) ($filtros['estacion_id'] ?? '') === (string) $es['id'] ? 'selected' : '' ?>><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
                <label class="field"><span class="field__label">Categoría</span>
                    <select name="categoria_id">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (string) ($filtros['categoria_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Estado</span>
                    <select name="estado_vehiculo">
                        <option value="">Todos</option>
                        <?php foreach ($estados as $ev): ?><option value="<?= e($ev) ?>" <?= ($filtros['estado_vehiculo'] ?? '') === $ev ? 'selected' : '' ?>><?= e($labelEstado[$ev] ?? $ev) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Clasificación</span>
                    <select name="en_disponibilidad">
                        <option value="">Todas</option>
                        <option value="1" <?= ($filtros['en_disponibilidad'] ?? '') === '1' ? 'selected' : '' ?>>Flota operativa</option>
                        <option value="0" <?= ($filtros['en_disponibilidad'] ?? '') === '0' ? 'selected' : '' ?>>Solo inventario</option>
                    </select></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/inventario" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <?php
    /** Una fila del resumen: enlace que filtra, con su barra de proporción de fondo. */
    $filaResumen = static function (string $etiqueta, int $n, string $href, bool $activo) use ($porcentaje): void {
        ?>
        <li>
            <a class="inv-fila<?= $activo ? ' is-activo' : '' ?>" href="<?= e($href) ?>"
               aria-pressed="<?= $activo ? 'true' : 'false' ?>">
                <span class="inv-fila__barra" style="width: <?= $porcentaje($n) ?>%" aria-hidden="true"></span>
                <span class="inv-fila__texto"><?= e($etiqueta) ?></span>
                <strong class="inv-fila__n"><?= $n ?></strong>
            </a>
        </li>
        <?php
    };
    ?>
    <div class="inv-cards">
        <div class="card inv-card">
            <h2>Por categoría</h2>
            <ul class="inv-list">
                <?php foreach ($conteos['por_categoria'] as $c): ?>
                    <?php $filaResumen($c['nombre'], (int) $c['n'], $enlaceFiltro('categoria_id', $c['id']), $filtroActivo('categoria_id', $c['id'])); ?>
                <?php endforeach; ?>
                <li class="inv-list__total"><span>Total</span><strong><?= (int) $conteos['total'] ?></strong></li>
            </ul>
        </div>

        <div class="inv-cards__col">
            <div class="card inv-card">
                <h2>Por estado del vehículo</h2>
                <ul class="inv-list">
                    <?php foreach ($conteos['por_estado'] as $c): ?>
                        <?php $filaResumen($labelEstado[$c['nombre']] ?? $c['nombre'], (int) $c['n'], $enlaceFiltro('estado_vehiculo', $c['nombre']), $filtroActivo('estado_vehiculo', $c['nombre'])); ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="card inv-card">
                <h2>Por alcance</h2>
                <ul class="inv-list">
                    <?php foreach ($conteos['por_alcance'] as $c): $intl = (int) $c['internacional'] === 1; ?>
                        <?php $filaResumen(
                            $intl ? 'Puede cruzar frontera' : 'Solo rutas nacionales',
                            (int) $c['n'],
                            $enlaceFiltro('internacional', $intl ? '1' : '0'),
                            $filtroActivo('internacional', $intl ? '1' : '0')
                        ); ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="card card--table">
        <?php if (empty($unidades)): ?>
            <div class="card__empty"><p>Sin unidades para estos filtros.</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr>
                <th class="col col--nombre">Placa</th>
                <th>Categoría</th>
                <th>Marca</th>
                <th>Modelo</th>
                <th>Año</th>
                <th>Combustible</th>
                <th>Capacidad</th>
                <th>Estado</th>
                <th>Estación</th>
            </tr></thead>
            <tbody>
            <?php foreach ($unidades as $u): $vacio = '<span class="muted">—</span>'; ?>
                <tr class="fila-unidad" data-unidad="<?= (int) $u['id'] ?>" data-placa="<?= e($u['placa_unidad']) ?>" tabindex="0">
                    <td class="col col--nombre">
                        <strong><?= e($u['placa_unidad']) ?></strong>
                        <?php if ((int) $u['puede_internacional'] === 1): ?>
                            <span class="alcance alcance--int" title="Tiene permiso vigente para cruzar frontera">INT</span>
                        <?php else: ?>
                            <span class="alcance alcance--nac" title="Sin permiso internacional: solo rutas dentro de su país">NAC</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($u['categoria']) ?></td>
                    <td><?= $u['marca'] ? e($u['marca']) : $vacio ?></td>
                    <td><?= $u['modelo'] ? e($u['modelo']) : $vacio ?></td>
                    <td><?= $u['anio'] ? (int) $u['anio'] : $vacio ?></td>
                    <td><?= $u['tipo_combustible'] ? e($u['tipo_combustible']) : $vacio ?></td>
                    <td><?= $u['capacidad'] ? e($u['capacidad']) : $vacio ?></td>
                    <td><?= e($labelEstado[$u['estado_vehiculo']] ?? $u['estado_vehiculo']) ?>
                        <?php if (!empty($u['estado_notas'])): ?><small class="muted block"><?= e($u['estado_notas']) ?></small><?php endif; ?></td>
                    <td><?= e($u['estacion_codigo']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</section>

<dialog id="dlg-unidad-ficha" class="dialog dialog--full dialog--xl">
    <div class="dialog__panel">
        <div class="dialog__head">
            <h2 id="ficha-titulo">Unidad</h2>
            <p class="dialog__lede" id="ficha-lede">Datos de la unidad y cómo se ha comportado.</p>
        </div>
        <div class="dialog__body" id="ficha-cuerpo"></div>
        <div class="dialog__actions">
            <button type="button" class="btn btn--primary" data-ficha-close>Cerrar</button>
        </div>
    </div>
</dialog>

<script src="/assets/js/inventario.js" type="module"></script>
