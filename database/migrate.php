<?php
/**
 * Runner de migraciones — Fase 0.
 *
 * Aplica los .sql de database/migrations/ en orden numérico, registra los aplicados
 * en la tabla schema_migrations y salta los ya registrados. Cada migración corre en
 * su propia transacción; si una falla, se revierte y el proceso se detiene.
 *
 * Nota MySQL: el DDL (CREATE/ALTER) provoca commit implícito, por lo que un CREATE a
 * medias no puede revertirse por completo. La transacción sí garantiza que la fila en
 * schema_migrations solo se escriba si la migración se ejecutó sin excepción, y el
 * salto de las ya registradas hace el proceso re-ejecutable.
 *
 * Uso: php database/migrate.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// ── Cargar .env (parser compartido) ──
require_once $root . '/config/env.php';
$env = load_env($root . '/.env');

$host = $env['DB_HOST']     ?? '127.0.0.1';
$port = $env['DB_PORT']     ?? '3306';
$name = $env['DB_DATABASE'] ?? '';
$user = $env['DB_USERNAME'] ?? '';
$pass = $env['DB_PASSWORD'] ?? '';

// ── Conexión ──
try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'"); // la BD trabaja siempre en UTC (plan §3.2)
} catch (PDOException $e) {
    fwrite(STDERR, "No se pudo conectar a la BD: {$e->getMessage()}\n");
    exit(1);
}

// ── Tabla de control ──
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(255) NOT NULL,
        applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = array_flip(
    $pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN)
);

$files = glob($root . '/database/migrations/*.sql');
sort($files, SORT_STRING); // orden numérico por prefijo 001_, 002_...

if (!$files) {
    echo "No hay migraciones en database/migrations/.\n";
    exit(0);
}

$ran = 0;
foreach ($files as $file) {
    $version = basename($file);
    if (isset($applied[$version])) {
        continue;
    }

    echo "→ Aplicando {$version} ... ";
    try {
        $pdo->beginTransaction();
        foreach (split_statements((string) file_get_contents($file)) as $statement) {
            $pdo->exec($statement);
        }
        $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?)")->execute([$version]);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        echo "OK\n";
        $ran++;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "ERROR\n";
        fwrite(STDERR, "  {$version} falló: {$e->getMessage()}\n");
        exit(1);
    }
}

echo $ran === 0
    ? "La base de datos ya está al día — nada que aplicar.\n"
    : "Listo: {$ran} migración(es) aplicada(s).\n";

/**
 * Separa un archivo .sql en sentencias individuales. Los .sql del proyecto no usan ';'
 * dentro de literales, así que dividir por ';' es seguro.
 */
function split_statements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql); // quitar comentarios de línea
    return array_values(array_filter(
        array_map('trim', explode(';', $sql)),
        static fn(string $s): bool => $s !== ''
    ));
}
