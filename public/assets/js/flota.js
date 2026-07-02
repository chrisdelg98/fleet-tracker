/**
 * Módulo Flota (plan §7.2). Alta/edición de unidades y cambio de estado (poka-yoke),
 * consumiendo /api/unidades. Tras cada escritura recarga la tabla server-rendered.
 */
import { api, showError } from './api.js';

const dlgUnidad = document.getElementById('dlg-unidad');
const dlgEstado = document.getElementById('dlg-estado');
const formUnidad = document.getElementById('form-unidad');
const formEstado = document.getElementById('form-estado');
const errUnidad = document.getElementById('form-unidad-error');
const errEstado = document.getElementById('form-estado-error');
const selCategoria = formUnidad.elements['categoria_vehiculo_id'];
const chkDisp = formUnidad.elements['en_disponibilidad'];

let dispTocadoManual = false;

// ── Poka-yoke: el check de disponibilidad hereda el default de la categoría (regla 14) ──
selCategoria.addEventListener('change', () => {
    if (dispTocadoManual) return;
    const opt = selCategoria.selectedOptions[0];
    chkDisp.checked = opt && opt.dataset.flota === '1';
});
chkDisp.addEventListener('change', () => { dispTocadoManual = true; });

// ── Poka-yoke: notas obligatorias si el estado no es OPERATIVO ──
const selEstado = formEstado.elements['estado_vehiculo'];
const txtNotas = formEstado.elements['estado_notas'];
function syncNotasReq() {
    const requiere = selEstado.value !== 'OPERATIVO';
    txtNotas.required = requiere;
    document.getElementById('estado-notas-field').style.opacity = requiere ? '1' : '0.6';
}
selEstado.addEventListener('change', syncNotasReq);

// ── Apertura de diálogos ──
document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;
    const id = btn.dataset.id;

    if (action === 'nueva-unidad') {
        formUnidad.reset();
        formUnidad.elements['id'].value = '';
        dispTocadoManual = false;
        errUnidad.hidden = true;
        document.getElementById('dlg-unidad-title').textContent = 'Nueva unidad';
        dlgUnidad.showModal();
    }

    if (action === 'editar') {
        const resp = await api('GET', `/api/unidades/${id}`);
        if (!resp.ok) { alert(resp.data.message || 'No se pudo cargar la unidad.'); return; }
        fillForm(resp.data);
        dispTocadoManual = true; // en edición el valor ya es el guardado, no re-heredar
        errUnidad.hidden = true;
        document.getElementById('dlg-unidad-title').textContent = 'Editar unidad';
        dlgUnidad.showModal();
    }

    if (action === 'estado') {
        formEstado.reset();
        formEstado.elements['id'].value = id;
        selEstado.value = btn.dataset.estado || 'OPERATIVO';
        syncNotasReq();
        errEstado.hidden = true;
        dlgEstado.showModal();
    }

    if (action === 'eliminar') {
        if (!confirm(`¿Eliminar la unidad ${btn.dataset.placa}? Queda inactiva (soft-delete).`)) return;
        const resp = await api('DELETE', `/api/unidades/${id}`);
        if (resp.ok) location.reload(); else alert(resp.data.message || 'No se pudo eliminar.');
    }
});

document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => b.closest('dialog').close()));

// ── Envío alta/edición ──
formUnidad.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const payload = collect(formUnidad);
    payload.permisos = [...formUnidad.querySelectorAll('input[name="permisos[]"]:checked')].map((c) => c.value);
    payload.en_disponibilidad = chkDisp.checked ? 1 : 0;

    const id = formUnidad.elements['id'].value;
    const resp = id
        ? await api('PUT', `/api/unidades/${id}`, payload)
        : await api('POST', '/api/unidades', payload);

    if (resp.ok) location.reload(); else showError(errUnidad, resp);
});

// ── Envío cambio de estado ──
formEstado.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const id = formEstado.elements['id'].value;
    const resp = await api('POST', `/api/unidades/${id}/estado`, {
        estado_vehiculo: selEstado.value,
        estado_notas: txtNotas.value,
    });
    if (resp.ok) location.reload(); else showError(errEstado, resp);
});

// ── Utilidades ──
function collect(form) {
    const out = {};
    for (const el of form.elements) {
        if (!el.name || el.name === 'permisos[]' || el.type === 'checkbox') continue;
        out[el.name] = el.value;
    }
    return out;
}

function fillForm(data) {
    for (const el of formUnidad.elements) {
        if (!el.name || el.name === 'permisos[]') continue;
        if (el.type === 'checkbox') { el.checked = Number(data.en_disponibilidad) === 1; continue; }
        el.value = data[el.name] ?? '';
    }
    const permisos = (data.permisos || []).map(String);
    formUnidad.querySelectorAll('input[name="permisos[]"]').forEach((c) => { c.checked = permisos.includes(c.value); });
}
