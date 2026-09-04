/**
 * Campos que se guardan en mayúsculas (placas, nombres): que se vean así mientras se escriben.
 *
 * El servidor normaliza igual, y él manda; esto solo evita la sorpresa de teclear "Cliente
 * Textil" y encontrarse "CLIENTE TEXTIL" en la tabla. Delegado, para que también alcance a
 * los campos que nacen dentro de un diálogo.
 */
document.addEventListener('input', (ev) => {
    const el = ev.target;
    if (!el.matches?.('[data-mayusculas]')) return;

    const arriba = el.value.toUpperCase();
    if (arriba === el.value) return;

    // Reasignar value manda el cursor al final; en una corrección a media palabra eso
    // reordena lo tecleado, así que se devuelve donde estaba.
    const { selectionStart: ini, selectionEnd: fin } = el;
    el.value = arriba;
    if (ini !== null) el.setSelectionRange(ini, fin);
});
