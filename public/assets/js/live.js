/**
 * Live en vivo (wallboard). Sondea /api/disponibilidad cada pocos segundos y pinta un
 * tablero gráfico: KPIs (que además filtran por categoría), dona de distribución y una
 * rejilla de unidades. Filtro por estación, tema claro/oscuro y detalle en modal al hacer
 * clic en una unidad. Sin interacción: se actualiza solo.
 */
import { api } from './api.js';

const REFRESH_MS = 8000;
const STALE_MS = 25000;

const SVG = {
    truck: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h11v9H3z"/><path d="M14 9h4l3 3v3h-7z"/><circle cx="7.5" cy="17.5" r="1.7"/><circle cx="17.5" cy="17.5" r="1.7"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/></svg>',
    clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/></svg>',
    alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4l9 15.5H3z"/><path d="M12 10v4.5"/><path d="M12 17.5h.01"/></svg>',
    sun: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
    moon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A8.5 8.5 0 1 1 11.2 3a6.5 6.5 0 0 0 9.8 9.8z"/></svg>',
};

// Metadatos por estado (color en hex igual a las variables de live.css).
const ESTADOS = {
    DISPONIBLE:       { label: 'Disponible',  color: '#1eae62', orden: 2 },
    EN_TRANSITO:      { label: 'En tránsito', color: '#2f7ff0', orden: 0 },
    RESERVADA:        { label: 'Reservada',   color: '#e0982a', orden: 1 },
    TALLER_BLOQUEADA: { label: 'Taller',      color: '#8394a8', orden: 3 },
};
const meta = (estado) => ESTADOS[estado] || { label: estado, color: '#8394a8', orden: 9 };
const DEMORA_COLOR = '#ef5b39';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

let data = [];            // últimas unidades del API
let station = '';         // filtro estación (codigo) o ''
let cat = '';             // filtro categoría: '', DISPONIBLE, EN_TRANSITO, RESERVADA, DEMORA
let prev = new Map();     // unidad_id -> estado (para detectar cambios)
let lastOk = 0;

// ── Tema ──
function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    const btn = $('live-theme');
    btn.innerHTML = theme === 'dark' ? SVG.sun : SVG.moon;
    btn.title = theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro';
}
function initTheme() {
    applyTheme(localStorage.getItem('live-theme') || 'light');
    $('live-theme').addEventListener('click', () => {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        localStorage.setItem('live-theme', next);
        applyTheme(next);
    });
}

// ── Reloj + frescura ──
function tick() {
    const now = new Date();
    $('live-time').textContent = now.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    $('live-date').textContent = now.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' });
    if (lastOk) {
        const secs = Math.round((Date.now() - lastOk) / 1000);
        $('live-updated').textContent = `actualizado hace ${secs}s`;
        $('live-dot').classList.toggle('is-stale', Date.now() - lastOk > STALE_MS);
    }
}

// ── Fechas en la zona de la estación ──
function fmtDT(utc, tz) {
    if (!utc) return '—';
    const d = new Date(String(utc).replace(' ', 'T') + 'Z');
    return new Intl.DateTimeFormat('es', { timeZone: tz, day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).format(d);
}

// ── KPIs (también filtros) ──
function renderKpis(scoped) {
    const n = (e) => scoped.filter((u) => u.estado === e).length;
    const cards = [
        { cat: 'DISPONIBLE',  c: '#1eae62', icon: SVG.check, num: n('DISPONIBLE'),  label: 'Disponibles' },
        { cat: 'EN_TRANSITO', c: '#2f7ff0', icon: SVG.truck, num: n('EN_TRANSITO'), label: 'En tránsito' },
        { cat: 'RESERVADA',   c: '#e0982a', icon: SVG.clock, num: n('RESERVADA'),   label: 'Reservadas' },
        { cat: 'DEMORA',      c: '#ef5b39', icon: SVG.alert, num: scoped.filter((u) => u.con_demora).length, label: 'Con demora' },
    ];
    $('live-kpis').innerHTML = cards.map((k) => `
        <button type="button" class="kpi${cat === k.cat ? ' is-active' : ''}" data-cat="${k.cat}" style="--c:${k.c}">
            <span class="kpi__icon">${k.icon}</span>
            <span class="kpi__body">
                <span class="kpi__num">${k.num}</span>
                <span class="kpi__label">${k.label}</span>
            </span>
        </button>`).join('');
}

// ── Dona de distribución (SVG) ──
function renderDonut(scoped) {
    const total = scoped.length;
    $('live-total').textContent = total;
    const orden = ['EN_TRANSITO', 'RESERVADA', 'DISPONIBLE', 'TALLER_BLOQUEADA'];
    const conteos = orden.map((e) => ({ e, count: scoped.filter((u) => u.estado === e).length })).filter((s) => s.count > 0);

    const R = 15.915;
    let acc = 0;
    const segs = total === 0
        ? '<circle cx="21" cy="21" r="15.915" stroke="rgba(150,150,150,0.18)" stroke-dasharray="100 0"></circle>'
        : conteos.map(({ e, count }) => {
            const pct = (count / total) * 100;
            const dash = `${pct.toFixed(2)} ${(100 - pct).toFixed(2)}`;
            const off = -acc;
            acc += pct;
            return `<circle class="seg" cx="21" cy="21" r="${R}" stroke="${meta(e).color}" stroke-dasharray="${dash}" stroke-dashoffset="${off.toFixed(2)}"></circle>`;
        }).join('');
    $('live-donut').innerHTML = segs;

    $('live-legend').innerHTML = orden.map((e) => {
        const count = scoped.filter((u) => u.estado === e).length;
        if (count === 0) return '';
        const pct = total ? Math.round((count / total) * 100) : 0;
        return `<li style="--c:${meta(e).color}">
            <div class="leg__top"><span class="dot"></span><span class="lbl">${meta(e).label}</span><span class="val">${count} · ${pct}%</span></div>
            <div class="leg__bar"><span style="width:${pct}%"></span></div>
        </li>`;
    }).join('');
}

// ── Rejilla de unidades ──
function tileHtml(u) {
    const m = meta(u.estado);
    const mv = u.movimiento;
    let metaLine;
    if (mv && (mv.origen || mv.destino)) {
        metaLine = `<span class="tile__route">${esc(mv.origen || '?')} → ${esc(mv.destino || '?')}</span>`;
    } else if (u.piloto) {
        metaLine = `<span>${esc(u.piloto)}</span>`;
    } else {
        metaLine = '<span>—</span>';
    }
    const demora = u.con_demora ? `<span class="tile__demora">${SVG.alert}<span>demora</span></span>` : '';
    const changed = prev.has(u.unidad_id) && prev.get(u.unidad_id) !== u.estado ? ' is-changed' : '';
    // Solo la capacidad.
    const sub = u.capacidad ? esc(u.capacidad) : '—';
    return `<button type="button" class="tile${changed}" data-id="${u.unidad_id}" style="--c:${m.color}">
        <span class="tile__top">
            <span class="tile__station" title="Estación ${esc(u.estacion_codigo || '')}">${esc(u.estacion_codigo || '—')}</span>
            <span class="tile__state">${esc(m.label)}</span>
        </span>
        <span class="tile__plate"><strong title="${esc(u.placa_unidad)}">${esc(u.placa_unidad)}</strong><small>${sub}</small></span>
        <span class="tile__meta">${metaLine}${demora}</span>
    </button>`;
}

function renderGrid(units) {
    const grid = $('live-grid');
    if (!units.length) {
        grid.innerHTML = '<div class="live__empty">Sin unidades para este filtro.</div>';
        return;
    }
    const ordenadas = [...units].sort((a, b) =>
        meta(a.estado).orden - meta(b.estado).orden || String(a.placa_unidad).localeCompare(b.placa_unidad));
    grid.innerHTML = ordenadas.map(tileHtml).join('');
}

// ── Aplicar filtros y pintar ──
function apply() {
    const scoped = station ? data.filter((u) => u.estacion_codigo === station) : data;
    renderKpis(scoped);
    renderDonut(scoped);
    const units = cat
        ? scoped.filter((u) => (cat === 'DEMORA' ? u.con_demora : u.estado === cat))
        : scoped;
    renderGrid(units);

    const partes = [];
    if (station) partes.push(`Estación ${station}`);
    if (cat) partes.push(cat === 'DEMORA' ? 'Con demora' : meta(cat).label);
    $('live-filterinfo').textContent = partes.length ? `Filtro: ${partes.join(' · ')}` : '';
}

// ── Modal de detalle ──
function openModal(u) {
    const m = meta(u.estado);
    const mv = u.movimiento;
    const rows = [];
    rows.push(['Estación', esc(u.estacion_codigo || '—')]);
    rows.push(['Tipo / capacidad', `${esc(u.tipo_equipo || '—')}${u.capacidad ? ' · ' + esc(u.capacidad) : ''}`]);
    rows.push(['Piloto', esc(u.piloto || '—')]);
    if (mv) {
        rows.push(['Ruta', `${esc(mv.origen || '?')} → ${esc(mv.destino || '?')}`]);
        if (mv.fecha_salida) rows.push(['Salida', esc(fmtDT(mv.fecha_salida, u.timezone))]);
        if (mv.fecha_fin_estimada) rows.push(['Fin estimado', esc(fmtDT(mv.fecha_fin_estimada, u.timezone))]);
        if (mv.reservado_para) rows.push(['Reservado para', esc(mv.reservado_para)]);
        if (mv.retorno_disponible) rows.push(['Retorno', mv.pais_solicita_retorno_id ? 'Tomado' : 'Disponible']);
    }
    if (u.override && u.override.motivo) rows.push(['Motivo', esc(u.override.motivo)]);
    if (u.con_demora) rows.push(['Demora', `<span class="mm__demora">${SVG.alert} Con demora</span>`]);

    $('live-modal-body').innerHTML = `
        <div class="mm__head" style="--c:${m.color}">
            <div class="mm__icon">${SVG.truck}</div>
            <div class="mm__title"><strong>${esc(u.placa_unidad)}</strong>${u.placa_furgon ? `<small>${esc(u.placa_furgon)}</small>` : ''}</div>
            <span class="mm__chip">${esc(m.label)}</span>
        </div>
        <dl class="mm__list">
            ${rows.map(([k, v]) => `<div class="mm__row"><dt>${k}</dt><dd>${v}</dd></div>`).join('')}
        </dl>`;
    $('live-modal').showModal();
}

// ── Sondeo ──
async function load() {
    const resp = await api('GET', '/api/disponibilidad');
    if (!resp.ok || !resp.data) {
        $('live-status').textContent = 'Sin conexión · reintentando…';
        $('live-status').classList.add('is-error');
        return;
    }
    data = resp.data.unidades || [];
    lastOk = Date.now();
    $('live-status').textContent = 'En línea';
    $('live-status').classList.remove('is-error');
    apply();
    prev = new Map(data.map((u) => [u.unidad_id, u.estado]));
}

// ── Eventos (delegación, se registran una sola vez) ──
function wireEvents() {
    $('live-kpis').addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-cat]');
        if (!b) return;
        cat = cat === b.dataset.cat ? '' : b.dataset.cat;
        apply();
    });
    $('live-estacion').addEventListener('change', (ev) => { station = ev.target.value; apply(); });
    $('live-grid').addEventListener('click', (ev) => {
        const t = ev.target.closest('[data-id]');
        if (!t) return;
        const u = data.find((x) => String(x.unidad_id) === t.dataset.id);
        if (u) openModal(u);
    });
    const dlg = $('live-modal');
    dlg.addEventListener('click', (ev) => {
        if (ev.target === dlg || ev.target.closest('[data-modal-close]')) dlg.close();
    });
}

initTheme();
wireEvents();
tick();
setInterval(tick, 1000);
load();
setInterval(load, REFRESH_MS);
