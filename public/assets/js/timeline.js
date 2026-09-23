/**
 * Popover del timeline (plan §7.5). Sustituye al tooltip nativo, que no se puede estilar ni
 * fijar: al pasar el ratón se asoma, al hacer clic queda anclado (con botón de cierre) y solo
 * se va con Escape, con el botón o al hacer clic fuera.
 *
 * Lo usan dos cosas: los bloques de movimiento y la celda de la unidad. La segunda existe
 * porque la pantalla mezcla unidades de varias estaciones —las de casa y las que vienen hacia
 * aquí— y hace falta saber de dónde es cada una sin salir del timeline.
 */
import { crearPanel, colocar, aLaVista } from './popover.js';

const tl = document.querySelector('.tl');
if (tl) {
    const pop = crearPanel();

    let anclado = null;      // elemento cuyo popover quedó fijo
    let actual = null;       // elemento que se está mostrando ahora

    // Los dos disparadores: el bloque de un movimiento y la celda de la unidad.
    const DISPARADOR = '[data-pop], [data-pop-unidad]';

    const fila = (etiqueta, valor) => `<div class="popover__row"><dt>${etiqueta}</dt><dd>${escapar(valor)}</dd></div>`;
    const escapar = (t) => String(t ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    /** Ficha de un movimiento dentro del popover. */
    function ficha(d, conUnidad) {
        return `
            <div class="popover__mov">
                <div class="popover__head">
                    <span class="chip ${escapar(d.estadoClase)}">${escapar(d.estado)}</span>
                    <span class="popover__id">#${escapar(d.mov)}</span>
                </div>
                <dl class="popover__body">
                    ${conUnidad ? fila('Unidad', d.unidad) : ''}
                    ${fila('Ruta', d.ruta)}
                    ${fila('Salida', d.salida)}
                    ${fila('Fin estimado', d.fin)}
                </dl>
            </div>`;
    }

    /** Ficha de la unidad: lo que identifica al vehículo, empezando por de dónde es. */
    function fichaUnidad(d) {
        return `
            <div class="popover__title">${escapar(d.placa)}</div>
            <dl class="popover__body">
                ${fila('Estación', d.estacion)}
                ${fila('País', d.pais)}
                ${fila('Categoría', d.categoria)}
                ${fila('Tipo de equipo', d.tipoEquipo)}
                ${fila('Capacidad', d.capacidad)}
                ${fila('Vehículo', d.vehiculo)}
                ${fila('Combustible', d.combustible)}
                ${fila('Alcance', d.alcance)}
                ${fila('Piloto asignado', d.piloto)}
                ${fila('Estado', d.estadoUnidad)}
            </dl>`;
    }

    function pintar(blk, fijo) {
        const cierre = fijo ? '<button type="button" class="popover__close" aria-label="Cerrar">&times;</button>' : '';
        if (blk.hasAttribute('data-pop-unidad')) {
            pop.innerHTML = cierre + fichaUnidad(blk.dataset);
            pop.classList.toggle('is-pinned', fijo);
            pop.hidden = false;
            colocar(blk, pop);
            actual = blk;
            return;
        }
        const grupo = blk.grupo || [blk];
        pop.innerHTML = cierre + (grupo.length > 1
            ? `<div class="popover__title">${grupo.length} movimientos · ${escapar(blk.dataset.unidad)}</div>`
              + grupo.map((b) => ficha(b.dataset, false)).join('')
            : ficha(blk.dataset, true));
        pop.classList.toggle('is-pinned', fijo);
        pop.hidden = false;
        colocar(blk, pop);
        actual = blk;
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
    const DIAS = tl.querySelectorAll('.tl__dia').length || 1;
    function ajustarBloques() {
        tl.querySelectorAll('.tl__row').forEach((fila) => {
            const bloques = [...fila.querySelectorAll('[data-pop]')];
            bloques.forEach((b) => {
                b.hidden = false;
                b.grupo = null;
                b.classList.toggle('tl__bloque--mini', b.offsetWidth < ANCHO_MINIMO);
            });

            // Dos viajes cortos seguidos ocupan casi el mismo píxel: se muestran como un solo
            // marcador con el número de movimientos, y el popover los lista todos.
            let base = null;
            bloques.forEach((b) => {
                if (base && b.getBoundingClientRect().left < base.getBoundingClientRect().right + 2) {
                    base.grupo.push(b);
                    b.hidden = true;
                    return;
                }
                base = b;
                base.grupo = [b];
            });

            const track = fila.querySelector('.tl__track');
            const anchoDia = track ? track.offsetWidth / DIAS : 0;

            bloques.filter((b) => !b.hidden).forEach((b) => {
                const n = b.grupo.length;
                b.classList.toggle('tl__bloque--grupo', n > 1);
                b.dataset.count = n > 1 ? String(n) : '';
                b.setAttribute('aria-label', n > 1 ? `${n} movimientos` : `Movimiento ${b.dataset.mov}`);

                // El ancho mínimo del marcador puede empujarlo al día siguiente y hacer creer
                // que el viaje cruza la medianoche: se recuesta para no salir de su día.
                b.style.transform = '';
                if (!b.classList.contains('tl__bloque--mini') || !anchoDia) return;
                const finDia = (Math.floor(b.offsetLeft / anchoDia) + 1) * anchoDia;
                const exceso = (b.offsetLeft + b.offsetWidth) - finDia;
                if (exceso > 0) b.style.transform = `translateX(${-Math.ceil(exceso)}px)`;
            });
        });
    }
    ajustarBloques();
    window.addEventListener('resize', () => { cerrar(); ajustarBloques(); });

    // El tooltip nativo estorbaría encima del popover: se guarda y se quita.
    tl.querySelectorAll(`${DISPARADOR}[title]`).forEach((blk) => {
        blk.dataset.tituloNativo = blk.title;
        blk.removeAttribute('title');
    });

    tl.addEventListener('mouseover', (e) => {
        const blk = e.target.closest(DISPARADOR);
        if (!blk || anclado || blk === actual) return;
        pintar(blk, false);
    });

    tl.addEventListener('mouseout', (e) => {
        const blk = e.target.closest(DISPARADOR);
        if (!blk || anclado) return;
        if (e.relatedTarget && pop.contains(e.relatedTarget)) return;
        cerrar();
    });

    // Foco de teclado: mismo comportamiento que el ratón.
    tl.addEventListener('focusin', (e) => {
        const blk = e.target.closest(DISPARADOR);
        if (blk && !anclado) pintar(blk, false);
    });

    tl.addEventListener('click', (e) => {
        const blk = e.target.closest(DISPARADOR);
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

    // El timeline se desplaza en horizontal y la página en vertical: el popover sigue a su
    // bloque, y si el bloque se va de la pantalla el popover se cierra en vez de quedar suelto.
    const reposicionar = () => {
        if (!actual) return;
        if (!aLaVista(actual)) { cerrar(); return; }
        colocar(actual, pop);
    };
    window.addEventListener('resize', reposicionar);
    document.addEventListener('scroll', reposicionar, true);
}
