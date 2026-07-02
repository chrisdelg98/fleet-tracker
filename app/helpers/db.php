<?php
/**
 * Utilidades de acceso a datos. Envuelve operaciones múltiples (escritura + bitácora,
 * cambio de estado + override) en una transacción: todo o nada (AGENTS.md §Integridad 1).
 */

declare(strict_types=1);

/**
 * Ejecuta $fn dentro de una transacción. Hace commit si retorna sin excepción y rollback
 * si lanza. Devuelve lo que retorne $fn.
 *
 * @template T
 * @param callable(PDO):T $fn
 * @return T
 */
function tx(PDO $pdo, callable $fn): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
