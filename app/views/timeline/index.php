<?php
/**
 * Timeline/Gantt por unidad (plan §7.5).
 *
 * @var array $dias
 * @var array $unidades
 * @var string $desde
 * @var int $diasTotal
 * @var bool $verTodas
 * @var array $estaciones
 * @var int|null $estacionSel
 */
$claseEstado = ['RESERVADO' => 'tl--reservada', 'PROGRAMADO' => 'tl--reservada', 'EN_TRANSITO' => 'tl--transito'];
$qs = http_build_query(array_filter([
    'desde' => $desde,
    'estacion_id' => $estacionSel,
], static fn($v) => $v !== null && $v !== ''));
?>
<section class="module">
    <div class="module__head">
        <div>
            <h1>Timeline de reservas</h1>
            <p class="module__subtitle">Observa por unidad las ventanas ocupadas de reservas y tránsito para anticipar disponibilidad y conflictos.</p>
        </div>
        <a class="btn btn--ghost-dark" href="/">← Dashboard</a>
    </div>

    <form class="filters-panel" method="get" action="/timeline" data-filters-panel data-initial-open="false">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Fecha base y alcance por estación</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="timeline-filters-more">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="timeline-filters-more" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Desde</span><input type="date" name="desde" value="<?= e($desde) ?>"></label>
                <?php if ($verTodas): ?>
                <label class="field"><span class="field__label">Estación</span>
                    <select name="estacion_id"><option value="">Todas</option>
                        <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= (string) $estacionSel === (string) $es['id'] ? 'selected' : '' ?>><?= e($es['codigo']) ?></option><?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/timeline" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <div class="card timeline-card">
        <?php if (empty($unidades)): ?>
            <div class="card__empty"><p>No hay unidades de flota operativa en el alcance.</p></div>
        <?php else: ?>
        <div class="timeline-card__wrap">
        <div class="tl" style="--tl-dias: <?= (int) $diasTotal ?>">
            <div class="tl__head">
                <div class="tl__unidad tl__corner">Unidad</div>
                <div class="tl__dias">
                    <?php foreach ($dias as $d): ?><div class="tl__dia"><strong><?= e($d['n']) ?></strong><small><?= e($d['m']) ?></small></div><?php endforeach; ?>
                </div>
            </div>
            <?php foreach ($unidades as $u): ?>
                <div class="tl__row">
                    <div class="tl__unidad"><?= e($u['placa_unidad']) ?></div>
                    <div class="tl__track">
                        <?php for ($i = 1; $i < $diasTotal; $i++): ?><span class="tl__grid" style="left: <?= round($i / $diasTotal * 100, 3) ?>%"></span><?php endfor; ?>
                        <?php foreach ($u['bloques'] as $b): ?>
                            <span class="tl__bloque <?= e($claseEstado[$b['estado']] ?? '') ?>" style="left: <?= $b['left'] ?>%; width: <?= $b['width'] ?>%" title="<?= e($b['title']) ?>"><?= e($b['label']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        </div>
        <p class="muted" style="margin-top: var(--sp-3)">Los bloques muestran las ventanas ocupadas. Las reservas se crean desde el <a href="/" class="link">Dashboard</a>; el backend rechaza cualquier traslape.</p>
        <?php endif; ?>
    </div>
</section>
