<?php
/**
 * Histórico de actividad (plan §7.7). @var array $resultado @var array $filtros
 * @var array $usuarios @var array $entidades @var array $acciones
 */
$qs = http_build_query(array_filter($filtros, static fn($v) => $v !== null && $v !== ''));
$r = $resultado;
$sel = static fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
?>
<section class="module">
    <div class="module__head">
        <div>
            <h1>Histórico de actividad</h1>
            <p class="module__subtitle">Consulta la bitácora del sistema por entidad, acción, usuario y fecha con exportación directa a CSV.</p>
        </div>
        <a class="btn btn--primary" href="/historico/export.csv<?= $qs ? '?' . e($qs) : '' ?>">⬇ Exportar CSV</a>
    </div>

    <form class="filters-panel" method="get" action="/historico" data-filters-panel data-initial-open="false">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Rango, entidad, acción, usuario e identificador</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="historico-filters-more">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="historico-filters-more" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Desde</span><input type="date" name="desde" value="<?= e($filtros['desde'] ?? '') ?>"></label>
                <label class="field"><span class="field__label">Hasta</span><input type="date" name="hasta" value="<?= e($filtros['hasta'] ?? '') ?>"></label>
                <label class="field"><span class="field__label">Entidad</span>
                    <select name="entidad"><option value="">Todas</option>
                        <?php foreach ($entidades as $ent): ?><option value="<?= e($ent) ?>" <?= $sel($filtros['entidad'] ?? '', $ent) ?>><?= e(ucfirst($ent)) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Acción</span>
                    <select name="accion"><option value="">Todas</option>
                        <?php foreach ($acciones as $ac): ?><option value="<?= e($ac) ?>" <?= $sel($filtros['accion'] ?? '', $ac) ?>><?= e($ac) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Usuario</span>
                    <select name="usuario_id"><option value="">Todos</option>
                        <?php foreach ($usuarios as $us): ?><option value="<?= (int) $us['id'] ?>" <?= $sel($filtros['usuario_id'] ?? '', $us['id']) ?>><?= e($us['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">ID de entidad</span><input type="number" name="entidad_id" value="<?= e($filtros['entidad_id'] ?? '') ?>" placeholder="ej. mov. #12" min="1"></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/historico" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <p class="dashboard__meta"><span><?= (int) $r['total'] ?> registro<?= $r['total'] === 1 ? '' : 's' ?></span> · <span class="muted">página <?= (int) $r['pagina'] ?> de <?= (int) $r['paginas'] ?></span></p>

    <div class="card card--table">
        <?php if (empty($r['filas'])): ?>
            <div class="card__empty"><p>Sin actividad para estos filtros.</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Fecha (UTC)</th><th>Usuario</th><th>Entidad</th><th>Acción</th><th>Detalle</th></tr></thead>
            <tbody>
            <?php foreach ($r['filas'] as $f): ?>
                <tr>
                    <td><?= e($f['timestamp']) ?></td>
                    <td><?= e($f['usuario'] ?? 'sistema') ?></td>
                    <td><?= e($f['entidad']) ?> #<?= (int) $f['entidad_id'] ?></td>
                    <td><span class="badge badge--muted"><?= e($f['accion']) ?></span></td>
                    <td><code class="detalle" title="<?= e((string) $f['detalle']) ?>"><?= e(mb_strimwidth((string) $f['detalle'], 0, 90, '…')) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($r['paginas'] > 1): ?>
    <nav class="pager">
        <?php for ($p = 1; $p <= $r['paginas']; $p++): $pq = http_build_query(array_merge($filtros, ['pagina' => $p])); ?>
            <a href="/historico?<?= e($pq) ?>" class="pager__link<?= $p === $r['pagina'] ? ' is-active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </nav>
    <?php endif; ?>
</section>
