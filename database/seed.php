<?php
/**
 * Seed runner — Fase 0. Inserta los datos iniciales de database/seeds/ de forma
 * idempotente (re-ejecutable sin duplicar): catálogos, estaciones y el Admin Global.
 * El admin se crea con password_hash, por eso el runner es PHP y no SQL plano.
 *
 * Uso: php database/seed.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/enums.php';
require_once $root . '/config/database.php';

$pdo = db();

$paises    = require $root . '/database/seeds/paises.php';
$categorias = require $root . '/database/seeds/categorias_vehiculo.php';
$tiposEquipo = require $root . '/database/seeds/tipos_equipo.php';
$tiposLic  = require $root . '/database/seeds/tipos_licencia.php';
$capacidades = require $root . '/database/seeds/capacidades.php';
$estaciones = require $root . '/database/seeds/estaciones.php';
$admin     = require $root . '/database/seeds/admin.php';

/** Inserta una fila solo si no existe otra que cumpla la condición. Devuelve 1 si insertó. */
$insertIfMissing = static function (PDO $pdo, string $existsSql, array $existsParams, string $insertSql, array $insertParams): int {
    $check = $pdo->prepare($existsSql);
    $check->execute($existsParams);
    if ($check->fetchColumn() !== false) {
        return 0;
    }
    $pdo->prepare($insertSql)->execute($insertParams);
    return 1;
};

// ── Países (unique codigo_iso) ──
$nP = 0;
foreach ($paises as [$iso, $nombre, $region, $orden]) {
    $nP += $insertIfMissing(
        $pdo,
        'SELECT 1 FROM paises WHERE codigo_iso = ?', [$iso],
        'INSERT INTO paises (codigo_iso, nombre, region, orden) VALUES (?, ?, ?, ?)',
        [$iso, $nombre, $region, $orden]
    );
}

// ── Categorías de vehículo (por nombre) ──
$nC = 0;
foreach ($categorias as [$nombre, $esFlota, $requiereFurgon, $orden]) {
    $nC += $insertIfMissing(
        $pdo,
        'SELECT 1 FROM categorias_vehiculo WHERE nombre = ?', [$nombre],
        'INSERT INTO categorias_vehiculo (nombre, es_flota_operativa, requiere_furgon, orden) VALUES (?, ?, ?, ?)',
        [$nombre, $esFlota, $requiereFurgon, $orden]
    );
}

// ── Capacidades (por nombre) ──
$nCap = 0;
foreach ($capacidades as [$nombre, $orden]) {
    $nCap += $insertIfMissing(
        $pdo,
        'SELECT 1 FROM capacidades WHERE nombre = ?', [$nombre],
        'INSERT INTO capacidades (nombre, orden) VALUES (?, ?)',
        [$nombre, $orden]
    );
}

// ── Tipos de equipo (por nombre) ──
$nTE = 0;
foreach ($tiposEquipo as $nombre) {
    $nTE += $insertIfMissing(
        $pdo,
        'SELECT 1 FROM tipos_equipo WHERE nombre = ?', [$nombre],
        'INSERT INTO tipos_equipo (nombre) VALUES (?)',
        [$nombre]
    );
}

// ── Tipos de licencia (por nombre) ──
$nTL = 0;
foreach ($tiposLic as $nombre) {
    $nTL += $insertIfMissing(
        $pdo,
        'SELECT 1 FROM tipos_licencia WHERE nombre = ?', [$nombre],
        'INSERT INTO tipos_licencia (nombre) VALUES (?)',
        [$nombre]
    );
}

// ── Estaciones (unique codigo; país por codigo_iso) ──
$nE = 0;
foreach ($estaciones as [$nombre, $codigo, $isoPais, $tz]) {
    $nE += $insertIfMissing(
        $pdo,
        'SELECT 1 FROM estaciones WHERE codigo = ?', [$codigo],
        'INSERT INTO estaciones (nombre, pais_id, codigo, timezone)
         VALUES (?, (SELECT id FROM paises WHERE codigo_iso = ?), ?, ?)',
        [$nombre, $isoPais, $codigo, $tz]
    );
}

// ── Admin Global (por email; contraseña hasheada) ──
$nA = $insertIfMissing(
    $pdo,
    'SELECT 1 FROM usuarios WHERE email = ?', [$admin['email']],
    'INSERT INTO usuarios (nombre, email, password_hash, rol, estacion_id)
     VALUES (?, ?, ?, ?, NULL)',
    [$admin['nombre'], $admin['email'], password_hash($admin['password'], PASSWORD_DEFAULT), $admin['rol']]
);

echo "Seeds aplicados (nuevos): países={$nP}, categorías={$nC}, capacidades={$nCap}, " .
     "tipos_equipo={$nTE}, tipos_licencia={$nTL}, estaciones={$nE}, admin={$nA}.\n";
if ($nA === 1) {
    echo "Admin Global creado: {$admin['email']} / {$admin['password']} (cámbiala tras el primer login).\n";
}
