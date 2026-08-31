/**
 * Carga masiva desde Excel. Sirve a cualquier entidad: el diálogo declara a qué endpoint
 * subir y qué columnas enseñar en la vista previa (helper dialogo_import()).
 *
 * Dos pasos deliberados: al elegir el archivo se analiza (sin escribir nada) y solo cuando el
 * informe sale limpio se habilita el botón de cargar. Así el usuario ve qué va a pasar antes
 * de que pase, y un archivo con errores nunca deja media carga aplicada.
 */

import { apiArchivo, mensajeError } from './api.js';

const dlg = document.getElementById('dlg-import');
const URL_IMPORT = dlg.dataset.importUrl;
const CAMPOS_VISTA = JSON.parse(dlg.dataset.importCampos);
const SINGULAR = dlg.dataset.importSingular;
const PLURAL = dlg.dataset.importPlural;
const inputArchivo = document.getElementById('import-archivo');
const zona = document.getElementById('import-soltar');
const nombre = document.getElementById('import-nombre');
const resultado = document.getElementById('import-resultado');
const resumen = document.getElementById('import-resumen');
const erroresWrap = document.getElementById('import-errores-wrap');
const erroresBody = document.getElementById('import-errores');
const vistaWrap = document.getElementById('import-vista-wrap');
const vistaBody = document.getElementById('import-vista');
const vistaPie = document.getElementById('import-vista-pie');
const btnConfirmar = document.getElementById('import-confirmar');
const error = document.getElementById('form-import-error');

/** Tope de errores en pantalla: más allá, la lista deja de ayudar y solo agobia. */
const MAX_ERRORES = 50;

let archivo = null;
let trabajando = false;

const esc = (t) => String(t ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/**
 * Estado de trabajo: además del botón, se bloquea la zona de subida. Si no, se puede soltar
 * un segundo archivo mientras el primero se valida y llega la respuesta equivocada.
 */
function trabajar(activo, texto = '') {
    trabajando = activo;
    inputArchivo.disabled = activo;
    zona.classList.toggle('is-bloqueado', activo);
    btnConfirmar.disabled = activo || btnConfirmar.disabled;
    if (activo) {
        resultado.hidden = false;
        resumen.innerHTML = `<span class="cargando" role="status" aria-live="polite">
            <span class="cargando__aro" aria-hidden="true"></span>${esc(texto)}</span>`;
    }
}

document.addEventListener('click', (ev) => {
    if (!ev.target.closest('[data-action="carga-masiva"]')) return;
    reiniciar();
    dlg.showModal();
});

// El diálogo cierra por su cuenta: el módulo se monta en varias páginas y no todas enlazan
// [data-close] igual. Un doble cierre donde la página ya lo hace es inofensivo.
dlg.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-close]')) dlg.close();
});

function reiniciar() {
    archivo = null;
    trabajar(false);
    inputArchivo.value = '';
    nombre.textContent = 'Arrastra el archivo aquí o haz clic para elegirlo';
    zona.classList.remove('is-cargado');
    resultado.hidden = true;
    erroresWrap.hidden = true;
    erroresBody.innerHTML = '';
    vistaWrap.hidden = true;
    vistaBody.innerHTML = '';
    resumen.innerHTML = '';
    error.hidden = true;
    btnConfirmar.disabled = true;
}

// ── Selección del archivo ──

inputArchivo.addEventListener('change', () => {
    if (inputArchivo.files.length) analizar(inputArchivo.files[0]);
});


['dragenter', 'dragover'].forEach((evento) => {
    zona.addEventListener(evento, (ev) => {
        ev.preventDefault();
        if (trabajando) { ev.dataTransfer.dropEffect = 'none'; return; }
        zona.classList.add('is-encima');
    });
});
['dragleave', 'drop'].forEach((evento) => {
    zona.addEventListener(evento, (ev) => { ev.preventDefault(); zona.classList.remove('is-encima'); });
});
zona.addEventListener('drop', (ev) => {
    if (trabajando) return;
    const soltado = ev.dataTransfer?.files?.[0];
    if (soltado) analizar(soltado);
});

// ── Paso 1: analizar sin escribir ──

async function analizar(elegido) {
    if (trabajando) return;
    archivo = elegido;
    nombre.textContent = elegido.name;
    zona.classList.add('is-cargado');
    error.hidden = true;
    btnConfirmar.disabled = true;
    erroresWrap.hidden = true;
    vistaWrap.hidden = true;
    trabajar(true, `Revisando ${elegido.name}…`);

    const datos = new FormData();
    datos.append('archivo', elegido);

    try {
        const resp = await apiArchivo(URL_IMPORT, datos);
        if (!resp.ok) {
            resultado.hidden = true;
            error.textContent = mensajeError(resp, 'No se pudo leer el archivo.');
            error.hidden = false;
            return;
        }
        pintar(resp.data, resp.message);
    } finally {
        // En finally: si la petición falla, la zona tiene que volver a aceptar archivos.
        trabajar(false);
    }
}

// ── Paso 2: confirmar ──

btnConfirmar.addEventListener('click', async () => {
    if (!archivo || trabajando) return;
    const etiquetaPrevia = btnConfirmar.innerHTML;
    btnConfirmar.disabled = true;
    btnConfirmar.innerHTML = '<span class="cargando__aro" aria-hidden="true"></span>Cargando…';
    trabajar(true, 'Guardando las unidades…');

    const datos = new FormData();
    datos.append('archivo', archivo);
    datos.append('confirmar', '1');

    let recargando = false;
    try {
        const resp = await apiArchivo(URL_IMPORT, datos);
        if (!resp.ok) {
            error.textContent = mensajeError(resp, 'No se pudo completar la carga.');
            error.hidden = false;
            btnConfirmar.disabled = false;   // fallo de red: se puede reintentar
            return;
        }
        if (resp.data?.confirmado) {
            // La tabla la pinta el servidor: recargar deja ver lo cargado. El indicador se
            // queda puesto a propósito hasta que la página se vaya.
            recargando = true;
            location.reload();
            return;
        }
        // El archivo dejó de estar limpio entre la vista previa y la confirmación (por ejemplo,
        // alguien dio de alta una de esas placas desde el formulario).
        pintar(resp.data, resp.message);
    } finally {
        if (!recargando) {
            // pintar() ya decidió si el botón sigue habilitado: si el archivo dejó de estar
            // limpio, tiene que quedarse bloqueado. Aquí solo se devuelve la etiqueta.
            trabajar(false);
            btnConfirmar.innerHTML = etiquetaPrevia;
        }
    }
});

// ── Informe ──

function pintar(data, mensaje) {
    const errores = data?.errores ?? [];
    const listas = data?.listas ?? 0;
    const limpio = errores.length === 0 && listas > 0;

    resumen.innerHTML = `<span class="import-resumen__estado ${limpio ? 'es-ok' : 'es-error'}">${esc(mensaje)}</span>`;
    btnConfirmar.disabled = !limpio;
    btnConfirmar.innerHTML = limpio
        ? `Cargar ${listas} ${listas === 1 ? SINGULAR : PLURAL}`
        : `Cargar ${PLURAL}`;

    if (errores.length === 0) {
        erroresWrap.hidden = true;
        pintarVista(data?.vista ?? [], listas);
        return;
    }
    vistaWrap.hidden = true;

    const visibles = errores.slice(0, MAX_ERRORES);
    erroresBody.innerHTML = visibles.map((e) => `
        <tr>
            <td class="col col--corta">${e.fila ? esc(e.fila) : '—'}</td>
            <td class="col col--corta">${esc(e.columna || '—')}</td>
            <td class="col col--corta">${e.valor ? esc(e.valor) : '<span class="muted">vacío</span>'}</td>
            <td class="col col--text">${esc(e.mensaje)}</td>
        </tr>`).join('')
        + (errores.length > MAX_ERRORES
            ? `<tr><td colspan="4" class="muted">y ${errores.length - MAX_ERRORES} problema${errores.length - MAX_ERRORES === 1 ? '' : 's'} más</td></tr>`
            : '');
    erroresWrap.hidden = false;
}

/** Vista previa de lo que se va a crear: confirmar a ciegas no es confirmar. */
function pintarVista(vista, listas) {
    if (vista.length === 0) {
        vistaWrap.hidden = true;
        return;
    }
    vistaBody.innerHTML = vista.map((f) => `
        <tr>
            <td class="col col--corta">${esc(f.fila)}</td>
            ${CAMPOS_VISTA.map((campo, i) => `
                <td class="col ${i === 0 ? 'col--nombre' : 'col--corta'}">${esc(f[campo] ?? '')}</td>`).join('')}
        </tr>`).join('');
    vistaPie.textContent = listas > vista.length
        ? `Se muestran las primeras ${vista.length} de ${listas} ${PLURAL}.`
        : '';
    vistaPie.hidden = listas <= vista.length;
    vistaWrap.hidden = false;
}
