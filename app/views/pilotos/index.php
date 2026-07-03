<?php
/**
 * Pilotos (plan §7.3). Alerta visual: licencia vencida (rojo) o por vencer ≤30 días (ámbar).
 * @var array $usuario @var array $pilotos @var array $tiposLicencia @var array $estaciones
 */
$esAdmin = $usuario['rol'] === Rol::ADMIN_GLOBAL;
$hoy = new DateTimeImmutable('today');
?>
<section class="module">
    <div class="module__head">
        <div>
            <h1>Pilotos</h1>
            <p class="module__subtitle">Gestiona pilotos, licencias y estación asignada para mantener la operación lista para programar movimientos.</p>
        </div>
        <button type="button" class="btn btn--primary" data-action="nuevo-piloto">＋ Nuevo piloto</button>
    </div>

    <?php if (empty($pilotos)): ?>
        <div class="card empty"><div class="card__empty"><p>Aún no hay pilotos. <button type="button" class="link" data-action="nuevo-piloto">Crea el primero →</button></p></div></div>
    <?php else: ?>
        <div class="card card--table">
            <table class="table">
                <thead><tr><th>Nombre</th><th>Licencia</th><th>N.º</th><th>Vencimiento</th><th>Estación</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pilotos as $p): ?>
                    <tr>
                        <td><strong><?= e($p['nombre']) ?></strong></td>
                        <td><?= e($p['tipo_licencia']) ?></td>
                        <td><?= e($p['no_licencia']) ?></td>
                        <td>
                            <?php if (empty($p['licencia_vence'])): ?>
                                <span class="muted">—</span>
                            <?php
                            else:
                                $dias = (int) $hoy->diff(new DateTimeImmutable($p['licencia_vence']))->format('%r%a');
                                if ($dias < 0): ?>
                                    <span class="badge badge--alert" title="<?= e($p['licencia_vence']) ?>">Vencida</span>
                                <?php elseif ($dias <= 30): ?>
                                    <span class="badge badge--warn" title="<?= e($p['licencia_vence']) ?>">Vence en <?= $dias ?> día<?= $dias === 1 ? '' : 's' ?></span>
                                <?php else: ?>
                                    <?= e($p['licencia_vence']) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e($p['estacion_codigo']) ?></td>
                        <td class="row-actions">
                            <?= action_chip('Editar', ['attrs' => ['data-action' => 'editar-piloto', 'data-id' => (int) $p['id']]]) ?>
                            <?= action_chip('Eliminar', ['icon' => 'delete', 'variant' => 'danger', 'attrs' => ['data-action' => 'eliminar-piloto', 'data-id' => (int) $p['id'], 'data-nombre' => $p['nombre']]]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<dialog id="dlg-piloto" class="dialog">
    <form method="dialog" class="form" id="form-piloto" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-piloto-title">Nuevo piloto</h2>
            <p class="dialog__lede">Registra al piloto con su licencia y estación operativa para mantener la asignación disponible en los movimientos.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
        <div class="grid-2">
            <label class="field"><span class="field__label">Nombre *</span>
                <input type="text" name="nombre" maxlength="150" required></label>
            <label class="field"><span class="field__label">Tipo de licencia *</span>
                <select name="tipo_licencia_id" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($tiposLicencia as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">N.º de licencia *</span>
                <input type="text" name="no_licencia" maxlength="60" required></label>
            <label class="field"><span class="field__label">Vencimiento</span>
                <input type="date" name="licencia_vence"></label>
            <?php if ($esAdmin): ?>
            <label class="field"><span class="field__label">Estación *</span>
                <select name="estacion_id" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <?php else: ?>
                <input type="hidden" name="estacion_id" value="<?= (int) $usuario['estacion_id'] ?>">
            <?php endif; ?>
        </div>
        </div>
        <p class="form__error" id="form-piloto-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar piloto</button>
        </div>
    </form>
</dialog>

<script src="/assets/js/pilotos.js" type="module"></script>
