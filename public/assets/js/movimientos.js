/**
 * Acciones sobre un movimiento desde la lista.
 *
 * Verlo sin poder tocarlo no resolvía nada: el caso que originó esta pantalla era alargar el fin
 * de dos viajes retenidos en aduana. Se reutilizan los mismos endpoints del tablero, así que las
 * reglas de estado y de traslape son exactamente las mismas; lo único que no vive aquí es la
 * edición completa de la reserva, que necesita el formulario entero.
 */
import { api } from './api.js';
import { confirmar } from './confirm.js';
import { toast } from './toast.js';
import { pintarConflicto } from './conflicto.js';

const $ = (id) => document.getElementById(id);
const dlgFin = $('dlg-fin');
const formFin = $('form-fin');
const dlgMotivo = $('dlg-cancelar');
const formMotivo = $('form-cancelar');

async function postAccion(url, cuerpo = null) {
    const r = await api('POST', url, cuerpo);
    if (r.ok) { location.reload(); return; }
    // El traslape llega con el número del otro movimiento: se puede buscar sin salir de aquí.
    toast(r.message || 'No se pudo completar la acción.', { tono: 'error', duracion: 9000 });
}

document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-mov]');
    if (!btn) return;
    const { mov, id } = btn.dataset;

    if (mov === 'reprogramar') {
        formFin.reset();
        formFin.elements['id'].value = id;
        formFin.elements['fecha_fin_estimada'].value = btn.dataset.fin || '';
        $('form-fin-error').hidden = true;
        dlgFin.showModal();
        return;
    }
    if (mov === 'cancelar') {
        formMotivo.reset();
        formMotivo.elements['id'].value = id;
        $('form-cancelar-error').hidden = true;
        dlgMotivo.showModal();
        return;
    }

    const textos = {
        confirmar: ['Confirmar movimiento', 'El viaje queda programado.'],
        salida: ['Marcar salida', 'La unidad pasa a estar en tránsito.'],
        llegada: ['Marcar llegada', 'El viaje se da por terminado y la unidad queda libre.'],
    };
    if (!textos[mov]) return;
    const ok = await confirmar({ titulo: textos[mov][0], mensaje: `Movimiento #${id}. ${textos[mov][1]}`, aceptar: textos[mov][0] });
    if (ok) postAccion(`/api/movimientos/${id}/${mov}`);
});

formFin?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const r = await api('POST', `/api/movimientos/${formFin.elements['id'].value}/reprogramar-fin`, {
        fecha_fin_estimada: formFin.elements['fecha_fin_estimada'].value,
        motivo: formFin.elements['motivo'].value,
    });
    if (r.ok) { location.reload(); return; }
    pintarConflicto($('form-fin-error'), r);
});

formMotivo?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const r = await api('POST', `/api/movimientos/${formMotivo.elements['id'].value}/cancelar`, {
        motivo: formMotivo.elements['motivo'].value,
    });
    if (r.ok) { location.reload(); return; }
    pintarConflicto($('form-cancelar-error'), r);
});

document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => b.closest('dialog').close()));
