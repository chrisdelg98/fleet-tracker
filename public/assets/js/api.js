/**
 * Cliente fetch para la API JSON. Devuelve { ok, status, data }.
 * La cookie de sesión viaja sola (samesite=Lax protege de CSRF cross-site).
 */
export async function api(method, url, body) {
    const opts = { method, headers: { Accept: 'application/json' } };
    if (body !== undefined) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    let data = {};
    try { data = await res.json(); } catch { /* respuesta sin cuerpo */ }
    return { ok: res.ok, status: res.status, data };
}

/** Muestra el primer error de validación (o el mensaje general) en un contenedor. */
export function showError(el, resp) {
    const errs = resp.data && resp.data.errors ? Object.values(resp.data.errors) : [];
    el.textContent = errs.length ? errs.join(' ') : (resp.data.message || 'Ocurrió un error.');
    el.hidden = false;
}
