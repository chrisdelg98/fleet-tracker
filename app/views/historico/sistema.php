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
$estadoVeh = EstadoVehiculo::labels();
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
    'email' => 'Correo', 'rol' => 'Rol', 'placa_unidad' => 'Placa',
    'unidad_id_regreso' => 'Unidad de regreso',
    'marca' => 'Marca', 'modelo' => 'Modelo', 'anio' => 'Año',
    'categoria_vehiculo_id' => 'Categoría', 'capacidad_id' => 'Capacidad',
    'en_disponibilidad' => 'En disponibilidad', 'queda_con_cliente' => 'Queda con el cliente',
    'piloto_asignado_id' => 'Piloto asignado',
    'no_licencia' => 'N.º de licencia', 'licencia_vence' => 'Vence la licencia',
    'tipo_licencia_id' => 'Tipo de licencia', 'documento_identidad' => 'Documento',
    'telefonos' => 'Teléfonos', 'codigo_nacional' => 'Código nacional',
    'codigo_internacional' => 'Código internacional',
    'descripcion' => 'Descripción', 'habilita_internacional' => 'Cruza frontera',
    'es_motriz' => 'Se mueve sola', 'es_flota_operativa' => 'Flota operativa',
    'orden' => 'Orden', 'cerrado' => 'Cerrado', 'retorno_disponible' => 'Retorno disponible',
];
/** Campos que en base son 0/1 pero que se leen como Sí/No. */
$esBooleano = static fn(string $k): bool => in_array($k, [
    'activo', 'cerrado', 'en_disponibilidad', 'queda_con_cliente', 'retorno_disponible',
], true) || str_starts_with($k, 'habilita_') || str_starts_with($k, 'es_') || str_starts_with($k, 'requiere_');
$fmtVal = static function (string $key, $val) use ($estadoMov, $estadoVeh, $esBooleano) {
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
    if ($esBooleano($key)) {
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
        $enAntes = array_key_exists($k, $antes);
        $enDespues = array_key_exists($k, $despues);
        $filas[] = [
            'label'   => $labelCampo[$k] ?? ucfirst(str_replace('_', ' ', (string) $k)),
            'antes'   => $enAntes ? $fmtVal($k, $antes[$k]) : null,
            'despues' => $enDespues ? $fmtVal($k, $despues[$k]) : null,
            'cambio'  => $enAntes && $enDespues,
            // Estar en el antes y en el después NO es haber cambiado: el snapshot guarda el
            // registro entero, así que una edición de un campo traía once "X → X".
            'distinto' => $enAntes && $enDespues
                ? $fmtVal($k, $antes[$k]) !== $fmtVal($k, $despues[$k])
                : true,
        ];
    }
    return $filas;
};
/** HTML del bloque de cambios de un evento (lista antes → después). */
$detalleHtml = static function (array $filas): string {
    $cambiados = array_values(array_filter($filas, static fn(array $f): bool => $f['distinto']));
    if ($cambiados === []) {
        return '<p class="muted">Se guardó sin modificar ningún dato.</p>';
    }
    $h = '<dl class="detalle-dl">';
    foreach ($cambiados as $f) {
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
/**
 * Resumen de un evento en una línea. Antes había que abrir el modal para saber si una
 * "Edición" cambió el nombre o la estación; el trabajo de la tabla es contarlo de una vez.
 */
$resumenEvento = static function (?string $json) use ($detalleFilas): string {
    $filas = $detalleFilas($json);
    if ($filas === []) {
        return '';
    }
    $cambiados = array_values(array_filter($filas, static fn(array $f): bool => $f['distinto']));
    if ($cambiados === []) {
        return '<span class="muted">Se guardó sin modificar ningún dato.</span>';
    }
    // Los cambios (antes → después) son la noticia; un alta se resume con sus datos clave.
    $conAntes = array_values(array_filter($cambiados, static fn(array $f): bool => $f['cambio']));
    $muestra = $conAntes !== [] ? $conAntes : $cambiados;

    $partes = [];
    foreach (array_slice($muestra, 0, 2) as $f) {
        $partes[] = $f['cambio']
            ? e($f['label']) . ': <span class="detalle-was">' . e((string) $f['antes'])
              . '</span> → <strong>' . e((string) $f['despues']) . '</strong>'
            : e($f['label']) . ': <strong>' . e((string) ($f['despues'] ?? $f['antes'])) . '</strong>';
    }
    $restantes = count($muestra) - 2;
    if ($restantes > 0) {
        $partes[] = '<span class="muted">y ' . $restantes . ' campo' . ($restantes === 1 ? '' : 's') . ' más</span>';
    }
    return implode(' · ', $partes);
};

/** Línea de tiempo con todos los eventos de una entidad (para el modal). */
$accLabel = ['CREAR' => 'Creación', 'EDITAR' => 'Edición', 'CAMBIO_ESTADO' => 'Cambio de estado', 'CANCELAR' => 'Cancelación', 'ELIMINAR' => 'Eliminación'];
/** "permisos_especiales" no es un nombre para enseñar; los catálogos ya tienen el suyo. */
$tipoLabel = static function (string $entidad): string {
    $propios = [
        'unidad' => 'Unidad', 'piloto' => 'Piloto', 'movimiento' => 'Movimiento',
        'usuario' => 'Usuario', 'estacion' => 'Estación', 'ruta' => 'Ruta', 'override' => 'Bloqueo',
    ];
    if (isset($propios[$entidad])) {
        return $propios[$entidad];
    }
    return in_array($entidad, CatalogoAdminService::tablas(), true)
        ? CatalogoAdminService::spec($entidad)['label']
        : ucfirst(str_replace('_', ' ', $entidad));
};
$timelineHtml = static function (array $eventos) use ($detalleFilas, $detalleHtml, $accLabel): string {
    $h = '<ol class="timeline">';
    foreach ($eventos as $ev) {
        $filas = $detalleFilas($ev['detalle']);
        $h .= '<li class="timeline__item">';
        $h .= '<div class="timeline__head">';
        $h .= '<span class="badge badge--muted">' . e($accLabel[$ev['accion']] ?? $ev['accion']) . '</span>';
        $h .= '<span class="timeline__meta">' . e($ev['timestamp']) . ' · ' . e($ev['usuario'] ?? 'sistema') . '</span>';
        $h .= '</div>';
        if ($filas) {
            $h .= $detalleHtml($filas);
        }
        $h .= '</li>';
    }
    return $h . '</ol>';
};
set_page_meta(
    'Registro del sistema',
    'Toda escritura registrada: catálogos, unidades, pilotos, usuarios y estaciones, con su autor y el antes/después.',
    [
        'padre' => ['label' => 'Histórico', 'href' => '/historico'],
        'accion' => '<a class="btn btn--primary" href="/historico/export.csv' . ($qs ? '?' . e($qs) : '') . '">⬇ Exportar CSV</a>',
    ]
);
?>
<section class="module">
    <form class="filters-panel" method="get" action="/historico/sistema" data-filters-panel data-initial-open="false">
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
                <label class="field"><span class="field__label">Por página</span>
                    <select name="por_pagina" onchange="this.form.submit()">
                        <?php foreach (HistoricoService::POR_PAGINA_OPCIONES as $op): ?><option value="<?= $op ?>" <?= $sel($r['por_pagina'], $op) ?>><?= $op ?></option><?php endforeach; ?>
                    </select></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/historico/sistema" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <p class="dashboard__meta"><span><?= (int) $r['total'] ?> entidad<?= $r['total'] === 1 ? '' : 'es' ?> con actividad</span> · <span class="muted">página <?= (int) $r['pagina'] ?> de <?= (int) $r['paginas'] ?></span></p>

    <div class="card card--table">
        <?php if (empty($r['filas'])): ?>
            <div class="card__empty"><p>Sin actividad para estos filtros.</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr>
                <th class="col col--nombre">Registro</th>
                <th class="col col--corta">Última actividad (UTC)</th>
                <th class="col col--text">Qué cambió</th>
                <th class="col col--corta">Usuario</th>
                <th class="col col--corta">Eventos</th>
                <th class="col--acciones"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($r['filas'] as $g):
                $key = $g['entidad'] . '#' . $g['entidad_id'];
                $tid = 'hist-' . $g['entidad'] . '-' . (int) $g['entidad_id'];
                $eventos = $r['eventos'][$key] ?? [];
                $ultimo = $eventos ? end($eventos) : null;
                // Si el registro ya no existe queda el número: el histórico habla del pasado.
                $etiqueta = $r['etiquetas'][$key] ?? ('#' . (int) $g['entidad_id']);
                $titulo = $tipoLabel($g['entidad']) . ' · ' . $etiqueta;
            ?>
                <tr>
                    <td class="col col--nombre">
                        <strong><?= e($etiqueta) ?></strong>
                        <small class="muted block"><?= e($tipoLabel($g['entidad'])) ?> #<?= (int) $g['entidad_id'] ?></small>
                    </td>
                    <td class="col col--corta">
                        <?= e($g['ultima']) ?>
                        <small class="block"><span class="badge badge--muted"><?= e($accLabel[$g['ultima_accion']] ?? $g['ultima_accion']) ?></span></small>
                    </td>
                    <td class="col col--text"><?= $ultimo ? $resumenEvento($ultimo['detalle']) : '<span class="muted">—</span>' ?></td>
                    <td class="col col--corta"><?= e($g['ultimo_usuario'] ?? 'sistema') ?></td>
                    <td class="col col--corta"><?= (int) $g['eventos'] ?></td>
                    <td class="col--acciones">
                        <button type="button" class="detalle-btn" data-detalle-open="<?= e($tid) ?>" data-detalle-title="<?= e($titulo) ?>">
                            <span class="detalle-btn__more">Ver historial</span>
                        </button>
                        <template id="<?= e($tid) ?>"><?= $timelineHtml($eventos) ?></template>
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
            <a href="/historico/sistema?<?= e($pq) ?>" class="pager__link<?= $p === $r['pagina'] ? ' is-active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </nav>
    <?php endif; ?>
</section>

<dialog id="dlg-detalle" class="dialog dialog--full">
    <div class="dialog__panel">
        <div class="dialog__head">
            <h2 id="detalle-title">Historial</h2>
            <p class="dialog__lede">Todos los eventos registrados para esta entidad, en orden cronológico (antes → después).</p>
        </div>
        <div class="dialog__body" id="detalle-body"></div>
        <div class="dialog__actions">
            <button type="button" class="btn btn--primary" data-detalle-close>Cerrar</button>
        </div>
    </div>
</dialog>

<script src="/assets/js/historico.js" type="module"></script>
