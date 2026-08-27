/**
 * Atajo de la coma: abre el formulario principal de la pantalla — nueva reserva en el
 * dashboard, nueva unidad en Flota, nueva ruta en Rutas, etc.
 *
 * No hay una lista de pantallas: la acción principal es la que el layout pinta en la topbar
 * (set_page_meta con 'accion'). Se dispara solo si es un <button>, para no lanzar por
 * accidente las acciones que son enlaces, como "Exportar CSV".
 */
const principal = document.querySelector('.topbar__actions button.btn--primary');

if (principal) {
    // Pista descubrible sin tocar las vistas: quien pase el ratón ve el atajo.
    const etiqueta = principal.textContent.trim();
    principal.title = `${etiqueta} (tecla ,)`;

    document.addEventListener('keydown', (e) => {
        if (e.key !== ',' || e.ctrlKey || e.metaKey || e.altKey) return;
        // Igual que la paleta: ni mientras se escribe ni con un modal abierto.
        if (e.target.closest('input, textarea, select, [contenteditable]')) return;
        if (document.querySelector('dialog[open]')) return;

        e.preventDefault();
        principal.click();
    });
}
