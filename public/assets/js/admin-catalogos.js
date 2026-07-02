/** Administración › Catálogos. Formulario dirigido por la spec de cada tabla. */
import { api, showError } from './api.js';

const specs = JSON.parse(document.getElementById('catalogos-spec').textContent);
const data = JSON.parse(document.getElementById('catalogos-data').textContent);
const regiones = JSON.parse(document.getElementById('catalogos-regiones').textContent);

const dlg = document.getElementById('dlg-catalogo');
const form = document.getElementById('form-catalogo');
const err = document.getElementById('form-catalogo-error');
const fieldsBox = document.getElementById('catalogo-fields');
const title = document.getElementById('dlg-catalogo-title');

function buildFields(tabla, item) {
    const fields = specs[tabla].fields;
    fieldsBox.innerHTML = '';
    for (const [campo, tipo] of Object.entries(fields)) {
        const label = campo.charAt(0).toUpperCase() + campo.slice(1).replace(/_/g, ' ');
        const val = item ? item[campo] : '';
        const wrap = document.createElement('label');
        wrap.className = 'field';
        let control;
        if (tipo === 'bool') {
            control = `<label class="check"><input type="checkbox" name="${campo}" value="1" ${Number(val) === 1 ? 'checked' : ''}> Sí</label>`;
        } else if (tipo === 'int') {
            control = `<input type="number" name="${campo}" min="0" value="${val ?? ''}">`;
        } else if (tipo === 'iso2') {
            control = `<input type="text" name="${campo}" maxlength="2" required value="${escapeAttr(val)}">`;
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
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const tabla = btn.dataset.tabla;
    const id = btn.dataset.id;

    if (btn.dataset.action === 'nuevo-catalogo') {
        form.reset();
        form.elements['id'].value = '';
        form.elements['__tabla'].value = tabla;
        buildFields(tabla, null);
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
        err.hidden = true;
        title.textContent = `Editar · ${specs[tabla].label}`;
        dlg.showModal();
    }

    if (btn.dataset.action === 'activo-catalogo') {
        if (!confirm('¿Desactivar este registro del catálogo?')) return;
        const resp = await api('POST', `/api/catalogos/${tabla}/${id}/activo`, { activo: false });
        if (resp.ok) location.reload(); else alert(resp.data.message || 'No se pudo actualizar.');
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

function escapeAttr(v) {
    return String(v ?? '').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}
