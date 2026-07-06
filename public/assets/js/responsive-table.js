/**
 * Tablas responsive (estándar reutilizable). Para cada <table class="table">:
 *   1. Copia el texto de cada <th> al atributo data-label de la celda correspondiente,
 *      para el modo "tarjeta" en móvil (cada fila se apila como Etiqueta: valor).
 *   2. La envuelve en .table-scroll para permitir scroll horizontal en anchos intermedios.
 * Se re-aplica automáticamente cuando el tbody cambia (tablas dinámicas como el dashboard).
 */

function labelize(table) {
    const headers = [...table.querySelectorAll('thead th')].map((th) => th.textContent.trim());
    if (!headers.length) return;
    table.querySelectorAll('tbody tr').forEach((tr) => {
        [...tr.children].forEach((td, i) => {
            if (td.hasAttribute('colspan')) return; // filas de mensaje (vacío / cargando)
            td.setAttribute('data-label', headers[i] || '');
        });
    });
}

function wrap(table) {
    const parent = table.parentElement;
    if (!parent || parent.classList.contains('table-scroll')) return;
    const scroller = document.createElement('div');
    scroller.className = 'table-scroll';
    parent.insertBefore(scroller, table);
    scroller.appendChild(table);
}

function enhance(root = document) {
    root.querySelectorAll('table.table').forEach((table) => {
        wrap(table);
        labelize(table);
        const tbody = table.querySelector('tbody');
        if (tbody && !tbody.dataset.rtObserved) {
            tbody.dataset.rtObserved = '1';
            new MutationObserver(() => labelize(table)).observe(tbody, { childList: true });
        }
    });
}

if (document.readyState !== 'loading') {
    enhance();
} else {
    document.addEventListener('DOMContentLoaded', () => enhance());
}
