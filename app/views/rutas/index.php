<?php
/**
 * Rutas (plan §7.4). Búsqueda por origen/destino/nombre; tipo auto-derivado (no editable).
 * @var array $usuario @var array $rutas @var string $q
 */
?>
<section class="module">
    <div class="module__head">
        <div>
            <h1>Rutas</h1>
            <p class="module__subtitle">Mantén el catálogo de trayectos con origen, destino y tiempos estimados reutilizables en reservas y programación.</p>
        </div>
        <button type="button" class="btn btn--primary" data-action="nueva-ruta">＋ Nueva ruta</button>
    </div>

    <form class="filters-panel" method="get" action="/rutas" data-filters-panel data-initial-open="false">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Búsqueda por nombre, ciudad o país</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="rutas-filters-more">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="rutas-filters-more" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Buscar rutas</span>
                    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre, ciudad o país…" class="search">
                </label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/rutas" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <?php if (empty($rutas)): ?>
        <div class="card empty">
            <div class="card__empty">
                <p><?= $q !== '' ? 'Sin resultados para «' . e($q) . '».' : 'Aún no hay rutas.' ?>
                   <button type="button" class="link" data-action="nueva-ruta">Crea la primera →</button></p>
            </div>
        </div>
    <?php else: ?>
        <div class="card card--table">
            <table class="table">
                <thead><tr><th>Nombre</th><th>Origen</th><th>Destino</th><th>Tipo</th><th>Km</th><th>Horas</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rutas as $r): ?>
                    <tr>
                        <td><strong><?= e($r['nombre']) ?></strong></td>
                        <td><?= e($r['ciudad_origen']) ?>, <?= e($r['pais_origen']) ?></td>
                        <td><?= e($r['ciudad_destino']) ?>, <?= e($r['pais_destino']) ?></td>
                        <td><span class="badge badge--muted"><?= $r['tipo_ruta'] === TipoRuta::NACIONAL ? 'Nacional' : 'Internacional' ?></span></td>
                        <td><?= $r['distancia_km'] !== null ? e(rtrim(rtrim($r['distancia_km'], '0'), '.')) : '—' ?></td>
                        <td><?= $r['horas_transito_estimadas'] !== null ? e(rtrim(rtrim($r['horas_transito_estimadas'], '0'), '.')) : '—' ?></td>
                        <td class="row-actions">
                            <?= action_chip('Editar', ['attrs' => ['data-action' => 'editar-ruta', 'data-id' => (int) $r['id']]]) ?>
                            <?= action_chip('Eliminar', ['icon' => 'delete', 'variant' => 'danger', 'attrs' => ['data-action' => 'eliminar-ruta', 'data-id' => (int) $r['id'], 'data-nombre' => $r['nombre']]]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<dialog id="dlg-ruta" class="dialog">
    <form method="dialog" class="form" id="form-ruta" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-ruta-title">Nueva ruta</h2>
            <p class="dialog__lede">Documenta el trayecto y sus tiempos estimados para que el sistema pueda reutilizarlo en reservas y programación.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
            <label class="field"><span class="field__label">Nombre *</span>
                <input type="text" name="nombre" maxlength="200" required placeholder="ej. SAL → GUA (frontera Pedro de Alvarado)"></label>
            <div class="grid-2">
                <label class="field"><span class="field__label">País de origen *</span>
                    <?= render_paises_select('pais_origen_id') ?></label>
                <label class="field"><span class="field__label">Ciudad de origen *</span>
                    <input type="text" name="ciudad_origen" maxlength="120" required></label>
                <label class="field"><span class="field__label">País de destino *</span>
                    <?= render_paises_select('pais_destino_id') ?></label>
                <label class="field"><span class="field__label">Ciudad de destino *</span>
                    <input type="text" name="ciudad_destino" maxlength="120" required></label>
                <label class="field"><span class="field__label">Distancia (km)</span>
                    <input type="number" name="distancia_km" min="0" step="0.01"></label>
                <label class="field"><span class="field__label">Horas de tránsito estimadas</span>
                    <input type="number" name="horas_transito_estimadas" min="0" step="0.5"></label>
            </div>
            <p class="dialog__lede">El tipo nacional o internacional se determina automáticamente según los países seleccionados.</p>
        </div>
        <p class="form__error" id="form-ruta-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar ruta</button>
        </div>
    </form>
</dialog>

<script src="/assets/js/rutas.js" type="module"></script>
