<?php
/**
 * Los dos diálogos del módulo, presentes en todas sus pantallas: registrar una intervención y
 * capturar kilometraje. Van juntos porque se abren desde cualquier sección —si hubiera que ir a
 * otra pantalla para anotar un mantenimiento, no se anotaría.
 *
 * @var array $unidadesParaModal
 * @var array $tiposMantenimiento
 * @var array $talleresParaModal
 * @var array $monedas
 * @var bool  $puedeRegistrar
 */
if (empty($puedeRegistrar)) {
    return;
}
$hoy = (new DateTimeImmutable('today'))->format('Y-m-d');
?>
<dialog id="dlg-mantenimiento" class="dialog dialog--ancho">
    <form method="dialog" class="form" id="form-mantenimiento" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-mantenimiento-title">Registrar mantenimiento</h2>
            <p class="dialog__lede">Anota el kilometraje: con él, la fecha del próximo servicio se calcula sola.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
            <div class="grid-4">
                <label class="field grid-4__2"><span class="field__label">Unidad *</span>
                    <select name="unidad_id" required>
                        <option value="">Selecciona…</option>
                        <?php foreach ($unidadesParaModal as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" data-estacion="<?= (int) $u['estacion_id'] ?>">
                                <?= e($u['placa_unidad']) ?> · <?= e($u['estacion_codigo']) ?><?= $u['marca'] ? ' · ' . e($u['marca']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Fecha *</span>
                    <input type="date" name="fecha" required max="<?= e($hoy) ?>" value="<?= e($hoy) ?>"></label>
                <label class="field"><span class="field__label">Kilometraje</span>
                    <input type="text" name="km" inputmode="numeric" autocomplete="off" placeholder="Ej.: 12849"></label>

                <label class="field grid-4__2"><span class="field__label">Tipo *</span>
                    <select name="tipo_mantenimiento_id" required>
                        <?php foreach ($tiposMantenimiento as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" data-reinicia="<?= (int) $t['reinicia_ciclo'] ?>"><?= e($t['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="field grid-4__2"><span class="field__label">Descripción</span>
                    <input type="text" name="descripcion" maxlength="255" placeholder="Cambio de aceite y filtros"></label>

                <!-- El taller se elige de la lista o se escribe: uno nuevo se agrega solo, sin
                     obligar a salir del registro para darlo de alta. -->
                <label class="field grid-4__2"><span class="field__label">Taller</span>
                    <select name="taller_id" id="mant-taller">
                        <option value="">— Elegir o escribir uno nuevo —</option>
                        <?php foreach ($talleresParaModal as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" data-estacion="<?= (int) $t['estacion_id'] ?>">
                                <?= e($t['nombre']) ?><?= (int) $t['es_propio'] === 1 ? ' · propio' : '' ?> · <?= e($t['estacion_codigo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="field grid-4__2"><span class="field__label">…o taller nuevo</span>
                    <input type="text" name="taller_nuevo" maxlength="150" placeholder="Nombre del taller" data-mayusculas autocomplete="off"></label>

                <label class="field"><span class="field__label">Costo</span>
                    <input type="text" name="costo" inputmode="decimal" autocomplete="off" placeholder="Sin factura aún: déjalo vacío"></label>
                <label class="field"><span class="field__label">Moneda</span>
                    <select name="moneda_id" id="mant-moneda" data-no-search>
                        <?php foreach ($monedas as $m): ?>
                            <option value="<?= (int) $m['id'] ?>" data-tasa="<?= e((string) $m['por_dolar']) ?>"><?= e($m['codigo']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="field"><span class="field__label">Tasa usada</span>
                    <input type="text" name="tasa_usada" inputmode="decimal" autocomplete="off">
                    <small class="field__note" id="mant-conversion" hidden></small></label>
                <label class="field"><span class="field__label">Factura</span>
                    <input type="text" name="factura" maxlength="60" autocomplete="off"></label>

                <label class="field grid-4__2"><span class="field__label">Observaciones</span>
                    <input type="text" name="observaciones" maxlength="255"></label>
                <label class="field grid-4__2"><span class="field__label">Nota del odómetro</span>
                    <input type="text" name="nota_odometro" maxlength="160" placeholder="Solo si el odómetro se reemplazó o está dañado"></label>

                <label class="check check--box grid-4__full" id="mant-reinicia-wrap">
                    <input type="checkbox" name="reinicia_ciclo" value="1">
                    <span>Reinicia el ciclo de servicio
                        <small class="block muted">Se marca solo según el tipo. Quítalo o ponlo si esta vez fue distinto.</small>
                    </span></label>
            </div>
        </div>
        <p class="form__error" id="form-mantenimiento-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar</button>
        </div>
    </form>
</dialog>

<dialog id="dlg-km" class="dialog dialog--ancho">
    <form method="dialog" class="form" id="form-km" novalidate>
        <div class="dialog__head">
            <h2>Capturar kilometraje</h2>
            <p class="dialog__lede">Escribe solo las unidades que vas a actualizar; las demás se quedan como están.</p>
        </div>
        <div class="dialog__body">
            <label class="field" style="max-width:220px"><span class="field__label">Fecha de la lectura *</span>
                <input type="date" name="fecha" required max="<?= e($hoy) ?>" value="<?= e($hoy) ?>"></label>
            <div class="card card--table">
                <table class="table">
                    <thead><tr><th>Unidad</th><th>Estación</th><th>Kilometraje</th><th>Nota (si el odómetro cambió)</th></tr></thead>
                    <tbody id="km-filas"></tbody>
                </table>
            </div>
        </div>
        <p class="form__error" id="form-km-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar kilometrajes</button>
        </div>
    </form>
</dialog>

<script type="application/json" id="mant-unidades"><?= json_encode(array_map(
    static fn(array $u): array => [
        'id' => (int) $u['id'],
        'placa' => $u['placa_unidad'],
        'estacion' => $u['estacion_codigo'],
        'estacion_id' => (int) $u['estacion_id'],
    ],
    $unidadesParaModal
), JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('/assets/js/mantenimientos.js')) ?>" type="module"></script>
