/**
 * Menú de acciones por fila (reutilizable). Cada fila trae:
 *   <div class="rowmenu" data-rowmenu>
 *     <button class="rowmenu__trigger" data-rowmenu-trigger>⋮</button>
 *     <div class="rowmenu__menu" role="menu"> …items… </div>
 *   </div>
 * Al abrir, el menú se clona y se porta a <body> con position:fixed para que NO lo recorte
 * el contenedor de la tabla (overflow hidden/scroll). Los items conservan sus data-* para
 * que el handler de la página (delegado en document) siga funcionando.
 */

let open = null; // { trigger, menu }

function closeRowMenus() {
    if (!open) return;
    open.menu.remove();
    open.trigger.setAttribute('aria-expanded', 'false');
    open = null;
}
window.closeRowMenus = closeRowMenus;

function place(trigger, menu) {
    const r = trigger.getBoundingClientRect();
    menu.style.top = `${r.bottom + 6}px`;
    menu.style.right = `${Math.max(8, window.innerWidth - r.right)}px`;
    menu.style.left = 'auto';
    // Si no cabe hacia abajo, abre hacia arriba.
    const h = menu.offsetHeight;
    if (r.bottom + 8 + h > window.innerHeight && r.top - 8 - h > 0) {
        menu.style.top = 'auto';
        menu.style.bottom = `${window.innerHeight - r.top + 6}px`;
    }
}

document.addEventListener('click', (ev) => {
    const trigger = ev.target.closest('[data-rowmenu-trigger]');
    if (trigger) {
        ev.preventDefault();
        const isSame = open && open.trigger === trigger;
        closeRowMenus();
        if (isSame) return;

        const template = trigger.parentElement.querySelector('.rowmenu__menu');
        if (!template) return;
        const menu = template.cloneNode(true);
        menu.classList.add('rowmenu__menu--portal');
        menu.hidden = false;
        document.body.appendChild(menu);
        trigger.setAttribute('aria-expanded', 'true');
        open = { trigger, menu };
        place(trigger, menu);

        // Al elegir una acción, cierra DESPUÉS de que el evento llegue al handler de la
        // página (delegado en document); por eso se difiere con setTimeout.
        menu.addEventListener('click', (e) => {
            if (e.target.closest('[data-mov], [data-action]')) setTimeout(closeRowMenus, 0);
        });
        return;
    }
    if (!ev.target.closest('.rowmenu__menu--portal')) closeRowMenus();
});

document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeRowMenus(); });
window.addEventListener('resize', closeRowMenus);
document.addEventListener('scroll', closeRowMenus, true);
