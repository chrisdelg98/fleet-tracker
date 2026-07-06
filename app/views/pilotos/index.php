<?php
/**
 * Pilotos (plan §7.3). Alerta visual: licencia vencida (rojo) o por vencer ≤30 días (ámbar).
 * @var array $usuario
 * @var array $pilotos
 * @var array $filtros
 * @var bool $verTodas
 * @var array $tiposLicencia
 * @var array $estaciones
 */
$esAdmin = $usuario['rol'] === Rol::ADMIN_GLOBAL;
$hoy = new DateTimeImmutable('today');
$hayFiltros = implode('', $filtros) !== '';
$estadosLicencia = ['vigente' => 'Vigente', 'por_vencer' => 'Por vencer (≤30 días)', 'vencida' => 'Vencida'];
?>
<section class="module">
    <div class="module__head">
        <div>
            <h1>Pilotos</h1>
            <p class="module__subtitle">Gestiona pilotos, licencias y estación asignada para mantener la operación lista para programar movimientos.</p>
        </div>
        <button type="button" class="btn btn--primary" data-action="nuevo-piloto">＋ Nuevo piloto</button>
    </div>

    <form class="filters-panel" method="get" action="/pilotos" data-filters-panel data-initial-open="<?= $hayFiltros ? 'true' : 'false' ?>">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong>Filtros</strong>
                <span>Estación, tipo de licencia, estado y búsqueda por nombre o número</span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="pilotos-filters-more">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="pilotos-filters-more" data-filters-more hidden>
            <div class="filters-grid">
                <?php if ($verTodas): ?>
                <label class="field"><span class="field__label">Estación</span>
                    <select name="estacion_id">
                        <option value="">Todas</option>
                        <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>" <?= (string) $filtros['estacion_id'] === (string) $es['id'] ? 'selected' : '' ?>><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
                <label class="field"><span class="field__label">Tipo de licencia</span>
                    <select name="tipo_licencia_id">
                        <option value="">Todos</option>
                        <?php foreach ($tiposLicencia as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (string) $filtros['tipo_licencia_id'] === (string) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Estado de licencia</span>
                    <select name="licencia">
                        <option value="">Todas</option>
                        <?php foreach ($estadosLicencia as $val => $lbl): ?><option value="<?= e($val) ?>" <?= $filtros['licencia'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Buscar</span>
                    <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Nombre o n.º de licencia…" class="search"></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/pilotos" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <?php if (empty($pilotos)): ?>
        <div class="card empty"><div class="card__empty">
            <?php if ($hayFiltros): ?>
                <p>Sin pilotos para estos filtros. <a href="/pilotos" class="link">Limpiar filtros</a></p>
            <?php else: ?>
                <p>Aún no hay pilotos. <button type="button" class="link" data-action="nuevo-piloto">Crea el primero →</button></p>
            <?php endif; ?>
        </div></div>
    <?php else: ?>
        <div class="card card--table">
            <table class="table">
                <thead><tr><th>Nombre</th><th>Licencia</th><th>N.º</th><th>Vencimiento</th><th>Estación</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pilotos as $p): ?>
                    <tr>
                        <td><strong><?= e($p['nombre']) ?></strong></td>
                        <td><?= e($p['tipo_licencia']) ?></td>
                        <td><?= e($p['no_licencia']) ?></td>
                        <td>
                            <?php if (empty($p['licencia_vence'])): ?>
                                <span class="muted">—</span>
                            <?php
                            else:
                                $dias = (int) $hoy->diff(new DateTimeImmutable($p['licencia_vence']))->format('%r%a');
                                if ($dias < 0): ?>
                                    <span class="badge badge--alert" title="<?= e($p['licencia_vence']) ?>">Vencida</span>
                                <?php elseif ($dias <= 30): ?>
                                    <span class="badge badge--warn" title="<?= e($p['licencia_vence']) ?>">Vence en <?= $dias ?> día<?= $dias === 1 ? '' : 's' ?></span>
                                <?php else: ?>
                                    <?= e($p['licencia_vence']) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e($p['estacion_codigo']) ?></td>
                        <td class="row-actions">
                            <?= row_menu([
                                ['label' => 'Editar', 'attrs' => ['data-action' => 'editar-piloto', 'data-id' => (int) $p['id']]],
                                ['label' => 'Eliminar', 'danger' => true, 'attrs' => ['data-action' => 'eliminar-piloto', 'data-id' => (int) $p['id'], 'data-nombre' => $p['nombre']]],
                            ]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<dialog id="dlg-piloto" class="dialog">
    <form method="dialog" class="form" id="form-piloto" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-piloto-title">Nuevo piloto</h2>
            <p class="dialog__lede">Registra al piloto con su licencia y estación operativa para mantener la asignación disponible en los movimientos.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
        <div class="grid-2">
            <label class="field"><span class="field__label">Nombre *</span>
                <input type="text" name="nombre" maxlength="150" required></label>
            <label class="field"><span class="field__label">Tipo de licencia *</span>
                <select name="tipo_licencia_id" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($tiposLicencia as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">N.º de licencia *</span>
                <input type="text" name="no_licencia" maxlength="60" required></label>
            <label class="field"><span class="field__label">Vencimiento</span>
                <input type="date" name="licencia_vence"></label>
            <?php if ($esAdmin): ?>
            <label class="field"><span class="field__label">Estación *</span>
                <select name="estacion_id" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <?php else: ?>
                <input type="hidden" name="estacion_id" value="<?= (int) $usuario['estacion_id'] ?>">
            <?php endif; ?>
        </div>
        </div>
        <p class="form__error" id="form-piloto-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar piloto</button>
        </div>
    </form>
</dialog>

<script src="/assets/js/pilotos.js" type="module"></script>
