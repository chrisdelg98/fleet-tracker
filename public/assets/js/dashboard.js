/**
 * Dashboard de disponibilidad (plan §7.1). Vive del endpoint /api/disponibilidad: los
 * filtros (incluida fecha futura) recalculan el estado de toda la flota (§2). Auto-refresh
 * cada 60s. Las horas se muestran en la timezone de cada estación (Intl), la BD está en UTC.
 */
import { api, showError } from './api.js';

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

const CHIP = {
    DISPONIBLE: ['chip--disponible', '🟢 Disponible'],
    RESERVADA: ['chip--reservada', '🟡 Reservada'],
    EN_TRANSITO: ['chip--transito', '🔵 En tránsito'],
    TALLER_BLOQUEADA: ['chip--taller', '⚪ Taller/Bloqueada'],
};

let fechaMode = 'hoy';
const colspan = cfg.puedeReservar ? 9 : 8;

const STATE_LABELS = {
    DISPONIBLE: 'Disponible',
    RESERVADA: 'Reservada',
    EN_TRANSITO: 'En tránsito',
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
    const tipo = document.getElementById('f-tipo').value;
    if (tipo) p.set('tipo_equipo_id', tipo);
    const placa = document.getElementById('f-placa').value.trim();
    if (placa) p.set('placa', placa);
    const estados = [...document.querySelectorAll('.f-estado:checked')].map((c) => c.value);
    if (estados.length) p.set('estado', estados.join(','));
    const retorno = document.getElementById('f-retorno').value;
    if (retorno === '1') p.set('solo_retorno', '1');
    if (retorno === '0') p.set('sin_retorno', '1');
    if (document.getElementById('f-demora').checked) p.set('solo_demora', '1');
    const rh = document.querySelector('[name="retorno_hacia_sel"]');
    if (rh && rh.value) p.set('retorno_hacia', rh.value);
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
    const resp = await api('GET', `/api/disponibilidad?${buildQuery()}`);
    if (!resp.ok) { body.innerHTML = `<tr><td colspan="${colspan}" class="muted">No se pudo cargar.</td></tr>`; return; }
    render(resp.data.unidades, resp.data);
}

function render(unidades, meta) {
    countEl.textContent = `${unidades.length} unidad${unidades.length === 1 ? '' : 'es'}`;
    rangoEl.textContent = `${fmtDia(meta.desde)} → ${fmtDia(meta.hasta)}`;
    const demoraCount = unidades.filter((u) => u.con_demora).length;
    demoraWrapEl.hidden = demoraCount === 0;
    demoraTextEl.textContent = `${demoraCount} ${demoraCount === 1 ? 'unidad con demora' : 'unidades con demora'}`;
    if (!unidades.length) {
        body.innerHTML = `<tr><td colspan="${colspan}" class="muted" style="text-align:center">Sin unidades para estos filtros.</td></tr>`;
        return;
    }
    body.innerHTML = unidades.map(rowHtml).join('');
}

function rowHtml(u) {
    const [cls, label] = CHIP[u.estado] || ['chip--muted', u.estado];
    const m = u.movimiento;
    const demora = u.con_demora ? '<span class="delay-flag"><span class="delay-flag__icon" aria-hidden="true">!</span><span>Con demora</span></span>' : '';
    const actividad = m
        ? `${esc(m.origen || '?')} → ${esc(m.destino || '?')} <small class="muted">· sale ${fmtLibera(m.fecha_salida, u.timezone)}</small>`
        : (u.override ? `<span class="muted">${esc(u.override.motivo || u.override.tipo)}</span>` : '—');
    const libera = m ? fmtLibera(m.fecha_fin_estimada, u.timezone) : '—';
    let retorno = '—';
    if (m && m.retorno_disponible) {
        retorno = m.pais_solicita_retorno_id
            ? `<span class="retorno retorno--tomado">↩ ${esc(m.retorno_iso || '')} tomado</span>`
            : `<span class="retorno">↩ Retorno disponible</span>`;
    }
    return `<tr>
        <td><strong>${esc(u.placa_unidad)}</strong>${u.placa_furgon ? `<small class="muted block">${esc(u.placa_furgon)}</small>` : ''}</td>
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

function accionesHtml(u) {
    const m = u.movimiento;
    const btn = (accion, txt, extra = '') => `<button type="button" class="link" data-mov="${accion}" data-unidad="${u.unidad_id}" ${m ? `data-id="${m.id}"` : ''} ${extra}>${txt}</button>`;
    const acc = [];
    if (u.estado === 'DISPONIBLE') {
        acc.push(btn('reservar', 'Reservar'), btn('bloquear', 'Bloquear'));
    } else if (u.override && u.override.tipo === 'BLOQUEADA') {
        acc.push(btn('desbloquear', 'Desbloquear'));
    } else if (m && m.estado === 'RESERVADO') {
        acc.push(btn('confirmar', 'Confirmar'), btn('cancelar', 'Cancelar', 'class="link link--danger"'));
    } else if (m && m.estado === 'PROGRAMADO') {
        acc.push(btn('salida', 'Marcar salida'), btn('cancelar', 'Cancelar', 'class="link link--danger"'));
    } else if (m && m.estado === 'EN_TRANSITO') {
        acc.push(btn('llegada', 'Marcar llegada'), btn('cancelar', 'Cancelar', 'class="link link--danger"'));
    }
    if (m && m.retorno_disponible && !m.pais_solicita_retorno_id) {
        acc.push(btn('apartar-retorno', 'Apartar retorno'));
    }
    return acc.join('');
}

// ── Acciones por fila ──
if (cfg.puedeReservar) {
    body.addEventListener('click', async (ev) => {
        const b = ev.target.closest('[data-mov]');
        if (!b) return;
        const { mov, id, unidad } = b.dataset;
        if (mov === 'reservar') return abrirReserva(unidad);
        if (mov === 'bloquear') return abrirMotivo('bloquear', unidad);
        if (mov === 'cancelar') return abrirMotivo('cancelar', id);
        if (mov === 'apartar-retorno') return abrirRetorno(id);
        if (mov === 'desbloquear') return postAccion(`/api/unidades/${unidad}/desbloquear`);
        if (mov === 'confirmar') return postAccion(`/api/movimientos/${id}/confirmar`);
        if (mov === 'llegada') return postAccion(`/api/movimientos/${id}/llegada`);
        if (mov === 'salida') {
            const r = await api('POST', `/api/movimientos/${id}/salida`, {});
            if (r.ok) load(); else alert(r.message || 'Para marcar salida asigna un piloto al movimiento.');
        }
    });
}

async function postAccion(url) {
    const r = await api('POST', url, {});
    if (r.ok) load(); else alert(r.message || 'No se pudo completar la acción.');
}

// ── Formulario de reserva ──
const dlgReserva = document.getElementById('dlg-reserva');
const formReserva = document.getElementById('form-reserva');
const errReserva = document.getElementById('form-reserva-error');

function abrirReserva(unidadId) {
    if (!formReserva) return;
    formReserva.reset();
    if (unidadId) formReserva.elements['unidad_id'].value = unidadId;
    toggleRutaCustom();
    formReserva.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
    errReserva.hidden = true;
    dlgReserva.showModal();
}

function toggleRutaCustom() {
    const usaCatalogo = formReserva.elements['ruta_id'].value !== '';
    formReserva.querySelectorAll('.ruta-custom').forEach((el) => { el.style.display = usaCatalogo ? 'none' : ''; });
}

if (formReserva) {
    formReserva.elements['ruta_id'].addEventListener('change', toggleRutaCustom);
    document.querySelectorAll('[data-action="nueva-reserva"]').forEach((b) => b.addEventListener('click', () => abrirReserva('')));

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

// ── Apartar retorno ──
const dlgRetorno = document.getElementById('dlg-retorno');
const formRetorno = document.getElementById('form-retorno');
const errRetorno = document.getElementById('form-retorno-error');

function abrirRetorno(id) {
    if (!formRetorno) return;
    formRetorno.reset();
    formRetorno.elements['id'].value = id;
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
['f-estacion', 'f-tipo', 'f-retorno', 'f-demora'].forEach((id) => document.getElementById(id).addEventListener('change', load));
document.querySelectorAll('.f-estado').forEach((c) => c.addEventListener('change', () => {
    syncStateSummary();
    load();
}));
const rhSel = document.querySelector('[name="retorno_hacia_sel"]');
if (rhSel) rhSel.addEventListener('change', load);
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
