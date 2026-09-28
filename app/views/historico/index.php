<?php
/**
 * Histórico: historial de viajes.
 *
 * Un movimiento es la unidad de trabajo de la operación, así que la vista principal cuenta
 * viajes: qué se prometió, qué pasó de verdad y quién intervino. El registro crudo de toda
 * escritura del sistema vive aparte, en /historico.
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
    'Movimientos',
    'Todo lo reservado, en curso y terminado: flota propia y contratada, con lo que se programó y lo que pasó.',
    ['acciones' => '<a class="btn btn--ghost-dark" href="/historico">Bitácora del sistema</a>']
);
?>
<section class="module">
    <?php
    // Atajos: lo que se consulta a diario, a un clic y sin abrir el panel. Los filtros finos
    // siguen abajo para lo demás. Cada atajo conserva el resto de filtros puestos.
    $enlace = static function (array $cambios) use ($filtros, $r): string {
        $q = array_filter(
            array_merge($filtros, ['por_pagina' => $r['por_pagina']], $cambios),
            static fn($v): bool => $v !== null && $v !== '' && $v !== false
        );
        return '/movimientos' . ($q ? '?' . http_build_query($q) : '');
    };
    $atajos = [
        ['Todos', 'Sin filtrar por estado: reservados, en camino, terminados y cancelados.',
            ['estado' => null, 'solo_demora' => null],
            ($filtros['estado'] ?? '') === '' && empty($filtros['solo_demora'])],
        ['Sin terminar', 'Todo lo que aún puede cambiar: reservados, programados y en tránsito. '
            . 'Es lo que hay que vigilar; incluye a los reservados, no es lo mismo que ellos solos.',
            ['estado' => HistoricoService::PENDIENTES, 'solo_demora' => null],
            ($filtros['estado'] ?? '') === HistoricoService::PENDIENTES],
        ['Reservados', 'Solo los apartados que todavía no salen ni se han confirmado.',
            ['estado' => EstadoMovimiento::RESERVADO, 'solo_demora' => null],
            ($filtros['estado'] ?? '') === EstadoMovimiento::RESERVADO],
        ['En tránsito', 'Los que ya salieron y siguen en camino.',
            ['estado' => EstadoMovimiento::EN_TRANSITO, 'solo_demora' => null],
            ($filtros['estado'] ?? '') === EstadoMovimiento::EN_TRANSITO],
        ['Terminados', 'Los que ya llegaron. Se conservan para consultar lo que pasó.',
            ['estado' => EstadoMovimiento::COMPLETADO, 'solo_demora' => null],
            ($filtros['estado'] ?? '') === EstadoMovimiento::COMPLETADO],
        ['Con demora', 'Terminaron después de su fin estimado. Se combina con el atajo que tengas puesto.',
            ['solo_demora' => 1], !empty($filtros['solo_demora'])],
    ];
    ?>
    <!-- Una sola barra, como el tablero: los atajos a la izquierda, el recuento a la derecha y
         los filtros finos detrás de «Más filtros». Antes eran dos bloques apilados que decían
         lo mismo dos veces. -->
    <form class="filters-panel filters-panel--split card" method="get" action="/movimientos" data-filters-panel data-initial-open="false">
        <div class="filters-panel__always">
            <div class="filters-panel__always-row">
                <div class="filters-panel__always-main">
                    <div class="atajos" role="group" aria-label="Vistas rápidas">
                        <?php foreach ($atajos as [$texto, $ayuda, $cambios, $activo]): ?>
                            <a class="chipbtn<?= $activo ? ' is-active' : '' ?>" href="<?= e($enlace($cambios)) ?>"
                               data-infotip="<?= e($ayuda) ?>"><?= e($texto) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="dashboard__status">
                    <strong><?= (int) $r['total'] ?> viaje<?= $r['total'] === 1 ? '' : 's' ?></strong>
                    <?php if ($r['paginas'] > 1): ?>
                        <span class="muted">página <?= (int) $r['pagina'] ?> de <?= (int) $r['paginas'] ?></span>
                    <?php endif; ?>
                    <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="hist-filters">
                        <span data-filters-toggle-label data-open-label="Más filtros" data-close-label="Ocultar filtros">Más filtros</span>
                        <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
                    </button>
                </div>
            </div>
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
                <label class="field"><span class="field__label">Flota</span>
                    <select name="flota" data-no-search>
                        <option value="">Toda</option>
                        <option value="propia" <?= $sel($filtros['flota'] ?? '', 'propia') ?>>Propia</option>
                        <option value="proveedor" <?= $sel($filtros['flota'] ?? '', 'proveedor') ?>>De proveedor</option>
                    </select></label>
                <label class="field"><span class="field__label">Buscar</span>
                    <input type="search" name="q" value="<?= e((string) $filtros['q']) ?>" placeholder="Placa, piloto, cliente o #68…" class="search" data-no-search></label>
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
                <a href="/movimientos" class="link">Limpiar</a>
            </div>
        </div>
    </form>


    <div class="card card--table">
        <?php if (empty($r['filas'])): ?>
            <div class="card__empty"><p>Sin movimientos para estos filtros. <a href="/movimientos" class="link">Limpiar filtros</a></p></div>
        <?php else: ?>
        <table class="table tabla-movimientos">
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
                        <?php $esPropia = $m['placa_unidad'] !== null; ?>
                        <strong><?= e($esPropia ? $m['placa_unidad'] : ($m['placa_tercero'] ?: '—')) ?></strong>
                        <?php if (!$esPropia): ?>
                            <span class="badge badge--warn" title="Unidad contratada a un proveedor">Proveedor</span>
                        <?php endif; ?>
                        <small class="muted block"><?= e($m['estacion_codigo'] ?? '—') ?> · Mov. #<?= (int) $m['id'] ?><?php
                            if (!$esPropia && $m['proveedor']) { echo ' · ' . e($m['proveedor']); } ?></small>
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
                                data-detalle-title="<?= e(($m['placa_unidad'] ?? $m['placa_tercero'] ?? '—') . ' · ' . $m['ruta'] . ' · Mov. #' . (int) $m['id']) ?>">
                            <span class="detalle-btn__more">Ver rastro<?= $eventos ? ' (' . count($eventos) . ')' : '' ?></span>
                        </button>
                        <template id="<?= e($tid) ?>"><?= $rastroHtml($eventos) ?></template>
                        <?php
                        // Ver sin poder hacer no resuelve nada: las acciones del movimiento viven
                        // aquí, con las mismas reglas de estado que el tablero. La edición completa
                        // sigue allá porque necesita el formulario entero de la reserva.
                        $acciones = [];
                        if (!empty($puedeGestionar) && !in_array($m['estado'], ['COMPLETADO', 'CANCELADO'], true)) {
                            if ($m['estado'] === EstadoMovimiento::RESERVADO) {
                                $acciones[] = ['label' => 'Confirmar', 'attrs' => ['data-mov' => 'confirmar', 'data-id' => (int) $m['id']]];
                            }
                            if (in_array($m['estado'], [EstadoMovimiento::RESERVADO, EstadoMovimiento::PROGRAMADO], true)) {
                                $acciones[] = ['label' => 'Marcar salida', 'attrs' => ['data-mov' => 'salida', 'data-id' => (int) $m['id']]];
                            }
                            if ($m['estado'] === EstadoMovimiento::EN_TRANSITO) {
                                $acciones[] = ['label' => 'Marcar llegada', 'attrs' => ['data-mov' => 'llegada', 'data-id' => (int) $m['id']]];
                            }
                            $acciones[] = ['label' => 'Cambiar fecha de fin', 'attrs' => [
                                'data-mov' => 'reprogramar', 'data-id' => (int) $m['id'],
                                'data-fin' => format_local($m['fecha_fin_estimada'], $m['timezone'], 'Y-m-d\TH:i')]];
                            $acciones[] = ['label' => 'Cancelar', 'danger' => true, 'attrs' => [
                                'data-mov' => 'cancelar', 'data-id' => (int) $m['id']]];
                        }
                        ?>
                        <?= $acciones === [] ? '' : row_menu($acciones) ?>
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
            <a href="/movimientos?<?= e($pq) ?>" class="pager__link<?= $p === $r['pagina'] ? ' is-active' : '' ?>"><?= $p ?></a>
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

<?php if (!empty($puedeGestionar)): ?>
<dialog id="dlg-fin" class="dialog">
    <form method="dialog" class="form" id="form-fin" novalidate>
        <div class="dialog__head">
            <h2>Cambiar fecha de fin</h2>
            <p class="dialog__lede">Ajusta el fin estimado cuando el viaje se alarga: retención en aduana,
               espera de descarga, acuerdo con el cliente. Queda en bitácora con su motivo.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
            <label class="field"><span class="field__label">Nuevo fin estimado *</span>
                <input type="datetime-local" name="fecha_fin_estimada" required></label>
            <label class="field"><span class="field__label">Motivo del cambio *</span>
                <textarea name="motivo" rows="3" required placeholder="Retención en aduana"></textarea></label>
        </div>
        <p class="form__error" id="form-fin-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar cambio</button>
        </div>
    </form>
</dialog>

<dialog id="dlg-cancelar" class="dialog">
    <form method="dialog" class="form" id="form-cancelar" novalidate>
        <div class="dialog__head">
            <h2>Cancelar movimiento</h2>
            <p class="dialog__lede">La unidad queda libre en esas fechas. El movimiento no se borra: queda cancelado con su motivo.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
            <label class="field"><span class="field__label">Motivo *</span>
                <textarea name="motivo" rows="3" required></textarea></label>
        </div>
        <p class="form__error" id="form-cancelar-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cerrar</button>
            <button type="submit" class="btn btn--primary">Cancelar movimiento</button>
        </div>
    </form>
</dialog>
<script src="<?= e(asset('/assets/js/movimientos.js')) ?>" type="module"></script>
<?php endif; ?>

<script src="<?= e(asset('/assets/js/historico.js')) ?>" type="module"></script>
