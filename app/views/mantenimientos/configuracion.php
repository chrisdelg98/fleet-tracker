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
               . 'Abajo eliges qué plan sigue cada categoría: todos los cabezales de una vez.',
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
                        <td><?= $usan === []
                            ? '<span class="muted">Ninguna todavía</span>'
                            : e(implode(' · ', array_column($usan, 'nombre'))) ?></td>
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

<?php if ($clave === 'planes'): ?>
    <!-- La pregunta real es «¿qué plan sigue esta categoría?», y desde el plan había que
         abrirlos todos para responderla. Un desplegable por fila además hace evidente la regla:
         una categoría sigue un plan, o ninguno. -->
    <div class="card cat-planes">
        <div class="cat-planes__head">
            <h2>Plan de cada categoría</h2>
            <p class="muted">Lo que dejes en «Sin plan» no lleva servicio programado y no aparece en el semáforo.</p>
        </div>
        <form id="form-cat-planes">
            <table class="table">
                <thead><tr><th>Categoría</th><th>Plan de servicio</th></tr></thead>
                <tbody>
                <?php foreach ($categorias as $c): $suyo = (int) ($c['plan_mantenimiento_id'] ?? 0); ?>
                    <tr>
                        <td><strong><?= e($c['nombre']) ?></strong></td>
                        <td>
                            <?php if ($puedeEditar): ?>
                                <select name="cat[<?= (int) $c['id'] ?>]" data-no-search>
                                    <option value="">Sin plan</option>
                                    <?php foreach ($items as $pl): ?>
                                        <option value="<?= (int) $pl['id'] ?>" <?= $suyo === (int) $pl['id'] ? 'selected' : '' ?>><?= e($pl['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <?php $nombres = array_column(array_filter($items, static fn($pl) => (int) $pl['id'] === $suyo), 'nombre'); ?>
                                <?= $nombres === [] ? '<span class="muted">Sin plan</span>' : e($nombres[0]) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($puedeEditar): ?>
                <div class="cat-planes__pie">
                    <p class="form__error" id="form-cat-error" hidden></p>
                    <button type="submit" class="btn btn--primary">Guardar asignación</button>
                </div>
            <?php endif; ?>
        </form>
    </div>
<?php endif; ?>

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



<script type="application/json" id="config-spec"><?= json_encode([$tabla => $spec], JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" id="config-data"><?= json_encode([$tabla => $items], JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" id="config-etiquetas"><?= json_encode(CatalogoAdminService::etiquetas(), JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('/assets/js/mantenimientos-config.js')) ?>" type="module"></script>
