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
 * @var array $filtros
 * @var array $categorias
 */
$claseEstado = ['RESERVADO' => 'tl--reservada', 'PROGRAMADO' => 'tl--reservada', 'EN_TRANSITO' => 'tl--transito'];
$etiquetaEstado = ['RESERVADO' => 'Reservado', 'PROGRAMADO' => 'Programado', 'EN_TRANSITO' => 'En tránsito'];
$chipEstado = ['RESERVADO' => 'chip--reservada', 'PROGRAMADO' => 'chip--reservada', 'EN_TRANSITO' => 'chip--transito'];

/** Con el cliente manda sobre el estado del movimiento: describe mejor dónde está la unidad. */
$claseBloque = static fn(array $b): string => !empty($b['con_cliente'])
    ? 'tl--cliente'
    : ($claseEstado[$b['estado']] ?? '');
$textoEstado = static fn(array $b): string => !empty($b['con_cliente'])
    ? 'Con cliente'
    : ($etiquetaEstado[$b['estado']] ?? $b['estado']);
$chipBloque = static fn(array $b): string => !empty($b['con_cliente'])
    ? 'chip--cliente'
    : ($chipEstado[$b['estado']] ?? '');
$qs = http_build_query(array_filter([
    'desde' => $desde,
    'estacion_id' => $estacionSel,
] + $filtros, static fn($v) => $v !== null && $v !== '' && $v !== false));

/** Enlace que cambia solo la ventana, conservando el resto de filtros. */
$enlaceRango = static function (int $dias) use ($desde, $estacionSel, $filtros): string {
    $q = array_filter(
        ['desde' => $desde, 'estacion_id' => $estacionSel, 'dias' => $dias] + $filtros,
        static fn($v) => $v !== null && $v !== '' && $v !== false
    );
    return '/timeline?' . http_build_query($q);
};
set_page_meta(
    'Timeline de reservas',
    'Observa por unidad las ventanas ocupadas de reservas y tránsito para anticipar disponibilidad y conflictos.',
    ['padre' => ['label' => 'Dashboard', 'href' => '/']]
);
?>
<section class="module">
    <form class="filters-panel" method="get" action="/timeline" data-filters-panel data-initial-open="false">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Ventana, estación, categoría, estado y placa</span>
            </div>
            <!-- La ventana se cambia sin abrir el panel: es lo que más se toca. -->
            <div class="tl-rangos" role="group" aria-label="Días visibles">
                <?php foreach (TimelineController::RANGOS as $d => $etiqueta): ?>
                    <a class="chipbtn<?= $diasTotal === $d ? ' is-active' : '' ?>" href="<?= e($enlaceRango($d)) ?>"><?= e($etiqueta) ?></a>
                <?php endforeach; ?>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="timeline-filters-more">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="timeline-filters-more" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Desde</span><input type="date" name="desde" value="<?= e($desde) ?>"></label>
                <label class="field"><span class="field__label">Ventana</span>
                    <select name="dias" data-no-search>
                        <?php foreach (TimelineController::RANGOS as $d => $etiqueta): ?><option value="<?= $d ?>" <?= $diasTotal === $d ? 'selected' : '' ?>><?= e($etiqueta) ?> (<?= $d ?> días)</option><?php endforeach; ?>
                    </select></label>
                <?php if ($verTodas): ?>
                <label class="field"><span class="field__label">Estación</span>
                    <select name="estacion_id"><option value="">Todas</option>
                        <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= (string) $estacionSel === (string) $es['id'] ? 'selected' : '' ?>><?= e($es['codigo']) ?></option><?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
                <label class="field"><span class="field__label">Categoría</span>
                    <select name="categoria_id"><option value="">Todas</option>
                        <?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (string) ($filtros['categoria_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Estado</span>
                    <select name="estado" data-no-search>
                        <option value="">Todos</option>
                        <?php foreach ($etiquetaEstado as $val => $lbl): ?><option value="<?= e($val) ?>" <?= ($filtros['estado'] ?? '') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Placa</span>
                    <input type="search" name="placa" value="<?= e((string) ($filtros['placa'] ?? '')) ?>" placeholder="Buscar placa…" data-no-search></label>
                <label class="field field--delay-filter"><span class="field__label">Filas</span>
                    <label class="delay-toggle"><input type="checkbox" name="solo_ocupadas" value="1" <?= !empty($filtros['solo_ocupadas']) ? 'checked' : '' ?>><span>Solo unidades con movimientos</span></label>
                </label>
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
                    <?php foreach ($dias as $d): ?><div class="tl__dia<?= $d['finde'] ? ' tl__dia--finde' : '' ?>"><strong><?= e($d['n']) ?></strong><small><?= e($d['m']) ?></small></div><?php endforeach; ?>
                </div>
            </div>
            <?php foreach ($unidades as $u): ?>
                <div class="tl__row">
                    <div class="tl__unidad"><?= e($u['placa_unidad']) ?></div>
                    <div class="tl__track">
                        <?php for ($i = 1; $i < $diasTotal; $i++): ?><span class="tl__grid" style="left: <?= round($i / $diasTotal * 100, 3) ?>%"></span><?php endfor; ?>
                        <?php foreach ($u['bloques'] as $b): ?>
                            <span class="tl__bloque <?= e($claseBloque($b)) ?>" style="left: <?= $b['left'] ?>%; width: <?= $b['width'] ?>%"
                                  tabindex="0" role="button" title="<?= e($b['title']) ?>"
                                  data-pop
                                  data-mov="<?= (int) $b['id'] ?>"
                                  data-estado="<?= e($textoEstado($b)) ?>"
                                  data-estado-clase="<?= e($chipBloque($b)) ?>"
                                  data-unidad="<?= e($u['placa_unidad']) ?>"
                                  data-ruta="<?= e($b['ruta']) ?>"
                                  data-salida="<?= e($b['salida']) ?>"
                                  data-fin="<?= e($b['fin']) ?>"><span class="tl__bloque-txt"><?= e($b['label']) ?></span></span>
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

<script src="/assets/js/timeline.js" type="module"></script>
