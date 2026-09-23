<?php
/**
 * Mantenimientos › Configuración. Cada cuánto toca servicio, con cuánta anticipación avisar, qué
 * tipos de mantenimiento existen y a cómo está cada moneda.
 *
 * Vive en el módulo y no en Administración porque la mantiene quien opera: un encargado ajusta
 * el intervalo o la tasa del día sin depender del Admin Global.
 *
 * @var array $usuario
 * @var array $catalogos    tabla => ['spec' => ..., 'items' => ...]
 * @var bool  $puedeEditar
 */
set_page_meta('Configuración de mantenimientos', 'Intervalos, avisos, tipos de servicio y tasas de cambio.');
$seccion = 'configuracion';

$ayuda = [
    'tipos_mantenimiento' => 'Solo el que reinicia el ciclo mueve la fecha del próximo servicio. Si todos lo reiniciaran, las alertas quedarían siempre en verde.',
    'planes_mantenimiento' => 'Cada cuánto toca servicio y con cuánta anticipación avisar. El plan por defecto lo usan todas las unidades que no tengan uno propio, en todas las estaciones.',
    'monedas' => 'Cuántas unidades equivalen a un dólar. Cada gasto guarda la tasa con la que se convirtió, así que cambiarla aquí no altera lo ya registrado.',
];
?>
<section class="module">
    <?php require __DIR__ . '/_nav.php'; ?>

    <?php foreach ($catalogos as $tabla => $datos): ?>
        <div class="card mant-config" data-tabla="<?= e($tabla) ?>">
            <div class="mant-config__head">
                <div>
                    <h2><?= e($datos['spec']['label']) ?></h2>
                    <p class="muted"><?= e($ayuda[$tabla] ?? '') ?></p>
                </div>
                <?php if ($puedeEditar): ?>
                    <button type="button" class="btn btn--ghost-dark" data-action="nuevo-config" data-tabla="<?= e($tabla) ?>">＋ Agregar</button>
                <?php endif; ?>
            </div>

            <table class="table">
                <thead><tr>
                    <?php foreach ($datos['spec']['fields'] as $campo => $tipo): ?>
                        <th><?= e(CatalogoAdminService::etiqueta($campo)) ?></th>
                    <?php endforeach; ?>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($datos['items'] as $item): ?>
                    <tr>
                        <?php foreach ($datos['spec']['fields'] as $campo => $tipo): ?>
                            <td>
                                <?php
                                $valor = $item[$campo] ?? null;
                                if ($tipo === 'bool') {
                                    echo (int) $valor === 1
                                        ? '<span class="badge badge--ok">Sí</span>'
                                        : '<span class="muted">No</span>';
                                } elseif ($valor === null || $valor === '') {
                                    echo '<span class="muted">—</span>';
                                } elseif ($tipo === 'int') {
                                    echo number_format((float) $valor);
                                } elseif ($tipo === 'decimal') {
                                    echo e(rtrim(rtrim((string) $valor, '0'), '.'));
                                } else {
                                    echo e((string) $valor);
                                }
                                ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="row-actions">
                            <?= !$puedeEditar ? '' : row_menu([
                                ['label' => 'Editar', 'attrs' => ['data-action' => 'editar-config', 'data-tabla' => $tabla, 'data-id' => (int) $item['id']]],
                                ['label' => 'Desactivar', 'danger' => true, 'attrs' => [
                                    'data-action' => 'desactivar-config', 'data-tabla' => $tabla, 'data-id' => (int) $item['id'],
                                    'data-nombre' => $item['nombre'] ?? ($item['codigo'] ?? '')]],
                            ]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($datos['items'] === []): ?>
                    <tr><td colspan="<?= count($datos['spec']['fields']) + 1 ?>" class="muted">Nada configurado todavía.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</section>

<?php if ($puedeEditar): ?>
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

<script type="application/json" id="config-spec"><?= json_encode(
    array_map(static fn(array $c): array => ['label' => $c['spec']['label'], 'fields' => $c['spec']['fields']], $catalogos),
    JSON_UNESCAPED_UNICODE
) ?></script>
<script type="application/json" id="config-data"><?= json_encode(
    array_map(static fn(array $c): array => $c['items'], $catalogos),
    JSON_UNESCAPED_UNICODE
) ?></script>
<script type="application/json" id="config-etiquetas"><?= json_encode(
    array_reduce(
        array_merge(...array_map(static fn(array $c): array => array_keys($c['spec']['fields']), array_values($catalogos))),
        static function (array $acc, string $campo): array {
            $acc[$campo] = CatalogoAdminService::etiqueta($campo);
            return $acc;
        },
        []
    ),
    JSON_UNESCAPED_UNICODE
) ?></script>
<script src="<?= e(asset('/assets/js/mantenimientos-config.js')) ?>" type="module"></script>
<?php endif; ?>
