<?php
/**
 * Carga masiva de flota. Solo declara lo propio de una unidad: sus columnas, cómo traducir los
 * nombres del Excel a ids y cómo insertarla. El recorrido común —analizar sin escribir, informe
 * de errores por fila y columna, confirmar en una sola transacción— vive en ImportadorExcel.
 */

declare(strict_types=1);

final class FlotaImportService extends ImportadorExcel
{
    public function __construct(
        PDO $pdo,
        private UnidadService $unidades,
        private UnidadModel $unidadModel,
        private OverrideModel $overrides,
        private CatalogoModel $catalogos
    ) {
        parent::__construct($pdo);
    }

    protected function entidad(): string
    {
        return 'Unidades';
    }

    protected function columnas(): array
    {
        return [
            ['clave' => 'placa_unidad',      'label' => 'Placa unidad',        'ancho' => 16],
            ['clave' => 'categoria',         'label' => 'Categoría',           'ancho' => 18],
            ['clave' => 'marca',             'label' => 'Marca',               'ancho' => 16],
            ['clave' => 'modelo',            'label' => 'Modelo',              'ancho' => 16],
            ['clave' => 'anio',              'label' => 'Año (AAAA)',          'ancho' => 12],
            ['clave' => 'tipo_equipo',       'label' => 'Tipo de equipo',      'ancho' => 18],
            ['clave' => 'capacidad',         'label' => 'Capacidad',           'ancho' => 14],
            ['clave' => 'estacion',          'label' => 'Estación',            'ancho' => 18],
            ['clave' => 'piloto',            'label' => 'Piloto asignado',     'ancho' => 22],
            ['clave' => 'estado',            'label' => 'Estado',              'ancho' => 18],
            ['clave' => 'estado_notas',      'label' => 'Notas del estado',    'ancho' => 34],
            ['clave' => 'en_disponibilidad', 'label' => 'En disponibilidad (Sí/No)', 'ancho' => 20],
            ['clave' => 'permisos',          'label' => 'Permisos especiales (separados por ;)', 'ancho' => 44],
        ];
    }

    protected function clavesUnicas(): array
    {
        return ['placa_unidad'];
    }

    protected function equivalencias(): array
    {
        return [
            'categoria_vehiculo_id' => 'categoria',
            'tipo_equipo_id'        => 'tipo_equipo',
            'capacidad_id'          => 'capacidad',
            'estacion_id'           => 'estacion',
            'piloto_asignado_id'    => 'piloto',
            'estado_vehiculo'       => 'estado',
        ];
    }

    protected function evaluarReglas(array $input, array $user): array
    {
        return $this->unidades->evaluar($input, null);
    }

    // ── Traducción de nombres a ids ──

    protected function traducir(array $cruda, array $indices, array $user): array
    {
        $errores = [];

        $input = [
            'placa_unidad'          => $cruda['placa_unidad'],
            'marca'                 => $cruda['marca'],
            'modelo'                => $cruda['modelo'],
            'anio'                  => $cruda['anio'],
            'estado_notas'          => $cruda['estado_notas'],
            'categoria_vehiculo_id' => $this->resolver($cruda, $indices, 'categoria', 'categorias', 'las categorías', $errores),
            'tipo_equipo_id'        => $this->resolver($cruda, $indices, 'tipo_equipo', 'tipos_equipo', 'los tipos de equipo', $errores),
            'capacidad_id'          => $this->resolver($cruda, $indices, 'capacidad', 'capacidades', 'las capacidades', $errores),
            'estacion_id'           => $this->resolver($cruda, $indices, 'estacion', 'estaciones', 'las estaciones', $errores),
            'piloto_asignado_id'    => $this->resolver($cruda, $indices, 'piloto', 'pilotos', 'los pilotos activos', $errores),
        ];

        if ($cruda['estado'] !== '') {
            $estado = $indices['estados'][$this->normalizar($cruda['estado'])] ?? null;
            if ($estado === null) {
                $errores['estado'] = "«{$cruda['estado']}» no es un estado válido.";
            } else {
                $input['estado_vehiculo'] = $estado;
            }
        }

        // Un estado que no es OPERATIVO tiene que explicarse: es la misma exigencia del
        // diálogo de cambio de estado, y sin notas el override automático nace sin motivo.
        if (($input['estado_vehiculo'] ?? EstadoVehiculo::OPERATIVO) !== EstadoVehiculo::OPERATIVO
            && $cruda['estado_notas'] === '') {
            $errores['estado_notas'] = 'Las notas son obligatorias cuando el vehículo no está operativo.';
        }

        if ($cruda['en_disponibilidad'] !== '') {
            $si = $this->normalizar($cruda['en_disponibilidad']);
            if (!in_array($si, ['si', 'no'], true)) {
                $errores['en_disponibilidad'] = "Escribe «Sí» o «No», no «{$cruda['en_disponibilidad']}».";
            } else {
                $input['en_disponibilidad'] = $si === 'si' ? 1 : 0;
            }
        }

        $input['permisos'] = $this->permisos($cruda, $indices, $input['estacion_id'], $errores);

        return [$input, $errores];
    }

    /**
     * Permisos separados por «;» (o coma). Un permiso es una autorización nacional: se rechaza
     * el que no corresponde al país de la estación, que en el formulario ni siquiera se ofrece.
     *
     * @return int[]
     */
    private function permisos(array $cruda, array $indices, ?int $estacionId, array &$errores): array
    {
        if ($cruda['permisos'] === '') {
            return [];
        }
        $paisEstacion = $estacionId !== null ? ($indices['pais_de_estacion'][$estacionId] ?? null) : null;

        $ids = [];
        foreach (preg_split('/[;,]/u', $cruda['permisos']) as $nombre) {
            $nombre = trim((string) $nombre);
            if ($nombre === '') {
                continue;
            }
            $permiso = $indices['permisos'][$this->normalizar($nombre)] ?? null;
            if ($permiso === null) {
                $errores['permisos'] = "«{$nombre}» no existe en los permisos especiales.";
                continue;
            }
            if ($permiso['pais_id'] !== null && $paisEstacion !== null && $permiso['pais_id'] !== $paisEstacion) {
                $errores['permisos'] = "«{$nombre}» es un permiso de otro país y no aplica a la estación indicada.";
                continue;
            }
            $ids[] = $permiso['id'];
        }
        return array_values(array_unique($ids));
    }

    // ── Catálogos ──

    protected function indices(array $user): array
    {
        $permisos = [];
        foreach ($this->catalogos->activos('permisos_especiales') as $p) {
            $permisos[$this->normalizar($p['nombre'])] = [
                'id' => (int) $p['id'],
                'pais_id' => $p['pais_id'] !== null ? (int) $p['pais_id'] : null,
            ];
        }

        $pilotos = [];
        foreach ($this->pilotosVisibles($user) as $p) {
            $pilotos[$this->normalizar($p['nombre'])] = (int) $p['id'];
        }

        $estados = [];
        foreach (EstadoVehiculo::values() as $estado) {
            $estados[$this->normalizar($estado)] = $estado;
            $estados[$this->normalizar(EstadoVehiculo::label($estado))] = $estado;
        }

        return $this->indiceEstaciones($this->catalogos) + [
            'categorias'   => $this->indicePorNombre($this->catalogos, 'categorias_vehiculo'),
            'tipos_equipo' => $this->indicePorNombre($this->catalogos, 'tipos_equipo'),
            'capacidades'  => $this->indicePorNombre($this->catalogos, 'capacidades'),
            'pilotos'      => $pilotos,
            'permisos'     => $permisos,
            'estados'      => $estados,
        ];
    }

    protected function listas(array $user): array
    {
        $nombres = static fn(array $filas): array => array_values(array_map(
            static fn(array $f): string => (string) $f['nombre'],
            $filas
        ));

        return [
            'categoria'         => $nombres($this->catalogos->activos('categorias_vehiculo', 'orden')),
            'tipo_equipo'       => $nombres($this->catalogos->activos('tipos_equipo', 'orden')),
            'capacidad'         => $nombres($this->catalogos->activos('capacidades', 'orden')),
            'estacion'          => $this->estacionesEscribibles($this->catalogos, $user),
            'piloto'            => array_values(array_map(
                static fn(array $p): string => (string) $p['nombre'],
                $this->pilotosVisibles($user)
            )),
            'estado'            => array_map(
                static fn(string $e): string => EstadoVehiculo::label($e),
                EstadoVehiculo::values()
            ),
            'en_disponibilidad' => ['Sí', 'No'],
            'permisos'          => $nombres($this->catalogos->activos('permisos_especiales')),
        ];
    }

    protected function ayuda(): array
    {
        return [
            'placa_unidad'      => 'Obligatoria. No puede repetirse ni existir ya en el sistema.',
            'categoria'         => 'Obligatoria. Elige de la lista desplegable.',
            'marca'             => 'Opcional. Hasta 80 caracteres.',
            'modelo'            => 'Opcional. Hasta 80 caracteres.',
            'anio'              => 'Opcional. Entre 1950 y ' . ((int) date('Y') + 1) . '.',
            'tipo_equipo'       => 'Opcional. Si se deja vacío se usa el tipo estándar.',
            'capacidad'         => 'Opcional.',
            'estacion'          => 'Obligatoria. Solo aparecen las estaciones donde puedes dar de alta.',
            'piloto'            => 'Opcional. Nombre exacto de un piloto activo.',
            'estado'            => 'Opcional. Si se deja vacío, queda Operativo.',
            'estado_notas'      => 'Obligatoria cuando el estado no es Operativo: explica por qué.',
            'en_disponibilidad' => 'Opcional. Sí o No. Vacío hereda el valor de la categoría.',
            'permisos'          => 'Opcional. Varios permisos separados por punto y coma (;).',
        ];
    }

    protected function ejemplo(array $listas): array
    {
        return [
            'placa_unidad'      => 'C12345',
            'categoria'         => $listas['categoria'][0] ?? '',
            'marca'             => 'Freightliner',
            'modelo'            => 'Columbia',
            'anio'              => '2018',
            'tipo_equipo'       => $listas['tipo_equipo'][0] ?? '',
            'capacidad'         => $listas['capacidad'][0] ?? '',
            'estacion'          => $listas['estacion'][0] ?? '',
            'piloto'            => $listas['piloto'][0] ?? '',
            'estado'            => EstadoVehiculo::label(EstadoVehiculo::OPERATIVO),
            'estado_notas'      => '',
            'en_disponibilidad' => 'Sí',
            'permisos'          => implode('; ', array_slice($listas['permisos'], 0, 2)),
        ];
    }

    // ── Inserción ──

    protected function insertar(array $item, array $user): void
    {
        $data = $item['data'];
        $id = $this->unidadModel->crear($data, $user['id']);
        $this->unidadModel->setPermisos($id, $item['input']['permisos'], $user['id']);

        // Una unidad que entra en mantenimiento necesita su override abierto, o aparecería
        // como disponible en el tablero pese a su estado (regla 18).
        if ((int) $data['en_disponibilidad'] === 1
            && in_array($data['estado_vehiculo'], EstadoVehiculo::GENERA_OVERRIDE, true)) {
            $this->overrides->abrir(
                $id,
                TipoOverride::EN_TALLER,
                OrigenOverride::AUTO_ESTADO,
                (string) $data['estado_notas'],
                $user['id']
            );
        }

        registrar_bitacora($this->pdo, $user['id'], 'unidad', $id, AccionBitacora::CREAR, [
            'despues' => $data,
            'origen'  => 'carga masiva',
        ]);
    }

    private function pilotosVisibles(array $user): array
    {
        if ($user['rol'] === Rol::ADMIN_GLOBAL) {
            return $this->pdo->query('SELECT id, nombre FROM pilotos WHERE activo = 1 ORDER BY nombre')->fetchAll();
        }
        $stmt = $this->pdo->prepare('SELECT id, nombre FROM pilotos WHERE activo = 1 AND estacion_id = :e ORDER BY nombre');
        $stmt->execute([':e' => $user['estacion_id']]);
        return $stmt->fetchAll();
    }
}
