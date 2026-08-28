<?php
/**
 * Flota — gestión de unidades (plan §7.2). Tabla server-rendered (todo escapado con e())
 * + diálogos para alta/edición y para el cambio de estado (poka-yoke). El JS del módulo
 * consume la API /api/unidades.
 *
 * @var array $usuario
 * @var array $unidades
 * @var array $filtros
 * @var bool $verTodas
 * @var array $categorias
 * @var array $tiposEquipo
 * @var array $capacidades
 * @var array $permisos
 * @var array $estaciones
 * @var array $pilotos
 * @var array $estados
 */
$esAdmin = $usuario['rol'] === Rol::ADMIN_GLOBAL;
$labelEstado = [
    EstadoVehiculo::OPERATIVO => 'Operativo', EstadoVehiculo::EN_MANTENIMIENTO => 'En mantenimiento',
    EstadoVehiculo::INOPERATIVO => 'Inoperativo', EstadoVehiculo::DE_BAJA => 'De baja',
];
$claseEstado = [
    EstadoVehiculo::OPERATIVO => 'ok', EstadoVehiculo::EN_MANTENIMIENTO => 'warn',
    EstadoVehiculo::INOPERATIVO => 'warn', EstadoVehiculo::DE_BAJA => 'muted',
];
set_page_meta(
    'Flota',
    'Administra unidades, estados operativos, clasificación y asignaciones base de la flota.',
    ['accion' => '<button type="button" class="btn btn--primary" data-action="nueva-unidad">＋ Nueva unidad</button>']
);
?>
<section class="module">
    <?php $hayFiltros = implode('', $filtros) !== ''; ?>
    <form class="filters-panel" method="get" action="/flota" data-filters-panel data-initial-open="<?= $hayFiltros ? 'true' : 'false' ?>">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Estación, categoría, tipo, estado, clasificación y placa</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="flota-filters-more">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="flota-filters-more" data-filters-more hidden>
            <div class="filters-grid">
                <?php if ($verTodas): ?>
                <label class="field"><span class="field__label">Estación</span>
                    <select name="estacion_id">
                        <option value="">Todas</option>
                        <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= (string) $filtros['estacion_id'] === (string) $es['id'] ? 'selected' : '' ?>><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
                <label class="field"><span class="field__label">Categoría</span>
                    <select name="categoria_id">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (string) $filtros['categoria_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Tipo de equipo</span>
                    <select name="tipo_equipo_id">
                        <option value="">Todos</option>
                        <?php foreach ($tiposEquipo as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (string) $filtros['tipo_equipo_id'] === (string) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Estado</span>
                    <select name="estado_vehiculo">
                        <option value="">Todos</option>
                        <?php foreach ($estados as $ev): ?><option value="<?= e($ev) ?>" <?= $filtros['estado_vehiculo'] === $ev ? 'selected' : '' ?>><?= e($labelEstado[$ev] ?? $ev) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Clasificación</span>
                    <select name="en_disponibilidad">
                        <option value="">Todas</option>
                        <option value="1" <?= (string) $filtros['en_disponibilidad'] === '1' ? 'selected' : '' ?>>Flota operativa</option>
                        <option value="0" <?= (string) $filtros['en_disponibilidad'] === '0' ? 'selected' : '' ?>>Solo inventario</option>
                    </select></label>
                <label class="field"><span class="field__label">Buscar placa</span>
                    <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Placa de unidad o furgón…" class="search"></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/flota" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <?php if (empty($unidades)): ?>
        <div class="card empty">
            <div class="card__empty">
                <?php if ($hayFiltros): ?>
                    <p>Sin unidades para estos filtros. <a href="/flota" class="link">Limpiar filtros</a></p>
                <?php else: ?>
                    <p>Aún no hay unidades registradas. <button type="button" class="link" data-action="nueva-unidad">Crea la primera →</button></p>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card card--table">
            <table class="table" id="tabla-unidades">
                <thead>
                    <tr>
                        <th>Placa</th><th>Categoría</th><th>Tipo / Capacidad</th><th>Estación</th>
                        <th>Disponibilidad</th><th>Estado</th><th>Piloto</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($unidades as $u): ?>
                    <tr data-id="<?= (int) $u['id'] ?>">
                        <td>
                            <strong><?= e($u['placa_unidad']) ?></strong>
                            <?php if (!empty($u['placa_furgon'])): ?><small class="muted"><?= e($u['placa_furgon']) ?></small><?php endif; ?>
                        </td>
                        <td><?= e($u['categoria']) ?></td>
                        <td><?= e($u['tipo_equipo'] ?? '—') ?><?php if (!empty($u['capacidad'])): ?> · <?= e($u['capacidad']) ?><?php endif; ?>
                            <?php if (!empty($u['anio'])): ?><small class="muted block"><?= (int) $u['anio'] ?></small><?php endif; ?></td>
                        <td><?= e($u['estacion_codigo']) ?></td>
                        <td>
                            <?php if ((int) $u['en_disponibilidad'] === 1): ?>
                                <span class="badge badge--ok">Flota operativa</span>
                            <?php else: ?>
                                <span class="badge badge--muted">Solo inventario</span>
                            <?php endif; ?>
                            <?php if (!empty($u['override_tipo'])): ?>
                                <small class="muted block" title="<?= e($u['override_motivo'] ?? '') ?>">
                                    <span class="badge badge--warn"><?= $u['override_tipo'] === 'BLOQUEADA' ? 'Bloqueada' : 'En taller' ?></span>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge--<?= e($claseEstado[$u['estado_vehiculo']] ?? 'muted') ?>">
                                <?= e($labelEstado[$u['estado_vehiculo']] ?? $u['estado_vehiculo']) ?>
                            </span>
                            <?php if (!empty($u['estado_notas'])): ?>
                                <small class="muted block" title="<?= e($u['estado_notas']) ?>"><?= e(mb_strimwidth($u['estado_notas'], 0, 40, '…')) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($u['piloto_asignado'] ?? '—') ?></td>
                        <td class="row-actions">
                            <?= row_menu([
                                ['label' => 'Editar', 'attrs' => ['data-action' => 'editar', 'data-id' => (int) $u['id']]],
                                ['label' => 'Cambiar estado', 'attrs' => ['data-action' => 'estado', 'data-id' => (int) $u['id'], 'data-estado' => $u['estado_vehiculo']]],
                                // Solo el bloqueo manual se levanta desde aquí; el de taller se cierra
                                // devolviendo la unidad a Operativo en "Cambiar estado".
                                !empty($u['tiene_bloqueo_manual'])
                                    ? ['label' => 'Desbloquear', 'attrs' => ['data-action' => 'desbloquear', 'data-id' => (int) $u['id'], 'data-placa' => $u['placa_unidad']]]
                                    : null,
                                ['label' => 'Eliminar', 'danger' => true, 'attrs' => ['data-action' => 'eliminar', 'data-id' => (int) $u['id'], 'data-placa' => $u['placa_unidad']]],
                            ]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Diálogo alta/edición -->
<dialog id="dlg-unidad" class="dialog">
    <form method="dialog" class="form" id="form-unidad" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-unidad-title">Nueva unidad</h2>
            <p class="dialog__lede">Define la unidad, su categoría operativa, estación base y permisos especiales en un solo formulario.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
        <div class="grid-2">
            <label class="field"><span class="field__label">Placa de unidad *</span>
                <input type="text" name="placa_unidad" maxlength="30" required></label>
            <label class="field"><span class="field__label">Placa de furgón <span data-furgon-req hidden>*</span></span>
                <input type="text" name="placa_furgon" maxlength="30"></label>
            <label class="field"><span class="field__label">Categoría *</span>
                <select name="categoria_vehiculo_id" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($categorias as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" data-flota="<?= (int) $c['es_flota_operativa'] ?>" data-requiere-furgon="<?= (int) $c['requiere_furgon'] ?>"><?= e($c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="field field--check"><span class="field__label">Disponibilidad</span>
                <label class="check check--box"><input type="checkbox" name="en_disponibilidad" value="1"><span>Participa en el dashboard (flota operativa)</span></label></label>
            <label class="field"><span class="field__label">Marca</span>
                <input type="text" name="marca" maxlength="80"></label>
            <label class="field"><span class="field__label">Modelo</span>
                <input type="text" name="modelo" maxlength="80"></label>
            <label class="field"><span class="field__label">Año</span>
                <input type="number" name="anio" min="1950" max="<?= (int) date('Y') + 1 ?>" step="1" placeholder="<?= (int) date('Y') ?>"></label>
            <label class="field"><span class="field__label">Tipo de equipo</span>
                <select name="tipo_equipo_id">
                    <option value="">—</option>
                    <?php foreach ($tiposEquipo as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">Capacidad</span>
                <select name="capacidad_id">
                    <option value="">—</option>
                    <?php foreach ($capacidades as $cap): ?><option value="<?= (int) $cap['id'] ?>"><?= e($cap['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <?php if ($esAdmin): ?>
            <label class="field"><span class="field__label">Estación *</span>
                <select name="estacion_id" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" data-pais="<?= (int) $es['pais_id'] ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <?php else: ?>
                <?php
                $paisUsuario = 0;
                foreach ($estaciones as $es) {
                    if ((int) $es['id'] === (int) $usuario['estacion_id']) { $paisUsuario = (int) $es['pais_id']; break; }
                }
                ?>
                <input type="hidden" name="estacion_id" value="<?= (int) $usuario['estacion_id'] ?>" data-pais="<?= $paisUsuario ?>">
            <?php endif; ?>
            <label class="field"><span class="field__label">Piloto asignado</span>
                <select name="piloto_asignado_id">
                    <option value="">—</option>
                    <?php foreach ($pilotos as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?>
                </select></label>
        </div>
        <details class="colapso" id="permisos-colapso">
            <summary class="colapso__head">
                <span class="colapso__title">Permisos especiales</span>
                <span class="colapso__meta" id="permisos-resumen">Ninguno</span>
                <span class="colapso__chevron" aria-hidden="true">▾</span>
            </summary>
            <div class="checks checks--grid">
                <?php foreach ($permisos as $pe): ?>
                    <label class="check" data-pais="<?= (int) ($pe['pais_id'] ?? 0) ?>"<?= !empty($pe['descripcion']) ? ' title="' . e($pe['descripcion']) . '"' : '' ?>>
                        <input type="checkbox" name="permisos[]" value="<?= (int) $pe['id'] ?>">
                        <span><?= e($pe['nombre']) ?></span>
                        <?php if (!empty($pe['habilita_internacional'])): ?>
                            <span class="alcance alcance--int" title="Marcar este permiso habilita a la unidad para rutas internacionales">INT</span>
                        <?php endif; ?>
                        <?php if (!empty($pe['descripcion'])): ?>
                            <button type="button" class="infotip" tabindex="-1" aria-label="Qué habilita este permiso"
                                    data-infotip="<?= e($pe['descripcion']) ?>">i</button>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </details>
        </div>
        <p class="form__error" id="form-unidad-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar unidad</button>
        </div>
    </form>
</dialog>

<!-- Diálogo cambio de estado (poka-yoke) -->
<dialog id="dlg-estado" class="dialog">
    <form method="dialog" class="form" id="form-estado" novalidate>
        <div class="dialog__head">
            <h2>Cambiar estado del vehículo</h2>
            <p class="dialog__lede">Toda unidad no operativa debe dejar el motivo documentado para proteger la disponibilidad calculada del sistema.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
            <label class="field"><span class="field__label">Nuevo estado *</span>
                <select name="estado_vehiculo" required>
                    <?php foreach ($estados as $e): ?><option value="<?= e($e) ?>"><?= e($labelEstado[$e] ?? $e) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field" id="estado-notas-field"><span class="field__label">Notas <span data-req>*</span></span>
                <textarea name="estado_notas" rows="3" placeholder="Motivo del mantenimiento, avería o baja"></textarea>
                <small>Obligatorio cuando el vehículo no está operativo.</small></label>
        </div>
        <p class="form__error" id="form-estado-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar estado</button>
        </div>
    </form>
</dialog>

<script src="/assets/js/flota.js" type="module"></script>
