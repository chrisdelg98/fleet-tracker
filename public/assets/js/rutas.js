/** Módulo Rutas (plan §7.4). CRUD contra /api/rutas; recarga tras cada escritura. */
import { api, showError } from './api.js';

const dlg = document.getElementById('dlg-ruta');
const form = document.getElementById('form-ruta');
const err = document.getElementById('form-ruta-error');

document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const id = btn.dataset.id;

    if (btn.dataset.action === 'nueva-ruta') {
        form.reset();
        form.elements['id'].value = '';
        err.hidden = true;
        document.getElementById('dlg-ruta-title').textContent = 'Nueva ruta';
        dlg.showModal();
    }

    if (btn.dataset.action === 'editar-ruta') {
        const resp = await api('GET', `/api/rutas/${id}`);
        if (!resp.ok) { alert(resp.message || 'No se pudo cargar.'); return; }
        for (const el of form.elements) {
            if (el.name && el.name !== 'id') el.value = resp.data[el.name] ?? '';
        }
        form.elements['id'].value = resp.data.id;
        err.hidden = true;
        document.getElementById('dlg-ruta-title').textContent = 'Editar ruta';
        dlg.showModal();
    }

    if (btn.dataset.action === 'eliminar-ruta') {
        if (!confirm(`¿Eliminar la ruta ${btn.dataset.nombre}?`)) return;
        const resp = await api('DELETE', `/api/rutas/${id}`);
        if (resp.ok) location.reload(); else alert(resp.message || 'No se pudo eliminar.');
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
        ? await api('PUT', `/api/rutas/${id}`, payload)
        : await api('POST', '/api/rutas', payload);
    if (resp.ok) location.reload(); else showError(err, resp);
});
