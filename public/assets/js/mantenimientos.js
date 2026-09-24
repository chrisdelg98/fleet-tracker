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
    payload.en_taller = form.elements['en_taller'].checked ? 1 : 0;

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
    if (!fecha) return null;
    const dias = Math.round((Date.now() - new Date(fecha).getTime()) / 86400000);
    if (dias <= 0) return 'hoy';
    if (dias === 1) return 'ayer';
    return `hace ${dias} días`;
}

const diasSin = (u) => (u.ultima_fecha ? (Date.now() - new Date(u.ultima_fecha).getTime()) / 86400000 : Infinity);

let deLaLista = [];          // unidades del modal, ya ordenadas
const escrito = new Map();   // id => km tecleado, para que filtrar no borre lo escrito

function filaKm(u) {
    const previo = u.ultimo_km == null
        ? '<span class="muted">Nunca se ha anotado</span>'
        : `<span class="km-previo">${Number(u.ultimo_km).toLocaleString('es')}</span> <small class="muted">km · ${desde(u.ultima_fecha)}</small>`;
    const valor = escrito.get(String(u.id)) ?? '';
    return `
        <tr data-fila="${u.id}">
            <td><strong>${u.placa}</strong> <small class="muted">${u.estacion ?? ''}</small></td>
            <td>${previo}</td>
            <td>
                <span class="km-input">
                    <input type="text" inputmode="numeric" autocomplete="off" data-km="${u.id}"
                           data-ultimo="${u.ultimo_km ?? ''}" value="${valor}" aria-label="Kilometraje de ${u.placa}">
                    <span aria-hidden="true">km</span>
                </span>
                <div class="km-nota" data-nota-wrap="${u.id}" hidden>
                    <small>Es menos que la última lectura. ¿Se reemplazó el odómetro?</small>
                    <input type="text" maxlength="160" autocomplete="off" data-nota="${u.id}"
                           placeholder="Explica por qué bajó">
                </div>
            </td>
        </tr>`;
}

/** Redibuja la lista aplicando búsqueda y atajo. Es instantáneo: los datos ya están aquí. */
function pintarKm() {
    const texto = ($('km-buscar')?.value || '').trim().toLowerCase();
    const filtro = document.querySelector('[data-km-filtro].is-active')?.dataset.kmFiltro || 'todas';

    const visibles = deLaLista.filter((u) => {
        if (texto && !`${u.placa} ${u.estacion ?? ''}`.toLowerCase().includes(texto)) return false;
        if (filtro === 'pendientes') return !escrito.get(String(u.id));
        if (filtro === 'viejas') return diasSin(u) > 30;
        return true;
    });

    $('km-filas').innerHTML = visibles.map(filaKm).join('');
    $('km-vacio').hidden = visibles.length > 0;
    contar();
}

function contar() {
    const llenos = [...escrito.values()].filter((v) => v.trim() !== '').length;
    const resumen = $('km-resumen');
    if (!resumen) return;
    resumen.textContent = llenos === 0
        ? `${deLaLista.length} unidades`
        : `${llenos} de ${deLaLista.length} anotadas`;
    resumen.classList.toggle('is-lleno', llenos > 0);
}

function abrirKm(unidadId) {
    // Primero lo que lleva más tiempo sin leerse: es lo que de verdad hay que salir a buscar.
    deLaLista = (unidadId ? UNIDADES.filter((u) => String(u.id) === String(unidadId)) : [...UNIDADES])
        .sort((a, b) => diasSin(b) - diasSin(a));
    escrito.clear();
    if ($('km-buscar')) $('km-buscar').value = '';
    document.querySelectorAll('[data-km-filtro]').forEach((b, i) => b.classList.toggle('is-active', i === 0));
    pintarKm();
    errKm.hidden = true;
    dlgKm.showModal();
}

// Lo tecleado se guarda aparte de la tabla: así se puede filtrar sin perder nada.
$('km-filas')?.addEventListener('input', (ev) => {
    const campo = ev.target.closest('[data-km]');
    if (!campo) return;
    escrito.set(campo.dataset.km, campo.value);
    contar();

    // La nota solo aparece cuando hace falta: once campos pidiendo explicación eran ruido.
    const ultimo = Number(campo.dataset.ultimo);
    const ahora = Number(String(campo.value).replace(/[., ]/g, ''));
    const baja = campo.dataset.ultimo !== '' && ahora > 0 && ahora < ultimo;
    const caja = $('km-filas').querySelector(`[data-nota-wrap="${campo.dataset.km}"]`);
    if (caja) caja.hidden = !baja;
});

$('km-buscar')?.addEventListener('input', pintarKm);
document.querySelectorAll('[data-km-filtro]').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('[data-km-filtro]').forEach((b) => b.classList.toggle('is-active', b === btn));
        pintarKm();
    });
});

formKm?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const filas = {};
    escrito.forEach((km, id) => {
        if (String(km).trim() === '') return;   // lo vacío se ignora: nadie llena toda la flota
        filas[id] = { km: String(km).trim(), nota: formKm.querySelector(`[data-nota="${id}"]`)?.value ?? '' };
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

    if (action === 'salida-taller') {
        const ok = await confirmar({
            titulo: 'Marcar salida del taller',
            mensaje: `${nombre} vuelve a estar disponible desde ahora y se podrá reservar.`,
            aceptar: 'Marcar salida',
        });
        if (!ok) return;
        const r = await api('POST', `/api/mantenimientos/${id}/salida`);
        if (r.ok) location.reload(); else toast(r.message || 'No se pudo marcar la salida.', { tono: 'error' });
    }

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
