/**
 * Confirmación en modal, en lugar del confirm() nativo (que no se puede estilar y se ve
 * como una alerta del navegador). Crea un <dialog> único bajo demanda y lo reutiliza.
 *
 *   if (!await confirmar({ mensaje: '¿Eliminar la ruta X?', peligro: true })) return;
 */
let dlg = null;

function crear() {
    dlg = document.createElement('dialog');
    dlg.className = 'dialog dialog--confirm';
    dlg.innerHTML = `
        <div class="confirm">
            <div class="confirm__texto">
                <h2 class="confirm__titulo"></h2>
                <p class="confirm__msg"></p>
            </div>
            <div class="dialog__actions">
                <button type="button" class="btn btn--ghost-dark" data-confirm-no>Cancelar</button>
                <button type="button" class="btn btn--primary" data-confirm-si>Aceptar</button>
            </div>
        </div>`;
    document.body.appendChild(dlg);
    return dlg;
}

export function confirmar({ titulo = '¿Confirmas la acción?', mensaje = '', aceptar = 'Aceptar', peligro = false } = {}) {
    const d = dlg ?? crear();
    d.querySelector('.confirm__titulo').textContent = titulo;
    const msg = d.querySelector('.confirm__msg');
    msg.textContent = mensaje;
    msg.hidden = mensaje === '';

    const si = d.querySelector('[data-confirm-si]');
    si.textContent = aceptar;
    si.classList.toggle('btn--peligro', peligro);
    si.classList.toggle('btn--primary', !peligro);

    const no = d.querySelector('[data-confirm-no]');

    return new Promise((resolve) => {
        let resuelto = false;

        const terminar = (valor) => {
            if (resuelto) return;          // close() dispara 'close' y volvería a entrar
            resuelto = true;
            si.removeEventListener('click', alAceptar);
            no.removeEventListener('click', alCancelar);
            d.removeEventListener('click', alFondo);
            d.removeEventListener('close', alCerrar);
            if (d.open) d.close();
            resolve(valor);
        };

        const alAceptar = () => terminar(true);
        // Cancelar necesita su propio manejador: un <button type="button"> no cierra un
        // <dialog> por sí solo, eso solo pasa dentro de un <form method="dialog">.
        const alCancelar = () => terminar(false);
        const alCerrar = () => terminar(false);                       // Escape
        // Clic fuera del panel: el evento llega al propio <dialog>, no a su contenido.
        const alFondo = (ev) => { if (ev.target === d) terminar(false); };

        si.addEventListener('click', alAceptar);
        no.addEventListener('click', alCancelar);
        d.addEventListener('click', alFondo);
        d.addEventListener('close', alCerrar);
        d.showModal();
        si.focus();
    });
}
