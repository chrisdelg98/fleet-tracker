function setPanelState(panel, open) {
    const toggle = panel.querySelector('[data-filters-toggle]');
    const more = panel.querySelector('[data-filters-more]');
    const label = toggle?.querySelector('[data-filters-toggle-label]');
    if (!toggle || !more) {
        return;
    }

    panel.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    more.hidden = !open;

    if (label) {
        label.textContent = open ? (label.dataset.closeLabel || 'Ocultar filtros') : (label.dataset.openLabel || 'Mostrar filtros');
    }
}

function initFilterPanels(root = document) {
    root.querySelectorAll('[data-filters-panel]').forEach((panel) => {
        if (panel.dataset.filtersReady === '1') {
            return;
        }
        panel.dataset.filtersReady = '1';

        const toggle = panel.querySelector('[data-filters-toggle]');
        const more = panel.querySelector('[data-filters-more]');
        if (!toggle || !more) {
            return;
        }

        const initialOpen = panel.dataset.initialOpen === 'true';
        setPanelState(panel, initialOpen);

        toggle.addEventListener('click', () => {
            setPanelState(panel, toggle.getAttribute('aria-expanded') !== 'true');
        });
    });
}

if (document.readyState !== 'loading') {
    initFilterPanels();
} else {
    document.addEventListener('DOMContentLoaded', () => initFilterPanels());
}

export { initFilterPanels };