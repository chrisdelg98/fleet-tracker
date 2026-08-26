/**
 * Popover de los bloques del timeline (plan §7.5). Sustituye al tooltip nativo, que no se
 * puede estilar ni fijar: al pasar el ratón se asoma, al hacer clic queda anclado (con botón
 * de cierre) y solo se va con Escape, con el botón o al hacer clic fuera.
 */
const tl = document.querySelector('.tl');
if (tl) {
    const pop = document.createElement('div');
    pop.className = 'popover';
    pop.hidden = true;
    document.body.appendChild(pop);

    let anclado = null;      // bloque cuyo popover quedó fijo
    let actual = null;       // bloque que se está mostrando ahora

    const fila = (etiqueta, valor) => `<div class="popover__row"><dt>${etiqueta}</dt><dd>${escapar(valor)}</dd></div>`;
    const escapar = (t) => String(t ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    function pintar(blk, fijo) {
        const d = blk.dataset;
        pop.innerHTML = `
            <div class="popover__head">
                <span class="chip ${escapar(d.estadoClase)}">${escapar(d.estado)}</span>
                <span class="popover__id">#${escapar(d.mov)}</span>
                ${fijo ? '<button type="button" class="popover__close" aria-label="Cerrar">&times;</button>' : ''}
            </div>
            <dl class="popover__body">
                ${fila('Unidad', d.unidad)}
                ${fila('Ruta', d.ruta)}
                ${fila('Salida', d.salida)}
                ${fila('Fin estimado', d.fin)}
            </dl>`;
        pop.classList.toggle('is-pinned', fijo);
        pop.hidden = false;
        colocar(blk);
        actual = blk;
    }

    /** Sobre el bloque si hay sitio; si no, debajo. Siempre dentro del ancho de la ventana. */
    function colocar(blk) {
        const r = blk.getBoundingClientRect();
        const p = pop.getBoundingClientRect();
        const margen = 8;
        const arriba = r.top - p.height - margen > 0;
        const top = arriba ? r.top - p.height - margen : r.bottom + margen;
        let left = r.left + r.width / 2 - p.width / 2;
        left = Math.max(margen, Math.min(left, window.innerWidth - p.width - margen));
        pop.style.top = `${top + window.scrollY}px`;
        pop.style.left = `${left + window.scrollX}px`;
    }

    function cerrar() {
        pop.hidden = true;
        pop.classList.remove('is-pinned');
        anclado = null;
        actual = null;
    }

    /**
     * Un bloque angosto no puede mostrar su etiqueta dentro: se marca como mini para que el
     * CSS lo deje como marca sólida y saque el texto al costado. Se recalcula al redimensionar
     * porque el ancho depende del ancho del track, no del movimiento.
     */
    const ANCHO_MINIMO = 46;
    function marcarMinis() {
        tl.querySelectorAll('[data-pop]').forEach((blk) => {
            blk.classList.toggle('tl__bloque--mini', blk.offsetWidth < ANCHO_MINIMO);
        });
    }
    marcarMinis();
    window.addEventListener('resize', marcarMinis);

    // El tooltip nativo estorbaría encima del popover: se guarda y se quita.
    tl.querySelectorAll('[data-pop][title]').forEach((blk) => {
        blk.dataset.tituloNativo = blk.title;
        blk.removeAttribute('title');
    });

    tl.addEventListener('mouseover', (e) => {
        const blk = e.target.closest('[data-pop]');
        if (!blk || anclado || blk === actual) return;
        pintar(blk, false);
    });

    tl.addEventListener('mouseout', (e) => {
        const blk = e.target.closest('[data-pop]');
        if (!blk || anclado) return;
        if (e.relatedTarget && pop.contains(e.relatedTarget)) return;
        cerrar();
    });

    // Foco de teclado: mismo comportamiento que el ratón.
    tl.addEventListener('focusin', (e) => {
        const blk = e.target.closest('[data-pop]');
        if (blk && !anclado) pintar(blk, false);
    });

    tl.addEventListener('click', (e) => {
        const blk = e.target.closest('[data-pop]');
        if (!blk) return;
        e.stopPropagation();
        if (anclado === blk) { cerrar(); return; }
        anclado = blk;
        pintar(blk, true);
    });

    pop.addEventListener('click', (e) => {
        if (e.target.closest('.popover__close')) cerrar();
    });

    document.addEventListener('click', (e) => {
        if (anclado && !pop.contains(e.target)) cerrar();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrar(); });

    // El timeline se desplaza en horizontal: el popover sigue a su bloque.
    const reposicionar = () => { if (actual) colocar(actual); };
    window.addEventListener('resize', reposicionar);
    document.addEventListener('scroll', reposicionar, true);
}
