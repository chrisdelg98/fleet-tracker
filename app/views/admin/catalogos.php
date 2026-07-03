<?php
/**
 * Administración › Catálogos (plan §5.3). Config-driven: una sección por tabla de referencia.
 * @var array $catalogos  tabla => ['spec'=>['label','fields'=>[campo=>tipo]], 'items'=>[...]]
 */
$regionLabels = REGION_LABELS;
$fmtCampo = static function (string $tipo, $valor) use ($regionLabels): string {
    if ($tipo === 'bool') {
        return ((int) $valor === 1) ? 'Sí' : 'No';
    }
    if ($tipo === 'region') {
        return $regionLabels[$valor] ?? (string) $valor;
    }
    return (string) $valor;
};
?>
<section class="module">
    <div class="module__head"><div><a href="/admin" class="link">← Administración</a><h1>Catálogos</h1></div></div>

    <?php foreach ($catalogos as $tabla => $cat): ?>
        <div class="card card--table catalogo">
            <div class="catalogo__head">
                <h2><?= e($cat['spec']['label']) ?></h2>
                <button type="button" class="btn btn--primary btn--sm" data-action="nuevo-catalogo" data-tabla="<?= e($tabla) ?>">＋ Agregar</button>
            </div>
            <table class="table">
                <thead><tr>
                    <?php foreach ($cat['spec']['fields'] as $campo => $tipo): ?>
                        <th><?= e(ucfirst(str_replace('_', ' ', $campo))) ?></th>
                    <?php endforeach; ?>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($cat['items'] as $item): ?>
                    <tr>
                        <?php foreach ($cat['spec']['fields'] as $campo => $tipo): ?>
                            <td><?= e($fmtCampo($tipo, $item[$campo] ?? '')) ?></td>
                        <?php endforeach; ?>
                        <td class="row-actions">
                            <button type="button" class="link" data-action="editar-catalogo" data-tabla="<?= e($tabla) ?>" data-id="<?= (int) $item['id'] ?>">Editar</button>
                            <button type="button" class="link" data-action="activo-catalogo" data-tabla="<?= e($tabla) ?>" data-id="<?= (int) $item['id'] ?>" data-activo="1">Desactivar</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</section>

<dialog id="dlg-catalogo" class="dialog">
    <form method="dialog" class="form" id="form-catalogo" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-catalogo-title">Catálogo</h2>
            <p class="dialog__lede">Edita parámetros base del sistema sin romper consistencia entre formularios, reglas y catálogos operativos.</p>
        </div>
        <input type="hidden" name="id" value="">
        <input type="hidden" name="__tabla" value="">
        <div class="dialog__body">
            <div id="catalogo-fields" class="form"></div>
        </div>
        <p class="form__error" id="form-catalogo-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar</button>
        </div>
    </form>
</dialog>

<script type="application/json" id="catalogos-spec"><?= json_encode(
    array_map(static fn(array $c): array => ['label' => $c['spec']['label'], 'fields' => $c['spec']['fields']], $catalogos),
    JSON_UNESCAPED_UNICODE
) ?></script>
<script type="application/json" id="catalogos-data"><?= json_encode(
    array_map(static fn(array $c): array => $c['items'], $catalogos),
    JSON_UNESCAPED_UNICODE
) ?></script>
<script type="application/json" id="catalogos-regiones"><?= json_encode(REGION_LABELS, JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/js/admin-catalogos.js" type="module"></script>
