/**
 * Mantenimientos › Configuración: intervalos, avisos, tipos y tasas.
 *
 * El formulario se dibuja a partir de la definición que manda el servidor, igual que la pantalla
 * de catálogos de Administración: los campos de cada catálogo viven en un solo sitio (SPEC), no
 * repetidos en el HTML.
 */
import { api, showError } from './api.js';
import { confirmar } from './confirm.js';
import { toast } from './toast.js';

const $ = (id) => document.getElementById(id);
const SPEC = JSON.parse($('config-spec').textContent);
const DATOS = JSON.parse($('config-data').textContent);
const ETIQUETAS = JSON.parse($('config-etiquetas').textContent);

const dlg = $('dlg-config');
const form = $('form-config');
const err = $('form-config-error');
const campos = $('config-campos');

const escapar = (v) => String(v ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

function control(campo, tipo, valor) {
    if (tipo === 'bool') {
        return `<label class="check"><input type="checkbox" name="${campo}" value="1" ${Number(valor) === 1 ? 'checked' : ''}> Sí</label>`;
    }
    if (tipo === 'int') {
        return `<input type="number" name="${campo}" min="0" value="${escapar(valor ?? '')}">`;
    }
    if (tipo === 'decimal') {
        // Paso fino: una tasa como 36.8523 tiene que poder escribirse tal cual.
        return `<input type="number" name="${campo}" min="0" step="0.000001" value="${escapar(valor ?? '')}">`;
    }
    if (tipo === 'iso3') {
        return `<input type="text" name="${campo}" maxlength="3" required value="${escapar(valor ?? '')}" style="text-transform:uppercase">`;
    }
    return `<input type="text" name="${campo}" maxlength="100" required value="${escapar(valor ?? '')}">`;
}

function abrir(tabla, id) {
    const spec = SPEC[tabla];
    const item = id ? (DATOS[tabla] || []).find((x) => String(x.id) === String(id)) : null;

    campos.innerHTML = Object.entries(spec.fields).map(([campo, tipo]) => `
        <label class="field">
            <span class="field__label">${escapar(ETIQUETAS[campo] ?? campo)}</span>
            ${control(campo, tipo, item ? item[campo] : null)}
        </label>`).join('');

    form.elements['id'].value = id ?? '';
    form.elements['__tabla'].value = tabla;
    $('dlg-config-title').textContent = (id ? 'Editar · ' : 'Agregar · ') + spec.label;
    err.hidden = true;
    dlg.showModal();
}

form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const tabla = form.elements['__tabla'].value;
    const id = form.elements['id'].value;
    const payload = {};
    for (const [campo, tipo] of Object.entries(SPEC[tabla].fields)) {
        const el = form.elements[campo];
        payload[campo] = tipo === 'bool' ? (el.checked ? 1 : 0) : el.value;
    }
    const r = id
        ? await api('PUT', `/api/mantenimientos/config/${tabla}/${id}`, payload)
        : await api('POST', `/api/mantenimientos/config/${tabla}`, payload);
    if (r.ok) location.reload(); else showError(err, r);
});

document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const { action, tabla, id, nombre } = btn.dataset;

    if (action === 'nuevo-config') abrir(tabla, null);
    if (action === 'editar-config') abrir(tabla, id);
    if (action === 'desactivar-config') {
        const ok = await confirmar({
            titulo: 'Desactivar',
            mensaje: `${nombre} dejará de ofrecerse al registrar. Lo ya registrado con esto se conserva.`,
            aceptar: 'Desactivar',
            peligro: true,
        });
        if (!ok) return;
        const r = await api('POST', `/api/mantenimientos/config/${tabla}/${id}/activo`, { activo: 0 });
        if (r.ok) location.reload(); else toast(r.message || 'No se pudo desactivar.', { tono: 'error' });
    }
});

// ── Aplicar un plan a categorías enteras ──
const dlgPlan = $('dlg-aplicar-plan');
const formPlan = $('form-aplicar-plan');

if (formPlan) {
    document.addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-action="aplicar-plan"]');
        if (!btn) return;
        formPlan.elements['plan_id'].value = btn.dataset.id;
        $('dlg-aplicar-title').textContent = `Aplicar «${btn.dataset.nombre}»`;
        // Se marca lo que ya usa este plan; lo demás queda libre para el plan por defecto.
        formPlan.querySelectorAll('[name="categorias[]"]').forEach((c) => {
            c.checked = c.dataset.plan === btn.dataset.id;
        });
        $('form-aplicar-error').hidden = true;
        dlgPlan.showModal();
    });

    formPlan.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const marcados = (nombre) => [...formPlan.querySelectorAll(`[name="${nombre}[]"]:checked`)].map((c) => Number(c.value));
        const r = await api('POST', `/api/mantenimientos/planes/${formPlan.elements['plan_id'].value}/categorias`, {
            categorias: marcados('categorias'),
            sin_plan: marcados('sin_plan'),
        });
        if (r.ok) location.reload(); else showError($('form-aplicar-error'), r);
    });
}

document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => b.closest('dialog').close()));
