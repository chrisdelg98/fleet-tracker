<?php
/**
 * Administración › Usuarios (plan §5.2, §4).
 *
 * La lista crece y se consulta para gestionar, no para leerla entera: filtros arriba, paginación
 * abajo y, en medio, el recuento de lo que se está viendo.
 *
 * @var array $usuario
 * @var array $resultado  filas, total, pagina, paginas, por_pagina
 * @var array $filtros    q, rol, estacion_id, activo
 * @var array $estaciones
 * @var array $roles
 * @var array $rolesSinEstacion
 */
set_page_meta(
    'Usuarios',
    'Administra cuentas, roles y alcance por estación para el personal con acceso al sistema.',
    [
        'padre' => ['label' => 'Administración', 'href' => '/admin'],
        'accion' => '<button type="button" class="btn btn--primary" data-action="nuevo-usuario">＋ Nuevo usuario</button>',
    ]
);
?>
<?php
$sel = static fn($valor, $opcion): string => (string) $valor === (string) $opcion ? 'selected' : '';
$hayFiltros = implode('', $filtros) !== '';
// El tamaño de página viaja con los filtros para no perderse al cambiar de página.
$comunes = $filtros + ['por_pagina' => $resultado['por_pagina']];
?>
<section class="module">
    <form class="filters-panel" method="get" action="/admin/usuarios" data-filters-panel data-initial-open="<?= $hayFiltros ? 'true' : 'false' ?>">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Nombre o correo, rol, estación y estado</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="usuarios-filtros">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="usuarios-filtros" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Buscar</span>
                    <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Nombre o correo…" class="search" data-no-search></label>
                <label class="field"><span class="field__label">Rol</span>
                    <select name="rol" data-no-search>
                        <option value="">Todos</option>
                        <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>" <?= $sel($filtros['rol'], $r) ?>><?= e(Rol::label($r)) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Estación</span>
                    <select name="estacion_id">
                        <option value="">Todas</option>
                        <option value="sin" <?= $sel($filtros['estacion_id'], 'sin') ?>>Sin estación</option>
                        <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= $sel($filtros['estacion_id'], $es['id']) ?>><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Estado</span>
                    <select name="activo" data-no-search>
                        <option value="">Todos</option>
                        <option value="1" <?= $sel($filtros['activo'], '1') ?>>Activos</option>
                        <option value="0" <?= $sel($filtros['activo'], '0') ?>>Inactivos</option>
                    </select></label>
                <label class="field"><span class="field__label">Por página</span>
                    <select name="por_pagina" data-no-search>
                        <?php foreach (UsuarioModel::POR_PAGINA_OPCIONES as $op): ?><option value="<?= $op ?>" <?= $sel($resultado['por_pagina'], $op) ?>><?= $op ?></option><?php endforeach; ?>
                    </select></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/admin/usuarios" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <p class="dashboard__meta">
        <span><?= (int) $resultado['total'] ?> usuario<?= (int) $resultado['total'] === 1 ? '' : 's' ?></span>
        <?php if ($resultado['paginas'] > 1): ?>
            · <span class="muted">página <?= (int) $resultado['pagina'] ?> de <?= (int) $resultado['paginas'] ?></span>
        <?php endif; ?>
    </p>

    <?php if ($resultado['filas'] === []): ?>
        <div class="card empty"><div class="card__empty">
            <p>Ningún usuario coincide con los filtros. <a href="/admin/usuarios" class="link">Limpiar filtros</a></p>
        </div></div>
    <?php else: ?>
    <div class="card card--table">
        <table class="table">
            <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estación</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($resultado['filas'] as $u):
                $propio = (int) $u['id'] === (int) $usuario['id'];
                $activo = (int) $u['activo'] === 1;
            ?>
                <tr class="<?= $activo ? '' : 'is-inactive' ?>">
                    <td><strong><?= e($u['nombre']) ?></strong>
                        <?php if ($propio): ?><span class="badge badge--muted">Tu cuenta</span><?php endif; ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e(Rol::label($u['rol'])) ?></td>
                    <td><?= e($u['estacion_codigo'] ?? '—') ?></td>
                    <td><?= $activo ? '<span class="badge badge--ok">Activo</span>' : '<span class="badge badge--muted">Inactivo</span>' ?></td>
                    <td class="row-actions">
                        <?= row_menu([
                            ['label' => 'Editar', 'attrs' => ['data-action' => 'editar-usuario', 'data-id' => (int) $u['id']]],
                            // La cuenta propia no se ofrece desactivar: el servidor lo rechaza igual,
                            // y ofrecer algo que siempre falla solo hace perder el viaje.
                            $propio ? null : ['label' => $activo ? 'Desactivar' : 'Activar', 'danger' => $activo,
                                'attrs' => ['data-action' => 'activo-usuario', 'data-id' => (int) $u['id'], 'data-activo' => (int) $u['activo']]],
                        ]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($resultado['paginas'] > 1): ?>
    <nav class="pager">
        <?php for ($p = 1; $p <= $resultado['paginas']; $p++): $pq = http_build_query(array_merge($comunes, ['pagina' => $p])); ?>
            <a href="/admin/usuarios?<?= e($pq) ?>" class="pager__link<?= $p === (int) $resultado['pagina'] ? ' is-active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
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
                    <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"><?= e(Rol::label($r)) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field" id="usuario-estacion-field"><span class="field__label">Estación <span id="estacion-req">*</span></span>
                <select name="estacion_id">
                    <option value="">Selecciona…</option>
                    <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field grid-2__full"><span class="field__label">Contraseña <span id="pass-req">*</span></span>
                <span class="field__control">
                    <input type="password" name="password" autocomplete="new-password">
                    <span class="field__btns">
                        <button type="button" class="field__btn" id="pass-generate" aria-label="Generar contraseña segura" title="Generar contraseña segura">
                            <svg viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false"><path d="M11 2.2l1 2.8 2.8 1-2.8 1-1 2.8-1-2.8-2.8-1 2.8-1 1-2.8Zm5.2 6.6.6 1.7 1.7.6-1.7.6-.6 1.7-.6-1.7-1.7-.6 1.7-.6.6-1.7ZM6.2 10.6l.9 2.3 2.3.9-2.3.9-.9 2.3-.9-2.3-2.3-.9 2.3-.9.9-2.3Z" fill="currentColor"/></svg>
                        </button>
                        <button type="button" class="field__btn" id="pass-toggle" aria-pressed="false" aria-label="Mostrar contraseña" title="Mostrar contraseña">
                            <svg class="field__btn-icon field__btn-icon--show" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false"><path d="M10 4c4.1 0 7.3 2.9 8.4 5.4a1.5 1.5 0 0 1 0 1.2C17.3 13.1 14.1 16 10 16s-7.3-2.9-8.4-5.4a1.5 1.5 0 0 1 0-1.2C2.7 6.9 5.9 4 10 4Zm0 1.8c-3.1 0-5.7 2.2-6.7 4.2 1 2 3.6 4.2 6.7 4.2s5.7-2.2 6.7-4.2c-1-2-3.6-4.2-6.7-4.2Zm0 1.4a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Z" fill="currentColor"/></svg>
                            <svg class="field__btn-icon field__btn-icon--hide" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false"><path d="M3.3 2.3a.9.9 0 0 0-1.3 1.3l2.2 2.2C2.9 6.8 1.9 8.2 1.4 9.4a1.5 1.5 0 0 0 0 1.2C2.6 13.1 5.8 16 9.9 16c1.5 0 2.9-.4 4.1-1l2.4 2.4a.9.9 0 1 0 1.3-1.3L3.3 2.3Zm5.3 7.9 1.9 1.9a2.1 2.1 0 0 1-1.9-1.9Zm-1.5-1.5.2-.4A2.8 2.8 0 0 1 12.4 12l-.4.2-1.5-1.5a2.1 2.1 0 0 0-2.9-2.9L7.1 8.7ZM9.9 5.8c3.1 0 5.7 2.2 6.7 4.2-.3.6-.9 1.4-1.7 2.1l1.3 1.3c1-1 1.8-2 2.2-2.8a1.5 1.5 0 0 0 0-1.2C17.2 6.9 14 4 9.9 4c-.9 0-1.7.1-2.5.4l1.4 1.5c.3 0 .7-.1 1.1-.1Z" fill="currentColor"/></svg>
                        </button>
                    </span>
                </span>
                <small class="field__note" id="pass-note"></small></label>
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
