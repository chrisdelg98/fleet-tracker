/**
 * Aviso breve que aparece en una esquina y se va solo.
 *
 * Es para lo que ocurrió *después* de la acción principal y no la invalida: la reserva se
 * guardó, pero el correo salió o no salió. Un alert() para eso obliga a cerrar una ventana
 * por algo que solo se estaba informando; un cartel fijo en la página se queda ahí para
 * siempre. Esto se lee al pasar y desaparece.
 *
 *   toast('Aviso enviado a 2 contactos.');
 *   toast('No se pudo enviar: …', { tono: 'error' });
 */

/** Un fallo se lee más despacio: trae un motivo que hay que entender, no solo confirmar. */
const DURACION = { ok: 4500, error: 9000 };

let pila = null;

function contenedor() {
    if (pila === null) {
        pila = document.createElement('div');
        pila.className = 'toasts';
        document.body.appendChild(pila);
    }
    return pila;
}

/**
 * @param {string} mensaje
 * @param {{tono?: 'ok'|'error', duracion?: number}} opciones
 */
export function toast(mensaje, { tono = 'ok', duracion } = {}) {
    const el = document.createElement('div');
    el.className = `toast toast--${tono}`;
    // Un fallo interrumpe al lector de pantalla; una confirmación espera su turno.
    el.setAttribute('role', tono === 'error' ? 'alert' : 'status');
    el.textContent = mensaje;

    const cerrar = () => {
        el.classList.add('is-yendose');
        // Si la animación no corre (pestaña oculta, motion reducido) el nodo se queda:
        // el evento no llega. El temporizador de respaldo lo retira igual.
        el.addEventListener('transitionend', () => el.remove(), { once: true });
        setTimeout(() => el.remove(), 400);
    };

    el.addEventListener('click', cerrar);
    contenedor().appendChild(el);
    // Un cuadro que aparece de golpe se lee como un error del navegador; entra deslizándose.
    requestAnimationFrame(() => el.classList.add('is-visible'));
    setTimeout(cerrar, duracion ?? DURACION[tono] ?? DURACION.ok);
}
