<?php
/**
 * Catálogo de proveedores de transporte y sus camiones.
 *
 * Una sola tabla con las columnas del camión; cada proveedor es un grupo (<tbody>) con una fila
 * de cabecera que abre y cierra sus camiones. Cerrados de entrada, para que la lista se lea como
 * una lista de proveedores; al buscar se abren, porque lo buscado suele ser una placa.
 *
 * @var array                                   $usuario
 * @var list<array>                             $lista     proveedores, cada uno con 'camiones'
 * @var string                                  $q
 * @var string                                  $estado    activos | desactivados | todos
 * @var list<array{id: int, nombre: string}>    $paraMover proveedores activos
 */
set_page_meta(
    'Proveedores',
    'Proveedores de transporte y sus camiones: lo que se ofrece al reservar con un camión contratado.',
    [
        'accion'   => '<button type="button" class="btn btn--primary" data-action="nuevo-proveedor">＋ Nuevo proveedor</button>',
        'acciones' => '<button type="button" class="btn btn--ghost-dark" data-action="carga-masiva">Carga masiva</button>',
    ]
);

$abiertos = $q !== '';
$fecha = static fn(?string $utc): string => $utc ? (new DateTimeImmutable($utc))->format('d/m/Y') : '';
$vacio = '<span class="muted">—</span>';
?>
<section class="module">
    <form class="card prov-buscar" method="get" action="/proveedores">
        <label class="field prov-buscar__q"><span class="field__label">Buscar</span>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre del proveedor o placa" data-no-search></label>
        <label class="field"><span class="field__label">Mostrar</span>
            <select name="estado" data-no-search>
                <?php foreach (['activos' => 'Activos', 'desactivados' => 'Desactivados', 'todos' => 'Todos'] as $valor => $texto): ?>
                    <option value="<?= e($valor) ?>"<?= $estado === $valor ? ' selected' : '' ?>><?= e($texto) ?></option>
                <?php endforeach; ?>
            </select></label>
        <div class="prov-buscar__acciones">
            <button type="submit" class="btn btn--ghost-dark">Buscar</button>
            <?php if ($q !== '' || $estado !== 'activos'): ?><a href="/proveedores" class="link">Limpiar</a><?php endif; ?>
        </div>
    </form>

    <?php if ($lista === []): ?>
        <div class="card empty"><div class="card__empty">
            <?php if ($q !== '' || $estado !== 'activos'): ?>
                <p>Nada coincide con la búsqueda. <a href="/proveedores" class="link">Ver todos</a></p>
            <?php else: ?>
                <p>Aún no hay proveedores. Se agregan solos al reservar con un camión contratado, o
                   <button type="button" class="link" data-action="nuevo-proveedor">créalo aquí →</button></p>
            <?php endif; ?>
        </div></div>
    <?php else: ?>
        <div class="card card--table">
            <table class="table tabla-proveedores">
                <thead>
                    <tr><th>Placa cabezal</th><th>Placa furgón</th><th>Motorista</th><th>Licencia</th>
                        <th>Documento</th><th>Teléfono</th><th>Códigos</th><th></th></tr>
                </thead>
                <?php foreach ($lista as $p):
                    $pid = (int) $p['id'];
                    $activo = (int) $p['activo'] === 1;
                    $camiones = $p['camiones'];
                    $meta = [];
                    $meta[] = (int) $p['camiones_activos'] . ' camión' . ((int) $p['camiones_activos'] === 1 ? '' : 'es');
                    $meta[] = (int) $p['viajes'] . ' viaje' . ((int) $p['viajes'] === 1 ? '' : 's');
                    if ($p['ultimo_uso']) {
                        $meta[] = 'último ' . $fecha($p['ultimo_uso']);
                    }
                ?>
                <tbody class="prov-grupo<?= $abiertos ? ' is-open' : '' ?><?= $activo ? '' : ' is-off' ?>" data-grupo="<?= $pid ?>">
                    <tr class="prov-fila">
                        <td colspan="8">
                            <div class="prov-fila__cuerpo">
                                <button type="button" class="prov-fila__toggle" data-action="abrir-grupo" aria-expanded="<?= $abiertos ? 'true' : 'false' ?>">
                                    <svg class="prov-fila__chevron" viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><path d="M7 4l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <strong><?= e($p['nombre']) ?></strong>
                                    <?php if (!$activo): ?><span class="badge badge--muted">Desactivado</span><?php endif; ?>
                                </button>
                                <span class="prov-fila__meta muted"><?= e(implode(' · ', $meta)) ?></span>
                                <?= row_menu([
                                    ['label' => 'Agregar camión', 'attrs' => ['data-action' => 'agregar-camion', 'data-id' => $pid, 'data-nombre' => $p['nombre']]],
                                    ['label' => 'Cambiar nombre', 'attrs' => ['data-action' => 'renombrar-proveedor', 'data-id' => $pid, 'data-nombre' => $p['nombre']]],
                                    ['label' => 'Fusionar con…', 'attrs' => ['data-action' => 'fusionar-proveedor', 'data-id' => $pid, 'data-nombre' => $p['nombre'],
                                        'data-camiones' => count($camiones), 'data-viajes' => (int) $p['viajes']]],
                                    $activo
                                        ? ['label' => 'Desactivar', 'danger' => true, 'attrs' => ['data-action' => 'activo-proveedor', 'data-id' => $pid, 'data-nombre' => $p['nombre'], 'data-activo' => '0']]
                                        : ['label' => 'Activar', 'attrs' => ['data-action' => 'activo-proveedor', 'data-id' => $pid, 'data-nombre' => $p['nombre'], 'data-activo' => '1']],
                                ]) ?>
                            </div>
                        </td>
                    </tr>
                    <?php foreach ($camiones as $c):
                        $cActivo = (int) $c['activo'] === 1;
                        $codigos = array_filter([$c['codigo_nacional'], $c['codigo_internacional']]);
                    ?>
                        <tr class="prov-camion<?= $cActivo ? '' : ' is-off' ?>">
                            <td><strong><?= e($c['placa_motriz']) ?></strong>
                                <?php if (!$cActivo): ?><span class="badge badge--muted">Desactivado</span><?php endif; ?></td>
                            <td><?= $c['placa_arrastre'] ? e($c['placa_arrastre']) : $vacio ?></td>
                            <td><?= $c['piloto'] ? e($c['piloto']) : $vacio ?></td>
                            <td><?= $c['licencia'] ? e($c['licencia']) : $vacio ?></td>
                            <td><?= $c['documento'] ? e($c['documento']) : $vacio ?></td>
                            <td><?= $c['telefonos'] ? e($c['telefonos']) : $vacio ?></td>
                            <td><?= $codigos !== [] ? e(implode(' / ', $codigos)) : $vacio ?></td>
                            <td class="row-actions">
                                <?= row_menu([
                                    ['label' => 'Editar', 'attrs' => ['data-action' => 'editar-camion', 'data-id' => (int) $c['id']]],
                                    $cActivo
                                        ? ['label' => 'Desactivar', 'danger' => true, 'attrs' => ['data-action' => 'activo-camion', 'data-id' => (int) $c['id'], 'data-nombre' => $c['placa_motriz'], 'data-activo' => '0']]
                                        : ['label' => 'Activar', 'attrs' => ['data-action' => 'activo-camion', 'data-id' => (int) $c['id'], 'data-nombre' => $c['placa_motriz'], 'data-activo' => '1']],
                                ]) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="prov-camion prov-camion--pie">
                        <td colspan="8">
                            <?php if ($camiones === []): ?><span class="muted">Sin camiones registrados.</span><?php endif; ?>
                            <button type="button" class="link" data-action="agregar-camion" data-id="<?= $pid ?>" data-nombre="<?= e($p['nombre']) ?>">＋ Agregar camión</button>
                        </td>
                    </tr>
                </tbody>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Nuevo proveedor / cambiar nombre -->
<dialog id="dlg-proveedor" class="dialog">
    <form method="dialog" class="form" id="form-proveedor" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-proveedor-title">Nuevo proveedor</h2>
            <p class="dialog__lede">Mayúsculas, tildes, puntos o «S.A. de C.V.» no cuentan: si ya existe con otra escritura, se te avisa.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
            <label class="field"><span class="field__label">Nombre *</span>
                <input type="text" name="nombre" maxlength="150" required data-mayusculas autocomplete="off"></label>
            <p class="prov-aviso" id="prov-nombre-aviso" hidden></p>
        </div>
        <p class="form__error" id="form-proveedor-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar</button>
        </div>
    </form>
</dialog>

<!-- Camión -->
<dialog id="dlg-camion" class="dialog dialog--ancho">
    <form method="dialog" class="form" id="form-camion" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-camion-title">Agregar camión</h2>
            <p class="dialog__lede" id="dlg-camion-lede"></p>
        </div>
        <input type="hidden" name="id" value="">
        <input type="hidden" name="proveedor_origen" value="">
        <div class="dialog__body">
            <div class="grid-4">
                <!-- Solo al editar: un camión cambia de proveedor cuando cambia de dueño. -->
                <label class="field grid-4__2" id="camion-proveedor-field" hidden><span class="field__label">Proveedor</span>
                    <select name="proveedor_id">
                        <?php foreach ($paraMover as $pm): ?>
                            <option value="<?= (int) $pm['id'] ?>"><?= e($pm['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Placa cabezal *</span>
                    <input type="text" name="placa_motriz" maxlength="30" required data-mayusculas autocomplete="off"></label>
                <label class="field"><span class="field__label">Placa furgón</span>
                    <input type="text" name="placa_arrastre" maxlength="30" data-mayusculas autocomplete="off"></label>
                <label class="field grid-4__2"><span class="field__label">Motorista</span>
                    <input type="text" name="piloto" maxlength="150" data-mayusculas></label>
                <label class="field"><span class="field__label">Licencia</span>
                    <input type="text" name="licencia" maxlength="40" data-mayusculas></label>
                <label class="field"><span class="field__label">Documento</span>
                    <input type="text" name="documento" maxlength="40"></label>
                <label class="field grid-4__2"><span class="field__label">Teléfono</span>
                    <input type="text" name="telefonos" maxlength="255"></label>
                <label class="field"><span class="field__label" title="Código de transporte nacional">Código nacional</span>
                    <input type="text" name="codigo_nacional" maxlength="40"></label>
                <label class="field"><span class="field__label" title="Código de transporte internacional">Código internacional</span>
                    <input type="text" name="codigo_internacional" maxlength="40"></label>
            </div>
        </div>
        <p class="form__error" id="form-camion-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar camión</button>
        </div>
    </form>
</dialog>

<!-- Fusionar -->
<dialog id="dlg-fusionar" class="dialog">
    <form method="dialog" class="form" id="form-fusionar" novalidate>
        <div class="dialog__head">
            <h2>Fusionar proveedor</h2>
            <p class="dialog__lede" id="fusionar-lede"></p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
            <label class="field"><span class="field__label">Pasar todo a *</span>
                <select name="destino_id" required>
                    <option value="">Selecciona el proveedor correcto…</option>
                    <?php foreach ($paraMover as $pm): ?>
                        <option value="<?= (int) $pm['id'] ?>"><?= e($pm['nombre']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <p class="muted prov-nota">El nombre fusionado deja de aparecer, pero quien vuelva a escribirlo llegará al proveedor correcto.</p>
        </div>
        <p class="form__error" id="form-fusionar-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Fusionar</button>
        </div>
    </form>
</dialog>

<?= dialogo_import([
    'titulo'    => 'Carga masiva de proveedores',
    'lede'      => 'Una fila por camión. Si el proveedor ya existe se usa ese, aunque esté escrito distinto. Si una fila falla, no se carga ninguna.',
    'plantilla' => '/proveedores/plantilla.xlsx',
    'url'       => '/api/proveedores/importar',
    'singular'  => 'camión',
    'plural'    => 'camiones',
    'columnas'  => [
        ['clave' => 'proveedor',    'label' => 'Proveedor', 'clase' => 'col col--text'],
        ['clave' => 'placa_motriz', 'label' => 'Placa cabezal'],
        ['clave' => 'piloto',       'label' => 'Motorista', 'clase' => 'col col--text'],
    ],
]) ?>

<script src="<?= e(asset('/assets/js/proveedores.js')) ?>" type="module"></script>
<script src="<?= e(asset('/assets/js/import-excel.js')) ?>" type="module"></script>
