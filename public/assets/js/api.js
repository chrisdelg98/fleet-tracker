/**
 * Cliente fetch para la API JSON. La respuesta del backend es el sobre
 * { ok, data|error, message[, errors] }; aquí se desenvuelve para que quien llama
 * reciba el objeto de negocio directamente en `data` y los mensajes en `message`/`errors`.
 * La cookie de sesión viaja sola (samesite=Lax protege de CSRF cross-site).
 *
 * @returns {{ok:boolean, status:number, data:*, message:string, errors:Object|null}}
 */
export async function api(method, url, body) {
    const opts = { method, headers: { Accept: 'application/json' } };
    if (body !== undefined) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    let payload = {};
    try { payload = await res.json(); } catch { /* respuesta sin cuerpo JSON */ }

    return {
        ok: res.ok,
        status: res.status,
        data: payload.data ?? null,
        message: payload.message || '',
        errors: payload.errors || null,
    };
}

/** Muestra el primer error de validación (o el mensaje general) en un contenedor. */
export function showError(el, resp) {
    const errs = resp.errors ? Object.values(resp.errors) : [];
    el.textContent = errs.length ? errs.join(' ') : (resp.message || 'Ocurrió un error.');
    el.hidden = false;
}
