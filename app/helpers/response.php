<?php
/**
 * Respuestas JSON con estructura consistente (AGENTS.md §Convenciones 7):
 * { ok: bool, data|error, message }. Cada función fija el código HTTP y termina.
 */

declare(strict_types=1);

/** Emite el cuerpo JSON con el código dado y corta la ejecución. */
function json_out(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Respuesta exitosa. */
function json_ok(mixed $data = null, string $message = '', int $status = 200): void
{
    json_out($status, ['ok' => true, 'data' => $data, 'message' => $message]);
}

/**
 * Respuesta de error. Códigos típicos: 401 (sin sesión), 403 (sin permiso),
 * 409 (traslape), 422 (validación).
 */
function json_error(string $error, int $status = 400, string $message = ''): void
{
    json_out($status, ['ok' => false, 'error' => $error, 'message' => $message !== '' ? $message : $error]);
}
