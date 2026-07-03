<?php
/**
 * Flota — gestión de unidades (plan §7.2). Tabla server-rendered (todo escapado con e())
 * + diálogos para alta/edición y para el cambio de estado (poka-yoke). El JS del módulo
 * consume la API /api/unidades.
 *
 * @var array $usuario @var array $unidades @var array $categorias @var array $tiposEquipo
 * @var array $permisos @var array $estaciones @var array $pilotos @var array $estados
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
?>
<section class="module">
    <div class="module__head">
        <h1>Flota</h1>
        <button type="button" class="btn btn--primary" data-action="nueva-unidad">＋ Nueva unidad</button>
    </div>

    <?php if (empty($unidades)): ?>
        <div class="card empty">
            <p>Aún no hay unidades registradas. <button type="button" class="link" data-action="nueva-unidad">Crea la primera →</button></p>
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
                        <td><?= e($u['tipo_equipo'] ?? '—') ?><?php if (!empty($u['capacidad'])): ?> · <?= e($u['capacidad']) ?><?php endif; ?></td>
                        <td><?= e($u['estacion_codigo']) ?></td>
                        <td>
                            <?php if ((int) $u['en_disponibilidad'] === 1): ?>
                                <span class="badge badge--ok">Flota operativa</span>
                            <?php else: ?>
                                <span class="badge badge--muted">Solo inventario</span>
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
                            <button type="button" class="link" data-action="editar" data-id="<?= (int) $u['id'] ?>">Editar</button>
                            <button type="button" class="link" data-action="estado" data-id="<?= (int) $u['id'] ?>" data-estado="<?= e($u['estado_vehiculo']) ?>">Cambiar estado</button>
                            <button type="button" class="link link--danger" data-action="eliminar" data-id="<?= (int) $u['id'] ?>" data-placa="<?= e($u['placa_unidad']) ?>">Eliminar</button>
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
                <label class="check"><input type="checkbox" name="en_disponibilidad" value="1"> Participa en el dashboard (flota operativa)</label></label>
            <label class="field"><span class="field__label">Marca</span>
                <input type="text" name="marca" maxlength="80"></label>
            <label class="field"><span class="field__label">Modelo</span>
                <input type="text" name="modelo" maxlength="80"></label>
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
                    <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <?php else: ?>
                <input type="hidden" name="estacion_id" value="<?= (int) $usuario['estacion_id'] ?>">
            <?php endif; ?>
            <label class="field"><span class="field__label">Piloto asignado</span>
                <select name="piloto_asignado_id">
                    <option value="">—</option>
                    <?php foreach ($pilotos as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?>
                </select></label>
        </div>
        <fieldset class="field">
            <legend class="field__label">Permisos especiales</legend>
            <div class="checks">
                <?php foreach ($permisos as $pe): ?>
                    <label class="check"><input type="checkbox" name="permisos[]" value="<?= (int) $pe['id'] ?>"> <?= e($pe['nombre']) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
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
