<?php
/**
 * Mantenimientos › Talleres. Cada estación gestiona los suyos, sea el taller propio o uno
 * subcontratado: para registrar da igual, y la marca «propio» solo sirve para comparar después.
 *
 * @var array $usuario
 * @var array $talleres
 * @var array $filtros
 * @var array $estaciones
 * @var array $estacionesEscribibles
 * @var bool  $puedeRegistrar
 */
set_page_meta(
    'Talleres',
    'A dónde se llevan las unidades: el taller propio y los subcontratados de cada estación.',
    ['accion' => !empty($puedeRegistrar)
        ? '<button type="button" class="btn btn--primary" data-action="nuevo-taller">＋ Nuevo taller</button>'
        : '']
);
$seccion = 'talleres';

$sel = static fn($a, $b): string => (string) $a === (string) $b ? 'selected' : '';
?>
<section class="module">
    <?php require __DIR__ . '/_nav.php'; ?>

    <form class="card prov-buscar" method="get" action="/mantenimientos/talleres">
        <label class="field prov-buscar__q"><span class="field__label">Buscar</span>
            <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Nombre del taller" data-no-search></label>
        <label class="field"><span class="field__label">Estación</span>
            <select name="estacion_id">
                <option value="">Todas</option>
                <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= $sel($filtros['estacion_id'], $es['id']) ?>><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span class="field__label">Mostrar</span>
            <select name="estado" data-no-search>
                <?php foreach (['activos' => 'Activos', 'desactivados' => 'Desactivados', 'todos' => 'Todos'] as $v => $t): ?>
                    <option value="<?= e($v) ?>" <?= $sel($filtros['estado'], $v) ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select></label>
        <div class="prov-buscar__acciones">
            <button type="submit" class="btn btn--ghost-dark">Buscar</button>
            <a href="/mantenimientos/talleres" class="link">Limpiar</a>
        </div>
    </form>

    <?php if ($talleres === []): ?>
        <div class="card empty"><div class="card__empty">
            <p>Aún no hay talleres. Se agregan solos al registrar un mantenimiento con un taller nuevo,
               <?php if (!empty($puedeRegistrar)): ?>o <button type="button" class="link" data-action="nuevo-taller">créalo aquí →</button><?php endif; ?></p>
        </div></div>
    <?php else: ?>
    <div class="card card--table">
        <table class="table">
            <thead><tr><th>Taller</th><th>Estación</th><th>Tipo</th><th>Teléfono</th><th>Intervenciones</th><th>Gasto</th><th>Último uso</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($talleres as $t): $activo = (int) $t['activo'] === 1; ?>
                <tr class="<?= $activo ? '' : 'is-inactive' ?>">
                    <td><strong><?= e($t['nombre']) ?></strong>
                        <?php if (!$activo): ?><span class="badge badge--muted">Desactivado</span><?php endif; ?>
                        <?php if ($t['notas']): ?><small class="muted block"><?= e($t['notas']) ?></small><?php endif; ?></td>
                    <td><?= e($t['estacion_codigo']) ?></td>
                    <td><?= (int) $t['es_propio'] === 1
                        ? '<span class="badge badge--ok">Propio</span>'
                        : '<span class="badge badge--muted">Externo</span>' ?></td>
                    <td><?= $t['telefonos'] ? e($t['telefonos']) : '<span class="muted">—</span>' ?></td>
                    <td><?= (int) $t['intervenciones'] ?></td>
                    <td><?= $t['costo_usd'] !== null ? '$' . number_format((float) $t['costo_usd'], 2) : '<span class="muted">—</span>' ?></td>
                    <td><?= $t['ultimo_uso'] ? e($t['ultimo_uso']) : '<span class="muted">—</span>' ?></td>
                    <td class="row-actions">
                        <?= empty($puedeRegistrar) ? '' : row_menu([
                            ['label' => 'Editar', 'attrs' => ['data-action' => 'editar-taller', 'data-id' => (int) $t['id']]],
                            $activo
                                ? ['label' => 'Desactivar', 'danger' => true, 'attrs' => ['data-action' => 'activo-taller', 'data-id' => (int) $t['id'], 'data-nombre' => $t['nombre'], 'data-activo' => '0']]
                                : ['label' => 'Activar', 'attrs' => ['data-action' => 'activo-taller', 'data-id' => (int) $t['id'], 'data-nombre' => $t['nombre'], 'data-activo' => '1']],
                        ]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php if (!empty($puedeRegistrar)): ?>
<dialog id="dlg-taller" class="dialog">
    <form method="dialog" class="form" id="form-taller" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-taller-title">Nuevo taller</h2>
            <p class="dialog__lede">El nombre se compara sin mayúsculas ni signos: «K&C» y «K & C» son el mismo taller.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
            <div class="grid-2">
                <label class="field"><span class="field__label">Nombre *</span>
                    <input type="text" name="nombre" maxlength="150" required data-mayusculas autocomplete="off"></label>
                <label class="field" id="taller-estacion-field"><span class="field__label">Estación *</span>
                    <select name="estacion_id">
                        <?php foreach ($estacionesEscribibles as $es): ?>
                            <option value="<?= (int) $es['id'] ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Teléfono</span>
                    <input type="text" name="telefonos" maxlength="255"></label>
                <label class="field"><span class="field__label">Notas</span>
                    <input type="text" name="notas" maxlength="255" placeholder="Especialidad, dirección…"></label>
                <label class="check check--box grid-2__full"><input type="checkbox" name="es_propio" value="1">
                    <span>Es nuestro taller
                        <small class="block muted">Para poder comparar después cuánto se hace adentro y cuánto se paga afuera.</small>
                    </span></label>
            </div>
        </div>
        <p class="form__error" id="form-taller-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar taller</button>
        </div>
    </form>
</dialog>
<script src="<?= e(asset('/assets/js/talleres.js')) ?>" type="module"></script>
<?php endif; ?>

<?php require __DIR__ . '/_modales.php'; ?>
