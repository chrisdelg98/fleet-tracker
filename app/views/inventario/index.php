<?php
/**
 * Inventario vehicular (plan §7.6). Solo lectura. @var array $conteos @var array $unidades
 * @var bool $verTodas @var array $filtros @var array $estaciones @var array $categorias @var array $estados
 */
$labelEstado = [
    EstadoVehiculo::OPERATIVO => 'Operativo', EstadoVehiculo::EN_MANTENIMIENTO => 'En mantenimiento',
    EstadoVehiculo::INOPERATIVO => 'Inoperativo', EstadoVehiculo::DE_BAJA => 'De baja',
];
$qs = http_build_query(array_filter($filtros, static fn($v) => $v !== null && $v !== ''));
?>
<section class="module">
    <div class="module__head">
        <div>
            <h1>Inventario vehicular</h1>
            <p class="module__subtitle">Visualiza la composición de la flota e inventario por categoría, estado y estación con filtros de solo lectura.</p>
        </div>
        <a class="btn btn--primary" href="/inventario/export.csv<?= $qs ? '?' . e($qs) : '' ?>">⬇ Exportar CSV</a>
    </div>

    <div class="inv-cards">
        <div class="card inv-card">
            <h2>Por categoría</h2>
            <ul class="inv-list">
                <?php foreach ($conteos['por_categoria'] as $c): ?>
                    <li><span><?= e($c['nombre']) ?></span><strong><?= (int) $c['n'] ?></strong></li>
                <?php endforeach; ?>
                <li class="inv-list__total"><span>Total</span><strong><?= (int) $conteos['total'] ?></strong></li>
            </ul>
        </div>
        <div class="card inv-card">
            <h2>Por estado del vehículo</h2>
            <ul class="inv-list">
                <?php foreach ($conteos['por_estado'] as $c): ?>
                    <li><span><?= e($labelEstado[$c['nombre']] ?? $c['nombre']) ?></span><strong><?= (int) $c['n'] ?></strong></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <form class="module__toolbar" method="get" action="/inventario">
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
        <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
        <?php if ($qs): ?><a href="/inventario" class="link">Limpiar</a><?php endif; ?>
    </form>

    <div class="card card--table">
        <?php if (empty($unidades)): ?>
            <p class="muted" style="text-align:center">Sin unidades para estos filtros.</p>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Placa</th><th>Categoría</th><th>Marca / Modelo</th><th>Estación</th><th>Clasificación</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach ($unidades as $u): ?>
                <tr>
                    <td><strong><?= e($u['placa_unidad']) ?></strong><?php if (!empty($u['placa_furgon'])): ?> <small class="muted"><?= e($u['placa_furgon']) ?></small><?php endif; ?></td>
                    <td><?= e($u['categoria']) ?></td>
                    <td><?= e(trim(($u['marca'] ?? '') . ' ' . ($u['modelo'] ?? ''))) ?: '—' ?></td>
                    <td><?= e($u['estacion_codigo']) ?></td>
                    <td><?= (int) $u['en_disponibilidad'] === 1 ? '<span class="badge badge--ok">Operativa</span>' : '<span class="badge badge--muted">Inventario</span>' ?></td>
                    <td><?= e($labelEstado[$u['estado_vehiculo']] ?? $u['estado_vehiculo']) ?>
                        <?php if (!empty($u['estado_notas'])): ?><small class="muted block"><?= e($u['estado_notas']) ?></small><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</section>
