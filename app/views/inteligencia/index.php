<?php
/**
 * @var array $usuario
 * @var array $filtros
 * @var array $reportes
 * @var array $estaciones
 * @var bool $alcanceTotal
 * @var array|null $flash
 */
$qs = http_build_query(array_filter($filtros, static fn($v) => $v !== null && $v !== ''));
$ret = $reportes['retornos'];
$clasesRetorno = [
    'APROVECHADO' => 'badge--ok',
    'VACIO' => 'badge--warn',
    'SIN_RETORNO' => 'badge--muted',
];
$sel = static fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
$PREVIEW = 5;
$util = $reportes['utilizacion'];
$dias = $reportes['dias_transito'];
$rutas = $reportes['rutas'];
$fmtDias = static fn($v) => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
?>
<section class="module">
    <div class="module__head">
        <div>
            <h1>Inteligencia</h1>
            <p class="module__subtitle">Resume utilización, retornos, tránsito y rutas para apoyar decisiones operativas.</p>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="alert <?= ($flash['type'] ?? '') === 'ok' ? 'alert--ok' : 'alert--error' ?>"><?= e($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <form class="filters-panel" method="get" action="/inteligencia" data-filters-panel data-initial-open="false">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Rango, estación y acciones de consulta</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="inteligencia-filters-more">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="inteligencia-filters-more" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Desde</span><input type="date" name="desde" value="<?= e($filtros['desde']) ?>"></label>
                <label class="field"><span class="field__label">Hasta</span><input type="date" name="hasta" value="<?= e($filtros['hasta']) ?>"></label>
                <?php if ($alcanceTotal): ?>
                    <label class="field"><span class="field__label">Estación</span>
                        <select name="estacion_id">
                            <option value="">Todas</option>
                            <?php foreach ($estaciones as $est): ?>
                                <option value="<?= (int) $est['id'] ?>" <?= $sel($filtros['estacion_id'] ?? '', $est['id']) ?>><?= e($est['codigo']) ?> · <?= e($est['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/inteligencia" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <div class="int-grid">
        <div class="card int-card">
            <h2>Utilización por estación</h2>
            <?php if (empty($util)): ?>
                <div class="card__empty"><p>Sin datos para el rango seleccionado.</p></div>
            <?php else: ?>
                <ul class="int-list">
                    <?php foreach (array_slice($util, 0, $PREVIEW) as $fila): $pct = max(0, min(100, (float) $fila['utilizacion_pct'])); ?>
                        <li class="int-list__row">
                            <div class="int-list__main">
                                <strong><?= e($fila['codigo']) ?></strong>
                                <small class="block"><?= e($fila['nombre']) ?></small>
                                <div class="int-bar"><span style="width: <?= e((string) $pct) ?>%"></span></div>
                            </div>
                            <div class="int-list__val"><?= e((string) $fila['utilizacion_pct']) ?>%<small><?= (int) $fila['unidades'] ?> u · <?= e((string) $fila['horas_ocupadas']) ?> h</small></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="int-card__foot">
                    <button type="button" class="int-more" data-modal-open="dlg-util">Ver detalle completo<?= count($util) > $PREVIEW ? ' (' . count($util) . ')' : '' ?> →</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="card int-card">
            <h2>Retornos</h2>
            <div class="int-stats">
                <div class="int-stat"><span>Aprovechados</span><strong><?= (int) $ret['conteos']['APROVECHADO'] ?></strong></div>
                <div class="int-stat"><span>Vacíos</span><strong><?= (int) $ret['conteos']['VACIO'] ?></strong></div>
                <div class="int-stat"><span>Sin retorno</span><strong><?= (int) $ret['conteos']['SIN_RETORNO'] ?></strong></div>
            </div>
            <?php if (empty($ret['detalle'])): ?>
                <div class="card__empty card__empty--compact"><p>Sin movimientos internacionales completados en el rango.</p></div>
            <?php else: ?>
                <p class="int-hint"><?= count($ret['detalle']) ?> movimiento<?= count($ret['detalle']) === 1 ? '' : 's' ?> internacional<?= count($ret['detalle']) === 1 ? '' : 'es' ?> con retorno evaluado.</p>
                <div class="int-card__foot">
                    <button type="button" class="int-more" data-modal-open="dlg-retornos">Ver movimientos (<?= count($ret['detalle']) ?>) →</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="card int-card">
            <h2>Días en tránsito por unidad</h2>
            <?php if (empty($dias)): ?>
                <div class="card__empty"><p>Sin movimientos completados para este rango.</p></div>
            <?php else: ?>
                <ul class="int-list">
                    <?php foreach (array_slice($dias, 0, $PREVIEW) as $fila): ?>
                        <li class="int-list__row">
                            <div class="int-list__main">
                                <strong><?= e($fila['placa_unidad']) ?></strong>
                                <small class="block"><?= e($fila['estacion_codigo']) ?> · <?= (int) $fila['movimientos'] ?> mov</small>
                            </div>
                            <div class="int-list__val"><?= e($fmtDias($fila['dias'])) ?> d<small><?= e((string) $fila['horas']) ?> h</small></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="int-card__foot">
                    <button type="button" class="int-more" data-modal-open="dlg-dias">Ver todas las unidades<?= count($dias) > $PREVIEW ? ' (' . count($dias) . ')' : '' ?> →</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="card int-card">
            <h2>Rutas más usadas</h2>
            <?php if (empty($rutas)): ?>
                <div class="card__empty"><p>Sin rutas completadas para este rango.</p></div>
            <?php else: ?>
                <ul class="int-list">
                    <?php foreach (array_slice($rutas, 0, $PREVIEW) as $fila): ?>
                        <li class="int-list__row">
                            <div class="int-list__main"><strong><?= e($fila['ruta']) ?></strong></div>
                            <div class="int-list__val"><?= (int) $fila['movimientos'] ?> mov<small><?= e((string) $fila['horas']) ?> h</small></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="int-card__foot">
                    <button type="button" class="int-more" data-modal-open="dlg-rutas">Ver todas las rutas<?= count($rutas) > $PREVIEW ? ' (' . count($rutas) . ')' : '' ?> →</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php /* Sección de suscripciones de correo retirada temporalmente (ver docs/fases-implementacion.md §Fase 4).
             El backend (rutas, NotificacionService, SuscripcionCorreoModel, tabla y disparadores) queda
             intacto; para reactivarla basta con restaurar este bloque de UI. */ ?>
</section>

<?php if (!empty($util)): ?>
<dialog class="dialog dialog--full" id="dlg-util">
    <div class="dialog__panel">
        <div class="dialog__head"><h2>Utilización por estación</h2><p class="dialog__lede">Horas ocupadas y porcentaje de utilización por estación en el rango seleccionado.</p></div>
        <div class="dialog__body">
            <table class="table">
                <thead><tr><th>Estación</th><th>Unidades</th><th>Horas ocupadas</th><th>% utilización</th></tr></thead>
                <tbody>
                <?php foreach ($util as $fila): ?>
                    <tr>
                        <td><strong><?= e($fila['codigo']) ?></strong><small class="muted block"><?= e($fila['nombre']) ?></small></td>
                        <td><?= (int) $fila['unidades'] ?></td>
                        <td><?= e((string) $fila['horas_ocupadas']) ?></td>
                        <td><strong><?= e((string) $fila['utilizacion_pct']) ?>%</strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="dialog__actions"><button type="button" class="btn btn--primary" data-modal-close>Cerrar</button></div>
    </div>
</dialog>
<?php endif; ?>

<?php if (!empty($ret['detalle'])): ?>
<dialog class="dialog dialog--full" id="dlg-retornos">
    <div class="dialog__panel">
        <div class="dialog__head"><h2>Retornos por movimiento</h2><p class="dialog__lede">Clasificación del retorno de cada movimiento internacional completado.</p></div>
        <div class="dialog__body">
            <table class="table">
                <thead><tr><th>Movimiento</th><th>Unidad</th><th>Ruta</th><th>Clasificación</th></tr></thead>
                <tbody>
                <?php foreach ($ret['detalle'] as $fila): ?>
                    <tr>
                        <td>#<?= (int) $fila['id'] ?><small class="muted block"><?= e($fila['estacion_codigo']) ?></small></td>
                        <td><?= e($fila['placa_unidad']) ?></td>
                        <td><?= e($fila['ruta']) ?></td>
                        <td><span class="badge <?= e($clasesRetorno[$fila['clasificacion']] ?? 'badge--muted') ?>"><?= e($fila['clasificacion']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="dialog__actions"><button type="button" class="btn btn--primary" data-modal-close>Cerrar</button></div>
    </div>
</dialog>
<?php endif; ?>

<?php if (!empty($dias)): ?>
<dialog class="dialog dialog--full" id="dlg-dias">
    <div class="dialog__panel">
        <div class="dialog__head"><h2>Días en tránsito por unidad</h2><p class="dialog__lede">Tiempo acumulado en tránsito de cada unidad en el rango seleccionado.</p></div>
        <div class="dialog__body">
            <table class="table">
                <thead><tr><th>Unidad</th><th>Estación</th><th>Movs.</th><th>Días</th><th>Horas</th></tr></thead>
                <tbody>
                <?php foreach ($dias as $fila): ?>
                    <tr>
                        <td><strong><?= e($fila['placa_unidad']) ?></strong><?php if (!empty($fila['placa_furgon'])): ?><small class="muted block"><?= e($fila['placa_furgon']) ?></small><?php endif; ?></td>
                        <td><?= e($fila['estacion_codigo']) ?></td>
                        <td><?= (int) $fila['movimientos'] ?></td>
                        <td><?= e($fmtDias($fila['dias'])) ?></td>
                        <td><?= e((string) $fila['horas']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="dialog__actions"><button type="button" class="btn btn--primary" data-modal-close>Cerrar</button></div>
    </div>
</dialog>
<?php endif; ?>

<?php if (!empty($rutas)): ?>
<dialog class="dialog dialog--full" id="dlg-rutas">
    <div class="dialog__panel">
        <div class="dialog__head"><h2>Rutas más usadas</h2><p class="dialog__lede">Movimientos y horas acumuladas por ruta en el rango seleccionado.</p></div>
        <div class="dialog__body">
            <table class="table">
                <thead><tr><th>Ruta</th><th>Movimientos</th><th>Horas acumuladas</th></tr></thead>
                <tbody>
                <?php foreach ($rutas as $fila): ?>
                    <tr>
                        <td><?= e($fila['ruta']) ?></td>
                        <td><?= (int) $fila['movimientos'] ?></td>
                        <td><?= e((string) $fila['horas']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="dialog__actions"><button type="button" class="btn btn--primary" data-modal-close>Cerrar</button></div>
    </div>
</dialog>
<?php endif; ?>

<script src="/assets/js/modal.js" type="module"></script>