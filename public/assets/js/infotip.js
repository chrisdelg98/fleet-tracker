/**
 * Ayuda y detalle contextual: abre el mismo popover del timeline junto al elemento que lo
 * dispara. Se asoma al pasar el ratón o al enfocar, y queda anclado al hacer clic.
 * Delegación en document, así funciona también con filas pintadas por JS.
 *
 *   Texto:      <button class="infotip" data-infotip="Explicación…">i</button>
 *   Con datos:  <button data-infotip-titulo="Retorno tomado"
 *                       data-infotip-datos='[["Ruta","CR → SV"],["Solicita","EFL CR"]]'>…</button>
 */
import { crearPanel, colocar, aLaVista, montarJuntoA } from './popover.js';

const SELECTOR = '[data-infotip], [data-infotip-datos]';
const pop = crearPanel();
let anclado = null;
let actual = null;

const escapar = (t) => String(t ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

function contenido(el, fijo) {
    const cierre = fijo ? '<button type="button" class="popover__close" aria-label="Cerrar">&times;</button>' : '';
    const titulo = el.dataset.infotipTitulo ? `<div class="popover__title">${escapar(el.dataset.infotipTitulo)}</div>` : '';

    let cuerpo = `<p class="popover__texto">${escapar(el.dataset.infotip)}</p>`;
    if (el.dataset.infotipDatos) {
        // Pares [etiqueta, valor]; el escape vive aquí, así nadie inyecta HTML desde los datos.
        const filas = JSON.parse(el.dataset.infotipDatos)
            .map(([k, v]) => `<div class="popover__row"><dt>${escapar(k)}</dt><dd>${escapar(v)}</dd></div>`)
            .join('');
        cuerpo = `<dl class="popover__body">${filas}</dl>`;
    }
    return cierre + titulo + cuerpo;
}

function mostrar(el, fijo) {
    pop.innerHTML = contenido(el, fijo);
    pop.classList.toggle('is-pinned', fijo);
    montarJuntoA(pop, el);
    pop.hidden = false;
    colocar(el, pop);
    actual = el;
}

function cerrar() {
    pop.hidden = true;
    pop.classList.remove('is-pinned');
    anclado = null;
    actual = null;
}

document.addEventListener('mouseover', (e) => {
    const el = e.target.closest(SELECTOR);
    if (el && !anclado && el !== actual) mostrar(el, false);
});

document.addEventListener('mouseout', (e) => {
    const el = e.target.closest(SELECTOR);
    if (el && !anclado && !pop.contains(e.relatedTarget)) cerrar();
});

document.addEventListener('focusin', (e) => {
    const el = e.target.closest(SELECTOR);
    if (el && !anclado) mostrar(el, false);
});

document.addEventListener('click', (e) => {
    if (e.target.closest('.popover__close')) { cerrar(); return; }
    const el = e.target.closest(SELECTOR);
    if (el) {
        if (anclado === el) { cerrar(); return; }
        anclado = el;
        mostrar(el, true);
        return;
    }
    if (anclado && !pop.contains(e.target)) cerrar();
});

document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrar(); });

const reposicionar = () => {
    if (!actual) return;
    if (!aLaVista(actual)) { cerrar(); return; }
    colocar(actual, pop);
};
window.addEventListener('resize', reposicionar);
document.addEventListener('scroll', reposicionar, true);
