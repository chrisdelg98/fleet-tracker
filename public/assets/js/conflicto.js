/**
 * Salidas para un traslape.
 *
 * Antes el aviso decía «traslapa con el #63» y ahí se acababa: había que salir de la pantalla,
 * buscar ese viaje y volver. Aquí, junto al mensaje, aparecen las acciones sobre ESE movimiento,
 * que es lo que hay que resolver para poder guardar.
 *
 * Lo usan el tablero y la lista de movimientos, así que el mensaje y las opciones son los mismos
 * en los dos sitios. Todo pasa por los endpoints de siempre, con su bitácora.
 */
import { api } from './api.js';
import { confirmar } from './confirm.js';
import { toast } from './toast.js';

const esc = (t) => String(t ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/**
 * Pinta el mensaje y, si el servidor mandó el movimiento en conflicto, sus acciones.
 *
 * @param {HTMLElement} caja      el <p class="form__error"> del formulario
 * @param {object} r              la respuesta del API
 * @param {Function} alResolver   se llama cuando el conflicto deja de existir
 * @returns {boolean} true si había un conflicto que ofrecer
 */
export function pintarConflicto(caja, r, alResolver) {
    const c = r?.data?.conflicto;
    caja.hidden = false;
    if (!c) {
        caja.textContent = r?.message || 'No se pudo completar la acción.';
        return false;
    }

    caja.innerHTML = `
        <span class="conflicto__texto">${esc(r.message)}</span>
        <span class="conflicto__acciones">
            <button type="button" class="btn btn--linea btn--sm" data-conf="ver">Ver el #${c.id}</button>
            <button type="button" class="btn btn--linea btn--sm" data-conf="cancelar">Cancelar el #${c.id}</button>
            <a class="btn btn--linea btn--sm" href="/movimientos?q=${c.id}" target="_blank" rel="noopener">Gestionarlo aparte</a>
        </span>
        <span class="conflicto__detalle" hidden></span>`;

    const detalle = caja.querySelector('.conflicto__detalle');

    caja.querySelector('[data-conf="ver"]').addEventListener('click', async () => {
        // Antes de tocar nada conviene saber qué es: de quién es el viaje y a dónde va.
        const d = await api('GET', `/api/movimientos/${c.id}`);
        if (!d.ok) { toast(d.message || 'No se pudo cargar.', { tono: 'error' }); return; }
        const m = d.data;
        const fila = (k, v) => (v ? `<span><strong>${esc(k)}:</strong> ${esc(v)}</span>` : '');
        detalle.hidden = false;
        detalle.innerHTML = fila('Unidad', m.placa_unidad || m.placa_motriz)
            + fila('Cliente', m.reservado_para)
            + fila('Piloto', m.piloto_nombre || m.piloto)
            + fila('Estado', c.estado)
            + fila('Del', `${c.desde} al ${c.hasta}`);
    });

    // Cancelar pide su motivo aquí mismo: es obligatorio en el servidor y sacar al usuario a
    // otro diálogo para escribir dos palabras rompía el hilo de lo que estaba haciendo.
    caja.querySelector('[data-conf="cancelar"]').addEventListener('click', () => {
        if (caja.querySelector('.conflicto__cancelar')) return;
        const caja2 = document.createElement('span');
        caja2.className = 'conflicto__cancelar';
        caja2.innerHTML = `
            <input type="text" maxlength="255" placeholder="Motivo para cancelar el #${c.id}">
            <button type="button" class="btn btn--primary btn--sm">Cancelar el #${c.id}</button>`;
        caja.appendChild(caja2);
        const campo = caja2.querySelector('input');
        campo.focus();

        caja2.querySelector('button').addEventListener('click', async () => {
            const motivo = campo.value.trim();
            if (motivo === '') { campo.focus(); toast('Escribe el motivo.', { tono: 'error' }); return; }
            const ok = await confirmar({
                titulo: `Cancelar el movimiento #${c.id}`,
                mensaje: 'Queda cancelado con su motivo y la unidad se libera en esas fechas. Después podrás guardar lo tuyo.',
                aceptar: 'Sí, cancelarlo',
                peligro: true,
            });
            if (!ok) return;
            const r2 = await api('POST', `/api/movimientos/${c.id}/cancelar`, { motivo });
            if (!r2.ok) { toast(r2.message || 'No se pudo cancelar.', { tono: 'error' }); return; }
            caja.innerHTML = `<span class="conflicto__texto">Movimiento #${c.id} cancelado. Ya puedes guardar.</span>`;
            if (typeof alResolver === 'function') alResolver();
        });
    });

    return true;
}
