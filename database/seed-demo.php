<?php
/**
 * Carga de datos de DEMO para pruebas (database/seeds/demo.php).
 *
 *   php database/seed-demo.php --force
 *
 * BORRA los datos operativos —pilotos, unidades, movimientos, overrides y bitácora— y los
 * reemplaza por el juego de ejemplo. NO toca catálogos, estaciones ni usuarios.
 *
 * Dos guardas a propósito: solo corre con APP_ENV=local y solo con --force, para que nadie
 * lo dispare por accidente ni contra un entorno que no sea el suyo.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
$entorno = load_env(BASE_PATH . '/.env')['APP_ENV'] ?? 'local';
if ($entorno !== 'local') {
    fwrite(STDERR, "Abortado: seed-demo solo corre con APP_ENV=local (actual: {$entorno}).\n");
    exit(1);
}
if (!in_array('--force', $argv, true)) {
    fwrite(STDERR, "Abortado: este script borra los datos operativos. Repite con --force si es lo que quieres.\n");
    exit(1);
}

$demo = require __DIR__ . '/seeds/demo.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** Ids de catálogo por nombre, para no acoplar el demo a ids concretos. */
$idPorNombre = static function (PDO $pdo, string $tabla, string $columna = 'nombre'): array {
    $out = [];
    foreach ($pdo->query("SELECT id, {$columna} AS k FROM {$tabla}")->fetchAll() as $r) {
        $out[$r['k']] = (int) $r['id'];
    }
    return $out;
};

$categorias  = $idPorNombre($pdo, 'categorias_vehiculo');
$tiposEquipo = $idPorNombre($pdo, 'tipos_equipo');
$capacidades = $idPorNombre($pdo, 'capacidades');
$estaciones  = $idPorNombre($pdo, 'estaciones', 'codigo');
$paises      = $idPorNombre($pdo, 'paises', 'codigo_iso');

$usuarioId = (int) $pdo->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetchColumn();
if ($usuarioId === 0) {
    fwrite(STDERR, "Abortado: no hay usuarios. Corre primero database/seed.php.\n");
    exit(1);
}

$ahora = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$desfase = static fn(int $horas): string => $ahora->modify(($horas >= 0 ? '+' : '') . $horas . ' hours')->format('Y-m-d H:i:s');

$pdo->beginTransaction();
try {
    // ── Limpieza de lo operativo (el orden respeta las claves foráneas) ──
    $pdo->exec('DELETE FROM bitacora');
    $pdo->exec('UPDATE movimientos SET movimiento_regreso_id = NULL');
    $pdo->exec('DELETE FROM movimientos');
    $pdo->exec('DELETE FROM overrides_unidad');
    $pdo->exec('DELETE FROM unidad_permisos');
    $pdo->exec('DELETE FROM unidades');
    $pdo->exec('DELETE FROM pilotos');

    // ── Pilotos ──
    $insPiloto = $pdo->prepare(
        'INSERT INTO pilotos (nombre, tipo_licencia_id, no_licencia, licencia_vence, estacion_id, activo, created_by)
         VALUES (:nombre, :tipo, :lic, :vence, :estacion, 1, :uid)'
    );
    $pilotoIds = [];
    foreach ($demo['pilotos'] as [$nombre, $tipoLic, $noLic, $diasVence, $estacion]) {
        $insPiloto->execute([
            ':nombre' => $nombre, ':tipo' => $tipoLic, ':lic' => $noLic,
            ':vence' => $ahora->modify(($diasVence >= 0 ? '+' : '') . $diasVence . ' days')->format('Y-m-d'),
            ':estacion' => $estaciones[$estacion], ':uid' => $usuarioId,
        ]);
        $pilotoIds[] = (int) $pdo->lastInsertId();
    }

    // ── Unidades ──
    $insUnidad = $pdo->prepare(
        'INSERT INTO unidades (placa_unidad, marca, modelo, anio, categoria_vehiculo_id, en_disponibilidad,
                               capacidad_id, tipo_equipo_id, estacion_id, piloto_asignado_id,
                               estado_vehiculo, estado_notas, activo, created_by)
         VALUES (:placa, :marca, :modelo, :anio, :categoria, 1, :capacidad, :tipo, :estacion, :piloto,
                 :estado, :notas, 1, :uid)'
    );
    $unidadIds = [];
    foreach ($demo['unidades'] as [$placa, $categoria, $marca, $modelo, $anio, $tipo, $capacidad, $estacion, $piloto, $estado]) {
        $insUnidad->execute([
            ':placa' => $placa, ':marca' => $marca, ':modelo' => $modelo, ':anio' => $anio,
            ':categoria' => $categorias[$categoria], ':capacidad' => $capacidades[$capacidad],
            ':tipo' => $tiposEquipo[$tipo], ':estacion' => $estaciones[$estacion],
            ':piloto' => $piloto !== null ? $pilotoIds[$piloto] : null,
            ':estado' => $estado,
            ':notas' => $estado === 'OPERATIVO' ? null : 'Registrado en la carga de ejemplo',
            ':uid' => $usuarioId,
        ]);
        $unidadIds[$placa] = (int) $pdo->lastInsertId();
    }

    // ── Permisos especiales de cada unidad ──
    // Un permiso lo emite una autoridad nacional: solo se puede asignar si es global o del
    // mismo país que la estación de la unidad. Lo que no cuadre se avisa en vez de colarse.
    $permisosCat = [];
    foreach ($pdo->query('SELECT id, nombre, pais_id FROM permisos_especiales WHERE activo = 1') as $r) {
        $permisosCat[$r['nombre']] = ['id' => (int) $r['id'], 'pais' => $r['pais_id'] !== null ? (int) $r['pais_id'] : null];
    }
    $paisDeEstacion = [];
    foreach ($pdo->query('SELECT codigo, pais_id FROM estaciones') as $r) {
        $paisDeEstacion[$r['codigo']] = (int) $r['pais_id'];
    }
    $estacionDeUnidad = [];
    foreach ($demo['unidades'] as $u) {
        $estacionDeUnidad[$u[0]] = $u[7];
    }
    $insPermiso = $pdo->prepare(
        'INSERT INTO unidad_permisos (unidad_id, permiso_especial_id, created_by)
         VALUES (:unidad, :permiso, :uid)'
    );
    $omitidos = [];
    foreach ($demo['permisos'] ?? [] as $placa => $nombres) {
        $paisUnidad = $paisDeEstacion[$estacionDeUnidad[$placa]] ?? null;
        foreach ($nombres as $nombre) {
            $permiso = $permisosCat[$nombre] ?? null;
            if ($permiso === null || ($permiso['pais'] !== null && $permiso['pais'] !== $paisUnidad)) {
                $omitidos[] = "{$placa}: {$nombre}";
                continue;
            }
            $insPermiso->execute([':unidad' => $unidadIds[$placa], ':permiso' => $permiso['id'], ':uid' => $usuarioId]);
        }
    }

    // ── Movimientos ──
    $insMov = $pdo->prepare(
        'INSERT INTO movimientos (unidad_id, piloto_id, pais_origen_id, pais_destino_id, tipo_ruta,
                                  fecha_salida, fecha_fin_estimada, estado, retorno_disponible,
                                  reservado_para, notas, created_by)
         VALUES (:unidad, :piloto, :origen, :destino, :tipo, :salida, :fin, :estado, :retorno,
                 :para, :notas, :uid)'
    );
    foreach ($demo['movimientos'] as [$placa, $estado, $origen, $destino, $salidaH, $finH, $piloto, $retorno, $para, $notas]) {
        $insMov->execute([
            ':unidad' => $unidadIds[$placa],
            ':piloto' => $piloto !== null ? $pilotoIds[$piloto] : null,
            ':origen' => $paises[$origen], ':destino' => $paises[$destino],
            ':tipo' => $origen === $destino ? 'NACIONAL' : 'INTERNACIONAL',
            ':salida' => $desfase($salidaH), ':fin' => $desfase($finH),
            ':estado' => $estado, ':retorno' => $retorno ? 1 : 0,
            ':para' => $para, ':notas' => $notas, ':uid' => $usuarioId,
        ]);
    }

    // ── Ocupaciones que no son viajes ──
    $insOv = $pdo->prepare(
        'INSERT INTO overrides_unidad (unidad_id, tipo, origen, desde, hasta, motivo, cerrado, created_by)
         VALUES (:unidad, :tipo, :origen, :desde, :hasta, :motivo, 0, :uid)'
    );
    foreach ($demo['overrides'] as [$placa, $tipo, $origen, $motivo, $desdeH, $hastaH]) {
        $insOv->execute([
            ':unidad' => $unidadIds[$placa], ':tipo' => $tipo, ':origen' => $origen,
            ':desde' => $desfase($desdeH), ':hasta' => $hastaH !== null ? $desfase($hastaH) : null,
            ':motivo' => $motivo, ':uid' => $usuarioId,
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Error cargando el demo: " . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "Demo cargado: %d pilotos, %d unidades, %d movimientos, %d ocupaciones.\n",
    count($demo['pilotos']),
    count($demo['unidades']),
    count($demo['movimientos']),
    count($demo['overrides'])
);
if ($omitidos) {
    fwrite(STDERR, "Permisos omitidos (no existen en el catálogo o son de otro país):
  - "
        . implode("
  - ", $omitidos) . "
");
}
