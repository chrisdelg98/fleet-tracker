/**
 * Carga masiva de flota. Dos pasos deliberados: al elegir el archivo se analiza (sin escribir
 * nada) y solo cuando el informe sale limpio se habilita el botón de cargar. Así el usuario ve
 * qué va a pasar antes de que pase, y un archivo con errores nunca deja media flota cargada.
 */

import { apiArchivo, mensajeError } from './api.js';

const dlg = document.getElementById('dlg-import');
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

const esc = (t) => String(t ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

document.addEventListener('click', (ev) => {
    if (!ev.target.closest('[data-action="carga-masiva"]')) return;
    reiniciar();
    dlg.showModal();
});

// El cierre por [data-close] ya lo enlaza flota.js para todos los diálogos de la página.

function reiniciar() {
    archivo = null;
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
    zona.addEventListener(evento, (ev) => { ev.preventDefault(); zona.classList.add('is-encima'); });
});
['dragleave', 'drop'].forEach((evento) => {
    zona.addEventListener(evento, (ev) => { ev.preventDefault(); zona.classList.remove('is-encima'); });
});
zona.addEventListener('drop', (ev) => {
    const soltado = ev.dataTransfer?.files?.[0];
    if (soltado) analizar(soltado);
});

// ── Paso 1: analizar sin escribir ──

async function analizar(elegido) {
    archivo = elegido;
    nombre.textContent = elegido.name;
    zona.classList.add('is-cargado');
    error.hidden = true;
    btnConfirmar.disabled = true;
    resumen.innerHTML = '<span class="muted">Revisando el archivo…</span>';
    resultado.hidden = false;
    erroresWrap.hidden = true;
    vistaWrap.hidden = true;

    const datos = new FormData();
    datos.append('archivo', elegido);
    const resp = await apiArchivo('/api/flota/importar', datos);

    if (!resp.ok) {
        resultado.hidden = true;
        error.textContent = mensajeError(resp, 'No se pudo leer el archivo.');
        error.hidden = false;
        return;
    }
    pintar(resp.data, resp.message);
}

// ── Paso 2: confirmar ──

btnConfirmar.addEventListener('click', async () => {
    if (!archivo) return;
    btnConfirmar.disabled = true;
    resumen.innerHTML = '<span class="muted">Cargando unidades…</span>';

    const datos = new FormData();
    datos.append('archivo', archivo);
    datos.append('confirmar', '1');
    const resp = await apiArchivo('/api/flota/importar', datos);

    if (!resp.ok) {
        error.textContent = mensajeError(resp, 'No se pudo completar la carga.');
        error.hidden = false;
        btnConfirmar.disabled = false;
        return;
    }
    if (resp.data?.confirmado) {
        location.reload();   // la tabla la pinta el servidor: recargar deja ver lo cargado
        return;
    }
    // El archivo dejó de estar limpio entre la vista previa y la confirmación (por ejemplo,
    // alguien dio de alta una de esas placas desde el formulario).
    pintar(resp.data, resp.message);
});

// ── Informe ──

function pintar(data, mensaje) {
    const errores = data?.errores ?? [];
    const listas = data?.listas ?? 0;
    const limpio = errores.length === 0 && listas > 0;

    resumen.innerHTML = `<span class="import-resumen__estado ${limpio ? 'es-ok' : 'es-error'}">${esc(mensaje)}</span>`;
    btnConfirmar.disabled = !limpio;
    btnConfirmar.textContent = limpio
        ? `Cargar ${listas} unidad${listas === 1 ? '' : 'es'}`
        : 'Cargar unidades';

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
            <td class="col col--nombre">${esc(f.placa_unidad)}</td>
            <td class="col col--corta">${esc(f.categoria)}</td>
            <td class="col col--corta">${esc(f.estacion)}</td>
            <td class="col col--text">${esc([f.marca, f.modelo, f.anio].filter(Boolean).join(' '))}</td>
        </tr>`).join('');
    vistaPie.textContent = listas > vista.length
        ? `Se muestran las primeras ${vista.length} de ${listas} unidades.`
        : '';
    vistaPie.hidden = listas <= vista.length;
    vistaWrap.hidden = false;
}
