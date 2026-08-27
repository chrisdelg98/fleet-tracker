/**
 * Navegación responsive: colapsar la sidebar en escritorio (más espacio) y abrirla como
 * panel deslizable (off-canvas) en tablet/móvil. Progresivo: sin JS, la sidebar se ve igual.
 */
const shell = document.querySelector('.app-shell');
if (shell) {
    const toggle = document.getElementById('nav-toggle');   // hamburguesa (topbar)
    const collapse = document.getElementById('nav-close');  // "contraer" (dentro de la sidebar)
    const backdrop = document.getElementById('nav-backdrop');

    const isDesktop = () => window.matchMedia('(min-width: 1181px)').matches;

    // El estado inicial (colapso y secciones) lo aplica el script inline del layout, antes
    // del primer pintado. Aquí solo se maneja la interacción y se guarda lo que el usuario elige.

    const openMobile = () => { shell.classList.add('is-nav-open'); toggle?.setAttribute('aria-expanded', 'true'); };
    const closeMobile = () => { shell.classList.remove('is-nav-open'); toggle?.setAttribute('aria-expanded', 'false'); };
    const toggleDesktop = () => {
        const collapsed = shell.classList.toggle('is-collapsed');
        localStorage.setItem('navCollapsed', collapsed ? '1' : '0');
    };

    toggle?.addEventListener('click', () => {
        if (isDesktop()) {
            toggleDesktop();
        } else {
            shell.classList.contains('is-nav-open') ? closeMobile() : openMobile();
        }
    });
    collapse?.addEventListener('click', () => (isDesktop() ? toggleDesktop() : closeMobile()));
    backdrop?.addEventListener('click', closeMobile);

    // Al navegar en móvil, cerrar el panel.
    shell.querySelectorAll('.sidebar__link').forEach((a) => a.addEventListener('click', () => {
        if (!isDesktop()) closeMobile();
    }));

    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMobile(); });
    window.addEventListener('resize', () => { if (isDesktop()) closeMobile(); });

    // ── Secciones colapsables del menú (acordeón). Recuerda qué secciones dejaste abiertas. ──
    const readSections = () => { try { return JSON.parse(localStorage.getItem('navSections') || '{}'); } catch { return {}; } };
    shell.querySelectorAll('.sidebar__group-toggle').forEach((btn) => {
        btn.addEventListener('click', () => {
            const group = btn.closest('.sidebar__group');
            const open = group.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            const state = readSections();
            state[group.dataset.section] = open;
            localStorage.setItem('navSections', JSON.stringify(state));
        });
    });
}

// ── Menú de usuario de la topbar (identidad + cerrar sesión) ──
const userMenu = document.querySelector('[data-usermenu]');
if (userMenu) {
    const trigger = userMenu.querySelector('[data-usermenu-trigger]');
    const panel = userMenu.querySelector('[data-usermenu-panel]');

    const close = () => {
        userMenu.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
        panel.hidden = true;
    };

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        const abrir = panel.hidden;
        panel.hidden = !abrir;
        userMenu.classList.toggle('is-open', abrir);
        trigger.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    });

    document.addEventListener('click', (e) => { if (!userMenu.contains(e.target)) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
}
