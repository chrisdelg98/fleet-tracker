<?php
/**
 * Borra los movimientos de prueba para arrancar la operación real.
 *
 * Toca solo lo que se genera operando: movimientos, sus activos de apoyo y las entradas de
 * bitácora que hablan de ellos. **No toca** flota, pilotos, rutas, contactos, usuarios ni
 * catálogos: eso es configuración, no pruebas.
 *
 * Por omisión no borra nada, solo cuenta. Un borrado en producción no debería poder ocurrir
 * por escribir mal un comando.
 *
 * Uso:
 *   php tools/limpiar-movimientos.php                 muestra qué se borraría
 *   php tools/limpiar-movimientos.php --confirmar     hace el respaldo y borra
 *   php tools/limpiar-movimientos.php --confirmar --bloqueos    borra además los bloqueos manuales
 *   php tools/limpiar-movimientos.php --confirmar --correos     vacía además la bitácora de correo
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

$opciones = array_slice($argv, 1);
$confirmar = in_array('--confirmar', $opciones, true);
$conBloqueos = in_array('--bloqueos', $opciones, true);
$conCorreos = in_array('--correos', $opciones, true);

$pdo = db();
$contar = static fn(string $sql): int => (int) $pdo->query('SELECT COUNT(*) FROM ' . $sql)->fetchColumn();

$movimientos = $contar('movimientos');
$apoyos      = $contar('movimiento_unidades');
$bitacora    = $contar("bitacora WHERE entidad = 'movimiento'");
$bloqueos    = $contar("overrides_unidad WHERE origen = 'MANUAL'");
$taller      = $contar("overrides_unidad WHERE origen = 'AUTO_ESTADO' AND cerrado = 0");

echo "\n── Lo que se borraría ──\n";
printf("  %-42s %d\n", 'movimientos (reservas y viajes)', $movimientos);
printf("  %-42s %d\n", 'movimiento_unidades (cabezales, chasis)', $apoyos);
printf("  %-42s %d\n", 'bitacora de movimientos', $bitacora);
printf("  %-42s %d%s\n", 'overrides manuales (bloqueos)', $bloqueos, $conBloqueos ? '' : '   ← se conservan, usa --bloqueos');

echo "\n── Lo que NO se toca ──\n";
foreach (['unidades' => 'unidades', 'pilotos' => 'pilotos', 'rutas' => 'rutas',
          'listas_notificacion' => 'listas de contactos', 'usuarios' => 'usuarios'] as $tabla => $etiqueta) {
    printf("  %-42s %d\n", $etiqueta, $contar($tabla));
}
if ($taller > 0) {
    printf("\n  Nota: hay %d override(s) abiertos por estado de la unidad (taller o inoperativa).\n", $taller);
    echo "  No se tocan: reflejan la condición real de esas unidades, no una prueba.\n";
    echo "  Si alguna ya está operativa, arréglalo cambiándole el estado desde Flota.\n";
}

if (!$confirmar) {
    echo "\n  Nada se ha borrado. Para hacerlo:\n";
    echo "      php tools/limpiar-movimientos.php --confirmar\n\n";
    exit(0);
}

// ── Respaldo ──
// Antes de borrar, las filas se guardan como INSERTs para poder devolverlas si algo salió mal.
$dir = BASE_PATH . '/storage/backups';
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "No se pudo crear {$dir}; sin respaldo no se borra nada.\n");
    exit(1);
}
$ruta = $dir . '/movimientos-' . date('Ymd-His') . '.sql';
$out = @fopen($ruta, 'w');
if ($out === false) {
    fwrite(STDERR, "No se pudo escribir {$ruta}; sin respaldo no se borra nada.\n");
    exit(1);
}

/** Vuelca una consulta como INSERTs, con los valores ya escapados por el driver. */
$volcar = static function (string $tabla, string $where) use ($pdo, $out): int {
    $filas = $pdo->query("SELECT * FROM {$tabla} {$where}")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as $fila) {
        $cols = implode(', ', array_keys($fila));
        $vals = implode(', ', array_map(
            static fn($v): string => $v === null ? 'NULL' : $pdo->quote((string) $v),
            $fila
        ));
        fwrite($out, "INSERT INTO {$tabla} ({$cols}) VALUES ({$vals});\n");
    }
    return count($filas);
};

fwrite($out, "-- Respaldo previo a limpiar movimientos · " . date('c') . "\n");
fwrite($out, "-- Para restaurar: mysql -u USUARIO -p BASE < " . basename($ruta) . "\n\n");
fwrite($out, "SET FOREIGN_KEY_CHECKS = 0;\n\n");
$volcar('movimientos', '');
$volcar('movimiento_unidades', '');
$volcar('bitacora', "WHERE entidad = 'movimiento'");
if ($conBloqueos) {
    $volcar('overrides_unidad', "WHERE origen = 'MANUAL'");
}
fwrite($out, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
fclose($out);
printf("\n  Respaldo escrito en %s (%s KB)\n", $ruta, number_format(filesize($ruta) / 1024, 1));

// ── Borrado ──
$pdo->beginTransaction();
try {
    // El regreso apunta a otro movimiento: se suelta la referencia antes de borrar para no
    // depender del orden de las filas.
    $pdo->exec('UPDATE movimientos SET movimiento_regreso_id = NULL');
    $pdo->exec('DELETE FROM movimiento_unidades');
    $pdo->exec('DELETE FROM movimientos');
    $pdo->exec("DELETE FROM bitacora WHERE entidad = 'movimiento'");
    if ($conBloqueos) {
        $pdo->exec("DELETE FROM overrides_unidad WHERE origen = 'MANUAL'");
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nFalló el borrado y se revirtió: " . $e->getMessage() . "\n");
    fwrite(STDERR, "El respaldo sigue en {$ruta}.\n");
    exit(1);
}

// Con las tablas vacías, la numeración vuelve a empezar: el primer viaje real es el #1 y no
// el #48. Va fuera de la transacción porque ALTER TABLE hace commit implícito en MySQL.
$pdo->exec('ALTER TABLE movimientos AUTO_INCREMENT = 1');

echo "\n  Listo. Movimientos borrados; flota, pilotos, rutas y contactos intactos.\n";
if (!$conCorreos) {
    echo "  La bitácora de correo se conservó (--correos para vaciarla).\n\n";
    exit(0);
}

$log = BASE_PATH . '/storage/logs/correo.log';
if (is_file($log) && @unlink($log)) {
    echo "  Bitácora de correo vaciada.\n\n";
} else {
    echo "  No había bitácora de correo que vaciar.\n\n";
}
