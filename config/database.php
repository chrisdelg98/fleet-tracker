<?php
/**
 * Conexión PDO centralizada (AGENTS.md §Stack, plan §10). Una sola instancia por
 * proceso, utf8mb4, errores como excepciones, prepares reales (sin emulación) y la
 * sesión de BD fijada en UTC (plan §3.2). Toda query del sistema usa este PDO con
 * prepared statements — jamás concatenar SQL.
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * Devuelve la instancia PDO compartida, creándola en el primer llamado.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $env  = load_env(dirname(__DIR__) . '/.env');
    $host = $env['DB_HOST']     ?? '127.0.0.1';
    $port = $env['DB_PORT']     ?? '3306';
    $name = $env['DB_DATABASE'] ?? '';
    $user = $env['DB_USERNAME'] ?? '';
    $pass = $env['DB_PASSWORD'] ?? '';

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
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        // No filtrar credenciales ni detalles de conexión al cliente.
        error_log('Error de conexión a BD: ' . $e->getMessage());
        http_response_code(500);
        exit('Error de conexión a la base de datos.');
    }

    return $pdo;
}
