<?php
/**
 * Dashboard "pantalla de aeropuerto" (plan §7.1). La tabla se puebla desde
 * /api/disponibilidad (cálculo §2); los filtros la recalculan (incluida fecha futura).
 * Disponibilidad visible para todos los roles; reservar solo la propia estación.
 *
 * @var array $usuario
 * @var bool $puedeReservar
 * @var array $estaciones
 * @var array $categorias
 * @var array $tiposEquipo
 * @var array $reservables
 * @var array $rutas
 * @var array $pilotos
 * @var string $fechaHoy
 */
set_page_meta(
    'Dashboard',
    'Consulta flota disponible, movimientos activos y retornos aprovechables en una sola vista operativa.',
    ['accion' => $puedeReservar ? '<button type="button" class="btn btn--primary" data-action="nueva-reserva">＋ Nueva reserva</button>' : '']
);
?>
<section class="module dashboard">
    <div class="filters-panel filters-panel--split card dashboard__filters-card" data-filters-panel data-initial-open="false">
        <div class="filters-panel__always">
            <div class="filters-panel__always-row">
                <div class="filters-panel__always-main">
                    <div class="filtros__fechas" role="group" aria-label="Fecha de consulta">
                        <button type="button" class="chipbtn is-active" data-fecha="hoy">Hoy</button>
                        <button type="button" class="chipbtn" data-fecha="manana">Mañana</button>
                        <button type="button" class="chipbtn" data-fecha="semana">Esta semana</button>
                    </div>

                    <?php
                    // Solo las categorías de flota operativa: el resto no llega al tablero, y
                    // una pastilla que siempre devuelve cero estorba más de lo que ayuda.
                    $categoriasRapidas = array_values(array_filter(
                        $categorias,
                        static fn(array $c): bool => (int) $c['es_flota_operativa'] === 1
                    ));
                    ?>
                    <?php if ($categoriasRapidas): ?>
                        <div class="filtros__categorias" role="group" aria-label="Filtrar por categoría">
                            <?php foreach ($categoriasRapidas as $c): ?>
                                <button type="button" class="catbtn" data-categoria="<?= (int) $c['id'] ?>" aria-pressed="false"><?= e($c['nombre']) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="dashboard__status">
                    <strong id="dash-count">—</strong>
                    <span id="dash-rango" class="muted"></span>
                    <span id="dash-demora" class="dashboard__delay" hidden><span class="dashboard__delay-icon" aria-hidden="true">!</span><span id="dash-demora-text">0 con demora</span></span>
                    <!-- Cierra la fila: lo que se usa de vez en cuando va al final, después
                         de lo que se lee siempre (los atajos de fecha y el recuento). -->
                    <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="dashboard-filters-more">
                        <span data-filters-toggle-label data-open-label="Más filtros" data-close-label="Ocultar filtros">Más filtros</span>
                        <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
                    </button>
                </div>
            </div>
            <div class="filtros-activos" id="dash-chips" hidden></div>
        </div>
        <div class="filters-panel__more" id="dashboard-filters-more" data-filters-more hidden>
        <div class="filters-grid filters-grid--dashboard">
            <label class="field"><span class="field__label">Fecha específica</span>
                <input type="date" id="f-fecha" value="<?= e($fechaHoy) ?>"></label>
            <label class="field"><span class="field__label">Retorno desde</span>
                <?= render_paises_select('retorno_desde_sel', null, false, 'Cualquier país') ?></label>
            <label class="field"><span class="field__label">Retorno disponible</span>
                <select id="f-retorno" data-no-search>
                    <option value="">Todos</option>
                    <option value="1">Sí</option>
                    <option value="0">No</option>
                </select></label>
            <div class="field field--state-filter">
                <span class="field__label">Estado</span>
                <div class="state-select" id="f-estados-wrap">
                    <button type="button" class="state-select__toggle" id="f-estados-toggle" aria-expanded="false" aria-controls="f-estados-menu">
                        <span id="f-estados-summary">Todos los estados</span>
                        <span class="state-select__chevron" aria-hidden="true">▾</span>
                    </button>
                    <div class="state-select__menu filtros__estados filtros__estados--legend" id="f-estados-menu" hidden>
                        <label class="estado-chip"><input type="checkbox" class="f-estado" value="DISPONIBLE"><span class="estado-chip__dot estado-chip__dot--disponible"></span><span>Disponible</span></label>
                        <label class="estado-chip"><input type="checkbox" class="f-estado" value="RESERVADA"><span class="estado-chip__dot estado-chip__dot--reservada"></span><span>Reservada</span></label>
                        <label class="estado-chip"><input type="checkbox" class="f-estado" value="EN_TRANSITO"><span class="estado-chip__dot estado-chip__dot--transito"></span><span>En tránsito</span></label>
                        <label class="estado-chip"><input type="checkbox" class="f-estado" value="EN_CLIENTE"><span class="estado-chip__dot estado-chip__dot--cliente"></span><span>Con cliente</span></label>
                        <label class="estado-chip"><input type="checkbox" class="f-estado" value="TALLER_BLOQUEADA"><span class="estado-chip__dot estado-chip__dot--taller"></span><span>Taller/Bloqueada</span></label>
                    </div>
                </div>
            </div>
            <label class="field"><span class="field__label">Estación</span>
                <select id="f-estacion">
                    <option value="">Todas</option>
                    <?php foreach ($estaciones as $es): ?><option value="<?= (int) $es['id'] ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">Placa</span>
                <input type="search" id="f-placa" placeholder="Buscar placa…" data-no-search></label>
            <label class="field"><span class="field__label">Categoría</span>
                <select id="f-categoria">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">Alcance</span>
                <select id="f-alcance" data-no-search>
                    <option value="">Todas</option>
                    <option value="1">Puede salir del país</option>
                    <option value="0">Solo rutas nacionales</option>
                </select></label>
            <label class="field"><span class="field__label">Tipo de equipo</span>
                <select id="f-tipo">
                    <option value="">Todos</option>
                    <?php foreach ($tiposEquipo as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field field--delay-filter"><span class="field__label">Demora</span>
                <label class="delay-toggle"><input type="checkbox" id="f-demora" value="1"><span>Solo con demora</span></label>
            </label>
        </div>
        </div>
    </div>

    <div class="card card--table dashboard__results-card">
        <table class="table dashboard__table">
            <thead>
                <tr>
                    <th>Unidad</th><th>Equipo</th><th>Estación</th><th>Estado</th>
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
<dialog id="dlg-reserva" class="dialog dialog--ancho">
    <form method="dialog" class="form" id="form-reserva" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-reserva-title">Nueva reserva</h2>
            <p class="dialog__lede">Programa una salida sin romper traslapes y deja definidos ruta, fechas y retorno desde el mismo flujo.</p>
        </div>
        <div class="dialog__body">
        <!-- Cuatro columnas y sin secciones: el formulario se lee de un barrido y ninguna
             fila queda a medias (los tramos completan lo que sobra). -->
        <div class="grid-4">
            <label class="field"><span class="field__label">Unidad *</span>
                <select name="unidad_id" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($reservables as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" data-piloto="<?= (int) ($u['piloto_asignado_id'] ?? 0) ?>"><?= e($u['placa_unidad']) ?> · <?= e($u['estacion_codigo']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">Tipo</span>
                <select name="estado">
                    <option value="RESERVADO">Reserva (apartado)</option>
                    <option value="PROGRAMADO">Programado (confirmado)</option>
                </select></label>
            <label class="field"><span class="field__label">Cabezal</span>
                <select name="apoyo_motriz_id" data-apoyo="motriz">
                    <option value="">— Ninguno / del cliente —</option>
                    <?php foreach ($reservables as $u): if ((int) $u['es_motriz'] !== 1) continue; ?>
                        <option value="<?= (int) $u['id'] ?>" data-estacion="<?= (int) $u['estacion_id'] ?>"><?= e($u['placa_unidad']) ?> · <?= e($u['categoria']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="field"><span class="field__label">Chasis o equipo</span>
                <select name="apoyo_arrastre_id" data-apoyo="arrastre">
                    <option value="">— Ninguno —</option>
                    <?php foreach ($reservables as $u): if ((int) $u['es_motriz'] === 1) continue; ?>
                        <option value="<?= (int) $u['id'] ?>" data-estacion="<?= (int) $u['estacion_id'] ?>"><?= e($u['placa_unidad']) ?> · <?= e($u['categoria']) ?></option>
                    <?php endforeach; ?>
                </select></label>

            <label class="field"><span class="field__label">Piloto <span class="field__warn" id="piloto-warn" hidden>Licencia vencida</span></span>
                <select name="piloto_id">
                    <option value="">—</option>
                    <?php
                    // La licencia vencida no bloquea (el movimiento puede ser de otro piloto),
                    // pero la marca viaja en la opción para advertirlo al seleccionarla.
                    $hoyLic = new DateTimeImmutable('today');
                    foreach ($pilotos as $p):
                        $vencida = !empty($p['licencia_vence']) && new DateTimeImmutable($p['licencia_vence']) < $hoyLic;
                    ?>
                        <option value="<?= (int) $p['id'] ?>"<?= $vencida ? ' data-licencia-vencida="1"' : '' ?>><?= e($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="field grid-4__3"><span class="field__label">Ruta del catálogo</span>
                <select name="ruta_id">
                    <option value="">— Ruta personalizada —</option>
                    <?php foreach ($rutas as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" data-origen="<?= (int) $r['pais_origen_id'] ?>" data-destino="<?= (int) $r['pais_destino_id'] ?>" data-horas="<?= e((string) ($r['horas_transito_estimadas'] ?? '')) ?>"><?= e($r['nombre']) ?></option>
                    <?php endforeach; ?>
                </select></label>

            <label class="field ruta-custom"><span class="field__label">País de origen *</span>
                <?= render_paises_select('pais_origen_id', null, false) ?></label>
            <label class="field ruta-custom"><span class="field__label">Ciudad origen</span>
                <input type="text" name="ruta_custom_origen" maxlength="150"></label>
            <label class="field ruta-custom"><span class="field__label">País de destino *</span>
                <?= render_paises_select('pais_destino_id', null, false) ?></label>
            <label class="field ruta-custom"><span class="field__label">Ciudad destino</span>
                <input type="text" name="ruta_custom_destino" maxlength="150"></label>

            <label class="field"><span class="field__label">Salida *</span>
                <input type="datetime-local" name="fecha_salida" required></label>
            <label class="field"><span class="field__label">Se libera *</span>
                <input type="datetime-local" name="fecha_fin_estimada" required></label>
            <label class="field"><span class="field__label">Reservado para</span>
                <input type="text" name="reservado_para" maxlength="150" placeholder="Estación / cliente"></label>
            <label class="field"><span class="field__label">Referencia CW</span>
                <input type="text" name="referencia_cw" maxlength="120"></label>

            <label class="check check--box grid-4__2"><input type="checkbox" name="retorno_disponible" value="1"><span>Retorno disponible</span></label>
            <label class="check check--box grid-4__2"><input type="checkbox" name="queda_con_cliente" value="1"><span>El equipo queda con el cliente</span></label>
        </div>
        </div>
        <p class="form__warn" id="reserva-conflicto" hidden></p>
        <p class="form__error" id="form-reserva-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar reserva</button>
        </div>
    </form>
</dialog>

<!-- Diálogo de motivo (cancelar / bloquear) -->
<!-- Panel de la unidad: estado, compañeros de viaje y acciones posibles, en un clic. -->
<dialog id="dlg-unidad" class="dialog dialog--panel">
    <div class="panel" id="panel-unidad">
        <button type="button" class="panel__cerrar" data-close aria-label="Cerrar">&times;</button>
        <div id="panel-unidad-cuerpo"></div>
    </div>
</dialog>

<dialog id="dlg-reprogramar" class="dialog">
    <form method="dialog" class="form" id="form-reprogramar" novalidate>
        <div class="dialog__head">
            <h2>Cambiar fecha de fin</h2>
            <p class="dialog__lede">Ajusta el fin estimado cuando el viaje se alarga por acuerdo con el cliente. Queda registrado en bitácora con su motivo.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
            <div class="grid-2">
                <label class="field"><span class="field__label">Fin estimado actual</span>
                    <input type="datetime-local" id="reprogramar-actual" disabled></label>
                <label class="field"><span class="field__label">Nuevo fin estimado *</span>
                    <input type="datetime-local" name="fecha_fin_estimada" required></label>
            </div>
            <label class="field"><span class="field__label">Motivo del cambio *</span>
                <textarea name="motivo" rows="3" required placeholder="Ej.: el cliente pidió dos días más de descarga"></textarea></label>
        </div>
        <p class="form__error" id="form-reprogramar-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar cambio</button>
        </div>
    </form>
</dialog>

<dialog id="dlg-motivo" class="dialog">
    <form method="dialog" class="form" id="form-motivo" novalidate>
        <div class="dialog__head">
            <h2 id="dlg-motivo-title">Motivo</h2>
            <p class="dialog__lede">Documenta la razón del cambio para mantener la trazabilidad operativa en bitácora.</p>
        </div>
        <input type="hidden" name="id" value="">
        <input type="hidden" name="accion" value="">
        <div class="dialog__body">
            <label class="field"><span class="field__label">Motivo *</span>
                <textarea name="motivo" rows="3" required></textarea></label>
        </div>
        <p class="form__error" id="form-motivo-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cerrar</button>
            <button type="submit" class="btn btn--primary">Confirmar</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($puedeReservar): ?>
<!-- Diálogo apartar retorno -->
<dialog id="dlg-retorno" class="dialog">
    <form method="dialog" class="form" id="form-retorno" novalidate>
        <div class="dialog__head">
            <h2>Apartar retorno</h2>
            <p class="dialog__lede">Convierte un retorno disponible en un nuevo movimiento de regreso sobre la misma unidad.</p>
        </div>
        <input type="hidden" name="id" value="">
        <div class="dialog__body">
        <p class="muted">Se creará un movimiento de regreso sobre la misma unidad, saliendo desde donde está el equipo.</p>
        <div class="grid-2">
            <label class="field"><span class="field__label">Destino del retorno *</span>
                <?= render_paises_select('pais_destino_id', null, false, 'País de origen de la ida') ?></label>
            <label class="field"><span class="field__label">Ciudad destino</span>
                <input type="text" name="ruta_custom_destino" maxlength="150"></label>
            <label class="field"><span class="field__label">Salida del retorno *</span>
                <input type="datetime-local" name="fecha_salida" required></label>
            <label class="field"><span class="field__label">Se libera *</span>
                <input type="datetime-local" name="fecha_fin_estimada" required></label>
            <label class="field"><span class="field__label">Quién lo toma</span>
                <input type="text" name="reservado_para" maxlength="150" placeholder="Estación o cliente externo"></label>
            <label class="field"><span class="field__label">País que solicita el retorno
                    <button type="button" class="infotip" aria-label="Qué significa el país que solicita el retorno"
                            data-infotip="País desde donde piden aprovechar el viaje de vuelta, en lugar de que el equipo regrese vacío. Normalmente es el país donde quedó la unidad, que avisa a la estación dueña para que se lo asigne. Déjalo vacío si quien lo pide es un cliente externo.">i</button>
                </span>
                <?= render_paises_select('pais_solicita_retorno_id', null, false, 'Opcional') ?></label>
        </div>
        </div>
        <p class="form__error" id="form-retorno-error" hidden></p>
        <div class="dialog__actions">
            <button type="button" class="btn btn--ghost-dark" data-close>Cancelar</button>
            <button type="submit" class="btn btn--primary">Apartar retorno</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<script type="application/json" id="dash-config"><?= json_encode(['puedeReservar' => $puedeReservar], JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/js/dashboard.js" type="module"></script>
