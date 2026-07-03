<?php
/**
 * @var array $filtros @var array $reportes @var array $suscripciones @var array $estaciones
 * @var array $paises @var bool $alcanceTotal @var array|null $flash @var array $tiposSuscripcion
 */
$qs = http_build_query(array_filter($filtros, static fn($v) => $v !== null && $v !== ''));
$ret = $reportes['retornos'];
$clasesRetorno = [
    'APROVECHADO' => 'badge--ok',
    'VACIO' => 'badge--warn',
    'SIN_RETORNO' => 'badge--muted',
];
$sel = static fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
?>
<section class="module">
    <div class="module__head">
        <div>
            <h1>Inteligencia</h1>
            <p class="module__subtitle">Resume utilización, retornos, tránsito y rutas para apoyar decisiones operativas y alertas por correo.</p>
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
            <?php if (empty($reportes['utilizacion'])): ?>
                <div class="card__empty"><p>Sin datos para el rango seleccionado.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Estación</th><th>Unidades</th><th>Horas ocupadas</th><th>% utilización</th></tr></thead>
                    <tbody>
                    <?php foreach ($reportes['utilizacion'] as $fila): ?>
                        <tr>
                            <td><strong><?= e($fila['codigo']) ?></strong><small class="muted block"><?= e($fila['nombre']) ?></small></td>
                            <td><?= (int) $fila['unidades'] ?></td>
                            <td><?= e((string) $fila['horas_ocupadas']) ?></td>
                            <td><strong><?= e((string) $fila['utilizacion_pct']) ?>%</strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
            <?php endif; ?>
        </div>

        <div class="card int-card">
            <h2>Días en tránsito por unidad</h2>
            <?php if (empty($reportes['dias_transito'])): ?>
                <div class="card__empty"><p>Sin movimientos completados para este rango.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Unidad</th><th>Estación</th><th>Movs.</th><th>Días</th><th>Horas</th></tr></thead>
                    <tbody>
                    <?php foreach ($reportes['dias_transito'] as $fila): ?>
                        <tr>
                            <td><strong><?= e($fila['placa_unidad']) ?></strong><?php if (!empty($fila['placa_furgon'])): ?><small class="muted block"><?= e($fila['placa_furgon']) ?></small><?php endif; ?></td>
                            <td><?= e($fila['estacion_codigo']) ?></td>
                            <td><?= (int) $fila['movimientos'] ?></td>
                            <td><?= e((string) $fila['dias']) ?></td>
                            <td><?= e((string) $fila['horas']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card int-card">
            <h2>Rutas más usadas</h2>
            <?php if (empty($reportes['rutas'])): ?>
                <div class="card__empty"><p>Sin rutas completadas para este rango.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Ruta</th><th>Movimientos</th><th>Horas acumuladas</th></tr></thead>
                    <tbody>
                    <?php foreach ($reportes['rutas'] as $fila): ?>
                        <tr>
                            <td><?= e($fila['ruta']) ?></td>
                            <td><?= (int) $fila['movimientos'] ?></td>
                            <td><?= e((string) $fila['horas']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="int-grid int-grid--bottom">
        <div class="card int-card">
            <h2>Suscripciones de correo</h2>
            <p class="muted">Los correos automáticos usan la dirección de tu usuario: <strong><?= e($usuario['email'] ?? '') ?></strong>.</p>
            <form class="form" method="post" action="/inteligencia/suscripciones">
                <?= csrf_field() ?>
                <div class="grid-2">
                    <label class="field"><span class="field__label">Tipo</span>
                        <select name="tipo">
                            <?php foreach ($tiposSuscripcion as $tipo): ?>
                                <option value="<?= e($tipo) ?>"><?= e($tipo === 'UNIDAD_LIBERADA' ? 'Unidad liberada' : 'Retorno disponible') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field"><span class="field__label">Estación (para unidad liberada)</span>
                        <select name="estacion_id">
                            <option value="">No aplica</option>
                            <?php foreach ($estaciones as $est): ?>
                                <option value="<?= (int) $est['id'] ?>"><?= e($est['codigo']) ?> · <?= e($est['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field"><span class="field__label">País (para retorno disponible)</span>
                        <select name="pais_id">
                            <option value="">No aplica</option>
                            <?php foreach ($paises as $pais): ?>
                                <option value="<?= (int) $pais['id'] ?>"><?= e($pais['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="dialog__actions int-actions">
                    <button type="submit" class="btn btn--primary">Agregar suscripción</button>
                </div>
            </form>
        </div>

        <div class="card int-card">
            <h2>Suscripciones activas</h2>
            <?php if (empty($suscripciones)): ?>
                <div class="card__empty"><p>Todavía no tienes suscripciones activas.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Tipo</th><th>Objetivo</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($suscripciones as $s): ?>
                        <tr>
                            <td><?= e($s['tipo'] === 'UNIDAD_LIBERADA' ? 'Unidad liberada' : 'Retorno disponible') ?></td>
                            <td>
                                <?php if ($s['tipo'] === 'UNIDAD_LIBERADA'): ?>
                                    <?= e(($s['estacion_codigo'] ?? '') . ' · ' . ($s['estacion_nombre'] ?? '')) ?>
                                <?php else: ?>
                                    <?= e($s['pais_nombre'] ?? '') ?>
                                <?php endif; ?>
                            </td>
                            <td class="row-actions">
                                <form method="post" action="/inteligencia/suscripciones/<?= (int) $s['id'] ?>/probar" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="link">Probar correo</button>
                                </form>
                                <form method="post" action="/inteligencia/suscripciones/<?= (int) $s['id'] ?>/eliminar" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="link link--danger">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</section>