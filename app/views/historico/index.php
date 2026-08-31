<?php
/**
 * Histórico: historial de viajes.
 *
 * Un movimiento es la unidad de trabajo de la operación, así que la vista principal cuenta
 * viajes: qué se prometió, qué pasó de verdad y quién intervino. El registro crudo de toda
 * escritura del sistema vive un nivel adentro, en /historico/sistema.
 *
 * @var array $usuario
 * @var array $resultado
 * @var array $filtros
 * @var array $estaciones
 * @var bool  $verTodas
 */
$r = $resultado;
$qs = http_build_query(array_filter($filtros, static fn($v) => $v !== null && $v !== ''));
$hayFiltros = implode('', array_map(static fn($v) => (string) $v, $filtros)) !== '';
$sel = static fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';

$estadoLabel = [
    EstadoMovimiento::RESERVADO => 'Reservado', EstadoMovimiento::PROGRAMADO => 'Programado',
    EstadoMovimiento::EN_TRANSITO => 'En tránsito', EstadoMovimiento::COMPLETADO => 'Completado',
    EstadoMovimiento::CANCELADO => 'Cancelado',
];
$estadoClase = [
    EstadoMovimiento::COMPLETADO => 'badge--ok', EstadoMovimiento::CANCELADO => 'badge--muted',
    EstadoMovimiento::EN_TRANSITO => 'badge--warn',
];

/** Fecha corta en la zona de la estación: el histórico se lee en hora local, no en UTC. */
$fmt = static function (?string $utc, ?string $tz): string {
    if (empty($utc)) {
        return '';
    }
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone($tz ?: 'UTC'))->format('d/m/Y H:i');
};

/** Una demora se entiende en horas o días, no en 4 320 minutos. */
$fmtDemora = static function (int $minutos): string {
    if ($minutos < 60) {
        return $minutos . ' min';
    }
    $horas = $minutos / 60;
    return $horas < 48 ? round($horas, 1) . ' h' : round($horas / 24, 1) . ' d';
};

// ── Traducción del detalle de bitácora a lenguaje llano ──
$accLabel = [
    'CREAR' => 'Se creó la reserva', 'EDITAR' => 'Se editó', 'CAMBIO_ESTADO' => 'Cambió de estado',
    'CANCELAR' => 'Se canceló', 'ELIMINAR' => 'Se eliminó',
];
$campoLabel = [
    'estado' => 'Estado', 'fecha_salida' => 'Salida', 'fecha_fin_estimada' => 'Fin estimado',
    'fecha_fin_real' => 'Fin real', 'motivo' => 'Motivo', 'motivo_cancelacion' => 'Motivo de cancelación',
    'piloto_id' => 'Piloto', 'unidad_id' => 'Unidad', 'ruta_id' => 'Ruta',
    'retorno_disponible' => 'Retorno disponible', 'queda_con_cliente' => 'Queda con el cliente',
    'reservado_para' => 'Reservado para', 'notas' => 'Notas',
];
$valorLabel = static function (string $campo, $v) use ($estadoLabel): string {
    if ($v === null || $v === '') {
        return '—';
    }
    if ($campo === 'estado') {
        return $estadoLabel[$v] ?? (string) $v;
    }
    if (in_array($campo, ['retorno_disponible', 'queda_con_cliente'], true)) {
        return ((int) $v === 1) ? 'Sí' : 'No';
    }
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    return (string) $v;
};

/** Rastro de un viaje: qué pasó, cuándo y quién lo hizo. */
$rastroHtml = static function (array $eventos) use ($accLabel, $campoLabel, $valorLabel, $fmt, $r): string {
    if ($eventos === []) {
        return '<p class="muted">Sin eventos registrados para este viaje.</p>';
    }
    $h = '<ol class="timeline">';
    foreach ($eventos as $ev) {
        $d = json_decode((string) $ev['detalle'], true);
        $antes = is_array($d['antes'] ?? null) ? $d['antes'] : [];
        $despues = is_array($d['despues'] ?? null) ? $d['despues'] : [];

        $h .= '<li class="timeline__item"><div class="timeline__head">';
        $h .= '<span class="badge badge--muted">' . e($accLabel[$ev['accion']] ?? $ev['accion']) . '</span>';
        $h .= '<span class="timeline__meta">' . e($ev['timestamp']) . ' UTC · ' . e($ev['usuario'] ?? 'sistema') . '</span>';
        $h .= '</div>';

        $lineas = [];
        foreach (array_keys($antes + $despues) as $k) {
            $va = array_key_exists($k, $antes) ? $valorLabel($k, $antes[$k]) : null;
            $vd = array_key_exists($k, $despues) ? $valorLabel($k, $despues[$k]) : null;
            if ($va !== null && $vd !== null && $va === $vd) {
                continue;   // el snapshot guarda el registro entero; esto no cambió
            }
            $etiqueta = e($campoLabel[$k] ?? ucfirst(str_replace('_', ' ', (string) $k)));
            $lineas[] = $va !== null && $vd !== null
                ? "<div class=\"detalle-dl__row\"><dt>{$etiqueta}</dt><dd><span class=\"detalle-was\">"
                  . e($va) . '</span> <span class="detalle-arrow">→</span> <strong>' . e($vd) . '</strong></dd></div>'
                : "<div class=\"detalle-dl__row\"><dt>{$etiqueta}</dt><dd><strong>" . e((string) ($vd ?? $va)) . '</strong></dd></div>';
        }
        $h .= $lineas ? '<dl class="detalle-dl">' . implode('', $lineas) . '</dl>' : '';
        $h .= '</li>';
    }
    return $h . '</ol>';
};

set_page_meta(
    'Historial de viajes',
    'Qué se programó, qué pasó de verdad y quién intervino en cada movimiento.',
    ['acciones' => '<a class="btn btn--ghost-dark" href="/historico/sistema">Registro del sistema</a>']
);
?>
<section class="module">
    <form class="filters-panel" method="get" action="/historico" data-filters-panel data-initial-open="<?= $hayFiltros ? 'true' : 'false' ?>">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Fechas, estación, estado, tipo de ruta y búsqueda por placa, piloto o cliente</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="hist-filters">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="hist-filters" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Salida desde</span>
                    <input type="date" name="desde" value="<?= e((string) $filtros['desde']) ?>"></label>
                <label class="field"><span class="field__label">Salida hasta</span>
                    <input type="date" name="hasta" value="<?= e((string) $filtros['hasta']) ?>"></label>
                <?php if ($verTodas): ?>
                <label class="field"><span class="field__label">Estación</span>
                    <select name="estacion_id">
                        <option value="">Todas</option>
                        <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= $sel($filtros['estacion_id'], $es['id']) ?>><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
                <label class="field"><span class="field__label">Estado</span>
                    <select name="estado" data-no-search>
                        <option value="">Todos</option>
                        <?php foreach ($estadoLabel as $val => $lbl): ?><option value="<?= e($val) ?>" <?= $sel($filtros['estado'], $val) ?>><?= e($lbl) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Tipo de ruta</span>
                    <select name="tipo_ruta" data-no-search>
                        <option value="">Todas</option>
                        <option value="<?= TipoRuta::NACIONAL ?>" <?= $sel($filtros['tipo_ruta'], TipoRuta::NACIONAL) ?>>Nacional</option>
                        <option value="<?= TipoRuta::INTERNACIONAL ?>" <?= $sel($filtros['tipo_ruta'], TipoRuta::INTERNACIONAL) ?>>Internacional</option>
                    </select></label>
                <label class="field"><span class="field__label">Buscar</span>
                    <input type="search" name="q" value="<?= e((string) $filtros['q']) ?>" placeholder="Placa, piloto o cliente…" class="search" data-no-search></label>
                <label class="field field--delay-filter"><span class="field__label">Demora</span>
                    <label class="delay-toggle"><input type="checkbox" name="solo_demora" value="1" <?= !empty($filtros['solo_demora']) ? 'checked' : '' ?>><span>Solo con demora</span></label>
                </label>
                <label class="field"><span class="field__label">Por página</span>
                    <select name="por_pagina" data-no-search>
                        <?php foreach (HistoricoService::POR_PAGINA_OPCIONES as $op): ?><option value="<?= $op ?>" <?= $sel($r['por_pagina'], $op) ?>><?= $op ?></option><?php endforeach; ?>
                    </select></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/historico" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <p class="dashboard__meta">
        <span><?= (int) $r['total'] ?> viaje<?= $r['total'] === 1 ? '' : 's' ?></span>
        · <span class="muted">página <?= (int) $r['pagina'] ?> de <?= (int) $r['paginas'] ?></span>
    </p>

    <div class="card card--table">
        <?php if (empty($r['filas'])): ?>
            <div class="card__empty"><p>Sin viajes para estos filtros. <a href="/historico" class="link">Limpiar filtros</a></p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr>
                <th class="col col--nombre">Unidad</th>
                <th class="col col--corta">Ruta</th>
                <th class="col col--corta">Piloto</th>
                <th class="col col--corta">Salida</th>
                <th class="col col--corta">Fin estimado</th>
                <th class="col col--corta">Fin real</th>
                <th class="col col--corta">Demora</th>
                <th class="col col--text">Cliente</th>
                <th class="col col--corta">Estado</th>
                <th class="col--acciones"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($r['filas'] as $m): $tid = 'viaje-' . (int) $m['id']; $eventos = $r['eventos'][(int) $m['id']] ?? []; ?>
                <tr>
                    <td class="col col--nombre">
                        <strong><?= e($m['placa_unidad']) ?></strong>
                        <small class="muted block"><?= e($m['estacion_codigo']) ?> · Mov. #<?= (int) $m['id'] ?></small>
                    </td>
                    <td class="col col--corta">
                        <?= e($m['ruta']) ?>
                        <?php if ($m['tipo_ruta'] === TipoRuta::INTERNACIONAL): ?>
                            <span class="alcance alcance--int" title="Ruta internacional">INT</span>
                        <?php endif; ?>
                    </td>
                    <td class="col col--corta"><?= $m['piloto'] ? e($m['piloto']) : '<span class="muted">—</span>' ?></td>
                    <td class="col col--corta"><?= e($fmt($m['fecha_salida'], $m['timezone'])) ?></td>
                    <td class="col col--corta"><?= e($fmt($m['fecha_fin_estimada'], $m['timezone'])) ?></td>
                    <td class="col col--corta"><?= $m['fecha_fin_real'] ? e($fmt($m['fecha_fin_real'], $m['timezone'])) : '<span class="muted">—</span>' ?></td>
                    <td class="col col--corta">
                        <?php if ($m['con_demora']): ?>
                            <span class="rend-alerta">+<?= e($fmtDemora((int) $m['demora_min'])) ?></span>
                        <?php elseif ($m['fecha_fin_real']): ?>
                            <span class="rend-ok">a tiempo</span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col col--text"><?= $m['reservado_para'] ? e($m['reservado_para']) : '<span class="muted">—</span>' ?></td>
                    <td class="col col--corta">
                        <span class="badge <?= e($estadoClase[$m['estado']] ?? 'badge--muted') ?>"><?= e($estadoLabel[$m['estado']] ?? $m['estado']) ?></span>
                    </td>
                    <td class="col--acciones">
                        <button type="button" class="detalle-btn" data-detalle-open="<?= e($tid) ?>"
                                data-detalle-title="<?= e($m['placa_unidad'] . ' · ' . $m['ruta'] . ' · Mov. #' . (int) $m['id']) ?>">
                            <span class="detalle-btn__more">Ver rastro<?= $eventos ? ' (' . count($eventos) . ')' : '' ?></span>
                        </button>
                        <template id="<?= e($tid) ?>"><?= $rastroHtml($eventos) ?></template>
                    </td>
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

<dialog id="dlg-detalle" class="dialog dialog--full">
    <div class="dialog__panel">
        <div class="dialog__head">
            <h2 id="detalle-title">Rastro del viaje</h2>
            <p class="dialog__lede">Todo lo que se registró de este movimiento, en orden cronológico.</p>
        </div>
        <div class="dialog__body" id="detalle-body"></div>
        <div class="dialog__actions">
            <button type="button" class="btn btn--primary" data-detalle-close>Cerrar</button>
        </div>
    </div>
</dialog>

<script src="/assets/js/historico.js" type="module"></script>
