<?php
/**
 * Escritura de catálogos y tablas de referencia (plan §5.3, solo Admin Global).
 * Config-driven: nombres de tabla y columnas en lista blanca (SPEC), nunca del usuario.
 * Soft-delete vía activo = 0. Escritura en transacción con bitácora.
 */

declare(strict_types=1);

final class CatalogoAdminService
{
    /** Tablas editables y sus campos con tipo. */
    private const SPEC = [
        'tipos_equipo'        => ['label' => 'Tipo de equipo',       'fields' => ['nombre' => 'string', 'descripcion' => 'text', 'orden' => 'int']],
        'tipos_licencia'      => ['label' => 'Tipo de licencia',     'fields' => ['nombre' => 'string']],
        'permisos_especiales' => ['label' => 'Permiso especial',     'fields' => ['nombre' => 'string']],
        'categorias_vehiculo' => ['label' => 'Categoría de vehículo','fields' => ['nombre' => 'string', 'es_flota_operativa' => 'bool', 'requiere_furgon' => 'bool', 'orden' => 'int']],
        'capacidades'         => ['label' => 'Capacidad',            'fields' => ['nombre' => 'string', 'descripcion' => 'text', 'orden' => 'int']],
        'paises'              => ['label' => 'País',                 'fields' => ['codigo_iso' => 'iso2', 'nombre' => 'string', 'region' => 'region', 'orden' => 'int']],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return string[] tablas editables. */
    public static function tablas(): array
    {
        return array_keys(self::SPEC);
    }

    public static function spec(string $tabla): array
    {
        self::assert($tabla);
        return self::SPEC[$tabla];
    }

    public function crear(string $tabla, array $input, array $user): int
    {
        self::assert($tabla);
        $data = $this->validar($tabla, $input, null);
        $cols = array_keys($data);
        $ph = array_map(static fn(string $c): string => ':' . $c, $cols);

        return tx($this->pdo, function () use ($tabla, $data, $cols, $ph, $user): int {
            $sql = "INSERT INTO {$tabla} (" . implode(', ', $cols) . ', created_by) VALUES ('
                 . implode(', ', $ph) . ', :created_by)';
            $params = [];
            foreach ($data as $k => $val) {
                $params[':' . $k] = $val;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params + [':created_by' => $user['id']]);
            $id = (int) $this->pdo->lastInsertId();
            registrar_bitacora($this->pdo, $user['id'], $tabla, $id, AccionBitacora::CREAR, ['despues' => $data]);
            return $id;
        });
    }

    public function actualizar(string $tabla, int $id, array $input, array $user): void
    {
        self::assert($tabla);
        $actual = $this->find($tabla, $id);
        if ($actual === null) {
            json_error('Registro no encontrado', 404);
        }
        $data = $this->validar($tabla, $input, $id);
        $sets = array_map(static fn(string $c): string => "{$c} = :{$c}", array_keys($data));

        tx($this->pdo, function () use ($tabla, $id, $data, $sets, $actual, $user): void {
            $params = [];
            foreach ($data as $k => $val) {
                $params[':' . $k] = $val;
            }
            $stmt = $this->pdo->prepare("UPDATE {$tabla} SET " . implode(', ', $sets) . ' WHERE id = :id');
            $stmt->execute($params + [':id' => $id]);
            registrar_bitacora($this->pdo, $user['id'], $tabla, $id, AccionBitacora::EDITAR, [
                'antes' => array_intersect_key($actual, $data), 'despues' => $data,
            ]);
        });
    }

    public function cambiarActivo(string $tabla, int $id, bool $activo, array $user): void
    {
        self::assert($tabla);
        $actual = $this->find($tabla, $id);
        if ($actual === null) {
            json_error('Registro no encontrado', 404);
        }
        tx($this->pdo, function () use ($tabla, $id, $actual, $activo, $user): void {
            $this->pdo->prepare("UPDATE {$tabla} SET activo = :a WHERE id = :id")
                ->execute([':a' => $activo ? 1 : 0, ':id' => $id]);
            registrar_bitacora($this->pdo, $user['id'], $tabla, $id, AccionBitacora::EDITAR, [
                'antes' => ['activo' => (int) $actual['activo']], 'despues' => ['activo' => $activo ? 1 : 0],
            ]);
        });
    }

    private function find(string $tabla, int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$tabla} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Valida y normaliza según el tipo de cada campo. */
    private function validar(string $tabla, array $input, ?int $exceptId): array
    {
        $v = new Validator($input);
        $fields = self::SPEC[$tabla]['fields'];
        $out = [];

        foreach ($fields as $campo => $tipo) {
            $label = ucfirst(str_replace('_', ' ', $campo));
            switch ($tipo) {
                case 'string':
                    $v->required($campo, $label)->maxLen($campo, 100, $label);
                    break;
                case 'text': // descripción opcional
                    $v->maxLen($campo, 255, $label);
                    break;
                case 'iso2':
                    $v->required($campo, $label)->maxLen($campo, 2, $label);
                    break;
                case 'int':
                    $v->positiveInt($campo, $label);
                    break;
                case 'region':
                    $v->required($campo, $label)->inEnum($campo, RegionPais::values(), $label);
                    break;
            }
        }
        $v->validateOrFail();

        foreach ($fields as $campo => $tipo) {
            $val = $v->value($campo);
            $out[$campo] = match ($tipo) {
                'bool'   => array_key_exists($campo, $input) ? (int) (bool) $input[$campo] : 0,
                'int'    => $val !== null && $val !== '' ? (int) $val : 0,
                'iso2'   => strtoupper((string) $val),
                'text'   => $val === null || $val === '' ? null : $val,
                default  => $val,
            };
        }

        // Unicidad de codigo_iso en países.
        if ($tabla === 'paises') {
            $sql = 'SELECT 1 FROM paises WHERE codigo_iso = :c' . ($exceptId ? ' AND id <> :id' : '') . ' LIMIT 1';
            $params = [':c' => $out['codigo_iso']];
            if ($exceptId) {
                $params[':id'] = $exceptId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn() !== false) {
                json_unprocessable(['codigo_iso' => 'Ya existe un país con ese código ISO.']);
            }
        }

        return $out;
    }

    private static function assert(string $tabla): void
    {
        if (!isset(self::SPEC[$tabla])) {
            json_error('Catálogo no válido', 404);
        }
    }
}
