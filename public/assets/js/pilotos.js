/** Módulo Pilotos (plan §7.3). CRUD contra /api/pilotos; recarga tras cada escritura. */
import { api, showError } from './api.js';
import { confirmar } from './confirm.js';

const dlg = document.getElementById('dlg-piloto');
const form = document.getElementById('form-piloto');
const err = document.getElementById('form-piloto-error');

/** Refresca el combobox buscable de cada select tras poblar/reset el formulario. */
function syncSelects() {
    form.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
}

/**
 * Los dos códigos de transporte son el mismo concepto en toda la región, pero cada país los
 * llama a su manera (en El Salvador, SV y SVC). La etiqueta sigue al país de la estación.
 */
const ETIQUETAS = JSON.parse(document.getElementById('pilotos-etiquetas-codigo').textContent);
const selEstacion = form.elements['estacion_id'];

function syncEtiquetasCodigo() {
    const opcion = selEstacion.tagName === 'SELECT' ? selEstacion.selectedOptions[0] : selEstacion;
    const pais = Number(opcion?.dataset.pais || 0);
    const etiquetas = ETIQUETAS[pais];
    form.querySelectorAll('[data-etiqueta-codigo]').forEach((el) => {
        const ambito = el.dataset.etiquetaCodigo;
        el.textContent = etiquetas
            ? etiquetas[ambito]
            : (ambito === 'nacional' ? 'Código de transporte nacional' : 'Código de transporte internacional');
    });
}

/**
 * Unidades asignadas: solo se ofrecen las de la estación del piloto, y las que ya lleva otro
 * motorista se bloquean. Reasignar en silencio dejaría a alguien sin su cabezal sin enterarse;
 * para eso está la ficha de la unidad, donde el cambio es explícito.
 */
const resumenUnidades = document.getElementById('unidades-resumen');

function filtrarUnidades() {
    // Vale igual para el <select> del admin y para el hidden del encargado.
    const estacion = Number(selEstacion.value) || 0;
    const editando = Number(form.elements['id'].value) || 0;

    form.querySelectorAll('#unidades-colapso .check').forEach((label) => {
        const casilla = label.querySelector('input');
        const suya = Number(label.dataset.estacion || 0);
        const deOtro = Number(label.dataset.pilotoActual || 0);
        const libre = deOtro === 0 || deOtro === editando;

        label.hidden = estacion !== 0 && suya !== estacion && !casilla.checked;
        // Una unidad de otro piloto se ve (para saber por qué no está) pero no se marca.
        casilla.disabled = !libre;
        label.classList.toggle('is-tomada', !libre);
    });
    resumirUnidades();
}

function resumirUnidades() {
    const n = form.querySelectorAll('input[name="unidades[]"]:checked').length;
    resumenUnidades.textContent = n === 0 ? 'Ninguna' : `${n} unidad${n === 1 ? '' : 'es'}`;
}

form.addEventListener('change', (ev) => {
    if (ev.target.name === 'unidades[]') resumirUnidades();
});

selEstacion.addEventListener('change', () => { syncEtiquetasCodigo(); filtrarUnidades(); });

document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const id = btn.dataset.id;

    if (btn.dataset.action === 'nuevo-piloto') {
        form.reset();
        form.elements['id'].value = '';
        err.hidden = true;
        syncSelects();
        syncEtiquetasCodigo();
        filtrarUnidades();
        document.getElementById('unidades-colapso').open = false;
        document.getElementById('dlg-piloto-title').textContent = 'Nuevo piloto';
        dlg.showModal();
    }

    if (btn.dataset.action === 'editar-piloto') {
        const resp = await api('GET', `/api/pilotos/${id}`);
        if (!resp.ok) { alert(resp.message || 'No se pudo cargar.'); return; }
        for (const el of form.elements) {
            if (!el.name || el.name === 'id' || el.type === 'checkbox') continue;
            el.value = resp.data[el.name] ?? '';
        }
        form.elements['id'].value = resp.data.id;
        const suyas = (resp.data.unidades || []).map(String);
        form.querySelectorAll('input[name="unidades[]"]').forEach((c) => { c.checked = suyas.includes(c.value); });
        err.hidden = true;
        syncSelects();
        syncEtiquetasCodigo();
        filtrarUnidades();
        document.getElementById('unidades-colapso').open = false;
        document.getElementById('dlg-piloto-title').textContent = 'Editar piloto';
        dlg.showModal();
    }

    if (btn.dataset.action === 'eliminar-piloto') {
        const ok = await confirmar({
            titulo: 'Eliminar piloto',
            mensaje: `${btn.dataset.nombre} quedará inactivo. No se borra: conserva su historial.`,
            aceptar: 'Eliminar',
            peligro: true,
        });
        if (!ok) return;
        const resp = await api('DELETE', `/api/pilotos/${id}`);
        if (resp.ok) location.reload(); else alert(resp.message || 'No se pudo eliminar.');
    }
});

document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => b.closest('dialog').close()));

form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const payload = {};
    for (const el of form.elements) {
        if (!el.name || el.name === 'id' || el.type === 'checkbox') continue;
        payload[el.name] = el.value;
    }
    // Las unidades van como lista de ids, no como el valor de la última casilla.
    payload.unidades = [...form.querySelectorAll('input[name="unidades[]"]:checked')].map((c) => c.value);
    const id = form.elements['id'].value;
    const resp = id
        ? await api('PUT', `/api/pilotos/${id}`, payload)
        : await api('POST', '/api/pilotos', payload);
    if (resp.ok) location.reload(); else showError(err, resp);
});
