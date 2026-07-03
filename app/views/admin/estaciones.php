<?php
/**
 * Administración › Estaciones (plan §5.1).
 *
 * @var array $estaciones
 */
$zonasAmerica = array_values(array_filter(timezone_identifiers_list(), static fn(string $z): bool => str_starts_with($z, 'America/')));
?>
<section class="module">
    <div class="module__head">
        <div><a href="/admin" class="link">← Administración</a><h1>Estaciones</h1><p class="module__subtitle">Define las sedes operativas, su país y la zona horaria que gobierna las vistas locales.</p></div>
        <button type="button" class="btn btn--primary" data-action="nueva-estacion">＋ Nueva estación</button>
    </div>

    <div class="card card--table">
        <table class="table">
            <thead><tr><th>Código</th><th>Nombre</th><th>País</th><th>Zona horaria</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($estaciones as $e): ?>
                <tr class="<?= (int) $e['activo'] === 0 ? 'is-inactive' : '' ?>">
                    <td><strong><?= e($e['codigo']) ?></strong></td>
                    <td><?= e($e['nombre']) ?></td>
                    <td><?= e($e['pais']) ?></td>
                    <td><?= e($e['timezone']) ?></td>
                    <td><?= (int) $e['activo'] === 1 ? '<span class="badge badge--ok">Activa</span>' : '<span class="badge badge--muted">Inactiva</span>' ?></td>
                    <td class="row-actions">
                        <?= action_chip('Editar', ['attrs' => ['data-action' => 'editar-estacion', 'data-id' => (int) $e['id']]]) ?>
                        <?= action_chip((int) $e['activo'] === 1 ? 'Desactivar' : 'Activar', [
                            'icon' => (int) $e['activo'] === 1 ? 'toggle-off' : 'toggle-on',
                            'variant' => (int) $e['activo'] === 1 ? 'warning' : 'success',
                            'attrs' => ['data-action' => 'activo-estacion', 'data-id' => (int) $e['id'], 'data-activo' => (int) $e['activo']],
                        ]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<dialog id="dlg-estacion" class="dialog">
    <form method="dialog" class="form" id="form-estacion" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-estacion-title">Nueva estación</h2>
            <p class="dialog__lede">Registra la sede con país y zona horaria IANA para que todos los cálculos y vistas respeten su contexto operativo.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
        <div class="grid-2">
            <label class="field"><span class="field__label">Código *</span>
                <input type="text" name="codigo" maxlength="10" required placeholder="ej. GUA"></label>
            <label class="field"><span class="field__label">Nombre *</span>
                <input type="text" name="nombre" maxlength="150" required></label>
            <label class="field"><span class="field__label">País *</span>
                <?= render_paises_select('pais_id') ?></label>
            <label class="field"><span class="field__label">Zona horaria (IANA) *</span>
                <select name="timezone" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($zonasAmerica as $z): ?><option value="<?= e($z) ?>"><?= e($z) ?></option><?php endforeach; ?>
                </select></label>
        </div>
        </div>
        <p class="form__error" id="form-estacion-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar estación</button>
        </div>
    </form>
</dialog>

<script src="/assets/js/admin-estaciones.js" type="module"></script>
