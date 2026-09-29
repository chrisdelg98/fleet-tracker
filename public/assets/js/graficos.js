/**
 * Hoja de gráficos con filtro cruzado.
 *
 * El servidor manda la tabla de hechos del rango y aquí se agrega todo. Por eso pulsar una barra
 * recalcula los otros gráficos al instante: no hay viaje al servidor. Un filtro que tarda medio
 * segundo deja de usarse, y entonces la pantalla no sirve para explorar, que es su único trabajo.
 *
 * Los gráficos son SVG escrito a mano —sin librerías, como manda AGENTS.md— y todos usan un solo
 * tono: cada uno compara magnitudes de UNA medida, así que el color no tiene que distinguir
 * series. Lo que el color marca es la selección: lo elegido en el tono de la marca, el resto en
 * gris. Eso además evita el problema de daltonismo de una paleta categórica.
 */
import { ESPECIFICACIONES } from './graficos-spec.js';

const $ = (id) => document.getElementById(id);
const FILAS = JSON.parse($('graf-datos').textContent);
const HOJA = $('graf-datos').dataset.hoja;
const SPEC = ESPECIFICACIONES[HOJA];

/** Dimensión => valores seleccionados. Vacío = sin filtrar por esa dimensión. */
const seleccion = new Map();

const esc = (t) => String(t ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const num = (n) => Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 });

// ── Filtro cruzado ──────────────────────────────────────────────────────────

/**
 * Filas que pasan los filtros activos.
 *
 * Al dibujar un gráfico se ignora SU propia dimensión: si no, al elegir una estación las demás
 * barras desaparecerían y no se podría comparar con el resto ni cambiar de opinión. Es la regla
 * que hace que un tablero de BI se sienta explorable.
 */
function filtradas(exceptoDim = null) {
    if (seleccion.size === 0) return FILAS;
    return FILAS.filter((f) => {
        for (const [dim, valores] of seleccion) {
            if (dim === exceptoDim) continue;
            if (!valores.has(String(f[dim]))) return false;
        }
        return true;
    });
}

function alternar(dim, valor) {
    const actual = seleccion.get(dim);
    if (!actual) {
        seleccion.set(dim, new Set([valor]));
    } else if (actual.has(valor)) {
        actual.delete(valor);
        if (actual.size === 0) seleccion.delete(dim);
    } else {
        actual.add(valor);
    }
    pintar();
}

// ── Agregación ──────────────────────────────────────────────────────────────

/** Suma la medida por valor de la dimensión, de mayor a menor. */
function agrupar(filas, dim, medida) {
    const mapa = new Map();
    for (const f of filas) {
        const k = String(f[dim] ?? '—');
        mapa.set(k, (mapa.get(k) || 0) + medida(f));
    }
    return [...mapa.entries()]
        .map(([etiqueta, valor]) => ({ etiqueta, valor }))
        .sort((a, b) => b.valor - a.valor);
}

/** Igual, pero en orden cronológico: el tiempo no se ordena por tamaño. */
function porMes(filas, medida) {
    const mapa = new Map();
    for (const f of filas) {
        const k = String(f.fecha).slice(0, 7);
        mapa.set(k, (mapa.get(k) || 0) + medida(f));
    }
    return [...mapa.entries()].sort().map(([etiqueta, valor]) => ({ etiqueta, valor }));
}

// ── Dibujo ──────────────────────────────────────────────────────────────────

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const mesCorto = (ym) => {
    const [a, m] = ym.split('-');
    return `${MESES[Number(m) - 1]} ${a.slice(2)}`;
};

/** ¿Está esta barra dentro de la selección? Sin selección en su dimensión, todas cuentan. */
const elegido = (dim, valor) => !seleccion.has(dim) || seleccion.get(dim).has(valor);

/** Columnas verticales: para el tiempo, donde el orden es la historia. */
function columnas(datos, dim, formato) {
    if (datos.length === 0) return '<p class="graf__vacio">Sin datos en este rango.</p>';
    const max = Math.max(...datos.map((d) => d.valor)) || 1;
    return `<div class="graf-cols" role="list">${datos.map((d) => {
        const alto = Math.max(2, Math.round((d.valor / max) * 100));
        return `<button type="button" role="listitem" class="graf-col${elegido(dim, d.etiqueta) ? '' : ' is-apagada'}"
                    data-dim="${esc(dim)}" data-valor="${esc(d.etiqueta)}"
                    title="${esc(mesCorto(d.etiqueta))}: ${esc(formato(d.valor))}">
                    <span class="graf-col__valor">${esc(formato(d.valor))}</span>
                    <span class="graf-col__barra" style="height:${alto}%"></span>
                    <span class="graf-col__etq">${esc(mesCorto(d.etiqueta))}</span>
                </button>`;
    }).join('')}</div>`;
}

/** Barras horizontales: lo demás. Los nombres de ruta o taller no caben debajo de una columna. */
function barras(datos, dim, formato, tope = 10) {
    if (datos.length === 0) return '<p class="graf__vacio">Sin datos en este rango.</p>';
    const vistas = datos.slice(0, tope);
    const max = Math.max(...vistas.map((d) => d.valor)) || 1;
    return `<div class="graf-barras" role="list">${vistas.map((d) => `
        <button type="button" role="listitem" class="graf-barra${elegido(dim, d.etiqueta) ? '' : ' is-apagada'}"
                data-dim="${esc(dim)}" data-valor="${esc(d.etiqueta)}"
                title="${esc(d.etiqueta)}: ${esc(formato(d.valor))}">
            <span class="graf-barra__etq">${esc(d.etiqueta)}</span>
            <span class="graf-barra__pista"><span class="graf-barra__fill" style="width:${Math.max(1, (d.valor / max) * 100)}%"></span></span>
            <span class="graf-barra__valor">${esc(formato(d.valor))}</span>
        </button>`).join('')}
        ${datos.length > tope ? `<p class="graf__resto">y ${datos.length - tope} más</p>` : ''}</div>`;
}

// ── Pintado completo ────────────────────────────────────────────────────────

function pintar() {
    const base = filtradas();

    // Cifras de cabecera: siempre sobre TODO lo filtrado.
    $('graf-kpis').innerHTML = SPEC.kpis.map((k) => `
        <div class="graf-kpi">
            <span class="graf-kpi__n">${esc(k.valor(base))}</span>
            <span class="graf-kpi__t">${esc(k.titulo)}</span>
        </div>`).join('');

    // Cada gráfico se dibuja ignorando su propia dimensión (ver filtradas()).
    $('graf-paneles').innerHTML = SPEC.paneles.map((p, i) => `
        <section class="card graf-panel${p.ancho ? ' graf-panel--ancho' : ''}">
            <h2>${esc(p.titulo)}</h2>
            <div data-panel="${i}"></div>
        </section>`).join('');

    SPEC.paneles.forEach((p, i) => {
        const filas = filtradas(p.dim);
        const datos = p.tiempo ? porMes(filas, SPEC.medida) : agrupar(filas, p.dim, SPEC.medida);
        const caja = document.querySelector(`[data-panel="${i}"]`);
        caja.innerHTML = p.tiempo
            ? columnas(datos, p.dim, SPEC.formato)
            : barras(datos, p.dim, SPEC.formato, p.tope);
    });

    pintarPastillas();
    pintarTabla(base);
}

function pintarPastillas() {
    const caja = $('graf-filtros-activos');
    if (seleccion.size === 0) {
        caja.hidden = true;
        caja.innerHTML = '';
        return;
    }
    caja.hidden = false;
    const pastillas = [];
    for (const [dim, valores] of seleccion) {
        for (const v of valores) {
            pastillas.push(`<button type="button" class="graf-pastilla" data-quitar-dim="${esc(dim)}" data-quitar-valor="${esc(v)}">
                <span class="graf-pastilla__dim">${esc(SPEC.etiquetas[dim] ?? dim)}</span> ${esc(v)} <span aria-hidden="true">×</span></button>`);
        }
    }
    caja.innerHTML = pastillas.join('')
        + '<button type="button" class="link" id="graf-limpiar">Quitar todos</button>';
}

/** La tabla es la versión leíble de los gráficos: sin ella, los datos solo existen en píxeles. */
function pintarTabla(filas) {
    const cols = SPEC.tabla;
    const vistas = filas.slice(0, 200);
    $('graf-tabla').innerHTML = `
        <table class="table">
            <thead><tr>${cols.map((c) => `<th>${esc(c.titulo)}</th>`).join('')}</tr></thead>
            <tbody>${vistas.map((f) => `<tr>${cols.map((c) => `<td>${esc(c.valor(f))}</td>`).join('')}</tr>`).join('')}</tbody>
        </table>
        ${filas.length > vistas.length ? `<p class="muted">Se muestran las primeras ${vistas.length} de ${num(filas.length)} filas.</p>` : ''}`;
}

// ── Eventos ─────────────────────────────────────────────────────────────────

document.addEventListener('click', (ev) => {
    const marca = ev.target.closest('[data-dim]');
    if (marca) { alternar(marca.dataset.dim, marca.dataset.valor); return; }

    const quitar = ev.target.closest('[data-quitar-dim]');
    if (quitar) { alternar(quitar.dataset.quitarDim, quitar.dataset.quitarValor); return; }

    if (ev.target.closest('#graf-limpiar')) { seleccion.clear(); pintar(); }
});

pintar();
