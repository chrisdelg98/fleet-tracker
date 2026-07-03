<?php
/**
 * Dashboard "pantalla de aeropuerto" (plan §7.1). La tabla se puebla desde
 * /api/disponibilidad (cálculo §2); los filtros la recalculan (incluida fecha futura).
 * Disponibilidad visible para todos los roles; reservar solo la propia estación.
 *
 * @var array $usuario @var bool $puedeReservar @var array $estaciones @var array $tiposEquipo
 * @var array $reservables @var array $rutas @var array $pilotos @var string $fechaHoy
 */
?>
<section class="module dashboard">
    <div class="module__head">
        <h1>Disponibilidad de flota</h1>
        <?php if ($puedeReservar): ?>
            <button type="button" class="btn btn--primary" data-action="nueva-reserva">＋ Nueva reserva</button>
        <?php endif; ?>
    </div>

    <div class="filtros card">
        <div class="filtros__fechas" role="group" aria-label="Fecha de consulta">
            <button type="button" class="chipbtn is-active" data-fecha="hoy">Hoy</button>
            <button type="button" class="chipbtn" data-fecha="manana">Mañana</button>
            <button type="button" class="chipbtn" data-fecha="semana">Esta semana</button>
            <input type="date" id="f-fecha" value="<?= e($fechaHoy) ?>" aria-label="Fecha específica">
        </div>
        <div class="filtros__row">
            <label class="field"><span class="field__label">Estación</span>
                <select id="f-estacion">
                    <option value="">Todas</option>
                    <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">Tipo de equipo</span>
                <select id="f-tipo">
                    <option value="">Todos</option>
                    <?php foreach ($tiposEquipo as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">Placa</span>
                <input type="search" id="f-placa" placeholder="Buscar placa…" data-no-search></label>
            <div class="field">
                <span class="field__label">Estado</span>
                <div class="filtros__estados">
                    <label class="check"><input type="checkbox" class="f-estado" value="DISPONIBLE"> 🟢</label>
                    <label class="check"><input type="checkbox" class="f-estado" value="RESERVADA"> 🟡</label>
                    <label class="check"><input type="checkbox" class="f-estado" value="EN_TRANSITO"> 🔵</label>
                    <label class="check"><input type="checkbox" class="f-estado" value="TALLER_BLOQUEADA"> ⚪</label>
                </div>
            </div>
            <label class="check filtros__retorno"><input type="checkbox" id="f-retorno"> Solo con retorno</label>
        </div>
    </div>

    <p class="dashboard__meta"><span id="dash-count">—</span> · <span id="dash-rango" class="muted"></span>
        <button type="button" class="link" data-action="refrescar">Actualizar</button></p>

    <div class="card">
        <table class="table dashboard__table">
            <thead>
                <tr>
                    <th>Unidad</th><th>Tipo / Capacidad</th><th>Estación</th><th>Estado</th>
                    <th>Actividad</th><th>Se libera</th><th>Retorno</th><th>Piloto</th>
                    <?php if ($puedeReservar): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="dash-body">
                <tr><td colspan="9" class="muted" style="text-align:center">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</section>

<?php if ($puedeReservar): ?>
<!-- Diálogo de reserva/movimiento -->
<dialog id="dlg-reserva" class="dialog">
    <form method="dialog" class="form" id="form-reserva" novalidate>
        <h2 id="dlg-reserva-title">Nueva reserva</h2>
        <div class="grid-2">
            <label class="field"><span class="field__label">Unidad *</span>
                <select name="unidad_id" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($reservables as $u): ?>
                        <option value="<?= (int) $u['id'] ?>"><?= e($u['placa_unidad']) ?> · <?= e($u['estacion_codigo']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">Tipo</span>
                <select name="estado">
                    <option value="RESERVADO">Reserva (apartado)</option>
                    <option value="PROGRAMADO">Programado (confirmado)</option>
                </select></label>
            <label class="field grid-2__full"><span class="field__label">Ruta del catálogo</span>
                <select name="ruta_id">
                    <option value="">— Ruta personalizada —</option>
                    <?php foreach ($rutas as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" data-origen="<?= (int) $r['pais_origen_id'] ?>" data-destino="<?= (int) $r['pais_destino_id'] ?>" data-horas="<?= e((string) ($r['horas_transito_estimadas'] ?? '')) ?>"><?= e($r['nombre']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="field ruta-custom"><span class="field__label">País de origen *</span>
                <?= render_paises_select('pais_origen_id', null, false) ?></label>
            <label class="field ruta-custom"><span class="field__label">País de destino *</span>
                <?= render_paises_select('pais_destino_id', null, false) ?></label>
            <label class="field ruta-custom"><span class="field__label">Ciudad origen</span>
                <input type="text" name="ruta_custom_origen" maxlength="150"></label>
            <label class="field ruta-custom"><span class="field__label">Ciudad destino</span>
                <input type="text" name="ruta_custom_destino" maxlength="150"></label>
            <label class="field"><span class="field__label">Salida *</span>
                <input type="datetime-local" name="fecha_salida" required></label>
            <label class="field"><span class="field__label">Se libera (fin estimado) *</span>
                <input type="datetime-local" name="fecha_fin_estimada" required></label>
            <label class="field"><span class="field__label">Piloto</span>
                <select name="piloto_id">
                    <option value="">—</option>
                    <?php foreach ($pilotos as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">Referencia CW</span>
                <input type="text" name="referencia_cw" maxlength="120"></label>
            <label class="field"><span class="field__label">Reservado para</span>
                <input type="text" name="reservado_para" maxlength="150" placeholder="Estación / cliente"></label>
            <label class="field field--check"><span class="field__label">Retorno</span>
                <label class="check"><input type="checkbox" name="retorno_disponible" value="1"> Retorno disponible</label></label>
        </div>
        <p class="form__error" id="form-reserva-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar reserva</button>
        </div>
    </form>
</dialog>

<!-- Diálogo de motivo (cancelar / bloquear) -->
<dialog id="dlg-motivo" class="dialog">
    <form method="dialog" class="form" id="form-motivo" novalidate>
        <h2 id="dlg-motivo-title">Motivo</h2>
        <input type="hidden" name="id" value="">
        <input type="hidden" name="accion" value="">
        <label class="field"><span class="field__label">Motivo *</span>
            <textarea name="motivo" rows="3" required></textarea></label>
        <p class="form__error" id="form-motivo-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cerrar</button>
            <button type="submit" class="btn btn--primary">Confirmar</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<script type="application/json" id="dash-config"><?= json_encode(['puedeReservar' => $puedeReservar], JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/js/dashboard.js" type="module"></script>
