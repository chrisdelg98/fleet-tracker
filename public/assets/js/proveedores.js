/**
 * Catálogo de proveedores: abrir y cerrar grupos, alta y edición de proveedores y camiones,
 * activar/desactivar y fusionar. Recarga tras cada escritura, como el resto de catálogos.
 */
import { api, showError } from './api.js';
import { confirmar } from './confirm.js';

const $ = (id) => document.getElementById(id);

// ── Grupos: abrir y cerrar los camiones de un proveedor ──
function alternarGrupo(grupo, abrir) {
    const abierto = abrir ?? !grupo.classList.contains('is-open');
    grupo.classList.toggle('is-open', abierto);
    grupo.querySelector('[data-action="abrir-grupo"]')?.setAttribute('aria-expanded', abierto ? 'true' : 'false');
}

// ── Proveedor: nuevo / cambiar nombre, con aviso de duplicados mientras se escribe ──
const dlgProveedor = $('dlg-proveedor');
const formProveedor = $('form-proveedor');
const errProveedor = $('form-proveedor-error');
const aviso = $('prov-nombre-aviso');
let esperaAviso = null;

/**
 * Pregunta al servidor si el nombre ya existe o se parece a otro. La comparación de verdad
 * (tildes, puntuación, «S.A. de C.V.») vive en el servidor: repetirla aquí sería tener dos
 * reglas que tarde o temprano dirían cosas distintas.
 */
async function revisarNombre() {
    const nombre = formProveedor.elements['nombre'].value.trim();
    const propio = formProveedor.elements['id'].value;
    if (nombre.length < 3) { aviso.hidden = true; return; }

    const r = await api('GET', `/api/proveedores/parecidos?nombre=${encodeURIComponent(nombre)}`);
    if (!r.ok || formProveedor.elements['nombre'].value.trim() !== nombre) return;   // ya cambió

    const { exacto, parecidos } = r.data;
    aviso.classList.remove('prov-aviso--error');
    if (exacto && String(exacto.id) !== propio) {
        aviso.textContent = exacto.activo
            ? `Ya existe como «${exacto.nombre}».`
            : `Ya existe como «${exacto.nombre}», desactivado: actívalo desde la lista.`;
        aviso.classList.add('prov-aviso--error');
        aviso.hidden = false;
        return;
    }
    const otros = (parecidos || []).filter((p) => String(p.id) !== propio);
    if (otros.length) {
        aviso.textContent = `¿Es alguno de estos? ${otros.map((p) => `«${p.nombre}»`).join(', ')}. Si es el mismo, no lo crees otra vez.`;
        aviso.hidden = false;
        return;
    }
    aviso.hidden = true;
}

formProveedor.elements['nombre'].addEventListener('input', () => {
    clearTimeout(esperaAviso);
    esperaAviso = setTimeout(revisarNombre, 300);
});

function abrirProveedor(id, nombre) {
    formProveedor.reset();
    formProveedor.elements['id'].value = id || '';
    formProveedor.elements['nombre'].value = nombre || '';
    $('dlg-proveedor-title').textContent = id ? 'Cambiar nombre' : 'Nuevo proveedor';
    errProveedor.hidden = true;
    aviso.hidden = true;
    dlgProveedor.showModal();
    formProveedor.elements['nombre'].focus();
}

formProveedor.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const id = formProveedor.elements['id'].value;
    const payload = { nombre: formProveedor.elements['nombre'].value };
    const r = id
        ? await api('PUT', `/api/proveedores/${id}`, payload)
        : await api('POST', '/api/proveedores', payload);
    if (r.ok) location.reload(); else showError(errProveedor, r);
});

// ── Camión ──
const dlgCamion = $('dlg-camion');
const formCamion = $('form-camion');
const errCamion = $('form-camion-error');
const CAMPOS_CAMION = ['placa_motriz', 'placa_arrastre', 'piloto', 'licencia', 'documento',
    'telefonos', 'codigo_nacional', 'codigo_internacional'];

function abrirCamionNuevo(proveedorId, nombre) {
    formCamion.reset();
    formCamion.elements['id'].value = '';
    formCamion.elements['proveedor_origen'].value = proveedorId;
    $('camion-proveedor-field').hidden = true;
    $('dlg-camion-title').textContent = 'Agregar camión';
    $('dlg-camion-lede').textContent = `De ${nombre}.`;
    errCamion.hidden = true;
    dlgCamion.showModal();
    formCamion.elements['placa_motriz'].focus();
}

async function abrirCamionEdicion(id) {
    const r = await api('GET', `/api/proveedor-camiones/${id}`);
    if (!r.ok) { alert(r.message || 'No se pudo cargar el camión.'); return; }
    const c = r.data;
    formCamion.reset();
    formCamion.elements['id'].value = c.id;
    formCamion.elements['proveedor_origen'].value = c.proveedor_id;
    for (const campo of CAMPOS_CAMION) formCamion.elements[campo].value = c[campo] ?? '';

    // Mover de proveedor: la lista trae los activos; si el suyo está desactivado se agrega
    // para que el desplegable diga la verdad en vez de mostrar otro.
    const sel = formCamion.elements['proveedor_id'];
    sel.querySelector('option[data-temporal]')?.remove();
    if (![...sel.options].some((o) => o.value === String(c.proveedor_id))) {
        const o = new Option(`${c.proveedor} (desactivado)`, c.proveedor_id);
        o.dataset.temporal = '1';
        sel.add(o);
    }
    sel.value = String(c.proveedor_id);
    sel.dispatchEvent(new Event('opciones-cambiadas'));
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    $('camion-proveedor-field').hidden = false;

    $('dlg-camion-title').textContent = `Editar camión ${c.placa_motriz}`;
    $('dlg-camion-lede').textContent = 'Para pasarlo a otro proveedor, cámbialo arriba: pasa si el camión cambió de dueño.';
    errCamion.hidden = true;
    dlgCamion.showModal();
}

formCamion.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const id = formCamion.elements['id'].value;
    const payload = {};
    for (const campo of CAMPOS_CAMION) payload[campo] = formCamion.elements[campo].value;
    let r;
    if (id) {
        payload.proveedor_id = formCamion.elements['proveedor_id'].value;
        r = await api('PUT', `/api/proveedor-camiones/${id}`, payload);
    } else {
        r = await api('POST', `/api/proveedores/${formCamion.elements['proveedor_origen'].value}/camiones`, payload);
    }
    if (r.ok) location.reload(); else showError(errCamion, r);
});

// ── Fusionar ──
const dlgFusionar = $('dlg-fusionar');
const formFusionar = $('form-fusionar');
const errFusionar = $('form-fusionar-error');

function abrirFusion(btn) {
    formFusionar.reset();
    formFusionar.elements['id'].value = btn.dataset.id;
    const camiones = Number(btn.dataset.camiones || 0);
    const viajes = Number(btn.dataset.viajes || 0);
    const cuenta = (n, uno, varios) => (n === 1 ? `su ${uno}` : `sus ${n} ${varios}`);
    $('fusionar-lede').textContent = `${cuenta(camiones, 'camión', 'camiones')} y ${cuenta(viajes, 'viaje', 'viajes')} `
        .replace(/^./, (c) => c.toUpperCase())
        + `pasan al proveedor que elijas, y «${btn.dataset.nombre}» se desactiva. Úsalo cuando son el mismo escrito distinto.`;
    // No se ofrece fusionarlo consigo mismo.
    const sel = formFusionar.elements['destino_id'];
    for (const o of sel.options) o.hidden = o.value === btn.dataset.id;
    sel.dispatchEvent(new Event('opciones-cambiadas'));
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    errFusionar.hidden = true;
    dlgFusionar.showModal();
}

formFusionar.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const destino = formFusionar.elements['destino_id'].value;
    if (!destino) {
        errFusionar.textContent = 'Elige el proveedor al que pasa todo.';
        errFusionar.hidden = false;
        return;
    }
    const r = await api('POST', `/api/proveedores/${formFusionar.elements['id'].value}/fusionar`, { destino_id: destino });
    if (r.ok) location.reload(); else showError(errFusionar, r);
});

// ── Acciones (delegadas: cubren el menú de fila, que se porta a <body>) ──
document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const { action, id, nombre } = btn.dataset;

    if (action === 'abrir-grupo') alternarGrupo(btn.closest('.prov-grupo'));
    if (action === 'nuevo-proveedor') abrirProveedor(null, '');
    if (action === 'renombrar-proveedor') abrirProveedor(id, nombre);
    if (action === 'fusionar-proveedor') abrirFusion(btn);
    if (action === 'agregar-camion') abrirCamionNuevo(id, nombre);
    if (action === 'editar-camion') abrirCamionEdicion(id);

    if (action === 'activo-proveedor' || action === 'activo-camion') {
        const activar = btn.dataset.activo === '1';
        const esProveedor = action === 'activo-proveedor';
        if (!activar) {
            const ok = await confirmar({
                titulo: esProveedor ? 'Desactivar proveedor' : 'Desactivar camión',
                mensaje: esProveedor
                    ? `${nombre} dejará de ofrecerse al reservar. Su historial y sus camiones se conservan; puedes activarlo cuando quieras.`
                    : `${nombre} dejará de ofrecerse al reservar. Si se vuelve a usar en una reserva, se activa solo.`,
                aceptar: 'Desactivar',
                peligro: true,
            });
            if (!ok) return;
        }
        const url = esProveedor ? `/api/proveedores/${id}/activo` : `/api/proveedor-camiones/${id}/activo`;
        const r = await api('POST', url, { activo: activar ? 1 : 0 });
        if (r.ok) location.reload(); else alert(r.message || 'No se pudo cambiar.');
    }
});

document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => b.closest('dialog').close()));
