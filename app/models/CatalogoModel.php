<?php
/**
 * Lectura de catálogos y tablas de referencia (plan §5.3). Nombres de tabla en lista
 * blanca (nunca provienen del usuario) para poder interpolarlos sin riesgo de inyección;
 * los valores siempre van por placeholder.
 */

declare(strict_types=1);

final class CatalogoModel
{
    private const TABLAS = [
        'tipos_equipo', 'tipos_licencia', 'permisos_especiales',
        'categorias_vehiculo', 'capacidades', 'paises', 'estaciones',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** Filas activas de un catálogo, en el orden indicado (columna en lista blanca). */
    public function activos(string $tabla, string $orderBy = 'nombre'): array
    {
        $this->assertTabla($tabla);
        $orden = in_array($orderBy, ['nombre', 'orden', 'codigo'], true) ? $orderBy : 'nombre';
        return $this->pdo->query("SELECT * FROM {$tabla} WHERE activo = 1 ORDER BY {$orden}")->fetchAll();
    }

    public function find(string $tabla, int $id): ?array
    {
        $this->assertTabla($tabla);
        $stmt = $this->pdo->prepare("SELECT * FROM {$tabla} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    private function assertTabla(string $tabla): void
    {
        if (!in_array($tabla, self::TABLAS, true)) {
            throw new InvalidArgumentException("Tabla de catálogo no permitida: {$tabla}");
        }
    }
}
