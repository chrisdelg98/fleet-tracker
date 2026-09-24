<?php
/**
 * Mantenimientos › Configuración. Una pantalla por catálogo: planes, tipos o monedas.
 *
 * Vive en el módulo y no en Administración porque la mantiene quien opera: un encargado ajusta
 * el intervalo o la tasa del día sin depender del Admin Global.
 *
 * @var array  $usuario
 * @var string $clave        planes | tipos | monedas
 * @var string $tabla        tabla del catálogo
 * @var string $titulo
 * @var array  $spec         definición de campos (CatalogoAdminService)
 * @var array  $items
 * @var array  $categorias   solo en planes: para decir a qué se aplica cada uno
 * @var bool   $puedeEditar
 */
set_page_meta($titulo, [
    'planes'  => 'Cada cuánto toca servicio y con cuánta anticipación avisar.',
    'tipos'   => 'Cómo se clasifica cada trabajo que se registra.',
    'monedas' => 'A cómo está cada moneda frente al dólar.',
][$clave] ?? '', ['accion' => $puedeEditar
    ? '<button type="button" class="btn btn--primary" data-action="nuevo-config" data-tabla="' . e($tabla) . '">＋ Agregar</button>'
    : '']);
$seccion = 'configuracion';

// Una frase por pantalla, la que responde la duda que trae quien entra.
$ayuda = [
    'planes'  => 'Un plan dice cada cuántos kilómetros o días toca el servicio programado. '
               . 'Se aplica a categorías enteras —todos los cabezales, por ejemplo— desde la columna «Aplica a».',
    'tipos'   => 'Solo dos hacen falta: <strong>Preventivo</strong> es el servicio programado y '
               . 'reinicia el conteo hacia el próximo; <strong>Correctivo</strong> es una reparación y no lo reinicia. '
               . 'Lo que se hizo va en la descripción de cada registro, no aquí.',
    'monedas' => 'Cuántas unidades equivalen a un dólar. Cada gasto guarda la tasa con la que se convirtió, '
               . 'así que cambiarla aquí no altera lo ya registrado.',
][$clave] ?? '';
?>
<section class="module">
    <?php require __DIR__ . '/_nav.php'; ?>

    <p class="muted" style="margin-bottom: var(--sp-3)"><?= $ayuda ?></p>

    <div class="card card--table mant-config" data-tabla="<?= e($tabla) ?>">
        <table class="table">
            <thead>
                <tr>
                    <?php foreach ($spec['fields'] as $campo => $tipo): ?>
                        <th><?= e(CatalogoAdminService::etiqueta($campo)) ?></th>
                    <?php endforeach; ?>
                    <?php if ($clave === 'planes'): ?><th>Aplica a</th><?php endif; ?>
                    <?php if ($puedeEditar): ?><th class="col col--acciones"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ($items === []): ?>
                <tr><td colspan="9" class="muted">Todavía no hay nada aquí. Usa «Agregar».</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $it): ?>
                <tr>
                    <?php foreach ($spec['fields'] as $campo => $tipo): ?>
                        <td>
                            <?php if ($tipo === 'bool'): ?>
                                <?= (int) ($it[$campo] ?? 0) === 1
                                    ? '<span class="chip chip--disponible">Sí</span>'
                                    : '<span class="muted">No</span>' ?>
                            <?php else: ?>
                                <?= e((string) ($it[$campo] ?? '')) !== '' ? e((string) $it[$campo]) : '—' ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <?php if ($clave === 'planes'): ?>
                        <?php
                        // Qué categorías usan este plan. Es la respuesta a «¿a quién le aplica esto?»,
                        // que antes obligaba a abrir las unidades una por una.
                        $usan = array_values(array_filter(
                            $categorias,
                            static fn(array $c): bool => (int) ($c['plan_mantenimiento_id'] ?? 0) === (int) $it['id']
                        ));
                        ?>
                        <td>
                            <?php if ($usan === []): ?>
                                <span class="muted"><?= (int) ($it['por_defecto'] ?? 0) === 1 ? 'Todas las que no tengan otro' : 'Ninguna' ?></span>
                            <?php else: ?>
                                <?= e(implode(' · ', array_column($usan, 'nombre'))) ?>
                            <?php endif; ?>
                            <?php if ($puedeEditar): ?>
                                <button type="button" class="btn btn--linea btn--sm" data-action="aplicar-plan"
                                        data-id="<?= (int) $it['id'] ?>" data-nombre="<?= e($it['nombre']) ?>">Aplicar a categorías</button>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($puedeEditar): ?>
                        <td class="col col--acciones">
                            <?= row_menu([
                                ['label' => 'Editar', 'attrs' => ['data-action' => 'editar-config',
                                    'data-tabla' => $tabla, 'data-id' => (int) $it['id']]],
                                ['label' => 'Desactivar', 'peligro' => true, 'attrs' => ['data-action' => 'desactivar-config',
                                    'data-tabla' => $tabla, 'data-id' => (int) $it['id'], 'data-nombre' => $it['nombre'] ?? '']],
                            ]) ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/_modales.php'; ?>

<!-- Alta y edición de cualquiera de los tres catálogos: los campos los dibuja el JS desde
     la definición que manda el servidor, así que este diálogo sirve para los tres. -->
<dialog id="dlg-config" class="dialog">
    <form method="dialog" class="form" id="form-config" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-config-title">Agregar</h2>
        </div>
        <input type="hidden" name="id" value="">
        <input type="hidden" name="__tabla" value="">
        <div class="dialog__body"><div id="config-campos" class="form"></div></div>
        <p class="form__error" id="form-config-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar</button>
        </div>
    </form>
</dialog>

<?php if ($clave === 'planes' && $puedeEditar): ?>
<dialog id="dlg-aplicar-plan" class="dialog">
    <form method="dialog" class="form" id="form-aplicar-plan" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-aplicar-title">Aplicar plan</h2>
            <p class="dialog__lede">Marca las categorías que usan este plan. Lo que desmarques vuelve al plan por defecto.</p>
        </div>
        <input type="hidden" name="plan_id" value="">
        <div class="dialog__body">
            <div class="checks">
                <?php foreach ($categorias as $c): ?>
                    <label class="check"><input type="checkbox" name="categorias[]" value="<?= (int) $c['id'] ?>"
                        data-plan="<?= (int) ($c['plan_mantenimiento_id'] ?? 0) ?>"
                        data-no-aplica="<?= (int) ($c['plan_no_aplica'] ?? 0) ?>"> <?= e($c['nombre']) ?></label>
                <?php endforeach; ?>
            </div>
            <p class="muted">¿Una categoría no lleva servicio programado —plataformas, contenedores—?
               Márcala en «No lleva plan» y dejará de aparecer en el semáforo.</p>
            <div class="checks">
                <?php foreach ($categorias as $c): ?>
                    <label class="check"><input type="checkbox" name="sin_plan[]" value="<?= (int) $c['id'] ?>"
                        <?= (int) ($c['plan_no_aplica'] ?? 0) === 1 ? 'checked' : '' ?>> <?= e($c['nombre']) ?> no lleva plan</label>
                <?php endforeach; ?>
            </div>
        </div>
        <p class="form__error" id="form-aplicar-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<script type="application/json" id="config-spec"><?= json_encode([$tabla => $spec], JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" id="config-data"><?= json_encode([$tabla => $items], JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" id="config-etiquetas"><?= json_encode(CatalogoAdminService::etiquetas(), JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('/assets/js/mantenimientos-config.js')) ?>" type="module"></script>
