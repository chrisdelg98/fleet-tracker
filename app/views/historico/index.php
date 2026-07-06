<?php
/**
 * Histórico de actividad (plan §7.7).
 *
 * @var array $resultado
 * @var array $filtros
 * @var array $usuarios
 * @var array $entidades
 * @var array $acciones
 */
$qs = http_build_query(array_filter($filtros, static fn($v) => $v !== null && $v !== ''));
$r = $resultado;
$sel = static fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';

// ── Traducción del JSON crudo de la bitácora a algo legible (etiquetas + enums en español) ──
$estadoMov = [
    'RESERVADO' => 'Reservado', 'PROGRAMADO' => 'Programado', 'EN_TRANSITO' => 'En tránsito',
    'COMPLETADO' => 'Completado', 'CANCELADO' => 'Cancelado',
];
$estadoVeh = [
    'OPERATIVO' => 'Operativo', 'EN_MANTENIMIENTO' => 'En mantenimiento',
    'INOPERATIVO' => 'Inoperativo', 'DE_BAJA' => 'De baja',
];
$labelCampo = [
    'estado' => 'Estado', 'estado_vehiculo' => 'Estado del vehículo', 'estado_notas' => 'Notas',
    'unidad_id' => 'Unidad', 'piloto_id' => 'Piloto', 'ruta_id' => 'Ruta', 'estacion_id' => 'Estación',
    'motivo' => 'Motivo', 'motivo_cancelacion' => 'Motivo de cancelación',
    'origen' => 'Origen', 'destino' => 'Destino', 'tipo' => 'Tipo', 'activo' => 'Activo',
    'fecha_salida' => 'Salida', 'fecha_fin_estimada' => 'Fin estimado', 'fecha_fin_real' => 'Fin real',
    'pais_solicita_retorno_id' => 'País solicita retorno', 'movimiento_regreso' => 'Mov. de regreso',
    'retorno_de' => 'Retorno de', 'bloqueos_cerrados' => 'Bloqueos cerrados',
    'codigo' => 'Código', 'nombre' => 'Nombre', 'pais' => 'País', 'pais_id' => 'País',
    'timezone' => 'Zona horaria', 'capacidad' => 'Capacidad', 'tipo_equipo_id' => 'Tipo de equipo',
    'email' => 'Correo', 'rol' => 'Rol', 'placa_unidad' => 'Placa', 'placa_furgon' => 'Placa furgón',
    'unidad_id_regreso' => 'Unidad de regreso',
];
$fmtVal = static function (string $key, $val) use ($estadoMov, $estadoVeh) {
    if ($val === null) {
        return '—';
    }
    if (is_bool($val)) {
        return $val ? 'Sí' : 'No';
    }
    if (is_array($val)) {
        return json_encode($val, JSON_UNESCAPED_UNICODE);
    }
    if ($key === 'estado') {
        return $estadoMov[$val] ?? (string) $val;
    }
    if ($key === 'estado_vehiculo') {
        return $estadoVeh[$val] ?? (string) $val;
    }
    if ($key === 'activo') {
        return ((int) $val === 1) ? 'Sí' : 'No';
    }
    if (str_ends_with($key, '_id') && is_numeric($val)) {
        return '#' . $val;
    }
    return (string) $val;
};
/** Convierte el detalle JSON en filas legibles [label, antes, despues, cambio]. */
$detalleFilas = static function (?string $json) use ($labelCampo, $fmtVal): array {
    $data = json_decode((string) $json, true);
    if (!is_array($data)) {
        return [];
    }
    $antes = (isset($data['antes']) && is_array($data['antes'])) ? $data['antes'] : [];
    $despues = (isset($data['despues']) && is_array($data['despues'])) ? $data['despues'] : [];
    if ($antes === [] && $despues === [] && $data !== []) {
        $despues = $data; // detalle plano sin antes/después
    }
    $filas = [];
    foreach (array_keys($antes + $despues) as $k) {
        $filas[] = [
            'label'   => $labelCampo[$k] ?? ucfirst(str_replace('_', ' ', (string) $k)),
            'antes'   => array_key_exists($k, $antes) ? $fmtVal($k, $antes[$k]) : null,
            'despues' => array_key_exists($k, $despues) ? $fmtVal($k, $despues[$k]) : null,
            'cambio'  => array_key_exists($k, $antes) && array_key_exists($k, $despues),
        ];
    }
    return $filas;
};
/** Resumen de una línea para la celda de la tabla. */
$detalleResumen = static function (array $filas, string $accion): string {
    foreach ($filas as $f) {
        if ($f['label'] === 'Estado' && $f['antes'] !== null && $f['despues'] !== null) {
            return $f['antes'] . ' → ' . $f['despues'];
        }
    }
    foreach ($filas as $f) {
        if ($f['label'] === 'Estado' && $f['despues'] !== null) {
            return (string) $f['despues'];
        }
    }
    foreach ($filas as $f) {
        if (in_array($f['label'], ['Motivo de cancelación', 'Motivo'], true) && $f['despues'] !== null) {
            return (string) $f['despues'];
        }
    }
    $acc = ['CREAR' => 'Creación', 'EDITAR' => 'Edición', 'CAMBIO_ESTADO' => 'Cambio de estado', 'CANCELAR' => 'Cancelación', 'ELIMINAR' => 'Eliminación'];
    $n = count($filas);
    return ($acc[$accion] ?? $accion) . ' · ' . $n . ' campo' . ($n === 1 ? '' : 's');
};
/** HTML del cuerpo del modal (lista antes → después). */
$detalleHtml = static function (array $filas): string {
    $h = '<dl class="detalle-dl">';
    foreach ($filas as $f) {
        $h .= '<div class="detalle-dl__row"><dt>' . e($f['label']) . '</dt><dd>';
        if ($f['cambio']) {
            $h .= '<span class="detalle-was">' . e((string) $f['antes']) . '</span> <span class="detalle-arrow">→</span> <strong>' . e((string) $f['despues']) . '</strong>';
        } elseif ($f['despues'] !== null) {
            $h .= '<strong>' . e((string) $f['despues']) . '</strong>';
        } else {
            $h .= '<span class="detalle-was">' . e((string) $f['antes']) . '</span>';
        }
        $h .= '</dd></div>';
    }
    return $h . '</dl>';
};
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
                    <td>
                        <?php $filas = $detalleFilas($f['detalle']); ?>
                        <?php if ($filas): ?>
                            <button type="button" class="detalle-btn" data-detalle-open="det-<?= (int) $f['id'] ?>">
                                <span class="detalle-btn__text"><?= e($detalleResumen($filas, $f['accion'])) ?></span>
                                <span class="detalle-btn__more">Ver detalle</span>
                            </button>
                            <template id="det-<?= (int) $f['id'] ?>"><?= $detalleHtml($filas) ?></template>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
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

<dialog id="dlg-detalle" class="dialog">
    <div class="dialog__head">
        <h2>Detalle del registro</h2>
        <p class="dialog__lede">Cambios registrados en la bitácora (antes → después).</p>
    </div>
    <div class="dialog__body" id="detalle-body"></div>
    <div class="dialog__actions">
        <button type="button" class="btn btn--primary" data-detalle-close>Cerrar</button>
    </div>
</dialog>

<script src="/assets/js/historico.js" type="module"></script>
