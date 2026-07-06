/**
 * Histórico: abre el detalle de una fila de bitácora en un modal legible.
 * El contenido ya viene renderizado (antes → después) dentro de un <template> por fila;
 * aquí solo lo movemos al diálogo compartido y lo mostramos.
 */
const dlg = document.getElementById('dlg-detalle');
if (dlg) {
    const body = document.getElementById('detalle-body');
    document.addEventListener('click', (ev) => {
        const open = ev.target.closest('[data-detalle-open]');
        if (open) {
            const tpl = document.getElementById(open.dataset.detalleOpen);
            body.innerHTML = tpl ? tpl.innerHTML : '<p class="muted">Sin detalle.</p>';
            dlg.showModal();
            return;
        }
        if (ev.target.closest('[data-detalle-close]')) dlg.close();
    });
    // Cerrar al hacer clic fuera del contenido (en el backdrop).
    dlg.addEventListener('click', (ev) => { if (ev.target === dlg) dlg.close(); });
}
