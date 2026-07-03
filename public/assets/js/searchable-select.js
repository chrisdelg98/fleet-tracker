/**
 * Desplegable buscable — estándar de UI (AGENTS.md §Convenciones 9).
 * Realza cada <select> de formulario a un combobox con filtro de texto, sin librerías.
 * El <select> nativo permanece como fuente de verdad (envío del form y .value siguen
 * funcionando); este componente solo aporta la búsqueda y sincroniza su vista con el
 * evento `change` del select. Para excluir un select, marcarlo con data-no-search.
 */

/** Realza todos los <select> dentro de `root` (omite los ya realzados y los data-no-search). */
export function enhanceSelects(root = document) {
    root.querySelectorAll('select:not([data-no-search])').forEach((sel) => {
        if (!sel.dataset.ssEnhanced) {
            new SearchableSelect(sel);
        }
    });
}

class SearchableSelect {
    constructor(select) {
        this.select = select;
        select.dataset.ssEnhanced = '1';
        this.boundReposition = () => this.positionList();
        this.portalRoot = select.closest('dialog') || document.body;

        this.wrap = document.createElement('div');
        this.wrap.className = 'ss';
        select.parentNode.insertBefore(this.wrap, select);
        this.wrap.appendChild(select);
        select.classList.add('ss__native');

        this.input = document.createElement('input');
        this.input.type = 'text';
        this.input.className = 'ss__input';
        this.input.autocomplete = 'off';
        this.input.placeholder = 'Buscar…';
        if (select.required) this.input.setAttribute('aria-required', 'true');

        this.list = document.createElement('ul');
        this.list.className = 'ss__list';
        this.list.hidden = true;
    this.portalRoot.appendChild(this.list);

        this.wrap.append(this.input);

        this.buildOptions();
        this.syncFromNative();

        this.input.addEventListener('focus', () => this.open());
        this.input.addEventListener('input', () => { this.open(); this.filter(this.input.value); });
        this.input.addEventListener('keydown', (e) => this.onKey(e));
        document.addEventListener('click', (e) => {
            if (!this.wrap.contains(e.target) && !this.list.contains(e.target)) this.close();
        });
        // Cuando el valor del select cambia (incluida la carga en edición) refresca la vista.
        select.addEventListener('change', () => this.syncFromNative());
    }

    buildOptions() {
        this.items = [];
        this.list.innerHTML = '';
        const addOption = (opt, groupLabel) => {
            if (opt.value === '' && opt.disabled) return;
            const li = document.createElement('li');
            li.className = 'ss__opt';
            li.textContent = opt.textContent;
            li.dataset.value = opt.value;
            li.dataset.search = (opt.textContent + ' ' + (groupLabel || '')).toLowerCase();
            li.addEventListener('mousedown', (e) => { e.preventDefault(); this.choose(opt.value, opt.textContent); });
            this.list.appendChild(li);
            this.items.push(li);
        };
        for (const child of this.select.children) {
            if (child.tagName === 'OPTGROUP') {
                const g = document.createElement('li');
                g.className = 'ss__group';
                g.textContent = child.label;
                this.list.appendChild(g);
                for (const opt of child.children) addOption(opt, child.label);
            } else {
                addOption(child);
            }
        }
    }

    syncFromNative() {
        const opt = this.select.selectedOptions[0];
        this.input.value = opt && opt.value !== '' ? opt.textContent.trim() : '';
    }

    open() {
        this.list.hidden = false;
        this.wrap.classList.add('is-open');
        this.list.classList.add('is-open');
        this.positionList();
        window.addEventListener('resize', this.boundReposition);
        document.addEventListener('scroll', this.boundReposition, true);
        this.filter('');
    }

    close() {
        this.list.hidden = true;
        this.wrap.classList.remove('is-open');
        this.list.classList.remove('is-open');
        window.removeEventListener('resize', this.boundReposition);
        document.removeEventListener('scroll', this.boundReposition, true);
        this.syncFromNative(); // descarta texto tecleado sin elegir
    }

    positionList() {
        if (this.list.hidden) return;

        const rect = this.input.getBoundingClientRect();
        const viewportHeight = window.innerHeight;
        const availableBelow = viewportHeight - rect.bottom - 12;
        const availableAbove = rect.top - 12;
        const openUpward = availableBelow < 220 && availableAbove > availableBelow;
        const maxHeight = Math.max(120, Math.min(240, openUpward ? availableAbove : availableBelow));

        this.list.style.left = `${rect.left}px`;
        this.list.style.width = `${rect.width}px`;
        this.list.style.maxHeight = `${maxHeight}px`;
        this.list.style.top = openUpward ? 'auto' : `${rect.bottom + 2}px`;
        this.list.style.bottom = openUpward ? `${viewportHeight - rect.top + 2}px` : 'auto';
    }

    filter(q) {
        const term = q.trim().toLowerCase();
        let visibles = 0;
        for (const li of this.items) {
            const match = li.dataset.search.includes(term);
            li.hidden = !match;
            if (match) visibles++;
        }
        this.list.querySelectorAll('.ss__group').forEach((g) => { g.hidden = term !== ''; });
        let empty = this.list.querySelector('.ss__empty');
        if (visibles === 0) {
            if (!empty) {
                empty = document.createElement('li');
                empty.className = 'ss__empty';
                empty.textContent = 'Sin resultados';
                this.list.appendChild(empty);
            }
            empty.hidden = false;
        } else if (empty) {
            empty.hidden = true;
        }
    }

    choose(value, text) {
        this.select.value = value;
        this.input.value = value !== '' ? text.trim() : '';
        this.close();
        this.select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    onKey(e) {
        if (e.key === 'Escape') { this.close(); return; }
        if (e.key === 'Enter') {
            const first = this.items.find((li) => !li.hidden);
            if (first) { e.preventDefault(); this.choose(first.dataset.value, first.textContent); }
        }
    }
}

// Realce automático al cargar la página.
if (document.readyState !== 'loading') {
    enhanceSelects();
} else {
    document.addEventListener('DOMContentLoaded', () => enhanceSelects());
}
