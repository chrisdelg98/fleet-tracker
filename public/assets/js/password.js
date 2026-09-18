/**
 * Generador de contraseñas y mostrar/ocultar, compartido por la pantalla de usuarios y por la
 * de cambiar la propia contraseña. Vive aquí para que las dos generen con el mismo criterio.
 */

// Sin caracteres ambiguos (I l 1 O 0) para poder dictarla o copiarla sin errores.
const MAYUS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const MINUS = 'abcdefghijkmnopqrstuvwxyz';
const DIGITOS = '23456789';
const SIMBOLOS = '!@#$%&*?-_';
const ALFABETO = MAYUS + MINUS + DIGITOS + SIMBOLOS;
const LARGO = 12;

/** Índice aleatorio uniforme: descarta el sobrante para no sesgar el módulo. */
function indiceAleatorio(max) {
    const limite = Math.floor(0x100000000 / max) * max;
    const buf = new Uint32Array(1);
    let n;
    do { crypto.getRandomValues(buf); n = buf[0]; } while (n >= limite);
    return n % max;
}

/** Contraseña con al menos un carácter de cada familia (mayúscula, minúscula, dígito, símbolo). */
export function generarPassword() {
    const familias = [MAYUS, MINUS, DIGITOS, SIMBOLOS];
    const chars = familias.map((f) => f[indiceAleatorio(f.length)]);
    while (chars.length < LARGO) chars.push(ALFABETO[indiceAleatorio(ALFABETO.length)]);
    // Fisher-Yates, para que las cuatro familias no queden siempre al inicio.
    for (let i = chars.length - 1; i > 0; i--) {
        const j = indiceAleatorio(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join('');
}

/** Pone o quita el ojo abierto en un botón de mostrar/ocultar y cambia el tipo del campo. */
export function setPassVisible(input, boton, visible) {
    input.type = visible ? 'text' : 'password';
    boton.classList.toggle('is-on', visible);
    boton.setAttribute('aria-pressed', visible ? 'true' : 'false');
    boton.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    boton.title = visible ? 'Ocultar contraseña' : 'Mostrar contraseña';
}

/**
 * Copia al portapapeles. Devuelve si pudo: el navegador lo niega sin HTTPS o sin permiso, y
 * entonces hay que decir "cópiala a mano" en vez de dar por hecho que ya está copiada.
 */
export async function copiar(texto) {
    try {
        await navigator.clipboard.writeText(texto);
        return true;
    } catch {
        return false;
    }
}
