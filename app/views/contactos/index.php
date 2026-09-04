<?php
/**
 * Listas de notificación (contactos). Cada estación mantiene las suyas; las corporativas
 * (sin estación) las ven todos y solo las edita el Admin Global.
 *
 * @var array $usuario
 * @var array $listas
 * @var string $q
 * @var bool $esAdmin
 * @var array $estaciones
 */
set_page_meta(
    'Contactos',
    'Agrupa destinatarios en listas con nombre para avisarles al crear una reserva sin volver a teclear sus correos.',
    ['accion' => '<button type="button" class="btn btn--primary" data-action="nueva-lista">＋ Nueva lista</button>']
);
?>
<section class="module">
    <form class="filters-panel" method="get" action="/contactos" data-filters-panel data-initial-open="<?= $q !== '' ? 'true' : 'false' ?>">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Búsqueda por nombre de lista o correo</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="contactos-filters-more">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="contactos-filters-more" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Buscar</span>
                    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre de la lista o un correo…" class="search"></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/contactos" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <?php if (empty($listas)): ?>
        <div class="card empty"><div class="card__empty">
            <p><?= $q !== '' ? 'Sin resultados para «' . e($q) . '».' : 'Aún no hay listas de contactos.' ?>
               <button type="button" class="link" data-action="nueva-lista">Crea la primera →</button></p>
        </div></div>
    <?php else: ?>
        <div class="card card--table">
            <table class="table">
                <thead><tr>
                    <th class="col col--nombre">Lista</th>
                    <th>Alcance</th>
                    <th>Correos</th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($listas as $l): $corporativa = $l['estacion_id'] === null; ?>
                    <tr>
                        <td class="col col--nombre"><strong><?= e($l['nombre']) ?></strong></td>
                        <td>
                            <?php if ($corporativa): ?>
                                <span class="badge badge--muted" title="Visible desde todas las estaciones">Corporativa</span>
                            <?php else: ?>
                                <?= e($l['estacion_codigo']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php foreach (CatalogoAdminService::correos($l['correos']) as $correo): ?>
                                <span class="chip-unidad"><?= e($correo) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="row-actions">
                            <?php
                            // Una lista corporativa solo la toca el Admin Global; a un encargado
                            // se le muestra pero sin acciones, para que sepa que existe.
                            $puedeEditar = !$corporativa || $esAdmin;
                            echo $puedeEditar ? row_menu([
                                ['label' => 'Editar', 'attrs' => ['data-action' => 'editar-lista', 'data-id' => (int) $l['id']]],
                                ['label' => 'Eliminar', 'danger' => true, 'attrs' => ['data-action' => 'eliminar-lista', 'data-id' => (int) $l['id'], 'data-nombre' => $l['nombre']]],
                            ]) : '<span class="muted">—</span>';
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<dialog id="dlg-lista" class="dialog">
    <form method="dialog" class="form" id="form-lista" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-lista-title">Nueva lista</h2>
            <p class="dialog__lede">Agrupa a quienes deben enterarse de una reserva: el cliente, la estación de destino, el ejecutivo de cuenta.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
            <div class="grid-2">
                <label class="field"><span class="field__label">Nombre *</span>
                    <input type="text" name="nombre" maxlength="100" required placeholder="Ej.: SV TEAM"></label>
                <?php if ($esAdmin): ?>
                    <label class="field"><span class="field__label">Estación</span>
                        <select name="estacion_id">
                            <option value="">Corporativa (todas)</option>
                            <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                        </select>
                        <small class="field__note">Sin estación, la lista queda disponible en toda la red.</small></label>
                <?php else: ?>
                    <?php
                    $miEstacion = '';
                    foreach ($estaciones as $es) {
                        if ((int) $es['id'] === (int) $usuario['estacion_id']) { $miEstacion = $es['codigo'] . ' · ' . $es['nombre']; break; }
                    }
                    ?>
                    <label class="field"><span class="field__label">Estación</span>
                        <input type="text" value="<?= e($miEstacion) ?>" disabled></label>
                <?php endif; ?>
            </div>
            <label class="field"><span class="field__label">Correos *</span>
                <textarea name="correos" rows="3" maxlength="500" required placeholder="ana@empresa.com, bruno@empresa.com"></textarea>
                <small class="field__note">Sepáralos por coma, punto y coma o salto de línea.</small></label>
        </div>
        <p class="form__error" id="form-lista-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar lista</button>
        </div>
    </form>
</dialog>

<script src="/assets/js/contactos.js" type="module"></script>
