/**
 * Modales declarativos reutilizables (<dialog>).
 *   - [data-modal-open="ID"]  abre el <dialog> con ese id
 *   - [data-modal-close]      cierra el <dialog> contenedor
 *   - clic en el backdrop del propio <dialog> también cierra
 * Delegación en document para que sirva con contenido renderizado por el servidor.
 */
document.addEventListener('click', (ev) => {
    const opener = ev.target.closest('[data-modal-open]');
    if (opener) {
        const dlg = document.getElementById(opener.dataset.modalOpen);
        if (dlg && typeof dlg.showModal === 'function') dlg.showModal();
        return;
    }
    if (ev.target.closest('[data-modal-close]')) {
        ev.target.closest('dialog')?.close();
        return;
    }
    if (ev.target.tagName === 'DIALOG') ev.target.close(); // backdrop
});
