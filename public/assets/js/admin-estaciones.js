/** Administración › Estaciones. CRUD contra /api/estaciones; recarga tras escribir. */
import { api, showError } from './api.js';

const dlg = document.getElementById('dlg-estacion');
const form = document.getElementById('form-estacion');
const err = document.getElementById('form-estacion-error');

document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const id = btn.dataset.id;

    if (btn.dataset.action === 'nueva-estacion') {
        form.reset();
        form.elements['id'].value = '';
        err.hidden = true;
        document.getElementById('dlg-estacion-title').textContent = 'Nueva estación';
        dlg.showModal();
    }

    if (btn.dataset.action === 'editar-estacion') {
        const resp = await api('GET', `/api/estaciones/${id}`);
        if (!resp.ok) { alert(resp.message || 'No se pudo cargar.'); return; }
        for (const el of form.elements) {
            if (el.name && el.name !== 'id') el.value = resp.data[el.name] ?? '';
        }
        form.elements['id'].value = resp.data.id;
        err.hidden = true;
        document.getElementById('dlg-estacion-title').textContent = 'Editar estación';
        dlg.showModal();
    }

    if (btn.dataset.action === 'activo-estacion') {
        const activar = btn.dataset.activo === '0';
        const resp = await api('POST', `/api/estaciones/${id}/activo`, { activo: activar });
        if (resp.ok) location.reload(); else alert(resp.message || 'No se pudo actualizar.');
    }
});

document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => b.closest('dialog').close()));

form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const payload = {};
    for (const el of form.elements) {
        if (el.name && el.name !== 'id') payload[el.name] = el.value;
    }
    const id = form.elements['id'].value;
    const resp = id
        ? await api('PUT', `/api/estaciones/${id}`, payload)
        : await api('POST', '/api/estaciones', payload);
    if (resp.ok) location.reload(); else showError(err, resp);
});
