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
        'permisos_especiales' => ['label' => 'Permiso especial',     'fields' => ['nombre' => 'string', 'descripcion' => 'text', 'pais_id' => 'pais', 'habilita_internacional' => 'bool']],
        'categorias_vehiculo' => ['label' => 'Categoría de vehículo','fields' => ['nombre' => 'string', 'es_flota_operativa' => 'bool', 'es_motriz' => 'bool', 'admite_arrastre' => 'bool', 'orden' => 'int']],
        'tipos_combustible'   => ['label' => 'Tipo de combustible','fields' => ['nombre' => 'string', 'orden' => 'int']],
        'capacidades'         => ['label' => 'Capacidad',            'fields' => ['nombre' => 'string', 'descripcion' => 'text', 'orden' => 'int']],
        'paises'              => ['label' => 'País',                 'fields' => ['codigo_iso' => 'iso2', 'nombre' => 'string', 'region' => 'region', 'orden' => 'int']],
        'tipos_mantenimiento' => ['label' => 'Tipo de mantenimiento', 'fields' => ['nombre' => 'string', 'es_preventivo' => 'bool', 'orden' => 'int']],
        'planes_mantenimiento'=> ['label' => 'Plan de mantenimiento', 'fields' => ['nombre' => 'string', 'intervalo_km' => 'int', 'intervalo_dias' => 'int', 'umbral_km' => 'int', 'umbral_dias' => 'int']],
        'monedas'             => ['label' => 'Moneda',                'fields' => ['codigo' => 'iso3', 'nombre' => 'string', 'por_dolar' => 'decimal', 'orden' => 'int']],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** Nombres de columna → etiqueta de interfaz; el resto se deriva del propio campo. */
    private const ETIQUETAS = [
        'nombre' => 'Nombre',
        'descripcion' => 'Descripción',
        'pais_id' => 'Alcance',
        'habilita_internacional' => 'Cruza frontera',
        'correos' => 'Correos',
        'codigo_iso' => 'Código ISO',
        'es_flota_operativa' => 'Flota operativa',
        'es_motriz' => 'Se mueve sola',
        'admite_arrastre' => 'Lleva equipo',
        'orden' => 'Orden',
        'region' => 'Región',
        'es_preventivo' => 'Reinicia el ciclo',
        'intervalo_km' => 'Cada (km)',
        'intervalo_dias' => 'Cada (días)',
        'umbral_km' => 'Avisar (km antes)',
        'umbral_dias' => 'Avisar (días antes)',
        'codigo' => 'Código',
        'por_dolar' => 'Unidades por dólar',
    ];

    /**
     * Correos de un campo de texto libre, limpios y sin repetir. Se acepta coma, punto y coma
     * o salto de línea porque es como llegan pegados desde un correo o una hoja.
     *
     * @return string[]
     */
    public static function correos(string $texto): array
    {
        $partes = preg_split('/[,;\s]+/u', trim($texto)) ?: [];
        $limpios = array_filter(array_map('trim', $partes), static fn(string $c): bool => $c !== '');
        return array_values(array_unique($limpios));
    }

    /** @return string[] los que no tienen forma de correo */
    public static function correosInvalidos(string $texto): array
    {
        return array_values(array_filter(
            self::correos($texto),
            static fn(string $c): bool => filter_var($c, FILTER_VALIDATE_EMAIL) === false
        ));
    }

    public static function etiqueta(string $campo): string
    {
        return self::ETIQUETAS[$campo] ?? ucfirst(str_replace('_', ' ', $campo));
    }

    /** Todas las etiquetas, para que el formulario del navegador use las mismas palabras. */
    public static function etiquetas(): array
    {
        return self::ETIQUETAS;
    }

    /**
     * Catálogos que pertenecen a un módulo y se gestionan dentro de él, no en Administración.
     *
     * Los de mantenimientos los mantiene cada encargado —son parte de su operación diaria, no de
     * la configuración del sistema—, así que viven en la pestaña Configuración del módulo. Siguen
     * pasando por este servicio: las reglas de escritura son las mismas, solo cambia quién entra.
     */
    public const DE_MODULO = ['tipos_mantenimiento', 'planes_mantenimiento', 'monedas'];

    /** @return string[] tablas editables desde Administración › Catálogos. */
    public static function tablas(): array
    {
        return array_values(array_diff(array_keys(self::SPEC), self::DE_MODULO));
    }

    /** @return string[] todas las tablas editables, vengan de donde vengan. */
    public static function todasLasTablas(): array
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
            $label = self::etiqueta($campo);
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
                case 'iso3':
                    $v->required($campo, $label)->maxLen($campo, 3, $label);
                    break;
                case 'decimal':
                    // Una tasa de cambio en cero o negativa haría una división imposible al
                    // convertir; se corta aquí y no al registrar el gasto.
                    $valor = (float) str_replace(',', '.', (string) ($input[$campo] ?? ''));
                    if ($valor <= 0) {
                        $v->addError($campo, "{$label} tiene que ser mayor que cero.");
                    }
                    break;
                case 'int':
                    $v->positiveInt($campo, $label);
                    break;
                case 'pais': // alcance del registro: un país del catálogo o vacío = global
                    $valor = trim((string) ($input[$campo] ?? ''));
                    if ($valor !== '' && !in_array((int) $valor, paises_ids_validos(), true)) {
                        json_unprocessable([$campo => 'El país no es válido.']);
                    }
                    break;
                case 'region':
                    $v->required($campo, $label)->inEnum($campo, RegionPais::values(), $label);
                    break;
                case 'correos': // uno o varios, separados por coma
                    $v->required($campo, $label)->maxLen($campo, 500, $label);
                    $malos = self::correosInvalidos((string) ($input[$campo] ?? ''));
                    if ($malos !== []) {
                        // Se citan los que fallan, no un "revisa el formato": con ocho
                        // direcciones en una celda, encontrar la mala a ojo es el problema.
                        $v->addError($campo, 'No parecen correos válidos: ' . implode(', ', $malos) . '.');
                    }
                    break;
            }
        }
        $v->validateOrFail();

        foreach ($fields as $campo => $tipo) {
            $val = $v->value($campo);
            $out[$campo] = match ($tipo) {
                'correos' => implode(', ', self::correos((string) $val)),
                'bool'   => array_key_exists($campo, $input) ? (int) (bool) $input[$campo] : 0,
                'int'    => $val !== null && $val !== '' ? (int) $val : 0,
                'iso2'   => strtoupper((string) $val),
                'iso3'   => strtoupper((string) $val),
                'decimal' => (float) str_replace(',', '.', (string) $val),
                'text'   => $val === null || $val === '' ? null : $val,
                'pais'   => $val === null || $val === '' ? null : (int) $val,
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

        // Unicidad del código de moneda: dos filas "USD" harían ambigua la conversión.
        if ($tabla === 'monedas') {
            $sql = 'SELECT 1 FROM monedas WHERE codigo = :c' . ($exceptId ? ' AND id <> :id' : '') . ' LIMIT 1';
            $params = [':c' => $out['codigo']];
            if ($exceptId) {
                $params[':id'] = $exceptId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn() !== false) {
                json_unprocessable(['codigo' => 'Ya existe una moneda con ese código.']);
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
