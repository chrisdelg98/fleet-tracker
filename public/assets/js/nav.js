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

    // Estado de colapso en escritorio se recuerda entre visitas.
    if (localStorage.getItem('navCollapsed') === '1') {
        shell.classList.add('is-collapsed');
    }

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
}
