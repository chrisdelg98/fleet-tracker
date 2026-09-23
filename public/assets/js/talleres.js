/** Mantenimientos › Talleres: alta, edición y activar/desactivar. */
import { api, showError } from './api.js';
import { confirmar } from './confirm.js';
import { toast } from './toast.js';

const dlg = document.getElementById('dlg-taller');
const form = document.getElementById('form-taller');
const err = document.getElementById('form-taller-error');
const CAMPOS = ['nombre', 'estacion_id', 'telefonos', 'notas'];

function abrirNuevo() {
    form.reset();
    form.elements['id'].value = '';
    document.getElementById('dlg-taller-title').textContent = 'Nuevo taller';
    // La estación solo se elige al crear: moverlo después dejaría su historial en otra estación.
    document.getElementById('taller-estacion-field').hidden = false;
    err.hidden = true;
    form.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
    dlg.showModal();
    form.elements['nombre'].focus();
}

async function abrirEdicion(id) {
    const r = await api('GET', `/api/talleres/${id}`);
    if (!r.ok) { toast(r.message || 'No se pudo cargar.', { tono: 'error' }); return; }
    form.reset();
    form.elements['id'].value = r.data.id;
    for (const campo of CAMPOS) {
        if (form.elements[campo]) form.elements[campo].value = r.data[campo] ?? '';
    }
    form.elements['es_propio'].checked = Number(r.data.es_propio) === 1;
    document.getElementById('dlg-taller-title').textContent = `Editar ${r.data.nombre}`;
    document.getElementById('taller-estacion-field').hidden = true;
    err.hidden = true;
    dlg.showModal();
}

form?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const payload = { es_propio: form.elements['es_propio'].checked ? 1 : 0 };
    for (const campo of CAMPOS) payload[campo] = form.elements[campo]?.value ?? '';
    const id = form.elements['id'].value;
    const r = id
        ? await api('PUT', `/api/talleres/${id}`, payload)
        : await api('POST', '/api/talleres', payload);
    if (r.ok) location.reload(); else showError(err, r);
});

document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const { action, id, nombre } = btn.dataset;

    if (action === 'nuevo-taller') abrirNuevo();
    if (action === 'editar-taller') abrirEdicion(id);
    if (action === 'activo-taller') {
        const activar = btn.dataset.activo === '1';
        if (!activar) {
            const ok = await confirmar({
                titulo: 'Desactivar taller',
                mensaje: `${nombre} dejará de ofrecerse al registrar. Su historial se conserva y puedes activarlo cuando quieras.`,
                aceptar: 'Desactivar',
                peligro: true,
            });
            if (!ok) return;
        }
        const r = await api('POST', `/api/talleres/${id}/activo`, { activo: activar ? 1 : 0 });
        if (r.ok) location.reload(); else toast(r.message || 'No se pudo cambiar.', { tono: 'error' });
    }
});
