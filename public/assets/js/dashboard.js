/**
 * Dashboard de disponibilidad (plan §7.1). Vive del endpoint /api/disponibilidad: los
 * filtros (incluida fecha futura) recalculan el estado de toda la flota (§2). Auto-refresh
 * cada 60s. Las horas se muestran en la timezone de cada estación (Intl), la BD está en UTC.
 */
import { api, showError, mensajeError } from './api.js';
import { confirmar } from './confirm.js';

const cfg = JSON.parse(document.getElementById('dash-config').textContent);
const body = document.getElementById('dash-body');
const countEl = document.getElementById('dash-count');
const rangoEl = document.getElementById('dash-rango');
const demoraWrapEl = document.getElementById('dash-demora');
const demoraTextEl = document.getElementById('dash-demora-text');
const stateSelectWrap = document.getElementById('f-estados-wrap');
const stateSelectToggle = document.getElementById('f-estados-toggle');
const stateSelectMenu = document.getElementById('f-estados-menu');
const stateSelectSummary = document.getElementById('f-estados-summary');

// El color del estado lo pinta el punto de .chip::before; la etiqueta va en texto plano.
const CHIP = {
    DISPONIBLE: ['chip--disponible', 'Disponible'],
    RESERVADA: ['chip--reservada', 'Reservada'],
    EN_TRANSITO: ['chip--transito', 'En tránsito'],
    EN_CLIENTE: ['chip--cliente', 'Con cliente'],
    TALLER_BLOQUEADA: ['chip--taller', 'Taller/Bloqueada'],
};

let fechaMode = 'hoy';
const colspan = cfg.puedeReservar ? 9 : 8;

const STATE_LABELS = {
    DISPONIBLE: 'Disponible',
    RESERVADA: 'Reservada',
    EN_TRANSITO: 'En tránsito',
    EN_CLIENTE: 'Con cliente',
    TALLER_BLOQUEADA: 'Taller/Bloqueada',
};

// ── Filtros → query ──
function buildQuery() {
    const p = new URLSearchParams();
    const hoy = new Date();
    const iso = (d) => d.toISOString().slice(0, 10);
    if (fechaMode === 'hoy') {
        p.set('fecha', document.getElementById('f-fecha').value || iso(hoy));
    } else if (fechaMode === 'manana') {
        const m = new Date(hoy); m.setDate(m.getDate() + 1); p.set('fecha', iso(m));
    } else if (fechaMode === 'semana') {
        const f = new Date(hoy); f.setDate(f.getDate() + 6);
        p.set('desde', iso(hoy)); p.set('hasta', iso(f));
    } else {
        p.set('fecha', document.getElementById('f-fecha').value || iso(hoy));
    }
    const est = document.getElementById('f-estacion').value;
    if (est) p.set('estacion_id', est);
    const categoria = document.getElementById('f-categoria').value;
    if (categoria) p.set('categoria_id', categoria);
    const tipo = document.getElementById('f-tipo').value;
    if (tipo) p.set('tipo_equipo_id', tipo);
    const alcance = document.getElementById('f-alcance').value;
    if (alcance !== '') p.set('internacional', alcance);
    const placa = document.getElementById('f-placa').value.trim();
    if (placa) p.set('placa', placa);
    const estados = [...document.querySelectorAll('.f-estado:checked')].map((c) => c.value);
    if (estados.length) p.set('estado', estados.join(','));
    const retorno = document.getElementById('f-retorno').value;
    if (retorno === '1') p.set('solo_retorno', '1');
    if (retorno === '0') p.set('sin_retorno', '1');
    if (document.getElementById('f-demora').checked) p.set('solo_demora', '1');
    const rd = document.querySelector('[name="retorno_desde_sel"]');
    if (rd && rd.value) p.set('retorno_desde', rd.value);
    return p;
}

function selectedStates() {
    return [...document.querySelectorAll('.f-estado:checked')].map((c) => c.value);
}

function syncStateSummary() {
    const estados = selectedStates();
    if (estados.length === 0) {
        stateSelectSummary.textContent = 'Todos los estados';
        return;
    }
    if (estados.length === 1) {
        stateSelectSummary.textContent = STATE_LABELS[estados[0]] || estados[0];
        return;
    }
    if (estados.length === 2) {
        stateSelectSummary.textContent = estados.map((estado) => STATE_LABELS[estado] || estado).join(', ');
        return;
    }
    stateSelectSummary.textContent = `${estados.length} estados seleccionados`;
}

function openStateMenu() {
    stateSelectMenu.hidden = false;
    stateSelectToggle.setAttribute('aria-expanded', 'true');
    stateSelectWrap.classList.add('is-open');
}

function closeStateMenu() {
    stateSelectMenu.hidden = true;
    stateSelectToggle.setAttribute('aria-expanded', 'false');
    stateSelectWrap.classList.remove('is-open');
}

async function load() {
    pintarChips();
    const resp = await api('GET', `/api/disponibilidad?${buildQuery()}`);
    if (!resp.ok) { body.innerHTML = `<tr><td colspan="${colspan}" class="muted">No se pudo cargar.</td></tr>`; return; }
    render(resp.data.unidades, resp.data);
}

let ultimasUnidades = [];

function render(unidades, meta) {
    window.closeRowMenus?.();
    ultimasUnidades = unidades;
    countEl.textContent = `${unidades.length} unidad${unidades.length === 1 ? '' : 'es'}`;
    // El rango solo aporta cuando abarca varios días: con "Hoy" repite lo que ya dice el chip.
    const desde = fmtDia(meta.desde);
    const hasta = fmtDia(meta.hasta);
    rangoEl.textContent = desde === hasta ? '' : `${desde} → ${hasta}`;
    const demoraCount = unidades.filter((u) => u.con_demora).length;
    demoraWrapEl.hidden = demoraCount === 0;
    demoraTextEl.textContent = `${demoraCount} ${demoraCount === 1 ? 'demora' : 'demoras'}`;
    if (!unidades.length) {
        body.innerHTML = `<tr><td colspan="${colspan}" class="muted" style="text-align:center">Sin unidades para estos filtros.</td></tr>`;
        return;
    }
    body.innerHTML = unidades.map(rowHtml).join('');
}

// Antes de pedir una unidad a otra estación hay que saber si puede cruzar la frontera; que el
// dato viva solo en el formulario de edición obliga a abrir cada unidad para descartarla.
function alcanceBadge(u) {
    return u.puede_internacional
        ? `<span class="alcance alcance--int" data-infotip="Tiene permiso vigente para cruzar frontera: sirve para rutas internacionales.">INT</span>`
        : `<span class="alcance alcance--nac" data-infotip="Sin permiso internacional: solo puede hacer rutas dentro de su país.">NAC</span>`;
}

function rowHtml(u) {
    const [cls, label] = CHIP[u.estado] || ['chip--muted', u.estado];
    const m = u.movimiento;
    const demora = u.con_demora ? '<span class="delay-flag"><span class="delay-flag__icon" aria-hidden="true">!</span><span>Con demora</span></span>' : '';
    const juntos = (m?.acompanantes || []).length
        ? `<small class="muted block">Va con ${esc(m.acompanantes.map((c) => c.placa).join(', '))} · Mov #${esc(m.id)}</small>`
        : '';
    const actividad = m
        ? `${esc(m.origen || '?')} → ${esc(m.destino || '?')} <small class="muted">· sale ${fmtLibera(m.fecha_salida, u.timezone)}</small>${juntos}`
        : (u.override ? `<span class="muted">${esc(u.override.motivo || u.override.tipo)}</span>` : '—');
    const libera = m ? fmtLibera(m.fecha_fin_estimada, u.timezone) : '—';
    let retorno = '—';
    if (m && m.retorno_disponible) {
        if (m.regreso_id) {
            // El detalle (a dónde va y quién lo pidió) se consulta en el popover del badge,
            // para no cargar la celda: en la tabla basta con saber que ya está tomado.
            const datos = [
                ['Movimiento', `#${m.regreso_id}`],
                ['Ruta', m.regreso_ruta || '—'],
                ['Salida', fmtFecha(m.regreso_salida, u.timezone)],
                ['Fin estimado', fmtFecha(m.regreso_fin, u.timezone)],
                ['Solicita', m.regreso_para || m.retorno_iso || 'Sin registrar'],
            ];
            retorno = `<button type="button" class="retorno retorno--tomado"
                data-infotip-titulo="Retorno tomado"
                data-infotip-datos="${esc(JSON.stringify(datos))}"
                aria-label="Ver detalle del retorno">↩ Retorno tomado</button>`;
        } else {
            retorno = `<span class="retorno">↩ Retorno disponible</span>`;
        }
    }
    return `<tr>
        <td><strong>${esc(u.placa_unidad)}</strong> ${alcanceBadge(u)}${u.placa_furgon ? `<small class="muted block">${esc(u.placa_furgon)}</small>` : ''}</td>
        <td>${esc(u.tipo_equipo || '—')}${u.capacidad ? ` · ${esc(u.capacidad)}` : ''}</td>
        <td>${esc(u.estacion_codigo)}</td>
        <td><span class="chip ${cls}">${label}</span>${demora ? `<small class="block delay-flag__wrap">${demora}</small>` : ''}</td>
        <td>${actividad}</td>
        <td>${libera}</td>
        <td>${retorno}</td>
        <td>${esc(u.piloto || '—')}</td>
        ${cfg.puedeReservar ? `<td class="row-actions">${accionesHtml(u)}</td>` : ''}
    </tr>`;
}

const KEBAB = '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M10 6.2a1.4 1.4 0 1 0 0-2.8 1.4 1.4 0 0 0 0 2.8Zm0 5.2a1.4 1.4 0 1 0 0-2.8 1.4 1.4 0 0 0 0 2.8Zm0 5.2a1.4 1.4 0 1 0 0-2.8 1.4 1.4 0 0 0 0 2.8Z" fill="currentColor"/></svg>';

function accionesHtml(u) {
    const m = u.movimiento;
    const item = (accion, txt, danger = false) => `<button type="button" role="menuitem" class="rowmenu__item${danger ? ' rowmenu__item--danger' : ''}" data-mov="${accion}" data-unidad="${u.unidad_id}"${m ? ` data-id="${m.id}"` : ''}>${txt}</button>`;
    const acc = [];
    let cancelar = null; // Cancelar siempre va al final del menú.
    if (u.estado === 'DISPONIBLE') {
        acc.push(item('reservar', 'Reservar'), item('bloquear', 'Bloquear'));
    } else if (u.override && u.override.tipo === 'BLOQUEADA') {
        acc.push(item('desbloquear', 'Desbloquear'));
    } else if (m && m.estado === 'RESERVADO') {
        acc.push(item('confirmar', 'Confirmar'));
        cancelar = item('cancelar', 'Cancelar', true);
    } else if (m && m.estado === 'PROGRAMADO') {
        acc.push(item('salida', 'Marcar salida'), item('reprogramar', 'Cambiar fecha de fin'));
        cancelar = item('cancelar', 'Cancelar', true);
    } else if (m && m.estado === 'EN_TRANSITO') {
        acc.push(item('llegada', 'Marcar llegada'), item('reprogramar', 'Cambiar fecha de fin'));
        cancelar = item('cancelar', 'Cancelar', true);
    }
    if (m && (m.acompanantes || []).length && m.unidad_id !== u.unidad_id) {
        acc.push(item('liberar', 'Liberar de este viaje'));
    }
    if (m && m.retorno_disponible && !m.regreso_id) {
        acc.push(item('apartar-retorno', 'Apartar retorno'));
    }
    if (cancelar) acc.push(cancelar);
    if (!acc.length) return '<span class="muted">—</span>';
    return `<div class="rowmenu" data-rowmenu>
        <button type="button" class="rowmenu__trigger" data-rowmenu-trigger aria-haspopup="true" aria-expanded="false" aria-label="Acciones">${KEBAB}</button>
        <div class="rowmenu__menu" role="menu">${acc.join('')}</div>
    </div>`;
}

// ── Acciones por fila (delegación en document: cubre el menú porteado a <body>) ──
if (cfg.puedeReservar) {
    document.addEventListener('click', async (ev) => {
        const b = ev.target.closest('[data-mov]');
        if (!b) return;
        // El panel y el menú comparten estos botones: al disparar una acción, el panel cierra.
        if (dlgUnidad?.open) dlgUnidad.close();
        const { mov, id, unidad } = b.dataset;
        if (mov === 'reservar') return abrirReserva(unidad);
        if (mov === 'bloquear') return abrirMotivo('bloquear', unidad);
        if (mov === 'cancelar') return abrirMotivo('cancelar', id);
        if (mov === 'liberar') {
            const ok = await confirmar({
                titulo: 'Liberar del viaje',
                mensaje: 'Este activo queda disponible; el movimiento sigue abierto con el resto.',
                aceptar: 'Liberar',
            });
            if (!ok) return;
            return postAccion(`/api/movimientos/${id}/liberar/${unidad}`);
        }
        if (mov === 'reprogramar') {
            const u = ultimasUnidades.find((x) => String(x.movimiento?.id) === String(id));
            return abrirReprogramar(id, u?.movimiento, u?.timezone);
        }
        if (mov === 'apartar-retorno') {
            const u = ultimasUnidades.find((x) => String(x.movimiento?.id) === String(id));
            return abrirRetorno(id, u?.movimiento, u?.timezone);
        }
        if (mov === 'desbloquear') return postAccion(`/api/unidades/${unidad}/desbloquear`);
        if (mov === 'confirmar') return postAccion(`/api/movimientos/${id}/confirmar`);
        if (mov === 'llegada') return postAccion(`/api/movimientos/${id}/llegada`);
        if (mov === 'salida') {
            const r = await api('POST', `/api/movimientos/${id}/salida`, {});
            if (r.ok) load(); else alert(mensajeError(r, 'No se pudo marcar la salida.'));
        }
    });
}

async function postAccion(url) {
    const r = await api('POST', url, {});
    if (r.ok) load(); else alert(mensajeError(r));
}

// ── Formulario de reserva ──
const dlgReserva = document.getElementById('dlg-reserva');
const formReserva = document.getElementById('form-reserva');
const errReserva = document.getElementById('form-reserva-error');
const warnReserva = document.getElementById('reserva-conflicto');

// Aviso en vivo: al elegir unidad/piloto + fechas, consulta si el rango se traslapa con otro
// movimiento activo (el guardado igual lo valida en el servidor, plan §8).
let conflictoTimer;

/** Frase de aviso para una lista de traslapes; `sujeto` ya viene redactado. */
function avisoTraslape(sujeto, cs) {
    const c = cs[0];
    const extra = cs.length > 1 ? ` y ${cs.length - 1} más` : '';
    return `⚠ ${sujeto} ya tiene un movimiento <strong>${esc(c.estado)}</strong> del <strong>${esc(c.desde)}</strong>`
        + ` al <strong>${esc(c.hasta)}</strong> (mov. #${esc(c.id)})${extra}.`;
}

async function checkConflicto() {
    if (!formReserva || !warnReserva) return;
    const unidad = formReserva.elements['unidad_id'].value;
    const piloto = formReserva.elements['piloto_id']?.value || '';
    const salida = formReserva.elements['fecha_salida'].value;
    const fin = formReserva.elements['fecha_fin_estimada'].value;
    if (!unidad || !salida || !fin) { warnReserva.hidden = true; return; }

    const qs = new URLSearchParams({ unidad_id: unidad, fecha_salida: salida, fecha_fin_estimada: fin });
    if (piloto) qs.set('piloto_id', piloto);
    const r = await api('GET', `/api/movimientos/conflictos?${qs}`);
    const datos = (r.ok && r.data) ? r.data : {};

    const avisos = [];
    if (datos.unidad?.length) avisos.push(avisoTraslape('Esta unidad', datos.unidad));
    if (datos.piloto?.length) avisos.push(avisoTraslape(esc(datos.piloto[0].piloto), datos.piloto));

    if (avisos.length) {
        warnReserva.innerHTML = `${avisos.join('<br>')} Ese horario se rechazará al guardar.`;
        warnReserva.hidden = false;
    } else {
        warnReserva.hidden = true;
    }
}
const scheduleConflicto = () => { clearTimeout(conflictoTimer); conflictoTimer = setTimeout(checkConflicto, 350); };

/**
 * El piloto asignado a la unidad entra como valor por defecto del movimiento; sigue siendo
 * editable porque el piloto que sale puede no ser el asignado (plan §5.4: son campos distintos).
 */
function aplicarPilotoAsignado() {
    const selPiloto = formReserva.elements['piloto_id'];
    if (!selPiloto) return;
    const asignado = formReserva.elements['unidad_id'].selectedOptions[0]?.dataset.piloto || '';
    // Si el asignado no está entre las opciones (otra estación, inactivo) se deja en blanco.
    const disponible = asignado !== '' && asignado !== '0' && [...selPiloto.options].some((o) => o.value === asignado);
    selPiloto.value = disponible ? asignado : '';
    // El combobox refleja el <select> nativo solo cuando este avisa del cambio.
    selPiloto.dispatchEvent(new Event('change', { bubbles: true }));
}

/** Advertencia sutil: el piloto elegido tiene la licencia vencida (no bloquea el guardado). */
function syncAvisoLicencia() {
    const aviso = document.getElementById('piloto-warn');
    if (!aviso) return;
    const opt = formReserva.elements['piloto_id']?.selectedOptions[0];
    aviso.hidden = opt?.dataset.licenciaVencida !== '1';
}

function abrirReserva(unidadId) {
    if (!formReserva) return;
    formReserva.reset();
    if (unidadId) formReserva.elements['unidad_id'].value = unidadId;
    toggleRutaCustom();
    formReserva.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
    aplicarPilotoAsignado();
    tipoTocadoManual = false;
    errReserva.hidden = true;
    if (warnReserva) warnReserva.hidden = true;
    dlgReserva.showModal();
}

/**
 * Una salida para hoy no se "aparta": se programa. El tipo se propone según la fecha para
 * ahorrar el paso de confirmar después, pero deja de proponerse en cuanto el usuario elige
 * a mano — el valor por defecto no debe pelear con una decisión explícita.
 */
let tipoTocadoManual = false;

function sugerirTipo() {
    if (!formReserva || tipoTocadoManual) return;
    const salida = formReserva.elements['fecha_salida'].value;
    if (!salida) return;
    const hoy = new Date();
    const mismaFecha = salida.slice(0, 10) === [
        hoy.getFullYear(),
        String(hoy.getMonth() + 1).padStart(2, '0'),
        String(hoy.getDate()).padStart(2, '0'),
    ].join('-');
    formReserva.elements['estado'].value = mismaFecha ? 'PROGRAMADO' : 'RESERVADO';
    formReserva.elements['estado'].dispatchEvent(new Event('change', { bubbles: true }));
}

function toggleRutaCustom() {
    const usaCatalogo = formReserva.elements['ruta_id'].value !== '';
    formReserva.querySelectorAll('.ruta-custom').forEach((el) => { el.style.display = usaCatalogo ? 'none' : ''; });
}

if (formReserva) {
    formReserva.elements['ruta_id'].addEventListener('change', toggleRutaCustom);
    formReserva.elements['unidad_id'].addEventListener('change', aplicarPilotoAsignado);
    formReserva.elements['piloto_id']?.addEventListener('change', syncAvisoLicencia);
    document.querySelectorAll('[data-action="nueva-reserva"]').forEach((b) => b.addEventListener('click', () => abrirReserva('')));

    ['unidad_id', 'piloto_id', 'fecha_salida', 'fecha_fin_estimada'].forEach((name) => {
        const el = formReserva.elements[name];
        el?.addEventListener('change', scheduleConflicto);
        el?.addEventListener('input', scheduleConflicto);
    });

    formReserva.elements['fecha_salida'].addEventListener('change', sugerirTipo);
    formReserva.elements['fecha_salida'].addEventListener('input', sugerirTipo);
    formReserva.elements['estado'].addEventListener('change', (e) => {
        if (e.isTrusted) tipoTocadoManual = true;   // solo cuenta si lo eligió una persona
    });

    formReserva.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const p = {};
        for (const el of formReserva.elements) {
            if (!el.name) continue;
            if (el.type === 'checkbox') { p[el.name] = el.checked ? 1 : 0; continue; }
            if (el.value !== '') p[el.name] = el.value;
        }
        const r = await api('POST', '/api/movimientos', p);
        if (r.ok) { dlgReserva.close(); load(); } else showError(errReserva, r);
    });
}

// ── Diálogo de motivo (cancelar movimiento / bloquear unidad) ──
const dlgMotivo = document.getElementById('dlg-motivo');
const formMotivo = document.getElementById('form-motivo');
const errMotivo = document.getElementById('form-motivo-error');

function abrirMotivo(accion, id) {
    formMotivo.reset();
    formMotivo.elements['accion'].value = accion;
    formMotivo.elements['id'].value = id;
    document.getElementById('dlg-motivo-title').textContent = accion === 'cancelar' ? 'Cancelar movimiento' : 'Bloquear unidad';
    errMotivo.hidden = true;
    dlgMotivo.showModal();
}

if (formMotivo) {
    formMotivo.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const { accion, id } = formMotivo.elements;
        const motivo = formMotivo.elements['motivo'].value;
        const r = accion.value === 'cancelar'
            ? await api('POST', `/api/movimientos/${id.value}/cancelar`, { motivo_cancelacion: motivo })
            : await api('POST', `/api/unidades/${id.value}/bloquear`, { motivo });
        if (r.ok) { dlgMotivo.close(); load(); } else showError(errMotivo, r);
    });
}

// ── Filtros aplicados ──
// Con parte de los filtros escondidos tras "Más filtros", lo aplicado tiene que verse
// siempre: si no, el tablero muestra una flota parcial y nadie recuerda por qué.
const chipsEl = document.getElementById('dash-chips');

function filtrosActivos() {
    const activos = [];
    const texto = (sel) => sel.options[sel.selectedIndex]?.textContent.trim();

    const est = document.getElementById('f-estacion');
    if (est.value) activos.push({ etiqueta: texto(est), limpiar: () => { est.value = ''; } });

    const estados = [...document.querySelectorAll('.f-estado:checked')];
    estados.forEach((c) => activos.push({
        etiqueta: STATE_LABELS[c.value] || c.value,
        limpiar: () => { c.checked = false; syncStateSummary(); },
    }));

    const placa = document.getElementById('f-placa');
    if (placa.value.trim()) activos.push({ etiqueta: `Placa: ${placa.value.trim()}`, limpiar: () => { placa.value = ''; } });

    const cat = document.getElementById('f-categoria');
    if (cat.value) activos.push({ etiqueta: texto(cat), limpiar: () => { cat.value = ''; } });

    const tipo = document.getElementById('f-tipo');
    if (tipo.value) activos.push({ etiqueta: texto(tipo), limpiar: () => { tipo.value = ''; } });

    const alcance = document.getElementById('f-alcance');
    if (alcance.value) activos.push({ etiqueta: texto(alcance), limpiar: () => { alcance.value = ''; } });

    const ret = document.getElementById('f-retorno');
    if (ret.value) activos.push({ etiqueta: `Retorno: ${texto(ret)}`, limpiar: () => { ret.value = ''; } });

    const desde = document.querySelector('[name="retorno_desde_sel"]');
    if (desde?.value) activos.push({ etiqueta: `Retorno desde ${texto(desde)}`, limpiar: () => { desde.value = ''; } });

    const demora = document.getElementById('f-demora');
    if (demora.checked) activos.push({ etiqueta: 'Solo con demora', limpiar: () => { demora.checked = false; } });

    return activos;
}

let activosActuales = [];

function pintarChips() {
    activosActuales = filtrosActivos();
    chipsEl.hidden = activosActuales.length === 0;
    chipsEl.innerHTML = activosActuales.map((f, i) => `
        <button type="button" class="filtro-chip" data-quitar="${i}">
            ${esc(f.etiqueta)}<span aria-hidden="true">×</span>
        </button>`).join('')
        + (activosActuales.length > 1 ? '<button type="button" class="link" data-limpiar-todo>Limpiar todo</button>' : '');
}

chipsEl.addEventListener('click', (ev) => {
    const quitar = ev.target.closest('[data-quitar]');
    const todo = ev.target.closest('[data-limpiar-todo]');
    if (!quitar && !todo) return;
    (todo ? activosActuales : [activosActuales[Number(quitar.dataset.quitar)]]).forEach((f) => f?.limpiar());
    // Los combobox buscables reflejan el <select>, así que hay que avisarles del cambio.
    document.querySelectorAll('#f-estacion, #f-categoria, #f-tipo, #f-alcance, #f-retorno, [name="retorno_desde_sel"]')
        .forEach((sel) => sel.dispatchEvent(new Event('change', { bubbles: true })));
    load();
});

// ── Panel de la unidad ──
// El menú "⋮" obliga a saber qué se quiere antes de ver en qué estado está la unidad, y no
// tiene sitio para los compañeros de viaje. El panel muestra estado y acciones juntos.
const dlgUnidad = document.getElementById('dlg-unidad');
const cuerpoUnidad = document.getElementById('panel-unidad-cuerpo');

/** Acciones posibles según el estado, con verbo claro. Reusa los mismos data-mov del menú. */
function accionesPanel(u) {
    const m = u.movimiento;
    const btn = (accion, txt, tono = '') =>
        `<button type="button" class="btn btn--linea ${tono}" data-mov="${accion}" data-unidad="${u.unidad_id}"${m ? ` data-id="${m.id}"` : ''}>${txt}</button>`;
    const acc = [];

    if (u.estado === 'DISPONIBLE') {
        acc.push(btn('reservar', 'Reservar', 'btn--linea-principal'), btn('bloquear', 'Bloquear'));
    } else if (u.override && u.override.tipo === 'BLOQUEADA') {
        acc.push(btn('desbloquear', 'Desbloquear', 'btn--linea-principal'));
    } else if (m && m.estado === 'RESERVADO') {
        acc.push(btn('confirmar', 'Confirmar', 'btn--linea-principal'));
    } else if (m && m.estado === 'PROGRAMADO') {
        acc.push(btn('salida', 'Marcar salida', 'btn--linea-principal'), btn('reprogramar', 'Cambiar fecha de fin'));
    } else if (m && m.estado === 'EN_TRANSITO') {
        acc.push(btn('llegada', 'Marcar llegada', 'btn--linea-principal'), btn('reprogramar', 'Cambiar fecha de fin'));
    }
    if (m && m.retorno_disponible && !m.regreso_id) acc.push(btn('apartar-retorno', 'Apartar retorno'));
    if (m && m.estado !== 'EN_TRANSITO') acc.push(btn('cancelar', 'Cancelar movimiento', 'btn--linea-peligro'));
    return acc;
}

function abrirPanelUnidad(u) {
    const m = u.movimiento;
    const [cls, label] = CHIP[u.estado] || ['chip--muted', u.estado];

    const fila = (k, v) => (v ? `<div class="panel__dato"><dt>${k}</dt><dd>${v}</dd></div>` : '');
    const datos = [
        fila('Estado', `<span class="chip ${cls}">${label}</span>`),
        m ? fila('Movimiento', `#${esc(m.id)} · ${esc(m.origen || '?')} → ${esc(m.destino || '?')}`) : '',
        m ? fila('Se libera', fmtLibera(m.fecha_fin_estimada, u.timezone)) : '',
        m && m.reservado_para ? fila('Reservado para', esc(m.reservado_para)) : '',
        !m && u.override ? fila('Motivo', esc(u.override.motivo || u.override.tipo)) : '',
        fila('Piloto', u.piloto ? esc(u.piloto) : '<span class="muted">sin asignar</span>'),
    ].join('');

    // Solo los activos de apoyo se liberan; el protagonista se cancela, no se suelta.
    const juntos = (m?.acompanantes || []).map((c) => `
        <div class="panel__junto">
            <strong>${esc(c.placa)}</strong>
            ${c.apoyo
                ? `<button type="button" class="btn btn--linea btn--sm" data-mov="liberar" data-unidad="${c.id}" data-id="${m.id}">Liberar</button>`
                : '<span class="muted">unidad reservada</span>'}
        </div>`).join('');

    const acciones = accionesPanel(u);
    cuerpoUnidad.innerHTML = `
        <div class="panel__id">
            <h2 class="panel__placa">${esc(u.placa_unidad)}</h2>
            <span class="panel__meta">${esc(u.categoria || '')} · ${esc(u.estacion_codigo)}</span>
        </div>
        <dl class="panel__datos">${datos}</dl>
        ${juntos ? `<div class="panel__bloque"><span class="panel__titulo">Va con</span>${juntos}</div>` : ''}
        ${acciones.length ? `<div class="panel__bloque"><span class="panel__titulo">Acciones</span><div class="panel__acciones">${acciones.join('')}</div></div>` : ''}`;
    dlgUnidad.showModal();
    // El foco entra en la acción principal, no en el botón de cerrar: abrir y pulsar Enter
    // resuelve el caso frecuente (confirmar, marcar salida, marcar llegada).
    cuerpoUnidad.querySelector('.panel__acciones .btn')?.focus();
}

// Un clic en la fila abre el panel; los controles propios de la fila siguen mandando.
body.addEventListener('click', (ev) => {
    if (ev.target.closest('button, a, .rowmenu, .alcance')) return;
    const fila = ev.target.closest('tr');
    const placa = fila?.querySelector('strong')?.textContent;
    const u = ultimasUnidades.find((x) => x.placa_unidad === placa);
    if (u) abrirPanelUnidad(u);
});

// ── Cambiar fecha de fin (prórroga en ruta) ──
const dlgReprogramar = document.getElementById('dlg-reprogramar');
const formReprogramar = document.getElementById('form-reprogramar');
const errReprogramar = document.getElementById('form-reprogramar-error');

function abrirReprogramar(id, mov, tz) {
    if (!formReprogramar || !mov) return;
    formReprogramar.reset();
    formReprogramar.elements['id'].value = id;
    const actual = paraInput(mov.fecha_fin_estimada, tz);
    document.getElementById('reprogramar-actual').value = actual;
    formReprogramar.elements['fecha_fin_estimada'].value = actual;
    errReprogramar.hidden = true;
    dlgReprogramar.showModal();
}

if (formReprogramar) {
    formReprogramar.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const id = formReprogramar.elements['id'].value;
        const r = await api('POST', `/api/movimientos/${id}/reprogramar-fin`, {
            fecha_fin_estimada: formReprogramar.elements['fecha_fin_estimada'].value,
            motivo: formReprogramar.elements['motivo'].value,
        });
        if (r.ok) { dlgReprogramar.close(); load(); } else showError(errReprogramar, r);
    });
}

// ── Apartar retorno ──
const dlgRetorno = document.getElementById('dlg-retorno');
const formRetorno = document.getElementById('form-retorno');
const errRetorno = document.getElementById('form-retorno-error');

/**
 * Propone los países según la ida: el retorno lo pide el país donde quedó la unidad (destino
 * de la ida) y, salvo que sigan a un tercer país, el equipo vuelve al origen. Ambos editables.
 */
function abrirRetorno(id, mov, tz) {
    if (!formRetorno) return;
    formRetorno.reset();
    formRetorno.elements['id'].value = id;
    if (mov) {
        formRetorno.elements['pais_solicita_retorno_id'].value = mov.pais_destino_id ?? '';
        formRetorno.elements['pais_destino_id'].value = mov.pais_origen_id ?? '';
        // El equipo no se libera hasta que termina la ida: esa es la salida más temprana.
        formRetorno.elements['fecha_salida'].value = paraInput(mov.fecha_fin_estimada, tz);
    }
    formRetorno.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
    errRetorno.hidden = true;
    dlgRetorno.showModal();
}

if (formRetorno) {
    formRetorno.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const id = formRetorno.elements['id'].value;
        const p = {};
        for (const el of formRetorno.elements) {
            if (el.name && el.name !== 'id' && el.value !== '') p[el.name] = el.value;
        }
        const r = await api('POST', `/api/movimientos/${id}/apartar-retorno`, p);
        if (r.ok) { dlgRetorno.close(); load(); } else showError(errRetorno, r);
    });
}

document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));

// ── Filtros ──
document.querySelectorAll('[data-fecha]').forEach((b) => b.addEventListener('click', () => {
    document.querySelectorAll('[data-fecha]').forEach((x) => x.classList.remove('is-active'));
    b.classList.add('is-active');
    fechaMode = b.dataset.fecha;
    load();
}));
document.getElementById('f-fecha').addEventListener('change', () => {
    document.querySelectorAll('[data-fecha]').forEach((x) => x.classList.remove('is-active'));
    fechaMode = 'fecha';
    load();
});
['f-estacion', 'f-categoria', 'f-tipo', 'f-alcance', 'f-retorno', 'f-demora'].forEach((id) => document.getElementById(id).addEventListener('change', load));
document.querySelectorAll('.f-estado').forEach((c) => c.addEventListener('change', () => {
    syncStateSummary();
    load();
}));
const rdSel = document.querySelector('[name="retorno_desde_sel"]');
if (rdSel) rdSel.addEventListener('change', load);
let placaTimer;
document.getElementById('f-placa').addEventListener('input', () => { clearTimeout(placaTimer); placaTimer = setTimeout(load, 300); });
document.querySelector('[data-action="refrescar"]').addEventListener('click', load);

stateSelectToggle.addEventListener('click', () => {
    if (stateSelectMenu.hidden) openStateMenu();
    else closeStateMenu();
});
document.addEventListener('click', (ev) => {
    if (!stateSelectWrap.contains(ev.target)) closeStateMenu();
});
document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') closeStateMenu();
});

// ── Auto-refresh 60s ──
setInterval(load, 60000);
syncStateSummary();
load();

// ── Utilidades de fecha (hora local de la estación con Intl) ──
function dayKey(date, tz) {
    return new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}
/** UTC → "27/08/2026 14:00" en la zona de la estación, para detalles y popovers. */
function fmtFecha(utc, tz) {
    if (!utc) return '—';
    const d = new Date(utc.replace(' ', 'T') + 'Z');
    return new Intl.DateTimeFormat('es-ES', {
        timeZone: tz, day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: false,
    }).format(d).replace(',', '');
}

/** UTC → "YYYY-MM-DDTHH:mm" en la zona de la estación (formato que pide datetime-local). */
function paraInput(utc, tz) {
    if (!utc) return '';
    const d = new Date(utc.replace(' ', 'T') + 'Z');
    // 'sv-SE' rinde "YYYY-MM-DD HH:mm", que es el formato del input salvo la T.
    return new Intl.DateTimeFormat('sv-SE', {
        timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: false,
    }).format(d).replace(' ', 'T');
}

function fmtLibera(utc, tz) {
    if (!utc) return '—';
    const d = new Date(utc.replace(' ', 'T') + 'Z');
    const hora = new Intl.DateTimeFormat('es', { timeZone: tz, hour: '2-digit', minute: '2-digit', hour12: false }).format(d);
    const now = new Date();
    const manana = new Date(now); manana.setDate(manana.getDate() + 1);
    const k = dayKey(d, tz);
    let dia;
    if (k === dayKey(now, tz)) dia = 'hoy';
    else if (k === dayKey(manana, tz)) dia = 'mañana';
    else dia = new Intl.DateTimeFormat('es', { timeZone: tz, day: '2-digit', month: 'short' }).format(d);
    return `${dia} ${hora}`;
}
function fmtDia(utc) {
    if (!utc) return '';
    const d = new Date(utc.replace(' ', 'T') + 'Z');
    return new Intl.DateTimeFormat('es', { day: '2-digit', month: 'short' }).format(d);
}
function esc(s) {
    return String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
