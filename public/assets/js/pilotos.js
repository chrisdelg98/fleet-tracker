/** Módulo Pilotos (plan §7.3). CRUD contra /api/pilotos; recarga tras cada escritura. */
import { api, showError } from './api.js';

const dlg = document.getElementById('dlg-piloto');
const form = document.getElementById('form-piloto');
const err = document.getElementById('form-piloto-error');

document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const id = btn.dataset.id;

    if (btn.dataset.action === 'nuevo-piloto') {
        form.reset();
        form.elements['id'].value = '';
        err.hidden = true;
        document.getElementById('dlg-piloto-title').textContent = 'Nuevo piloto';
        dlg.showModal();
    }

    if (btn.dataset.action === 'editar-piloto') {
        const resp = await api('GET', `/api/pilotos/${id}`);
        if (!resp.ok) { alert(resp.data.message || 'No se pudo cargar.'); return; }
        for (const el of form.elements) {
            if (el.name && el.name !== 'id') el.value = resp.data[el.name] ?? '';
        }
        form.elements['id'].value = resp.data.id;
        err.hidden = true;
        document.getElementById('dlg-piloto-title').textContent = 'Editar piloto';
        dlg.showModal();
    }

    if (btn.dataset.action === 'eliminar-piloto') {
        if (!confirm(`¿Eliminar al piloto ${btn.dataset.nombre}? Queda inactivo (soft-delete).`)) return;
        const resp = await api('DELETE', `/api/pilotos/${id}`);
        if (resp.ok) location.reload(); else alert(resp.data.message || 'No se pudo eliminar.');
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
        ? await api('PUT', `/api/pilotos/${id}`, payload)
        : await api('POST', '/api/pilotos', payload);
    if (resp.ok) location.reload(); else showError(err, resp);
});
