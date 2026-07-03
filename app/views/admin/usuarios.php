<?php
/**
 * Administración › Usuarios (plan §5.2, §4). @var array $usuarios @var array $estaciones
 * @var array $roles @var array $rolesSinEstacion
 */
$labelRol = [
    Rol::ADMIN_GLOBAL => 'Admin Global', Rol::ENCARGADO => 'Encargado',
    Rol::CONSULTA_BASICO => 'Consulta Básico', Rol::CONSULTA_INVENTARIO => 'Consulta Inventario',
    Rol::CONSULTA_REGIONAL => 'Consulta Regional',
];
?>
<section class="module">
    <div class="module__head">
        <div><a href="/admin" class="link">← Administración</a><h1>Usuarios</h1></div>
        <button type="button" class="btn btn--primary" data-action="nuevo-usuario">＋ Nuevo usuario</button>
    </div>

    <div class="card card--table">
        <table class="table">
            <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estación</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr class="<?= (int) $u['activo'] === 0 ? 'is-inactive' : '' ?>">
                    <td><strong><?= e($u['nombre']) ?></strong></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e($labelRol[$u['rol']] ?? $u['rol']) ?></td>
                    <td><?= e($u['estacion_codigo'] ?? '—') ?></td>
                    <td><?= (int) $u['activo'] === 1 ? '<span class="badge badge--ok">Activo</span>' : '<span class="badge badge--muted">Inactivo</span>' ?></td>
                    <td class="row-actions">
                        <button type="button" class="link" data-action="editar-usuario" data-id="<?= (int) $u['id'] ?>">Editar</button>
                        <button type="button" class="link" data-action="activo-usuario" data-id="<?= (int) $u['id'] ?>" data-activo="<?= (int) $u['activo'] ?>"><?= (int) $u['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<dialog id="dlg-usuario" class="dialog" data-roles-sin-estacion='<?= e(json_encode($rolesSinEstacion)) ?>'>
    <form method="dialog" class="form" id="form-usuario" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-usuario-title">Nuevo usuario</h2>
            <p class="dialog__lede">Configura acceso, rol y alcance por estación. La autorización final siempre se valida en backend.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
        <div class="grid-2">
            <label class="field"><span class="field__label">Nombre *</span>
                <input type="text" name="nombre" maxlength="150" required></label>
            <label class="field"><span class="field__label">Correo *</span>
                <input type="email" name="email" maxlength="190" required></label>
            <label class="field"><span class="field__label">Rol *</span>
                <select name="rol" required>
                    <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"><?= e($labelRol[$r] ?? $r) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field" id="usuario-estacion-field"><span class="field__label">Estación *</span>
                <select name="estacion_id">
                    <option value="">Selecciona…</option>
                    <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">Contraseña <span id="pass-req">*</span></span>
                <input type="password" name="password" autocomplete="new-password">
                <small class="muted" id="pass-hint" hidden>Déjala en blanco para no cambiarla.</small></label>
        </div>
        </div>
        <p class="form__error" id="form-usuario-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar usuario</button>
        </div>
    </form>
</dialog>

<script src="/assets/js/admin-usuarios.js" type="module"></script>
