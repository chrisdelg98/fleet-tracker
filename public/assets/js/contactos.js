/** Listas de notificación. CRUD contra /api/contactos; recarga tras cada escritura. */
import { api, showError, mensajeError } from './api.js';
import { confirmar } from './confirm.js';

const dlg = document.getElementById('dlg-lista');
const form = document.getElementById('form-lista');
const err = document.getElementById('form-lista-error');

/** Refresca el combobox buscable de cada select tras poblar o limpiar el formulario. */
function syncSelects() {
    form.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
}

document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const id = btn.dataset.id;

    if (btn.dataset.action === 'nueva-lista') {
        form.reset();
        form.elements['id'].value = '';
        err.hidden = true;
        syncSelects();
        document.getElementById('dlg-lista-title').textContent = 'Nueva lista';
        dlg.showModal();
    }

    if (btn.dataset.action === 'editar-lista') {
        const resp = await api('GET', `/api/contactos/${id}`);
        if (!resp.ok) { alert(mensajeError(resp, 'No se pudo cargar la lista.')); return; }
        for (const el of form.elements) {
            // El campo de estación del encargado va deshabilitado y sin name: no se toca.
            if (el.name && el.name !== 'id') el.value = resp.data[el.name] ?? '';
        }
        form.elements['id'].value = resp.data.id;
        err.hidden = true;
        syncSelects();
        document.getElementById('dlg-lista-title').textContent = 'Editar lista';
        dlg.showModal();
    }

    if (btn.dataset.action === 'eliminar-lista') {
        const ok = await confirmar({
            titulo: 'Eliminar lista',
            mensaje: `"${btn.dataset.nombre}" dejará de ofrecerse al reservar. Las reservas ya enviadas conservan sus correos.`,
            aceptar: 'Eliminar',
            peligro: true,
        });
        if (!ok) return;
        const resp = await api('DELETE', `/api/contactos/${id}`);
        if (resp.ok) location.reload(); else alert(mensajeError(resp, 'No se pudo eliminar.'));
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
        ? await api('PUT', `/api/contactos/${id}`, payload)
        : await api('POST', '/api/contactos', payload);
    if (resp.ok) location.reload(); else showError(err, resp);
});
