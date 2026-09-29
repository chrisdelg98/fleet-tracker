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

/** Valor abreviado para el centro de la dona, que es angosto: 1,250,000 → $1.25M, 18,940 → $18.9K.
 *  El total completo va en el tooltip. Debajo de 10 000 se muestra entero, que ahí sí cabe. */
function compacto(n, money) {
    const v = Number(n);
    const abs = Math.abs(v);
    let s;
    if (abs >= 1e6) s = (v / 1e6).toFixed(2).replace(/\.?0+$/, '') + 'M';
    else if (abs >= 1e4) s = (v / 1e3).toFixed(1).replace(/\.?0+$/, '') + 'K';
    else s = num(v);
    return money ? '$' + s : s;
}

// ── Filtro cruzado ──────────────────────────────────────────────────────────

/**
 * Filas que pasan los filtros activos.
 *
 * Al dibujar un gráfico se ignora SU propia dimensión: si no, al elegir una estación las demás
 * barras desaparecerían y no se podría comparar con el resto ni cambiar de opinión. Es la regla
 * que hace que un tablero de BI se sienta explorable.
 */
/** El valor de una fila para comparar con la selección: la fecha se reduce al mes, que es la
 *  granularidad de sus barras. Sin esto, un filtro «2026-09» nunca casaba con «2026-09-15». */
const valorDim = (f, dim) => (dim === 'fecha' ? String(f.fecha).slice(0, 7) : String(f[dim]));

function filtradas(exceptoDim = null) {
    if (seleccion.size === 0) return FILAS;
    return FILAS.filter((f) => {
        for (const [dim, valores] of seleccion) {
            if (dim === exceptoDim) continue;
            if (!valores.has(valorDim(f, dim))) return false;
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

/** Columnas verticales con eje: para el tiempo, donde el orden es la historia. */
function columnas(datos, dim, formato) {
    if (datos.length === 0) return '<p class="graf__vacio">Sin datos en este rango.</p>';
    const max = Math.max(...datos.map((d) => d.valor)) || 1;
    // Tres marcas en el eje (0, mitad, tope) y sus líneas de fondo: sin referencia, la altura
    // de una barra no se puede leer como cantidad.
    const eje = [max, Math.round(max / 2), 0];
    const cols = datos.map((d) => {
        const alto = Math.max(2, Math.round((d.valor / max) * 100));
        return `<button type="button" role="listitem" class="graf-col${elegido(dim, d.etiqueta) ? '' : ' is-apagada'}"
                    data-dim="${esc(dim)}" data-valor="${esc(d.etiqueta)}"
                    title="${esc(mesCorto(d.etiqueta))}: ${esc(formato(d.valor))}">
                    <span class="graf-col__valor">${esc(compacto(d.valor, SPEC.dinero))}</span>
                    <span class="graf-col__barra" style="height:${alto}%"></span>
                    <span class="graf-col__etq">${esc(mesCorto(d.etiqueta))}</span>
                </button>`;
    }).join('');
    return `<div class="graf-plot">
        <div class="graf-ejeY">${eje.map((v) => `<span>${esc(compacto(v, SPEC.dinero))}</span>`).join('')}</div>
        <div class="graf-cols" role="list">${cols}</div>
    </div>`;
}

/** Colores de la dona: el principal y tintes descendentes. Son 2-4 rebanadas de UNA dimensión
 *  —identidad—, así que aquí el color sí distingue; se mantiene dentro del tono de marca. */
const DONA = ['var(--color-primary)', 'var(--primary-border)', 'var(--color-ink-mute)', 'var(--color-bg-strong)'];

/** Anillo: para una dimensión de pocas categorías (nacional/internacional, propia/contratada). */
function dona(datos, dim, formato) {
    if (datos.length === 0) return '<p class="graf__vacio">Sin datos en este rango.</p>';
    const total = datos.reduce((a, d) => a + d.valor, 0) || 1;
    const R = 52, C = 2 * Math.PI * R;
    let inicio = 0;
    const arcos = datos.map((d, i) => {
        const frac = d.valor / total;
        const rot = inicio * 360 - 90;   // empieza arriba
        inicio += frac;
        return `<circle cx="60" cy="60" r="${R}" fill="none" stroke="${DONA[i] || DONA[3]}" stroke-width="15"
            stroke-dasharray="${frac * C} ${C}" transform="rotate(${rot} 60 60)"
            class="graf-dona__arco${elegido(dim, d.etiqueta) ? '' : ' is-apagada'}"
            data-dim="${esc(dim)}" data-valor="${esc(d.etiqueta)}"></circle>`;
    }).join('');
    const leyenda = datos.map((d, i) => `
        <button type="button" class="graf-leyenda${elegido(dim, d.etiqueta) ? '' : ' is-apagada'}"
                data-dim="${esc(dim)}" data-valor="${esc(d.etiqueta)}">
            <span class="graf-leyenda__punto" style="background:${DONA[i] || DONA[3]}"></span>
            <span class="graf-leyenda__etq">${esc(d.etiqueta)}</span>
            <span class="graf-leyenda__val">${esc(formato(d.valor))} · ${Math.round((d.valor / total) * 100)}%</span>
        </button>`).join('');
    return `<div class="graf-dona">
        <svg viewBox="0 0 120 120" class="graf-dona__svg" role="img" aria-label="Anillo de ${esc(dim)}">
            <circle cx="60" cy="60" r="${R}" fill="none" stroke="var(--color-bg-strong)" stroke-width="15"></circle>
            ${arcos}
            <text x="60" y="58" text-anchor="middle" class="graf-dona__total">${esc(compacto(total, SPEC.dinero))}<title>${esc(formato(total))}</title></text>
            <text x="60" y="74" text-anchor="middle" class="graf-dona__cap">Total</text>
        </svg>
        <div class="graf-leyendas">${leyenda}</div>
    </div>`;
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
            <span class="graf-kpi__ico" aria-hidden="true"><svg viewBox="0 0 20 20" width="20" height="20"><path d="${k.icono}" fill="currentColor"/></svg></span>
            <span class="graf-kpi__cuerpo">
                <span class="graf-kpi__n">${esc(k.valor(base))}</span>
                <span class="graf-kpi__t">${esc(k.titulo)}</span>
            </span>
        </div>`).join('');

    // Cada panel ocupa las columnas que le tocan por su peso (span): el gráfico de tiempo y las
    // listas largas se llevan más; las donas y las listas cortas, una. Así la hoja tiene jerarquía
    // en vez de tratar todo por igual.
    $('graf-paneles').innerHTML = SPEC.paneles.map((p, i) => `
        <section class="card graf-panel" style="grid-column: span ${p.span || 1}">
            <h2>${esc(p.titulo)}</h2>
            <div data-panel="${i}"></div>
        </section>`).join('');

    SPEC.paneles.forEach((p, i) => {
        const filas = filtradas(p.dim);
        const datos = p.tiempo ? porMes(filas, SPEC.medida) : agrupar(filas, p.dim, SPEC.medida);
        const caja = document.querySelector(`[data-panel="${i}"]`);
        caja.innerHTML = p.tiempo ? columnas(datos, p.dim, SPEC.formato)
            : p.dona ? dona(datos, p.dim, SPEC.formato)
                : barras(datos, p.dim, SPEC.formato, p.tope);
    });

    pintarPastillas();
    // Al cambiar el filtro cruzado, la tabla vuelve a la primera página (no a la búsqueda: esa la
    // conserva quien esté buscando algo puntual).
    tablaBase = base;
    tablaPag = 1;
    pintarTabla();
}

function pintarPastillas() {
    const caja = $('graf-filtros-activos');
    // Siempre en el flujo (no `hidden`): su alto está reservado en el CSS, así al aplicar el
    // primer filtro las pastillas aparecen en su sitio sin empujar el resto de la pantalla.
    if (seleccion.size === 0) {
        caja.innerHTML = '<span class="graf-activos__vacio">Pulsa una barra para filtrar.</span>';
        return;
    }
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

/**
 * La tabla es la versión leíble de los gráficos. Con búsqueda y paginación propias, sobre lo que
 * ya está en el navegador: es instantáneo, no hace falta ir al servidor por cada tecla ni página.
 *
 * El buscador vive fuera de este contenedor (en la vista), así no se redibuja al teclear y no
 * pierde el foco; aquí solo se reemplaza la tabla y el pie.
 */
const POR_PAGINA = 15;
let tablaBase = [];   // filas del filtro cruzado actual
let tablaPag = 1;
let tablaQ = '';

function pintarTabla() {
    const cols = SPEC.tabla;
    const q = tablaQ.trim().toLowerCase();
    const filas = q === ''
        ? tablaBase
        : tablaBase.filter((f) => cols.some((c) => String(c.valor(f)).toLowerCase().includes(q)));

    const paginas = Math.max(1, Math.ceil(filas.length / POR_PAGINA));
    if (tablaPag > paginas) tablaPag = paginas;
    const desde = (tablaPag - 1) * POR_PAGINA;
    const vistas = filas.slice(desde, desde + POR_PAGINA);

    const pager = paginas <= 1 ? '' : `
        <div class="graf-pager">
            <button type="button" class="btn btn--linea btn--sm" data-tabla-pag="${tablaPag - 1}" ${tablaPag === 1 ? 'disabled' : ''}>‹ Anterior</button>
            <span class="muted">Página ${tablaPag} de ${paginas}</span>
            <button type="button" class="btn btn--linea btn--sm" data-tabla-pag="${tablaPag + 1}" ${tablaPag === paginas ? 'disabled' : ''}>Siguiente ›</button>
        </div>`;

    $('graf-tabla').innerHTML = `
        <table class="table">
            <thead><tr>${cols.map((c) => `<th${c.clase ? ` class="${c.clase}"` : ''}>${esc(c.titulo)}</th>`).join('')}</tr></thead>
            <tbody>${vistas.length === 0
                ? `<tr><td colspan="${cols.length}" class="muted" style="padding:var(--sp-4)">Sin resultados${q ? ' para «' + esc(tablaQ) + '»' : ''}.</td></tr>`
                : vistas.map((f) => `<tr>${cols.map((c) => {
                    const v = esc(c.valor(f));
                    return c.clase ? `<td class="${c.clase}" title="${v}">${v}</td>` : `<td>${v}</td>`;
                }).join('')}</tr>`).join('')}</tbody>
        </table>
        <div class="graf-tabla__pie">
            <span class="muted">${filas.length === 0 ? '0 filas' : `${desde + 1}–${desde + vistas.length} de ${num(filas.length)}`}</span>
            ${pager}
        </div>`;
}

// ── Eventos ─────────────────────────────────────────────────────────────────

document.addEventListener('click', (ev) => {
    const marca = ev.target.closest('[data-dim]');
    if (marca) { alternar(marca.dataset.dim, marca.dataset.valor); return; }

    const quitar = ev.target.closest('[data-quitar-dim]');
    if (quitar) { alternar(quitar.dataset.quitarDim, quitar.dataset.quitarValor); return; }

    if (ev.target.closest('#graf-limpiar')) { seleccion.clear(); pintar(); return; }

    // Paginación de la tabla: solo redibuja la tabla, no los gráficos.
    const pag = ev.target.closest('[data-tabla-pag]');
    if (pag) { tablaPag = Number(pag.dataset.tablaPag); pintarTabla(); }
});

// Búsqueda en la tabla: sobre lo ya cargado, así que responde a cada tecla sin ir al servidor.
$('graf-tabla-buscar')?.addEventListener('input', (ev) => {
    tablaQ = ev.target.value;
    tablaPag = 1;
    pintarTabla();
});

pintar();
