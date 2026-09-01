/**
 * Ficha de unidad del inventario: al hacer clic en una fila se abre todo lo que se sabe de
 * esa unidad —sus datos y cómo se ha comportado— sin salir de la pantalla.
 *
 * La tabla contesta "qué tengo"; la ficha contesta "qué tal me ha respondido".
 */

import { api, mensajeError } from './api.js';

const dlg = document.getElementById('dlg-unidad-ficha');
const titulo = document.getElementById('ficha-titulo');
const lede = document.getElementById('ficha-lede');
const cuerpo = document.getElementById('ficha-cuerpo');
const tabla = document.querySelector('.card--table');

const esc = (t) => String(t ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const oNada = (v) => (v === null || v === undefined || v === '' ? '<span class="muted">—</span>' : esc(v));

const ESTADOS = {
    RESERVADO: 'Reservado', PROGRAMADO: 'Programado', EN_TRANSITO: 'En tránsito',
    COMPLETADO: 'Completado', CANCELADO: 'Cancelado',
};

/** Fecha corta; la hora no aporta en una ficha que resume meses. */
const fecha = (utc) => (utc ? new Date(utc.replace(' ', 'T') + 'Z').toLocaleDateString('es', {
    day: '2-digit', month: '2-digit', year: 'numeric',
}) : '—');

/** Una demora se entiende en horas o días, no en minutos sueltos. */
function demora(minutos) {
    if (!minutos) return '<span class="rend-ok">a tiempo</span>';
    const horas = minutos / 60;
    const texto = horas < 48 ? `${Math.round(horas * 10) / 10} h` : `${Math.round(horas / 24 * 10) / 10} d`;
    return `<span class="rend-alerta">+${texto}</span>`;
}

// ── Apertura ──

tabla?.addEventListener('click', (ev) => {
    const fila = ev.target.closest('.fila-unidad');
    if (fila) abrir(fila.dataset.unidad, fila.dataset.placa);
});

// Con teclado: la fila es focusable, así que Enter tiene que hacer lo mismo que el clic.
tabla?.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Enter') return;
    const fila = ev.target.closest('.fila-unidad');
    if (fila) { ev.preventDefault(); abrir(fila.dataset.unidad, fila.dataset.placa); }
});

dlg.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-ficha-close]')) dlg.close();
});

async function abrir(id, placa) {
    titulo.textContent = placa;
    lede.textContent = 'Cargando…';
    cuerpo.innerHTML = '<p class="muted">Buscando la información de la unidad…</p>';
    dlg.showModal();

    const resp = await api('GET', `/api/unidades/${id}/estadisticas`);
    if (!resp.ok) {
        cuerpo.innerHTML = `<p class="form__error">${esc(mensajeError(resp, 'No se pudo cargar la ficha.'))}</p>`;
        lede.textContent = '';
        return;
    }
    pintar(resp.data);
}

// ── Pintado ──

function pintar(d) {
    const u = d.unidad;
    const a = d.actividad;

    titulo.textContent = u.placa_unidad;
    lede.textContent = [u.categoria, u.marca, u.modelo, u.anio].filter(Boolean).join(' · ');

    // Dos columnas: la ficha a la izquierda, que es lo estable, y a la derecha el
    // comportamiento, que es lo que cambia y lo que se viene a consultar.
    cuerpo.innerHTML = `
        <div class="ficha">
            <aside class="ficha__lateral">${bloqueFicha(u)}</aside>
            <div class="ficha__principal">
                ${bloqueCifras(a, d.indisponible)}
                ${bloqueActividad(d)}
            </div>
        </div>`;
}

/** Las cifras que se miran primero: cuánto trabajó y si cumplió. */
function bloqueCifras(a, ind) {
    const cifras = [
        ['Viajes completados', a.viajes, a.internacionales ? `${a.internacionales} internacionales` : 'todos nacionales'],
        ['Días en ruta', a.dias_en_ruta, a.viajes ? `${a.duracion_media_h} h por viaje` : ''],
        ['Puntualidad', a.puntualidad === null ? '—' : `${a.puntualidad}%`,
            a.con_demora ? `${a.con_demora} con demora · ${a.demora_media_h} h de media` : 'sin demoras'],
        ['Días detenida', ind.dias,
            ind.episodios ? `${ind.episodios} episodio${ind.episodios === 1 ? '' : 's'}${ind.abiertos ? ' · ' + ind.abiertos + ' abierto' : ''}` : 'nunca detenida'],
        ['Retornos ofrecidos', a.con_retorno, a.con_retorno ? `${a.retorno_aprovechado} aprovechados` : 'ninguno'],
    ];
    return `<div class="ficha-cifras">${cifras.map(([etiqueta, valor, nota]) => `
        <div class="ficha-cifra">
            <span class="ficha-cifra__valor">${esc(valor)}</span>
            <span class="ficha-cifra__etiqueta">${esc(etiqueta)}</span>
            ${nota ? `<span class="ficha-cifra__nota">${esc(nota)}</span>` : ''}
        </div>`).join('')}</div>`;
}

function bloqueFicha(u) {
    const filas = [
        ['Categoría', u.categoria],
        ['Marca', u.marca], ['Modelo', u.modelo], ['Año', u.anio],
        ['Combustible', u.tipo_combustible], ['Capacidad', u.capacidad],
        ['Tipo de equipo', u.tipo_equipo],
        ['Estación', u.estacion],
        ['Piloto asignado', u.piloto_asignado],
        ['Permisos', u.permisos],
    ];
    return `
        <h3>Ficha</h3>
        <dl class="detalle-dl">
            ${filas.map(([k, v]) => `<div class="detalle-dl__row"><dt>${esc(k)}</dt><dd>${oNada(v)}</dd></div>`).join('')}
            <div class="detalle-dl__row"><dt>Alcance</dt><dd>
                <span class="alcance ${u.puede_internacional ? 'alcance--int' : 'alcance--nac'}">${u.puede_internacional ? 'INT' : 'NAC'}</span>
            </dd></div>
            <div class="detalle-dl__row"><dt>Estado</dt><dd>${oNada(u.estado_vehiculo)}</dd></div>
            ${u.estado_notas
                ? `<div class="detalle-dl__row"><dt>Comentario</dt><dd>${esc(u.estado_notas)}</dd></div>`
                : ''}
        </dl>`;
}

/**
 * Actividad: rutas, pilotos y últimos viajes. Si la unidad no tiene un solo movimiento, se
 * dice una vez y en claro, en vez de tres secciones vacías explicando lo mismo por separado.
 */
function bloqueActividad(d) {
    const sinNada = d.rutas.length === 0 && d.pilotos.length === 0 && d.ultimos.length === 0;
    if (sinNada) {
        return `<div class="ficha-vacio">
            <strong>Todavía sin actividad</strong>
            <p class="muted">Esta unidad no tiene movimientos registrados, así que aún no hay rutas, pilotos ni viajes que mostrar.</p>
        </div>`;
    }

    const lista = (titulo, filas) => (filas.length === 0 ? '' : `
        <section class="ficha-bloque">
            <h3>${esc(titulo)}</h3>
            <ul class="int-list">${filas.map((f) => `
                <li class="int-list__row">
                    <div class="int-list__main"><strong>${esc(f.ruta ?? f.nombre)}</strong></div>
                    <div class="int-list__val">${f.viajes}<small>viaje${f.viajes === 1 ? '' : 's'}</small></div>
                </li>`).join('')}</ul>
        </section>`);

    const columnas = lista('Rutas más frecuentes', d.rutas) + lista('Pilotos que la han llevado', d.pilotos);

    return `
        ${columnas ? `<div class="ficha-dos">${columnas}</div>` : ''}
        ${bloqueViajes(d.ultimos)}`;
}

function bloqueViajes(viajes) {
    if (viajes.length === 0) {
        return '';
    }
    return `<section class="ficha-bloque">
        <h3>Últimos viajes</h3>
        <div class="table-wrap"><table class="table">
            <thead><tr>
                <th>Ruta</th><th>Piloto</th><th>Salida</th><th>Fin real</th>
                <th>Demora</th><th>Cliente</th><th>Estado</th>
            </tr></thead>
            <tbody>${viajes.map((v) => `
                <tr>
                    <td>${esc(v.ruta)}</td>
                    <td>${oNada(v.piloto)}</td>
                    <td>${fecha(v.fecha_salida)}</td>
                    <td>${v.fecha_fin_real ? fecha(v.fecha_fin_real) : '<span class="muted">—</span>'}</td>
                    <td>${v.fecha_fin_real ? demora(v.demora_min) : '<span class="muted">—</span>'}</td>
                    <td>${oNada(v.cliente)}</td>
                    <td>${esc(ESTADOS[v.estado] ?? v.estado)}</td>
                </tr>`).join('')}</tbody>
        </table></div>
    </section>`;
}
