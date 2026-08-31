<?php
/**
 * Carga masiva de flota desde Excel (.xlsx) o CSV.
 *
 * El principio es que NADA se escribe hasta que el archivo entero está bien: se analiza,
 * se devuelve el informe de errores y solo una segunda llamada confirma la inserción, que
 * corre en una sola transacción. Media carga aplicada es peor que ninguna: obliga a averiguar
 * a mano qué entró y qué no.
 *
 * Las reglas de cada unidad NO se reimplementan aquí: se delegan en UnidadService::evaluar(),
 * el mismo camino que usa el alta individual. Este servicio solo traduce nombres a ids
 * ("Cabezal" → categoria_vehiculo_id) y acumula los errores por fila y columna.
 */

declare(strict_types=1);

final class FlotaImportService
{
    /** Tope de filas de datos: coincide con el rango de las listas desplegables de la plantilla. */
    public const MAX_FILAS = 999;

    /** Tamaño máximo del archivo subido. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Columnas de la plantilla, en orden. La clave es el nombre interno; 'label' es lo que ve
     * el usuario en Excel y lo que se cita en los errores.
     */
    private const COLUMNAS = [
        ['clave' => 'placa_unidad',       'label' => 'Placa unidad',      'ancho' => 16, 'obligatoria' => true],
        ['clave' => 'categoria',          'label' => 'Categoría',         'ancho' => 18, 'obligatoria' => true],
        ['clave' => 'marca',              'label' => 'Marca',             'ancho' => 16, 'obligatoria' => false],
        ['clave' => 'modelo',             'label' => 'Modelo',            'ancho' => 16, 'obligatoria' => false],
        ['clave' => 'anio',               'label' => 'Año',               'ancho' => 8,  'obligatoria' => false],
        ['clave' => 'tipo_equipo',        'label' => 'Tipo de equipo',    'ancho' => 18, 'obligatoria' => false],
        ['clave' => 'capacidad',          'label' => 'Capacidad',         'ancho' => 14, 'obligatoria' => false],
        ['clave' => 'estacion',           'label' => 'Estación',          'ancho' => 18, 'obligatoria' => true],
        ['clave' => 'piloto',             'label' => 'Piloto asignado',   'ancho' => 22, 'obligatoria' => false],
        ['clave' => 'estado',             'label' => 'Estado',            'ancho' => 18, 'obligatoria' => false],
        ['clave' => 'estado_notas',       'label' => 'Notas del estado',  'ancho' => 34, 'obligatoria' => false],
        ['clave' => 'en_disponibilidad',  'label' => 'En disponibilidad', 'ancho' => 16, 'obligatoria' => false],
        ['clave' => 'permisos',           'label' => 'Permisos especiales', 'ancho' => 40, 'obligatoria' => false],
    ];

    public function __construct(
        private PDO $pdo,
        private UnidadService $unidades,
        private UnidadModel $unidadModel,
        private OverrideModel $overrides,
        private CatalogoModel $catalogos
    ) {
    }

    // ── Plantilla ──

    /**
     * Genera la plantilla .xlsx con los catálogos vigentes del usuario: encabezado fijo, una
     * fila de ejemplo y listas desplegables que apuntan a la hoja "Listas". Las listas evitan
     * la mayor parte de los errores de tecleo antes de que el archivo llegue al servidor.
     */
    public function plantilla(array $user): string
    {
        $listas = $this->listas($user);

        $encabezado = array_column(self::COLUMNAS, 'label');

        // Las listas viven en columnas paralelas de la hoja "Listas"; cada validación apunta
        // a su columna con el largo exacto para no ofrecer huecos en blanco.
        $validaciones = [];
        $columnasLista = [];
        $i = 0;
        foreach ($listas as $nombre => $valores) {
            $letra = XlsxWriter::letra(++$i);
            $columnasLista[$nombre] = $valores === []
                ? null
                : "Listas!\${$letra}\$2:\${$letra}\$" . (count($valores) + 1);
        }
        foreach (self::COLUMNAS as $idx => $col) {
            $origen = $columnasLista[$col['clave']] ?? null;
            if ($origen !== null) {
                $validaciones[] = ['col' => $idx + 1, 'origen' => $origen];
            }
        }

        // La hoja de listas se arma por columnas: primera fila el título, debajo los valores.
        $alto = max(array_map('count', $listas) ?: [0]) + 1;
        $filasListas = [];
        for ($f = 0; $f < $alto; $f++) {
            $fila = [];
            foreach ($listas as $nombre => $valores) {
                $fila[] = $f === 0 ? $this->tituloLista($nombre) : ($valores[$f - 1] ?? '');
            }
            $filasListas[] = $fila;
        }

        // La hoja de datos va SOLO con el encabezado. Una fila de ejemplo dentro de ella se
        // importaría como unidad real si el usuario olvida borrarla; el ejemplo vive aparte.
        return (new XlsxWriter())
            ->hoja('Unidades', [$encabezado], [
                'anchos' => array_column(self::COLUMNAS, 'ancho'),
                'congelar' => true,
                'validaciones' => $validaciones,
            ])
            ->hoja('Listas', $filasListas, [
                'anchos' => array_fill(0, count($listas), 26),
                'congelar' => true,
            ])
            ->hoja('Instrucciones', $this->instrucciones($listas), [
                'anchos' => [26, 46, 34],
                'congelar' => true,
            ])
            ->generar();
    }

    // ── Análisis ──

    /**
     * Lee el archivo y valida cada fila sin escribir nada.
     *
     * @return array{total:int, listas:array, errores:array, filas:array}
     */
    public function analizar(string $ruta, string $nombreOriginal, array $user): array
    {
        $filas = $this->leerArchivo($ruta, $nombreOriginal);
        if ($filas === []) {
            return ['total' => 0, 'errores' => [['fila' => 0, 'columna' => '', 'mensaje' => 'El archivo no tiene filas.']], 'filas' => []];
        }

        // Se saca el encabezado SIN reindexar: array_shift renumeraría las filas y los errores
        // citarían un número que el usuario no encuentra en su hoja.
        $claveEncabezado = array_key_first($filas);
        $errores = $this->verificarEncabezado($filas[$claveEncabezado]);
        unset($filas[$claveEncabezado]);
        if ($errores !== []) {
            return ['total' => 0, 'errores' => $errores, 'filas' => []];
        }

        if (count($filas) > self::MAX_FILAS) {
            return ['total' => count($filas), 'filas' => [], 'errores' => [[
                'fila' => 0, 'columna' => '',
                'mensaje' => 'El archivo trae ' . count($filas) . ' filas y el máximo por carga es ' . self::MAX_FILAS . '.',
            ]]];
        }

        $indices = $this->indices($user);
        $errores = [];
        $listas = [];
        $placasVistas = [];
        $procesadas = 0;

        foreach ($filas as $indice => $celdas) {
            $numeroFila = $indice + 1;               // el número que el usuario ve en Excel
            $cruda = $this->asociar($celdas);
            if ($this->filaVacia($cruda)) {
                continue;   // fila en blanco a media hoja: se ignora, no es un error
            }
            $procesadas++;

            [$input, $erroresFila] = $this->traducir($cruda, $indices, $user);

            // Duplicados dentro del propio archivo: la base no los ve porque aún no se insertó.
            $placa = trim((string) ($cruda['placa_unidad'] ?? ''));
            if ($placa !== '' && isset($placasVistas[mb_strtolower($placa)])) {
                $erroresFila['placa_unidad'] = 'La placa se repite en la fila ' . $placasVistas[mb_strtolower($placa)] . ' del archivo.';
            } elseif ($placa !== '') {
                $placasVistas[mb_strtolower($placa)] = $numeroFila;
            }

            // Las reglas de negocio son las mismas del alta individual.
            if ($erroresFila === []) {
                ['data' => $data, 'errores' => $erroresRegla] = $this->unidades->evaluar($input, null);
                $erroresFila = $erroresRegla;
                if ($erroresFila === [] && !can_write_station($user, (int) $data['estacion_id'])) {
                    $erroresFila['estacion'] = 'No tienes permiso para dar de alta unidades en esa estación.';
                }
                if ($erroresFila === []) {
                    $listas[] = ['fila' => $numeroFila, 'data' => $data, 'permisos' => $input['permisos'], 'cruda' => $cruda];
                }
            }

            foreach ($erroresFila as $campo => $mensaje) {
                $errores[] = [
                    'fila' => $numeroFila,
                    'columna' => $this->etiquetaDe($campo),
                    'valor' => (string) ($cruda[$this->claveDe($campo)] ?? ''),
                    'mensaje' => $mensaje,
                ];
            }
        }

        return ['total' => $procesadas, 'errores' => $errores, 'filas' => $listas];
    }

    // ── Ejecución ──

    /**
     * Vuelve a analizar y, solo si no hay un único error, inserta todo en una transacción.
     * El re-análisis no es paranoia: entre la vista previa y la confirmación alguien pudo
     * haber dado de alta una de esas placas desde el formulario.
     *
     * @return array{importadas:int, errores:array}
     */
    public function importar(string $ruta, string $nombreOriginal, array $user): array
    {
        $informe = $this->analizar($ruta, $nombreOriginal, $user);
        if ($informe['errores'] !== []) {
            return ['importadas' => 0, 'errores' => $informe['errores']];
        }
        if ($informe['filas'] === []) {
            return ['importadas' => 0, 'errores' => [['fila' => 0, 'columna' => '', 'mensaje' => 'El archivo no trae unidades que cargar.']]];
        }

        $creadas = tx($this->pdo, function () use ($informe, $user): int {
            $n = 0;
            foreach ($informe['filas'] as $item) {
                $data = $item['data'];
                $id = $this->unidadModel->crear($data, $user['id']);
                $this->unidadModel->setPermisos($id, $item['permisos'], $user['id']);

                // Una unidad que entra en mantenimiento necesita su override abierto, o
                // aparecería como disponible en el tablero pese a su estado (regla 18).
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
                $n++;
            }
            return $n;
        });

        return ['importadas' => $creadas, 'errores' => []];
    }

    // ── Lectura del archivo ──

    /** @return array<int, array<int, string>> */
    private function leerArchivo(string $ruta, string $nombreOriginal): array
    {
        $extension = strtolower((string) pathinfo($nombreOriginal, PATHINFO_EXTENSION));

        if ($extension === 'csv' || $extension === 'txt') {
            return $this->leerCsv($ruta);
        }
        if ($extension !== 'xlsx') {
            throw new RuntimeException('Formato no soportado. Usa la plantilla .xlsx (también se acepta .csv).');
        }
        return XlsxReader::leer($ruta);
    }

    /**
     * CSV como alternativa: Excel en español guarda con punto y coma, así que el separador se
     * detecta por la línea de encabezado en vez de darlo por supuesto.
     *
     * @return array<int, array<int, string>>
     */
    private function leerCsv(string $ruta): array
    {
        $manejador = fopen($ruta, 'r');
        if ($manejador === false) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }

        // El BOM que escribe Excel se salta en el flujo, no se quita del campo ya parseado:
        // si no, el primer campo no empieza por comilla y fgetcsv deja las comillas dentro.
        $inicio = self::esBom((string) fread($manejador, 3)) ? 3 : 0;
        fseek($manejador, $inicio);
        $primera = (string) fgets($manejador);
        $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
        fseek($manejador, $inicio);

        $filas = [];
        $i = 0;
        // Escape explícito: evita el aviso de obsolescencia de PHP 8.4 y deja el CSV
        // estándar (solo comillas dobles, sin barra invertida como escape).
        while (($campos = fgetcsv($manejador, 0, $separador, '"', '')) !== false) {
            $filas[$i++] = array_map(static fn($v): string => trim((string) $v), $campos);
        }
        fclose($manejador);
        return $filas;
    }

    /** @return array<int, array{fila:int, columna:string, mensaje:string}> */
    private function verificarEncabezado(array $encabezado): array
    {
        $esperado = array_column(self::COLUMNAS, 'label');
        $recibido = array_map(
            static fn($v): string => trim(preg_replace('/\s+/u', ' ', (string) $v)),
            $encabezado
        );

        foreach ($esperado as $i => $label) {
            $hay = $recibido[$i] ?? '';
            if (mb_strtolower($hay) !== mb_strtolower($label)) {
                return [[
                    'fila' => 1,
                    'columna' => XlsxWriter::letra($i + 1),
                    'mensaje' => "El encabezado no coincide con la plantilla: se esperaba «{$label}» en la columna "
                        . XlsxWriter::letra($i + 1) . " y llegó «{$hay}». Descarga la plantilla otra vez.",
                ]];
            }
        }
        return [];
    }

    /** Celdas por posición → array asociativo por clave de columna. */
    private function asociar(array $celdas): array
    {
        $out = [];
        foreach (self::COLUMNAS as $i => $col) {
            $out[$col['clave']] = trim((string) ($celdas[$i] ?? ''));
        }
        return $out;
    }

    private function filaVacia(array $cruda): bool
    {
        return implode('', $cruda) === '';
    }

    // ── Traducción de nombres a ids ──

    /**
     * Convierte los nombres del Excel en los ids que espera UnidadService.
     *
     * @return array{0: array, 1: array<string,string>} [input, errores]
     */
    private function traducir(array $cruda, array $indices, array $user): array
    {
        $errores = [];

        $resolver = function (string $clave, string $catalogo, string $etiqueta) use ($cruda, $indices, &$errores): ?int {
            $valor = $cruda[$clave];
            if ($valor === '') {
                return null;
            }
            $id = $indices[$catalogo][$this->normalizar($valor)] ?? null;
            if ($id === null) {
                $errores[$clave] = "«{$valor}» no existe en {$etiqueta}. Revisa la hoja Listas de la plantilla.";
            }
            return $id;
        };

        $input = [
            'placa_unidad'          => $cruda['placa_unidad'],
            'marca'                 => $cruda['marca'],
            'modelo'                => $cruda['modelo'],
            'anio'                  => $cruda['anio'],
            'estado_notas'          => $cruda['estado_notas'],
            'categoria_vehiculo_id' => $resolver('categoria', 'categorias', 'las categorías'),
            'tipo_equipo_id'        => $resolver('tipo_equipo', 'tipos_equipo', 'los tipos de equipo'),
            'capacidad_id'          => $resolver('capacidad', 'capacidades', 'las capacidades'),
            'estacion_id'           => $resolver('estacion', 'estaciones', 'las estaciones'),
            'piloto_asignado_id'    => $resolver('piloto', 'pilotos', 'los pilotos activos'),
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

    // ── Índices de catálogo ──

    /** Mapas nombre normalizado → id, para resolver el Excel sin una consulta por fila. */
    private function indices(array $user): array
    {
        $porNombre = function (string $tabla): array {
            $out = [];
            foreach ($this->catalogos->activos($tabla) as $fila) {
                $out[$this->normalizar($fila['nombre'])] = (int) $fila['id'];
            }
            return $out;
        };

        $estaciones = [];
        $paisDeEstacion = [];
        foreach ($this->catalogos->activos('estaciones') as $e) {
            $estaciones[$this->normalizar($e['codigo'])] = (int) $e['id'];
            $estaciones[$this->normalizar($e['nombre'])] = (int) $e['id'];
            // Formato "TRK · EFL Trucking", que es como se ve en los desplegables.
            $estaciones[$this->normalizar($e['codigo'] . ' · ' . $e['nombre'])] = (int) $e['id'];
            $paisDeEstacion[(int) $e['id']] = (int) $e['pais_id'];
        }

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

        return [
            'categorias'       => $porNombre('categorias_vehiculo'),
            'tipos_equipo'     => $porNombre('tipos_equipo'),
            'capacidades'      => $porNombre('capacidades'),
            'estaciones'       => $estaciones,
            'pilotos'          => $pilotos,
            'permisos'         => $permisos,
            'estados'          => $estados,
            'pais_de_estacion' => $paisDeEstacion,
        ];
    }

    /** Listas que se publican en la plantilla (mismo orden que las columnas que las usan). */
    private function listas(array $user): array
    {
        $nombres = static fn(array $filas): array => array_values(array_map(
            static fn(array $f): string => (string) $f['nombre'],
            $filas
        ));

        $estaciones = array_values(array_map(
            static fn(array $e): string => $e['codigo'] . ' · ' . $e['nombre'],
            array_filter(
                $this->catalogos->activos('estaciones'),
                static fn(array $e): bool => can_write_station($user, (int) $e['id'])
            )
        ));

        return [
            'categoria'         => $nombres($this->catalogos->activos('categorias_vehiculo', 'orden')),
            'tipo_equipo'       => $nombres($this->catalogos->activos('tipos_equipo', 'orden')),
            'capacidad'         => $nombres($this->catalogos->activos('capacidades', 'orden')),
            'estacion'          => $estaciones,
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

    private function tituloLista(string $clave): string
    {
        foreach (self::COLUMNAS as $col) {
            if ($col['clave'] === $clave) {
                return $col['label'];
            }
        }
        return $clave;
    }

    /**
     * Hoja de ayuda: qué espera cada columna y un ejemplo con valores reales de los catálogos.
     * Va en su propia pestaña para que la de datos quede lista para pegar y cargar.
     */
    private function instrucciones(array $listas): array
    {
        $ejemplo = [
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

        $ayuda = [
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

        $filas = [['Columna', 'Qué se espera', 'Ejemplo']];
        foreach (self::COLUMNAS as $col) {
            $filas[] = [$col['label'], $ayuda[$col['clave']] ?? '', $ejemplo[$col['clave']] ?? ''];
        }
        $filas[] = ['', '', ''];
        $filas[] = ['Antes de subir', 'Las columnas con lista desplegable solo aceptan valores de la hoja Listas.', ''];
        $filas[] = ['', 'El archivo se revisa entero: si una fila falla, no se carga ninguna.', ''];
        $filas[] = ['', 'Máximo ' . self::MAX_FILAS . ' unidades por archivo.', ''];
        return $filas;
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

    // ── Utilidades ──

    /** Marca de orden de bytes UTF-8 (EF BB BF) que Excel antepone al guardar un CSV. */
    private static function esBom(string $inicio): bool
    {
        return strlen($inicio) === 3
            && ord($inicio[0]) === 0xEF && ord($inicio[1]) === 0xBB && ord($inicio[2]) === 0xBF;
    }

    /** Comparación tolerante: sin acentos, sin mayúsculas y sin espacios de más. */
    private function normalizar(string $texto): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));
        $texto = mb_strtolower($texto, 'UTF-8');
        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }

    /** Campo interno (el que devuelve la validación) → etiqueta de la columna del Excel. */
    private function etiquetaDe(string $campo): string
    {
        $clave = $this->claveDe($campo);
        foreach (self::COLUMNAS as $col) {
            if ($col['clave'] === $clave) {
                return $col['label'];
            }
        }
        return $campo;
    }

    /** Los errores de UnidadService vienen con nombre de columna de base de datos. */
    private function claveDe(string $campo): string
    {
        return [
            'categoria_vehiculo_id' => 'categoria',
            'tipo_equipo_id'        => 'tipo_equipo',
            'capacidad_id'          => 'capacidad',
            'estacion_id'           => 'estacion',
            'piloto_asignado_id'    => 'piloto',
            'estado_vehiculo'       => 'estado',
        ][$campo] ?? $campo;
    }
}
