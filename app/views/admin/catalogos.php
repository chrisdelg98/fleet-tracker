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
$tablas = array_keys($catalogos);
$catalogoActivo = $tablas[0] ?? null;
?>
<section class="module">
    <div class="module__head"><div><a href="/admin" class="link">← Administración</a><h1>Catálogos</h1><p class="module__subtitle">Mantén actualizados los parámetros base del sistema usados por formularios, reglas y validaciones operativas.</p></div></div>

    <?php if ($catalogoActivo !== null): ?>
        <div class="catalogos-grid" role="tablist" aria-label="Catálogos disponibles">
            <?php foreach ($catalogos as $tabla => $cat): ?>
                <button type="button" class="catalogo-card<?= $tabla === $catalogoActivo ? ' is-active' : '' ?>" data-catalogo-tab="<?= e($tabla) ?>" aria-selected="<?= $tabla === $catalogoActivo ? 'true' : 'false' ?>">
                    <strong><?= e($cat['spec']['label']) ?></strong>
                    <span class="catalogo-card__meta"><?= count($cat['items']) ?> registro<?= count($cat['items']) === 1 ? '' : 's' ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php foreach ($catalogos as $tabla => $cat): ?>
        <section class="card catalogo-panel<?= $tabla === $catalogoActivo ? ' is-active' : '' ?>" data-catalogo-panel="<?= e($tabla) ?>" <?= $tabla === $catalogoActivo ? '' : 'hidden' ?>>
            <div class="catalogo-panel__head">
                <div>
                    <h2><?= e($cat['spec']['label']) ?></h2>
                    <p class="catalogo-panel__copy">Consulta y ajusta este catálogo sin mezclarlo con el resto del bloque administrativo.</p>
                </div>
                <button type="button" class="btn btn--primary btn--sm" data-action="nuevo-catalogo" data-tabla="<?= e($tabla) ?>">＋ Agregar</button>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <?php foreach ($cat['spec']['fields'] as $campo => $tipo): ?>
                            <th><?= e(ucfirst(str_replace('_', ' ', $campo))) ?></th>
                        <?php endforeach; ?>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($cat['items'] as $item): ?>
                        <?php $activo = isset($item['activo']) ? (int) $item['activo'] : 1; ?>
                        <tr class="<?= $activo === 0 ? 'is-inactive' : '' ?>">
                            <?php foreach ($cat['spec']['fields'] as $campo => $tipo): ?>
                                <td><?= e($fmtCampo($tipo, $item[$campo] ?? '')) ?></td>
                            <?php endforeach; ?>
                            <td class="row-actions">
                                <?= action_chip('Editar', [
                                    'attrs' => [
                                        'data-action' => 'editar-catalogo',
                                        'data-tabla' => $tabla,
                                        'data-id' => (int) $item['id'],
                                    ],
                                ]) ?>
                                <?= action_chip($activo === 1 ? 'Desactivar' : 'Activar', [
                                    'icon' => $activo === 1 ? 'toggle-off' : 'toggle-on',
                                    'variant' => $activo === 1 ? 'warning' : 'success',
                                    'attrs' => [
                                        'data-action' => 'activo-catalogo',
                                        'data-tabla' => $tabla,
                                        'data-id' => (int) $item['id'],
                                        'data-activo' => $activo,
                                    ],
                                ]) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
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
