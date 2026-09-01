<?php
/**
 * Maquinaria común de las cargas masivas desde Excel/CSV.
 *
 * El principio es el mismo para cualquier entidad: NADA se escribe hasta que el archivo entero
 * está bien. Se analiza, se devuelve el informe de errores y solo una segunda llamada confirma
 * la inserción, que corre en una sola transacción. Media carga aplicada es peor que ninguna:
 * obliga a averiguar a mano qué entró y qué no.
 *
 * Aquí vive todo lo que no depende de QUÉ se carga: generar la plantilla con sus desplegables,
 * leer el archivo, comprobar el encabezado, numerar las filas como las ve el usuario, detectar
 * repetidos dentro del propio archivo y dar formato al informe. Cada importador concreto solo
 * declara sus columnas, cómo traducir nombres a ids y cómo insertar una fila.
 *
 * Las reglas de negocio NO se reimplementan en los importadores: se delegan en el servicio de
 * la entidad (UnidadService::evaluar, PilotoService::evaluar), el mismo camino que usa el alta
 * individual. Si vivieran duplicadas, el Excel acabaría aceptando lo que el formulario rechaza.
 */

declare(strict_types=1);

abstract class ImportadorExcel
{
    /** Tope de filas de datos: coincide con el rango de las listas desplegables de la plantilla. */
    public const MAX_FILAS = 999;

    /** Tamaño máximo del archivo subido. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(protected PDO $pdo)
    {
    }

    // ── Lo que define cada importador concreto ──

    /** Nombre de la hoja de datos y de lo que se carga, en plural ("Unidades", "Pilotos"). */
    abstract protected function entidad(): string;

    /**
     * Columnas en orden: [['clave' => 'placa_unidad', 'label' => 'Placa unidad', 'ancho' => 16]].
     * 'clave' es el nombre interno; 'label' lo que ve el usuario y lo que se cita en los errores.
     */
    abstract protected function columnas(): array;

    /** Clave de columna => valores admitidos, para las listas desplegables. */
    abstract protected function listas(array $user): array;

    /** Clave de columna => explicación para la hoja de instrucciones. */
    abstract protected function ayuda(): array;

    /** Clave de columna => valor de ejemplo. */
    abstract protected function ejemplo(array $listas): array;

    /** Mapas nombre normalizado => id, para no consultar el catálogo en cada fila. */
    abstract protected function indices(array $user): array;

    /**
     * Traduce la fila cruda del Excel al input que espera el servicio de la entidad.
     * @return array{0: array, 1: array<string,string>} [input, errores por campo]
     */
    abstract protected function traducir(array $cruda, array $indices, array $user): array;

    /** Aplica las reglas del alta individual. @return array{data: ?array, errores: array} */
    abstract protected function evaluarReglas(array $input, array $user): array;

    /**
     * Columnas cuyo valor repetido significa "es el mismo registro dos veces". Son varias
     * porque una entidad puede tener más de un identificador natural: un piloto se reconoce
     * por su número de licencia y también por su documento.
     *
     * @return string[] claves de columna
     */
    abstract protected function clavesUnicas(): array;

    /** Inserta una fila ya validada dentro de la transacción. */
    abstract protected function insertar(array $item, array $user): void;

    /** Campo interno devuelto por la validación => clave de columna del Excel. */
    protected function equivalencias(): array
    {
        return [];
    }

    /**
     * Cómo se llama la fila para el usuario. El informe de errores cita el número de fila,
     * pero "fila 4" obliga a volver al Excel para saber de quién se habla; con el nombre o la
     * placa delante, el problema se identifica sin salir de la pantalla.
     */
    protected function etiquetaFila(array $cruda): string
    {
        $primera = $this->columnas()[0]['clave'] ?? '';
        return trim((string) ($cruda[$primera] ?? ''));
    }

    // ── Plantilla ──

    /**
     * Plantilla .xlsx con los catálogos vigentes del usuario: encabezado fijo, listas
     * desplegables apuntando a la hoja "Listas" y una hoja de instrucciones. Las listas atajan
     * la mayor parte de los errores de tecleo antes de que el archivo llegue al servidor.
     */
    public function plantilla(array $user): string
    {
        $columnas = $this->columnas();
        $listas = $this->listas($user);

        // Las listas viven en columnas paralelas de la hoja "Listas"; cada validación apunta
        // a su columna con el largo exacto para no ofrecer huecos en blanco.
        $columnasLista = [];
        $i = 0;
        foreach ($listas as $nombre => $valores) {
            $letra = XlsxWriter::letra(++$i);
            $columnasLista[$nombre] = $valores === []
                ? null
                : "Listas!\${$letra}\$2:\${$letra}\$" . (count($valores) + 1);
        }
        $validaciones = [];
        foreach ($columnas as $idx => $col) {
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
                $fila[] = $f === 0 ? $this->tituloDe($nombre) : ($valores[$f - 1] ?? '');
            }
            $filasListas[] = $fila;
        }

        // La hoja de datos va SOLO con el encabezado. Una fila de ejemplo dentro de ella se
        // importaría como registro real si el usuario olvida borrarla; el ejemplo vive aparte.
        return (new XlsxWriter())
            ->hoja($this->entidad(), [array_column($columnas, 'label')], [
                'anchos' => array_column($columnas, 'ancho'),
                'congelar' => true,
                'validaciones' => $validaciones,
            ])
            ->hoja('Listas', $filasListas, [
                'anchos' => array_fill(0, max(count($listas), 1), 26),
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
     * @return array{total:int, errores:array, filas:array}
     */
    public function analizar(string $ruta, string $nombreOriginal, array $user): array
    {
        $filas = $this->leerArchivo($ruta, $nombreOriginal);
        if ($filas === []) {
            return $this->soloError('El archivo no tiene filas.');
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
            return $this->soloError(
                'El archivo trae ' . count($filas) . ' filas y el máximo por carga es ' . self::MAX_FILAS . '.',
                count($filas)
            );
        }

        $indices = $this->indices($user);
        $clavesUnicas = $this->clavesUnicas();
        $errores = [];
        $validas = [];
        $vistos = [];
        $procesadas = 0;

        foreach ($filas as $indice => $celdas) {
            $numeroFila = $indice + 1;               // el número que el usuario ve en Excel
            $cruda = $this->asociar($celdas);
            if (implode('', $cruda) === '') {
                continue;   // fila en blanco a media hoja: se ignora, no es un error
            }
            $procesadas++;

            [$input, $erroresFila] = $this->traducir($cruda, $indices, $user);

            // Repetidos dentro del propio archivo: la base no los ve porque aún no se insertó.
            foreach ($clavesUnicas as $clave) {
                $valor = trim((string) ($cruda[$clave] ?? ''));
                if ($valor === '') {
                    continue;
                }
                $visto = $clave . '|' . $this->normalizar($valor);
                if (isset($vistos[$visto])) {
                    $erroresFila[$clave] = 'Se repite en la fila ' . $vistos[$visto] . ' del archivo.';
                } else {
                    $vistos[$visto] = $numeroFila;
                }
            }

            // Las reglas se aplican SIEMPRE, aunque la traducción ya haya fallado: si se
            // cortara aquí, un nombre de catálogo mal escrito escondería el resto de fallos
            // de la fila y obligaría a corregir el archivo en dos vueltas.
            ['data' => $data, 'errores' => $erroresRegla] = $this->evaluarReglas($input, $user);

            foreach ($erroresRegla as $campo => $mensaje) {
                // Si la traducción ya habló de esa columna, su mensaje manda: cita el valor
                // que escribió el usuario ("«Cabezote» no existe") en vez del genérico
                // ("la categoría es obligatoria") que sale de no haberla podido resolver.
                $clave = $this->claveDe($campo);
                if (!isset($erroresFila[$clave]) && !isset($erroresFila[$campo])) {
                    $erroresFila[$campo] = $mensaje;
                }
            }

            if ($erroresFila === [] && $data !== null) {
                if (!can_write_station($user, (int) $data['estacion_id'])) {
                    $erroresFila['estacion'] = 'No tienes permiso para dar de alta en esa estación.';
                } else {
                    $validas[] = ['fila' => $numeroFila, 'data' => $data, 'input' => $input, 'cruda' => $cruda];
                }
            }

            foreach ($erroresFila as $campo => $mensaje) {
                $clave = $this->claveDe($campo);
                $errores[] = [
                    'fila' => $numeroFila,
                    'registro' => $this->etiquetaFila($cruda),
                    'columna' => $this->etiquetaDe($clave),
                    'valor' => (string) ($cruda[$clave] ?? ''),
                    'mensaje' => $mensaje,
                ];
            }
        }

        return ['total' => $procesadas, 'errores' => $errores, 'filas' => $validas];
    }

    // ── Ejecución ──

    /**
     * Vuelve a analizar y, solo si no hay un único error, inserta todo en una transacción.
     * El re-análisis no es paranoia: entre la vista previa y la confirmación alguien pudo
     * haber dado de alta uno de esos registros desde el formulario.
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
            return ['importadas' => 0, 'errores' => $this->soloError('El archivo no trae registros que cargar.')['errores']];
        }

        $creadas = tx($this->pdo, function () use ($informe, $user): int {
            foreach ($informe['filas'] as $item) {
                $this->insertar($item, $user);
            }
            return count($informe['filas']);
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
        $recibido = array_map(
            static fn($v): string => trim((string) preg_replace('/\s+/u', ' ', (string) $v)),
            $encabezado
        );

        foreach ($this->columnas() as $i => $col) {
            $hay = $recibido[$i] ?? '';
            if (mb_strtolower($hay) !== mb_strtolower($col['label'])) {
                $letra = XlsxWriter::letra($i + 1);
                return [[
                    'fila' => 1,
                    'registro' => '',
                    'columna' => $letra,
                    'mensaje' => "El encabezado no coincide con la plantilla: se esperaba «{$col['label']}»"
                        . " en la columna {$letra} y llegó «{$hay}». Descarga la plantilla otra vez.",
                ]];
            }
        }
        return [];
    }

    /** Celdas por posición => array asociativo por clave de columna. */
    private function asociar(array $celdas): array
    {
        $out = [];
        foreach ($this->columnas() as $i => $col) {
            $out[$col['clave']] = trim((string) ($celdas[$i] ?? ''));
        }
        return $out;
    }

    // ── Utilidades para los importadores concretos ──

    /**
     * Resuelve un valor de texto contra un índice de catálogo y acumula el error si no existe.
     * Devuelve null cuando la celda viene vacía (los catálogos opcionales lo permiten).
     */
    protected function resolver(
        array $cruda,
        array $indices,
        string $clave,
        string $catalogo,
        string $etiqueta,
        array &$errores
    ): ?int {
        $valor = $cruda[$clave] ?? '';
        if ($valor === '') {
            return null;
        }
        $id = $indices[$catalogo][$this->normalizar($valor)] ?? null;
        if ($id === null) {
            $errores[$clave] = "«{$valor}» no existe en {$etiqueta}. Revisa la hoja Listas de la plantilla.";
        }
        return $id;
    }

    /** Índice nombre normalizado => id de un catálogo simple. */
    protected function indicePorNombre(CatalogoModel $catalogos, string $tabla): array
    {
        $out = [];
        foreach ($catalogos->activos($tabla) as $fila) {
            $out[$this->normalizar($fila['nombre'])] = (int) $fila['id'];
        }
        return $out;
    }

    /**
     * Índice de estaciones aceptando código, nombre y el formato "TRK · EFL Trucking" con el
     * que se ven en los desplegables, más el país de cada una.
     *
     * @return array{estaciones: array<string,int>, pais_de_estacion: array<int,int>}
     */
    protected function indiceEstaciones(CatalogoModel $catalogos): array
    {
        $estaciones = [];
        $pais = [];
        foreach ($catalogos->activos('estaciones') as $e) {
            $id = (int) $e['id'];
            $estaciones[$this->normalizar($e['codigo'])] = $id;
            $estaciones[$this->normalizar($e['nombre'])] = $id;
            $estaciones[$this->normalizar($e['codigo'] . ' · ' . $e['nombre'])] = $id;
            $pais[$id] = (int) $e['pais_id'];
        }
        return ['estaciones' => $estaciones, 'pais_de_estacion' => $pais];
    }

    /** Estaciones donde el usuario puede dar de alta, como se muestran en los desplegables. */
    protected function estacionesEscribibles(CatalogoModel $catalogos, array $user): array
    {
        return array_values(array_map(
            static fn(array $e): string => $e['codigo'] . ' · ' . $e['nombre'],
            array_filter(
                $catalogos->activos('estaciones'),
                static fn(array $e): bool => can_write_station($user, (int) $e['id'])
            )
        ));
    }

    /**
     * Normaliza una fecha a 'Y-m-d'. Devuelve null si no se reconoce.
     *
     * Excel no guarda las fechas como texto sino como el número de días desde 1899-12-30, así
     * que una celda con formato de fecha llega aquí como "45678". Sin esta conversión el
     * usuario vería "45678 no es una fecha válida" y no sabría qué hizo mal. También se
     * aceptan los formatos que la gente teclea a mano.
     */
    protected function fechaIso(string $valor): ?string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        // Rango razonable de serie de Excel: 1955 (20000) a 2119 (80000).
        if (ctype_digit($valor) && (int) $valor > 20000 && (int) $valor < 80000) {
            return (new DateTimeImmutable('1899-12-30'))
                ->modify('+' . (int) $valor . ' days')
                ->format('Y-m-d');
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y'] as $formato) {
            $fecha = DateTime::createFromFormat($formato, $valor);
            if ($fecha !== false && $fecha->format($formato) === $valor) {
                return $fecha->format('Y-m-d');
            }
        }
        return null;
    }

    /** Comparación tolerante: sin acentos, sin mayúsculas y sin espacios de más. */
    protected function normalizar(string $texto): string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        return strtr(mb_strtolower($texto, 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }

    // ── Internos ──

    /** Hoja de ayuda: qué espera cada columna y un ejemplo con valores reales de los catálogos. */
    private function instrucciones(array $listas): array
    {
        $ejemplo = $this->ejemplo($listas);
        $ayuda = $this->ayuda();

        $filas = [['Columna', 'Qué se espera', 'Ejemplo']];
        foreach ($this->columnas() as $col) {
            $filas[] = [$col['label'], $ayuda[$col['clave']] ?? '', $ejemplo[$col['clave']] ?? ''];
        }
        $filas[] = ['', '', ''];
        $filas[] = ['Antes de subir', 'Las columnas con lista desplegable solo aceptan valores de la hoja Listas.', ''];
        $filas[] = ['', 'El archivo se revisa entero: si una fila falla, no se carga ninguna.', ''];
        $filas[] = ['', 'Máximo ' . self::MAX_FILAS . ' registros por archivo.', ''];
        return $filas;
    }

    private function tituloDe(string $clave): string
    {
        return $this->etiquetaDe($clave);
    }

    private function etiquetaDe(string $clave): string
    {
        foreach ($this->columnas() as $col) {
            if ($col['clave'] === $clave) {
                return $col['label'];
            }
        }
        return $clave;
    }

    /** Los errores del servicio vienen con nombre de columna de base de datos. */
    private function claveDe(string $campo): string
    {
        return $this->equivalencias()[$campo] ?? $campo;
    }

    /** @return array{total:int, errores:array, filas:array} */
    private function soloError(string $mensaje, int $total = 0): array
    {
        return [
            'total' => $total,
            'errores' => [['fila' => 0, 'registro' => '', 'columna' => '', 'valor' => '', 'mensaje' => $mensaje]],
            'filas' => [],
        ];
    }

    /** Marca de orden de bytes UTF-8 (EF BB BF) que Excel antepone al guardar un CSV. */
    private static function esBom(string $inicio): bool
    {
        return strlen($inicio) === 3
            && ord($inicio[0]) === 0xEF && ord($inicio[1]) === 0xBB && ord($inicio[2]) === 0xBF;
    }
}
