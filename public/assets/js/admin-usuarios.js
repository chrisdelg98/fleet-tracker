/** Administración › Usuarios. Coherencia rol/estación en el form; contraseña opcional al editar. */
import { api, showError } from './api.js';

const dlg = document.getElementById('dlg-usuario');
const form = document.getElementById('form-usuario');
const err = document.getElementById('form-usuario-error');
const rolesSinEstacion = JSON.parse(dlg.dataset.rolesSinEstacion);
const selRol = form.elements['rol'];
const estacionField = document.getElementById('usuario-estacion-field');
const selEstacion = form.elements['estacion_id'];
const passReq = document.getElementById('pass-req');
const passHint = document.getElementById('pass-hint');

/** Refresca el combobox buscable de cada select tras poblar/reset el formulario. */
function syncSelects() {
    form.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
}

// Poka-yoke: los roles globales/regionales no llevan estación.
function syncEstacion() {
    const sinEstacion = rolesSinEstacion.includes(selRol.value);
    estacionField.style.display = sinEstacion ? 'none' : '';
    selEstacion.required = !sinEstacion;
    if (sinEstacion) selEstacion.value = '';
}
selRol.addEventListener('change', syncEstacion);

function setPasswordMode(editando) {
    form.elements['password'].required = !editando;
    passReq.hidden = editando;
    passHint.hidden = !editando;
}

document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const id = btn.dataset.id;

    if (btn.dataset.action === 'nuevo-usuario') {
        form.reset();
        form.elements['id'].value = '';
        setPasswordMode(false);
        syncSelects();
        syncEstacion();
        err.hidden = true;
        document.getElementById('dlg-usuario-title').textContent = 'Nuevo usuario';
        dlg.showModal();
    }

    if (btn.dataset.action === 'editar-usuario') {
        const resp = await api('GET', `/api/usuarios/${id}`);
        if (!resp.ok) { alert(resp.message || 'No se pudo cargar.'); return; }
        form.reset();
        form.elements['nombre'].value = resp.data.nombre;
        form.elements['email'].value = resp.data.email;
        form.elements['rol'].value = resp.data.rol;
        form.elements['estacion_id'].value = resp.data.estacion_id ?? '';
        form.elements['id'].value = resp.data.id;
        setPasswordMode(true);
        syncSelects();
        syncEstacion();
        err.hidden = true;
        document.getElementById('dlg-usuario-title').textContent = 'Editar usuario';
        dlg.showModal();
    }

    if (btn.dataset.action === 'activo-usuario') {
        const activar = btn.dataset.activo === '0';
        const resp = await api('POST', `/api/usuarios/${id}/activo`, { activo: activar });
        if (resp.ok) location.reload(); else alert(resp.message || 'No se pudo actualizar.');
    }
});

document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => b.closest('dialog').close()));

form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const payload = {};
    for (const el of form.elements) {
        if (!el.name || el.name === 'id') continue;
        if (el.name === 'password' && el.value === '') continue; // no cambiar contraseña
        payload[el.name] = el.value;
    }
    const id = form.elements['id'].value;
    const resp = id
        ? await api('PUT', `/api/usuarios/${id}`, payload)
        : await api('POST', '/api/usuarios', payload);
    if (resp.ok) location.reload(); else showError(err, resp);
});
