/**
 * Cambio de la propia contraseña: mostrar/ocultar, generador y la lista de requisitos que se
 * marca mientras se escribe. Las reglas las pinta el servidor (password_reglas); aquí solo se
 * comprueban, así que agregar o quitar una se hace en un solo sitio.
 */

import { generarPassword, setPassVisible, copiar } from './password.js';

const form = document.querySelector('form[action="/perfil/password"]');
if (form) {
    const nueva = form.elements['nueva'];
    const confirmacion = form.elements['confirmacion'];
    const lista = document.getElementById('requisitos');
    const avisoCoincide = document.getElementById('coincide');

    const COMPRUEBA = {
        largo: (v) => v.length >= 8,
        mayuscula: (v) => /\p{Lu}/u.test(v),
        minuscula: (v) => /\p{Ll}/u.test(v),
        numero: (v) => /\p{Nd}/u.test(v),
        especial: (v) => /[^\p{L}\p{Nd}]/u.test(v),
    };

    // Mostrar/ocultar: cada campo con su botón, sin que uno destape a los demás.
    form.querySelectorAll('[data-ver]').forEach((boton) => {
        const input = boton.closest('.field').querySelector('input');
        boton.addEventListener('click', () => setPassVisible(input, boton, input.type === 'password'));
    });

    function marcarRequisitos() {
        const valor = nueva.value;
        for (const item of lista.querySelectorAll('[data-regla]')) {
            const cumple = valor !== '' && COMPRUEBA[item.dataset.regla]?.(valor) === true;
            item.classList.toggle('is-ok', cumple);
        }
    }

    function marcarCoincidencia() {
        // Callado mientras no haya nada que comparar: avisar "no coinciden" a la primera letra
        // de la confirmación sería regañar por algo que la persona está por terminar de escribir.
        if (confirmacion.value === '' || nueva.value === '') {
            avisoCoincide.hidden = true;
            return;
        }
        const igual = confirmacion.value === nueva.value;
        avisoCoincide.hidden = false;
        avisoCoincide.textContent = igual ? 'Las dos coinciden.' : 'Las dos contraseñas no coinciden.';
        avisoCoincide.classList.toggle('field__note--error', !igual);
        avisoCoincide.classList.toggle('field__note--ok', igual);
    }

    nueva.addEventListener('input', () => { marcarRequisitos(); marcarCoincidencia(); });
    confirmacion.addEventListener('input', marcarCoincidencia);

    // Generar: llena las dos casillas y las deja a la vista. Una contraseña generada que hay que
    // volver a teclear en la confirmación se copia mal y se pierde el punto de generarla.
    const botonGenerar = form.querySelector('[data-generar]');
    botonGenerar?.addEventListener('click', async () => {
        const generada = generarPassword();
        nueva.value = generada;
        confirmacion.value = generada;
        // Se destapan las dos nuevas, no la actual: hay que poder leer lo que se va a guardar.
        form.querySelectorAll('[data-ver]').forEach((boton) => {
            const input = boton.closest('.field').querySelector('input');
            if (input !== form.elements['actual']) setPassVisible(input, boton, true);
        });
        marcarRequisitos();
        marcarCoincidencia();
        nueva.focus();
        nueva.select();
        const copiada = await copiar(generada);
        avisoCoincide.hidden = false;
        avisoCoincide.classList.remove('field__note--error');
        avisoCoincide.classList.add('field__note--ok');
        avisoCoincide.textContent = copiada
            ? 'Generada y copiada al portapapeles. Guárdala antes de salir.'
            : 'Generada. Cópiala antes de guardar.';
    });

    marcarRequisitos();
}
