/**
 * Módulo Flota (plan §7.2). Alta/edición de unidades y cambio de estado (poka-yoke),
 * consumiendo /api/unidades. Tras cada escritura recarga la tabla server-rendered.
 */
import { api, showError, mensajeError } from './api.js';
import { confirmar } from './confirm.js';

const dlgUnidad = document.getElementById('dlg-unidad');
const dlgEstado = document.getElementById('dlg-estado');
const formUnidad = document.getElementById('form-unidad');
const formEstado = document.getElementById('form-estado');
const errUnidad = document.getElementById('form-unidad-error');
const errEstado = document.getElementById('form-estado-error');
const selCategoria = formUnidad.elements['categoria_vehiculo_id'];
const chkDisp = formUnidad.elements['en_disponibilidad'];
const placaFurgon = formUnidad.elements['placa_furgon'];
const furgonReq = formUnidad.querySelector('[data-furgon-req]');

let dispTocadoManual = false;

// ── Poka-yoke: al elegir categoría ──
selCategoria.addEventListener('change', () => {
    const opt = selCategoria.selectedOptions[0];
    // el check de disponibilidad hereda el default de la categoría (regla 14)
    if (!dispTocadoManual) chkDisp.checked = !!opt && opt.dataset.flota === '1';
    // placa de furgón obligatoria si la categoría jala furgón (ej. Cabezal)
    const requiere = !!opt && opt.dataset.requiereFurgon === '1';
    placaFurgon.required = requiere;
    furgonReq.hidden = !requiere;
});
chkDisp.addEventListener('change', () => { dispTocadoManual = true; });

/** Dispara change en los selects para que el combobox buscable refleje el valor actual. */
function syncSelects() {
    formUnidad.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
}

// ── Poka-yoke: notas obligatorias si el estado no es OPERATIVO ──
const selEstado = formEstado.elements['estado_vehiculo'];
const txtNotas = formEstado.elements['estado_notas'];
function syncNotasReq() {
    const requiere = selEstado.value !== 'OPERATIVO';
    txtNotas.required = requiere;
    document.getElementById('estado-notas-field').style.opacity = requiere ? '1' : '0.6';
}
selEstado.addEventListener('change', syncNotasReq);

/**
 * Los permisos son autorizaciones nacionales: solo se ofrecen los del país de la estación de
 * la unidad, más los globales (sin país). Evita listas con trámites que no aplican.
 */
const selEstacionUnidad = formUnidad.elements['estacion_id'];

function filtrarPermisos() {
    const opcion = selEstacionUnidad.tagName === 'SELECT'
        ? selEstacionUnidad.selectedOptions[0]
        : selEstacionUnidad;   // estación fija del encargado
    const pais = Number(opcion?.dataset.pais || 0);

    formUnidad.querySelectorAll('.checks .check').forEach((label) => {
        const suyo = Number(label.dataset.pais || 0);
        const marcado = label.querySelector('input').checked;
        const aplica = suyo === 0 || pais === 0 || suyo === pais;
        // Uno ya marcado se queda a la vista aunque no aplique: ocultarlo lo borraría al
        // guardar sin que nadie lo viera. Se muestra para que la persona decida.
        label.hidden = !aplica && !marcado;
    });
}

selEstacionUnidad.addEventListener('change', () => { filtrarPermisos(); resumirPermisos(); });

/**
 * La sección va plegada: no es obligatoria y ocupaba media pantalla. El resumen dice cuántos
 * hay marcados para no tener que abrirla solo para comprobarlo.
 */
const resumenPermisos = document.getElementById('permisos-resumen');

function resumirPermisos() {
    const n = formUnidad.querySelectorAll('input[name="permisos[]"]:checked').length;
    resumenPermisos.textContent = n === 0 ? 'Ninguno' : `${n} seleccionado${n === 1 ? '' : 's'}`;
}

formUnidad.addEventListener('change', (ev) => {
    if (ev.target.name === 'permisos[]') resumirPermisos();
});

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
        syncSelects();
        filtrarPermisos();
        resumirPermisos();
        document.getElementById('permisos-colapso').open = false;
        document.getElementById('dlg-unidad-title').textContent = 'Nueva unidad';
        dlgUnidad.showModal();
    }

    if (action === 'desbloquear') {
        const ok = await confirmar({
            titulo: 'Desbloquear unidad',
            mensaje: `${btn.dataset.placa} volverá a aparecer como disponible en el tablero.`,
            aceptar: 'Desbloquear',
        });
        if (!ok) return;
        const resp = await api('POST', `/api/unidades/${id}/desbloquear`, {});
        if (resp.ok) location.reload(); else alert(mensajeError(resp, 'No se pudo desbloquear.'));
        return;
    }

    if (action === 'editar') {
        const resp = await api('GET', `/api/unidades/${id}`);
        if (!resp.ok) { alert(mensajeError(resp, 'No se pudo cargar la unidad.')); return; }
        fillForm(resp.data);
        dispTocadoManual = true; // en edición el valor ya es el guardado, no re-heredar
        errUnidad.hidden = true;
        syncSelects();
        filtrarPermisos();
        document.getElementById('permisos-colapso').open = false;
        document.getElementById('dlg-unidad-title').textContent = 'Editar unidad';
        dlgUnidad.showModal();
    }

    if (action === 'estado') {
        formEstado.reset();
        formEstado.elements['id'].value = id;
        selEstado.value = btn.dataset.estado || 'OPERATIVO';
        selEstado.dispatchEvent(new Event('change', { bubbles: true }));
        errEstado.hidden = true;
        dlgEstado.showModal();
    }

    if (action === 'eliminar') {
        const ok = await confirmar({
            titulo: 'Eliminar unidad',
            mensaje: `${btn.dataset.placa} quedará inactiva. No se borra: conserva su historial.`,
            aceptar: 'Eliminar',
            peligro: true,
        });
        if (!ok) return;
        const resp = await api('DELETE', `/api/unidades/${id}`);
        if (resp.ok) location.reload(); else alert(mensajeError(resp, 'No se pudo eliminar.'));
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
    resumirPermisos();
}
