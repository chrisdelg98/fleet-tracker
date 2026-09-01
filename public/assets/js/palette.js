/**
 * Paleta de búsqueda de accesos, disponible en toda la plataforma. Se abre con "." fuera de
 * campos de texto, filtra sin acentos, se recorre con flechas y entra con Enter. La lista la
 * publica el layout en #app-accesos, ya filtrada por permisos (helpers/navegacion.php).
 */
const datos = document.getElementById('app-accesos');
if (datos) {
    const accesos = JSON.parse(datos.textContent);

    const overlay = document.createElement('div');
    overlay.className = 'palette';
    overlay.hidden = true;
    overlay.innerHTML = `
        <div class="palette__panel" role="dialog" aria-modal="true" aria-label="Buscar acceso">
            <div class="palette__search">
                <svg class="palette__lupa" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M8.75 3a5.75 5.75 0 0 1 4.55 9.27l3.72 3.72a.9.9 0 1 1-1.28 1.28l-3.72-3.72A5.75 5.75 0 1 1 8.75 3Zm0 1.8a3.95 3.95 0 1 0 0 7.9 3.95 3.95 0 0 0 0-7.9Z" fill="currentColor"/></svg>
                <input type="text" class="palette__input" placeholder="Buscar sección…" autocomplete="off" spellcheck="false">
            </div>
            <ul class="palette__list" role="listbox"></ul>
            <div class="palette__foot">
                <span><kbd>↑</kbd><kbd>↓</kbd> moverse · <kbd>Enter</kbd> abrir · <kbd>Esc</kbd> cerrar</span>
                <span class="palette__foot-clic"><kbd>.</kbd> abre este menú</span>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    const input = overlay.querySelector('.palette__input');
    const lista = overlay.querySelector('.palette__list');
    let resultados = [];
    let cursor = 0;

    /** Sin acentos y en minúsculas: "histórico" se encuentra escribiendo "historico". */
    const normalizar = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

    /** Resalta el trozo que coincide, comparando sin acentos pero recortando sobre el original. */
    function resaltar(texto, q) {
        if (!q) return texto;
        const i = normalizar(texto).indexOf(q);
        if (i < 0) return texto;
        return `${texto.slice(0, i)}<mark>${texto.slice(i, i + q.length)}</mark>${texto.slice(i + q.length)}`;
}

    function pintar(termino) {
        const q = normalizar(termino.trim());
        resultados = q === ''
            ? accesos
            : accesos.filter((a) => normalizar(`${a.label} ${a.grupo}`).includes(q));
        cursor = 0;

        if (resultados.length === 0) {
            lista.innerHTML = '<li class="palette__empty">Sin coincidencias para <strong>' + termino.trim() + '</strong></li>';
            return;
        }

        // El índice alimenta el retardo de la animación: los resultados entran en cascada.
        lista.innerHTML = resultados.map((a, i) => `
            <li class="palette__item${i === 0 ? ' is-active' : ''}" role="option" data-href="${a.href}" data-grupo="${a.grupo}" style="--i: ${Math.min(i, 12)}">
                <span class="palette__icono">${a.icono}</span>
                <span class="palette__label">${resaltar(a.label, q)}</span>
                <span class="palette__grupo">${a.grupo}</span>
                <span class="palette__enter" aria-hidden="true">↵</span>
            </li>`).join('');
}

    function mover(paso) {
        if (!resultados.length) return;
        cursor = (cursor + paso + resultados.length) % resultados.length;
        lista.querySelectorAll('.palette__item').forEach((li, i) => li.classList.toggle('is-active', i === cursor));
        lista.querySelectorAll('.palette__item')[cursor]?.scrollIntoView({ block: 'nearest' });
}

    function abrir() {
        overlay.hidden = false;
        input.value = '';
        pintar('');
        input.focus();
}

    function cerrar() {
        overlay.hidden = true;
}

    function ir() {
        const destino = resultados[cursor];
        if (destino) window.location.href = destino.href;
}

    // El punto abre la paleta, salvo mientras se escribe en un campo.
    document.addEventListener('keydown', (e) => {
        const escribiendo = e.target.closest('input, textarea, select, [contenteditable]');
        // Con un modal abierto el usuario está en otra tarea: la paleta no debe interrumpirla.
        const enModal = document.querySelector('dialog[open]') !== null;
        if (e.key === '.' && !escribiendo && !enModal && overlay.hidden) {
            e.preventDefault();
            abrir();
            return;
        }
        if (overlay.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); cerrar(); }
        if (e.key === 'ArrowDown') { e.preventDefault(); mover(1); }
        if (e.key === 'ArrowUp') { e.preventDefault(); mover(-1); }
        if (e.key === 'Enter') { e.preventDefault(); ir(); }
    });

    // En móvil no hay tecla ".": el botón de la topbar es la vía de entrada.
    document.querySelectorAll('[data-palette-open]').forEach((btn) => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); abrir(); });
    });

    input.addEventListener('input', () => pintar(input.value));
    lista.addEventListener('click', (e) => {
        const li = e.target.closest('.palette__item');
        if (li) window.location.href = li.dataset.href;
    });
    overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) cerrar(); });
}
