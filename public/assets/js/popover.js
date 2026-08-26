/**
 * Piezas compartidas del popover flotante (.popover en app.css): crear el panel, colocarlo
 * junto a su ancla sin taparse con la topbar y saber si el ancla sigue a la vista.
 * Lo usan el timeline (detalle de un bloque) y los infotip de los formularios.
 */

/** Panel vacío y oculto, montado en <body> hasta que alguien lo reubique. */
export function crearPanel() {
    const pop = document.createElement('div');
    pop.className = 'popover';
    pop.hidden = true;
    document.body.appendChild(pop);
    return pop;
}

/**
 * Un <dialog> modal se pinta en el "top layer", por encima de cualquier z-index del documento.
 * Para que el popover se vea dentro de un modal tiene que colgar del propio <dialog>.
 */
export function montarJuntoA(pop, ancla) {
    const raiz = ancla.closest('dialog[open]') ?? document.body;
    if (pop.parentNode !== raiz) {
        raiz.appendChild(pop);
    }
}

/**
 * Encima del ancla si cabe; si no, debajo. El techo no es la ventana sino el borde inferior
 * de la topbar (es sticky), y nunca se sale por abajo ni por los lados. Coordenadas de
 * viewport: el panel es position:fixed, así vale igual colgando de <body> o de un <dialog>.
 */
export function colocar(ancla, pop) {
    const r = ancla.getBoundingClientRect();
    const p = pop.getBoundingClientRect();
    const margen = 8;
    const techo = (document.querySelector('.topbar')?.getBoundingClientRect().bottom ?? 0) + margen;
    const piso = window.innerHeight - margen;

    let top = r.top - p.height - margen >= techo ? r.top - p.height - margen : r.bottom + margen;
    top = Math.min(Math.max(top, techo), Math.max(techo, piso - p.height));

    let left = r.left + r.width / 2 - p.width / 2;
    left = Math.max(margen, Math.min(left, window.innerWidth - p.width - margen));

    pop.style.top = `${top}px`;
    pop.style.left = `${left}px`;
}

/** ¿El ancla sigue visible entre la topbar y el borde inferior? */
export function aLaVista(ancla) {
    const r = ancla.getBoundingClientRect();
    const techo = document.querySelector('.topbar')?.getBoundingClientRect().bottom ?? 0;
    return r.bottom > techo && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth;
}
