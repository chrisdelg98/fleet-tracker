<?php
/**
 * Bitácora / audit log (AGENTS.md §Convenciones 4, plan §5.9).
 * TODA escritura del sistema registra una fila con snapshot antes/después. Debe llamarse
 * DENTRO de la misma transacción que la operación que audita (integridad #5): si la
 * operación se revierte, su rastro también.
 */

declare(strict_types=1);

/**
 * Inserta una fila de bitácora.
 *
 * @param int|null    $usuarioId Autor (null solo para eventos generados por el sistema).
 * @param string      $entidad   "movimiento", "unidad", "piloto", "override"...
 * @param string      $accion    Constante de AccionBitacora.
 * @param array|null  $detalle   Snapshot del cambio, ej. {campo: {antes, despues}}.
 */
function registrar_bitacora(
    PDO $pdo,
    ?int $usuarioId,
    string $entidad,
    int $entidadId,
    string $accion,
    ?array $detalle = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO bitacora (usuario_id, entidad, entidad_id, accion, detalle)
         VALUES (:usuario_id, :entidad, :entidad_id, :accion, :detalle)'
    );
    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':entidad'    => $entidad,
        ':entidad_id' => $entidadId,
        ':accion'     => $accion,
        ':detalle'    => $detalle !== null ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null,
    ]);
}
