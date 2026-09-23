/**
 * Módulo de mantenimientos: el modal de registro (con el conversor de moneda en vivo) y la
 * captura de kilometraje de varias unidades a la vez.
 *
 * Se carga en las cinco secciones, porque registrar tiene que estar a un clic desde cualquiera.
 */
import { api, showError } from './api.js';
import { confirmar } from './confirm.js';
import { toast } from './toast.js';

const $ = (id) => document.getElementById(id);
const dlg = $('dlg-mantenimiento');
const form = $('form-mantenimiento');
const err = $('form-mantenimiento-error');
const UNIDADES = JSON.parse($('mant-unidades')?.textContent || '[]');

const CAMPOS = ['unidad_id', 'fecha', 'km', 'tipo_mantenimiento_id', 'descripcion', 'taller_id',
    'taller_nuevo', 'costo', 'moneda_id', 'tasa_usada', 'factura', 'observaciones', 'nota_odometro'];

// ── Conversor: se ve el equivalente en dólares antes de guardar ──
const conversion = $('mant-conversion');

/** Tasa a usar: la escrita a mano (la de la factura) o, si no, la vigente de esa moneda. */
function tasaActual() {
    const escrita = parseFloat(String(form.elements['tasa_usada'].value).replace(',', '.'));
    if (escrita > 0) return escrita;
    return parseFloat(form.elements['moneda_id'].selectedOptions[0]?.dataset.tasa || '1');
}

function mostrarConversion() {
    const costo = parseFloat(String(form.elements['costo'].value).replace(/[, ]/g, ''));
    const moneda = form.elements['moneda_id'].selectedOptions[0]?.textContent.trim() || '';
    const tasa = tasaActual();
    if (!(costo > 0) || !(tasa > 0)) { conversion.hidden = true; return; }
    conversion.hidden = false;
    conversion.textContent = moneda === 'USD'
        ? `Se guarda como $${costo.toFixed(2)}.`
        : `${moneda} ${costo.toLocaleString('es')} ÷ ${tasa} = $${(costo / tasa).toFixed(2)}`;
}

/** La tasa vigente se propone al cambiar de moneda; si ya venía escrita, se respeta. */
function proponerTasa() {
    const campo = form.elements['tasa_usada'];
    const vigente = form.elements['moneda_id'].selectedOptions[0]?.dataset.tasa || '';
    if (campo.dataset.tocado !== '1') campo.value = vigente;
    mostrarConversion();
}

if (form) {
    form.elements['tasa_usada'].addEventListener('input', (ev) => {
        ev.target.dataset.tocado = ev.target.value.trim() === '' ? '' : '1';
        mostrarConversion();
    });
    form.elements['costo'].addEventListener('input', mostrarConversion);
    form.elements['moneda_id'].addEventListener('change', proponerTasa);

    // El tipo propone si reinicia el ciclo; la casilla queda editable porque una reparación
    // grande a veces incluye el cambio de aceite.
    form.elements['tipo_mantenimiento_id'].addEventListener('change', () => {
        const reinicia = form.elements['tipo_mantenimiento_id'].selectedOptions[0]?.dataset.reinicia === '1';
        form.elements['reinicia_ciclo'].checked = reinicia;
    });

    // Talleres: se ofrecen solo los de la estación de la unidad elegida, porque un taller de
    // otra estación no puede haber hecho ese trabajo.
    form.elements['unidad_id'].addEventListener('change', () => {
        const estacion = form.elements['unidad_id'].selectedOptions[0]?.dataset.estacion || '';
        const sel = form.elements['taller_id'];
        let cambio = false;
        for (const o of sel.options) {
            const ocultar = o.value !== '' && estacion !== '' && o.dataset.estacion !== estacion;
            if (o.hidden !== ocultar) { o.hidden = ocultar; cambio = true; }
        }
        if (sel.selectedOptions[0]?.hidden) sel.value = '';
        if (cambio) sel.dispatchEvent(new Event('opciones-cambiadas'));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

function abrirRegistro(unidadId) {
    form.reset();
    form.elements['id'].value = '';
    form.elements['tasa_usada'].dataset.tocado = '';
    if (unidadId) form.elements['unidad_id'].value = unidadId;
    $('dlg-mantenimiento-title').textContent = 'Registrar mantenimiento';
    err.hidden = true;
    conversion.hidden = true;
    form.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
    proponerTasa();
    dlg.showModal();
}

async function abrirEdicion(id) {
    const r = await api('GET', `/api/mantenimientos/${id}`);
    if (!r.ok) { toast(r.message || 'No se pudo cargar.', { tono: 'error' }); return; }
    const m = r.data;
    form.reset();
    form.elements['id'].value = m.id;
    for (const campo of CAMPOS) {
        if (form.elements[campo]) form.elements[campo].value = m[campo] ?? '';
    }
    form.elements['reinicia_ciclo'].checked = Number(m.reinicia_ciclo) === 1;
    // La tasa ya guardada manda: es con la que se convirtió ese gasto, no la de hoy.
    form.elements['tasa_usada'].dataset.tocado = m.tasa_usada ? '1' : '';
    $('dlg-mantenimiento-title').textContent = `Editar mantenimiento de ${m.placa_unidad}`;
    err.hidden = true;
    form.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
    form.elements['unidad_id'].value = m.unidad_id;
    form.elements['taller_id'].value = m.taller_id ?? '';
    form.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
    mostrarConversion();
    dlg.showModal();
}

form?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const payload = {};
    for (const campo of CAMPOS) payload[campo] = form.elements[campo]?.value ?? '';
    payload.reinicia_ciclo = form.elements['reinicia_ciclo'].checked ? 1 : 0;

    const id = form.elements['id'].value;
    const r = id
        ? await api('PUT', `/api/mantenimientos/${id}`, payload)
        : await api('POST', '/api/mantenimientos', payload);
    if (r.ok) location.reload(); else showError(err, r);
});

// ── Captura de kilometraje ──
const dlgKm = $('dlg-km');
const formKm = $('form-km');
const errKm = $('form-km-error');

/** Días desde una fecha, en palabras. «hace 28 días» dice más que una fecha suelta. */
function desde(fecha) {
    if (!fecha) return 'nunca';
    const dias = Math.round((Date.now() - new Date(fecha).getTime()) / 86400000);
    if (dias <= 0) return 'hoy';
    if (dias === 1) return 'ayer';
    return `hace ${dias} días`;
}

function abrirKm(unidadId) {
    const filas = unidadId ? UNIDADES.filter((u) => String(u.id) === String(unidadId)) : UNIDADES;
    // Primero lo que lleva más tiempo sin leerse: es lo que de verdad hay que salir a buscar.
    const orden = [...filas].sort((a, b) => (a.ultima_fecha ?? '') .localeCompare(b.ultima_fecha ?? ''));

    $('km-filas').innerHTML = orden.map((u) => `
        <tr data-fila="${u.id}">
            <td><strong>${u.placa}</strong><br><small class="muted">${u.estacion ?? ''}</small></td>
            <td>${u.ultimo_km == null
                    ? '<span class="muted">Sin lecturas</span>'
                    : `${Number(u.ultimo_km).toLocaleString('es')} km<br><small class="muted">${desde(u.ultima_fecha)}</small>`}</td>
            <td>
                <input type="text" inputmode="numeric" autocomplete="off" data-km="${u.id}"
                       data-ultimo="${u.ultimo_km ?? ''}" placeholder="${u.ultimo_km ?? 'km'}" style="max-width:140px">
                <div data-nota-wrap="${u.id}" hidden style="margin-top:4px">
                    <small class="muted">Escribiste menos que la última lectura. ¿Se reemplazó el odómetro?</small>
                    <input type="text" maxlength="160" autocomplete="off" data-nota="${u.id}"
                           placeholder="Explica por qué bajó">
                </div>
            </td>
        </tr>`).join('');
    errKm.hidden = true;
    dlgKm.showModal();
}

// La nota solo aparece cuando hace falta: once campos vacíos pidiendo explicación era ruido,
// y quien no tiene nada que explicar no debería verlos.
$('km-filas')?.addEventListener('input', (ev) => {
    const campo = ev.target.closest('[data-km]');
    if (!campo) return;
    const ultimo = Number(campo.dataset.ultimo);
    const ahora = Number(String(campo.value).replace(/[., ]/g, ''));
    const baja = campo.dataset.ultimo !== '' && ahora > 0 && ahora < ultimo;
    const caja = $('km-filas').querySelector(`[data-nota-wrap="${campo.dataset.km}"]`);
    if (caja) caja.hidden = !baja;
});

formKm?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const filas = {};
    formKm.querySelectorAll('[data-km]').forEach((input) => {
        const km = input.value.trim();
        if (km === '') return;   // lo vacío se ignora: nadie llena toda la flota cada mes
        filas[input.dataset.km] = { km, nota: formKm.querySelector(`[data-nota="${input.dataset.km}"]`)?.value ?? '' };
    });
    if (Object.keys(filas).length === 0) {
        errKm.textContent = 'Escribe al menos un kilometraje.';
        errKm.hidden = false;
        return;
    }
    const r = await api('POST', '/api/mantenimientos/lecturas', { fecha: formKm.elements['fecha'].value, filas });
    if (!r.ok) { showError(errKm, r); return; }

    // Lo que sí entró se guarda; lo que falló se dice placa por placa, sin perder lo tecleado.
    const errores = Object.entries(r.data?.errores ?? {});
    if (errores.length === 0) { location.reload(); return; }
    const nombre = (id) => UNIDADES.find((u) => String(u.id) === String(id))?.placa ?? id;
    errKm.innerHTML = `${r.message} No se guardaron: ` + errores.map(([id, m]) => `<strong>${nombre(id)}</strong>: ${m}`).join(' · ');
    errKm.hidden = false;
});

// ── Acciones de las pantallas ──
document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const { action, id, unidad, href, nombre } = btn.dataset;

    if (action === 'nuevo-mantenimiento') abrirRegistro(unidad);
    if (action === 'editar-mantenimiento') abrirEdicion(id);
    if (action === 'capturar-km') abrirKm(unidad);
    if (action === 'ir-historial') location.href = href;

    if (action === 'eliminar-mantenimiento') {
        const ok = await confirmar({
            titulo: 'Eliminar mantenimiento',
            mensaje: `Se borra el registro de ${nombre} y el kilometraje que se anotó con él. El historial no lo conserva.`,
            aceptar: 'Eliminar',
            peligro: true,
        });
        if (!ok) return;
        const r = await api('DELETE', `/api/mantenimientos/${id}`);
        if (r.ok) location.reload(); else toast(r.message || 'No se pudo eliminar.', { tono: 'error' });
    }
});

document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => b.closest('dialog').close()));
