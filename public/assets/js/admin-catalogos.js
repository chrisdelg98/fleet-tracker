/** Administración › Catálogos. Formulario dirigido por la spec de cada tabla. */
import { api, showError, mensajeError } from './api.js';
import { enhanceSelects } from './searchable-select.js';
import { confirmar } from './confirm.js';

const specs = JSON.parse(document.getElementById('catalogos-spec').textContent);
const data = JSON.parse(document.getElementById('catalogos-data').textContent);
const regiones = JSON.parse(document.getElementById('catalogos-regiones').textContent);

const dlg = document.getElementById('dlg-catalogo');
const form = document.getElementById('form-catalogo');
const err = document.getElementById('form-catalogo-error');
const fieldsBox = document.getElementById('catalogo-fields');
const title = document.getElementById('dlg-catalogo-title');
const catalogTabs = Array.from(document.querySelectorAll('[data-catalogo-tab]'));
const catalogPanels = Array.from(document.querySelectorAll('[data-catalogo-panel]'));

/**
 * El catálogo activo vive en la URL (#permisos_especiales). Guardar recarga la página para
 * releer del servidor, y sin esto la recarga devolvía siempre al primer catálogo: se perdía
 * la sección en la que estabas trabajando. De paso, la pestaña queda enlazable.
 */
function activateCatalog(tabla, recordar = true) {
    catalogTabs.forEach((tab) => {
        const active = tab.dataset.catalogoTab === tabla;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    catalogPanels.forEach((panel) => {
        const active = panel.dataset.catalogoPanel === tabla;
        panel.hidden = !active;
        panel.classList.toggle('is-active', active);
    });
    if (recordar) {
        // replaceState y no location.hash: no ensucia el historial ni salta el scroll.
        history.replaceState(null, '', `#${tabla}`);
    }
}

if (catalogTabs.length > 0) {
    const enUrl = decodeURIComponent(location.hash.replace('#', ''));
    const valido = catalogTabs.some((tab) => tab.dataset.catalogoTab === enUrl);
    activateCatalog(
        valido ? enUrl : (catalogTabs.find((tab) => tab.classList.contains('is-active'))?.dataset.catalogoTab || catalogTabs[0].dataset.catalogoTab),
        valido
    );
}

const paises = JSON.parse(document.getElementById('catalogos-paises')?.textContent || '[]');
const etiquetas = JSON.parse(document.getElementById('catalogos-etiquetas')?.textContent || '{}');

function buildFields(tabla, item) {
    const fields = specs[tabla].fields;
    fieldsBox.innerHTML = '';
    for (const [campo, tipo] of Object.entries(fields)) {
        const label = etiquetas[campo] || (campo.charAt(0).toUpperCase() + campo.slice(1).replace(/_/g, ' '));
        const val = item ? item[campo] : '';
        const wrap = document.createElement('label');
        wrap.className = 'field';
        let control;
        if (tipo === 'bool') {
            control = `<label class="check"><input type="checkbox" name="${campo}" value="1" ${Number(val) === 1 ? 'checked' : ''}> Sí</label>`;
        } else if (tipo === 'int') {
            control = `<input type="number" name="${campo}" min="0" value="${val ?? ''}">`;
        } else if (tipo === 'text') {
            control = `<textarea name="${campo}" rows="2" maxlength="255">${escapeTexto(val)}</textarea>`;
        } else if (tipo === 'iso2') {
            control = `<input type="text" name="${campo}" maxlength="2" required value="${escapeAttr(val)}">`;
        } else if (tipo === 'pais') {
            // Vacío = global. El alcance es por país porque el permiso lo emite una autoridad
            // nacional: el mismo trámite vale para todas las estaciones de ese país.
            const opts = paises.map((p) =>
                `<option value="${p.id}" ${String(val) === String(p.id) ? 'selected' : ''}>${p.nombre}</option>`).join('');
            control = `<select name="${campo}"><option value="">Global (todos los países)</option>${opts}</select>`;
        } else if (tipo === 'region') {
            const opts = Object.entries(regiones).map(([k, lbl]) =>
                `<option value="${k}" ${val === k ? 'selected' : ''}>${lbl}</option>`).join('');
            control = `<select name="${campo}" required>${opts}</select>`;
        } else {
            control = `<input type="text" name="${campo}" maxlength="100" required value="${escapeAttr(val)}">`;
        }
        wrap.innerHTML = `<span class="field__label">${label}</span>${control}`;
        fieldsBox.appendChild(wrap);
    }
}

document.addEventListener('click', async (ev) => {
    const catalogTab = ev.target.closest('[data-catalogo-tab]');
    if (catalogTab) {
        activateCatalog(catalogTab.dataset.catalogoTab);
    }

    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const tabla = btn.dataset.tabla;
    const id = btn.dataset.id;

    if (btn.dataset.action === 'nuevo-catalogo') {
        form.reset();
        form.elements['id'].value = '';
        form.elements['__tabla'].value = tabla;
        buildFields(tabla, null);
        enhanceSelects(fieldsBox);
        err.hidden = true;
        title.textContent = `Nuevo · ${specs[tabla].label}`;
        dlg.showModal();
    }

    if (btn.dataset.action === 'editar-catalogo') {
        const item = (data[tabla] || []).find((r) => String(r.id) === String(id));
        form.reset();
        form.elements['id'].value = id;
        form.elements['__tabla'].value = tabla;
        buildFields(tabla, item);
        enhanceSelects(fieldsBox);
        err.hidden = true;
        title.textContent = `Editar · ${specs[tabla].label}`;
        dlg.showModal();
    }

    if (btn.dataset.action === 'activo-catalogo') {
        const activo = Number(btn.dataset.activo || '1') === 1;
        const siguiente = !activo;
        const ok = await confirmar({
            titulo: siguiente ? 'Activar registro' : 'Desactivar registro',
            mensaje: siguiente
                ? 'Volverá a estar disponible en los formularios.'
                : 'Dejará de ofrecerse en los formularios; los datos que ya lo usan no cambian.',
            aceptar: siguiente ? 'Activar' : 'Desactivar',
        });
        if (!ok) return;
        const resp = await api('POST', `/api/catalogos/${tabla}/${id}/activo`, { activo: siguiente });
        if (resp.ok) location.reload(); else alert(mensajeError(resp, 'No se pudo actualizar.'));
    }
});

document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => b.closest('dialog').close()));

form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const tabla = form.elements['__tabla'].value;
    const fields = specs[tabla].fields;
    const payload = {};
    for (const [campo, tipo] of Object.entries(fields)) {
        const el = form.elements[campo];
        payload[campo] = tipo === 'bool' ? (el.checked ? 1 : 0) : el.value;
    }
    const id = form.elements['id'].value;
    const resp = id
        ? await api('PUT', `/api/catalogos/${tabla}/${id}`, payload)
        : await api('POST', `/api/catalogos/${tabla}`, payload);
    if (resp.ok) location.reload(); else showError(err, resp);
});

/** Escape para contenido de elemento (el de atributo no sirve dentro de un textarea). */
function escapeTexto(v) {
    return String(v ?? '').replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
}

function escapeAttr(v) {
    return String(v ?? '').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}
