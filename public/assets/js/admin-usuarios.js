/** Administración › Usuarios. Coherencia rol/estación en el form; contraseña opcional al editar. */
import { api, showError, mensajeError } from './api.js';
import { generarPassword, setPassVisible as verPassword, copiar } from './password.js';

const dlg = document.getElementById('dlg-usuario');
const form = document.getElementById('form-usuario');
const err = document.getElementById('form-usuario-error');
const rolesSinEstacion = JSON.parse(dlg.dataset.rolesSinEstacion);
const selRol = form.elements['rol'];
const estacionField = document.getElementById('usuario-estacion-field');
const selEstacion = form.elements['estacion_id'];
const passReq = document.getElementById('pass-req');
const passNote = document.getElementById('pass-note');
const estacionReq = document.getElementById('estacion-req');

/** Refresca el combobox buscable de cada select tras poblar/reset el formulario. */
function syncSelects() {
    form.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
}

// Poka-yoke: los roles globales/regionales no llevan estación. El campo se limpia y se
// bloquea en lugar de ocultarse, para que el formulario no cambie de forma al elegir el rol.
function syncEstacion() {
    const sinEstacion = rolesSinEstacion.includes(selRol.value);
    if (sinEstacion) selEstacion.value = '';
    selEstacion.disabled = sinEstacion;
    selEstacion.required = !sinEstacion;
    estacionReq.hidden = sinEstacion;
    estacionField.classList.toggle('is-disabled', sinEstacion);
    selEstacion.dispatchEvent(new Event('change', { bubbles: true }));
}
selRol.addEventListener('change', syncEstacion);

function setPasswordMode(editando) {
    form.elements['password'].required = !editando;
    passReq.hidden = editando;
    notaPassword(editando ? 'Déjala en blanco para no cambiarla.' : '');
}

// ── Contraseña: mostrar/ocultar y generador seguro ──
const passInput = form.elements['password'];
const passToggle = document.getElementById('pass-toggle');
const passGenerate = document.getElementById('pass-generate');

function setPassVisible(visible) {
    verPassword(passInput, passToggle, visible);
}

function notaPassword(texto) {
    passNote.textContent = texto;
}

function limpiarPassword() {
    setPassVisible(false);
}

passToggle.addEventListener('click', () => setPassVisible(passInput.type === 'password'));

passGenerate.addEventListener('click', async () => {
    passInput.value = generarPassword();
    setPassVisible(true);
    passInput.focus();
    passInput.select();
    // El portapapeles exige origen seguro y permiso: si no se pudo, hay que decirlo en vez de
    // dar por copiado algo que no lo está.
    notaPassword(await copiar(passInput.value)
        ? 'Generada y copiada al portapapeles.'
        : 'Generada. Cópiala antes de guardar.');
});

document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const id = btn.dataset.id;

    if (btn.dataset.action === 'nuevo-usuario') {
        form.reset();
        form.elements['id'].value = '';
        setPasswordMode(false);
        limpiarPassword();
        syncSelects();
        syncEstacion();
        err.hidden = true;
        document.getElementById('dlg-usuario-title').textContent = 'Nuevo usuario';
        dlg.showModal();
    }

    if (btn.dataset.action === 'editar-usuario') {
        const resp = await api('GET', `/api/usuarios/${id}`);
        if (!resp.ok) { alert(mensajeError(resp, 'No se pudo cargar.')); return; }
        form.reset();
        form.elements['nombre'].value = resp.data.nombre;
        form.elements['email'].value = resp.data.email;
        form.elements['rol'].value = resp.data.rol;
        form.elements['estacion_id'].value = resp.data.estacion_id ?? '';
        form.elements['id'].value = resp.data.id;
        setPasswordMode(true);
        limpiarPassword();
        syncSelects();
        syncEstacion();
        err.hidden = true;
        document.getElementById('dlg-usuario-title').textContent = 'Editar usuario';
        dlg.showModal();
    }

    if (btn.dataset.action === 'activo-usuario') {
        const activar = btn.dataset.activo === '0';
        const resp = await api('POST', `/api/usuarios/${id}/activo`, { activo: activar });
        if (resp.ok) location.reload(); else alert(mensajeError(resp, 'No se pudo actualizar.'));
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
