<?php
/**
 * Carga masiva del historial de mantenimientos: una fila por intervención.
 *
 * Es la puerta por la que entran los dos años que hoy viven en la hoja de cálculo. Por eso
 * prioriza que el archivo se pueda arreglar de una sola vez: cada fila dice todo lo que tiene
 * mal, el taller desconocido no se inventa —se manda a crearlo en Talleres— y el kilometraje se
 * revisa también contra las demás filas del archivo, no solo contra lo ya guardado.
 *
 * Las reglas son las del formulario: MantenimientoService::evaluar las aplica igual para los dos.
 */

declare(strict_types=1);

final class MantenimientoImportService extends ImportadorExcel
{
    private OdometroEnArchivo $odometro;

    /** Talleres del archivo que no existen todavía, por estación. */
    private array $talleresFaltantes = [];

    public function __construct(
        PDO $pdo,
        private MantenimientoService $mantenimientos,
        private UnidadModel $unidades,
        private TallerModel $talleres,
        private CatalogoModel $catalogos
    ) {
        parent::__construct($pdo);
        $this->odometro = new OdometroEnArchivo();
    }

    protected function entidad(): string
    {
        return 'Mantenimientos';
    }

    protected function columnas(): array
    {
        return [
            ['clave' => 'fecha',         'label' => 'Fecha',          'ancho' => 12],
            ['clave' => 'placa',         'label' => 'Placa',          'ancho' => 14],
            ['clave' => 'tipo',          'label' => 'Tipo',           'ancho' => 20],
            ['clave' => 'descripcion',   'label' => 'Descripción',    'ancho' => 40],
            ['clave' => 'taller',        'label' => 'Taller',         'ancho' => 24],
            ['clave' => 'costo',         'label' => 'Costo',          'ancho' => 12],
            ['clave' => 'moneda',        'label' => 'Moneda',         'ancho' => 10],
            ['clave' => 'tasa',          'label' => 'Tasa de cambio', 'ancho' => 14],
            ['clave' => 'factura',       'label' => 'Factura',        'ancho' => 16],
            ['clave' => 'km',            'label' => 'Kilometraje',    'ancho' => 14],
            ['clave' => 'observaciones', 'label' => 'Observaciones',  'ancho' => 34],
            ['clave' => 'nota_odometro', 'label' => 'Nota odómetro',  'ancho' => 30],
        ];
    }

    /** En el informe, la fila se nombra por la placa: es lo que el usuario busca en su hoja. */
    protected function etiquetaFila(array $cruda): string
    {
        $placa = NombreCatalogo::placa((string) ($cruda['placa'] ?? ''));
        return $placa !== '' ? $placa : trim((string) ($cruda['fecha'] ?? ''));
    }

    /**
     * Ninguna: la misma unidad puede tener dos intervenciones el mismo día (una revisión y una
     * reparación aparte), y rechazarlas obligaría a fundir en una fila dos gastos distintos.
     */
    protected function clavesUnicas(): array
    {
        return [];
    }

    protected function listas(array $user): array
    {
        return [
            'placa'  => array_column($this->unidadesEscribibles($user), 'placa_unidad'),
            'tipo'   => array_column($this->catalogos->activos('tipos_mantenimiento'), 'nombre'),
            'moneda' => array_column($this->catalogos->activos('monedas', 'orden'), 'codigo'),
            // Los talleres NO se listan: se repiten de estación en estación («K&C» está en
            // varias) y el desplegable mostraría el mismo nombre tres veces sin decir cuál es.
            // Se resuelven contra la estación de la unidad de cada fila.
        ];
    }

    /**
     * Mapas nombre => id, una sola vez por análisis. Aquí también se limpia el rastro del
     * odómetro, porque analizar() se llama de nuevo al confirmar la carga.
     */
    protected function indices(array $user): array
    {
        $this->odometro->reiniciar();
        $this->talleresFaltantes = [];

        $unidades = [];
        $estacionDe = [];
        foreach ($this->unidadesEscribibles($user) as $u) {
            $unidades[NombreCatalogo::placa((string) $u['placa_unidad'])] = (int) $u['id'];
            $estacionDe[(int) $u['id']] = ['id' => (int) $u['estacion_id'], 'codigo' => (string) $u['estacion_codigo']];
        }

        // El taller se busca por estación: «K&C» de TRK y «K&C» de HON son talleres distintos.
        $talleres = [];
        foreach ($this->talleres->activos([]) as $t) {
            $talleres[(int) $t['estacion_id']][NombreCatalogo::clave((string) $t['nombre'])] = (int) $t['id'];
        }

        $monedas = [];
        foreach ($this->catalogos->activos('monedas', 'orden') as $m) {
            $monedas[$this->normalizar((string) $m['codigo'])] = (int) $m['id'];
        }

        return [
            'unidades'   => $unidades,
            'estacionDe' => $estacionDe,
            'tipos'      => $this->indicePorNombre($this->catalogos, 'tipos_mantenimiento'),
            'talleres'   => $talleres,
            'monedas'    => $monedas,
        ];
    }

    protected function traducir(array $cruda, array $indices, array $user): array
    {
        $errores = [];

        $unidadId = null;
        $placa = NombreCatalogo::placa((string) $cruda['placa']);
        if ($placa === '') {
            $errores['placa'] = 'Escribe la placa de la unidad.';
        } else {
            $unidadId = $indices['unidades'][$placa] ?? null;
            if ($unidadId === null) {
                $errores['placa'] = "No hay ninguna unidad tuya con la placa «{$cruda['placa']}».";
            }
        }

        $fecha = null;
        if (trim((string) $cruda['fecha']) === '') {
            $errores['fecha'] = 'Escribe la fecha de la intervención.';
        } else {
            $fecha = $this->fechaIso((string) $cruda['fecha']);
            if ($fecha === null) {
                $errores['fecha'] = "«{$cruda['fecha']}» no es una fecha. Usa el formato DD/MM/AAAA.";
            }
        }

        $tipoId = null;
        if (trim((string) $cruda['tipo']) === '') {
            $errores['tipo'] = 'Indica el tipo de mantenimiento.';
        } else {
            $tipoId = $this->resolver($cruda, $indices, 'tipo', 'tipos', 'los tipos de mantenimiento', $errores);
        }

        // Taller: tiene que existir ya en la estación de la unidad. No se crea al vuelo porque
        // un nombre mal escrito daría de alta un taller fantasma en medio de la carga.
        $tallerId = $this->taller($cruda, $indices, $unidadId, $errores);

        $monedaId = null;
        if (trim((string) $cruda['moneda']) !== '') {
            $monedaId = $this->resolver($cruda, $indices, 'moneda', 'monedas', 'las monedas', $errores);
        }

        $input = [
            'unidad_id'             => $unidadId,
            'fecha'                 => $fecha,
            'tipo_mantenimiento_id' => $tipoId,
            'descripcion'           => $cruda['descripcion'],
            'taller_id'             => $tallerId,
            'costo'                 => $cruda['costo'],
            'moneda_id'             => $monedaId,
            'tasa_usada'            => $cruda['tasa'],
            'factura'               => $cruda['factura'],
            'km'                    => $cruda['km'],
            'observaciones'         => $cruda['observaciones'],
            'nota_odometro'         => $cruda['nota_odometro'],
            // reinicia_ciclo no se manda: lo hereda del tipo, que es lo correcto para un
            // histórico. Corregir el caso raro a mano es más barato que una columna más.
        ];

        return [$input, $errores];
    }

    /** El taller de la fila, buscado entre los de la estación de esa unidad. */
    private function taller(array $cruda, array $indices, ?int $unidadId, array &$errores): ?int
    {
        $nombre = trim((string) $cruda['taller']);
        if ($nombre === '' || $unidadId === null) {
            return null;   // sin taller se registra igual; sin unidad, el error ya está dicho
        }
        $estacion = $indices['estacionDe'][$unidadId] ?? ['id' => 0, 'codigo' => 'esa estación'];
        $id = $indices['talleres'][$estacion['id']][NombreCatalogo::clave($nombre)] ?? null;
        if ($id === null) {
            $clave = $estacion['id'] . '|' . NombreCatalogo::clave($nombre);
            $this->talleresFaltantes[$clave] ??= [
                'nombre'      => NombreCatalogo::mostrar($nombre),
                'estacion_id' => $estacion['id'],
                'estacion'    => $estacion['codigo'],
                'filas'       => 0,
            ];
            $this->talleresFaltantes[$clave]['filas']++;
            $errores['taller'] = "«{$nombre}» todavía no es un taller de {$estacion['codigo']}.";
        }
        return $id;
    }

    /** Los talleres que habría que crear para que el archivo entre tal cual está. */
    public function extras(): array
    {
        return ['talleres_faltantes' => array_values($this->talleresFaltantes)];
    }

    /** La fila como quedará guardada: la fecha ya legible y el dinero con dos decimales. */
    public function vistaPrevia(array $cruda): array
    {
        $cruda['fecha'] = $this->fechaIso((string) $cruda['fecha']) ?? $cruda['fecha'];
        foreach (['costo', 'tasa'] as $campo) {
            $valor = trim((string) ($cruda[$campo] ?? ''));
            if ($valor !== '' && is_numeric(str_replace(',', '.', $valor))) {
                $cruda[$campo] = number_format((float) str_replace(',', '.', $valor), 2, '.', '');
            }
        }
        return $cruda;
    }

    protected function evaluarReglas(array $input, array $user): array
    {
        $resultado = $this->mantenimientos->evaluar($input, $user);

        // El servicio compara el kilometraje contra lo ya guardado; esto lo compara contra las
        // demás filas del mismo archivo, que todavía no existen en la base.
        $data = $resultado['data'];
        if ($data !== null && $data['km'] !== null) {
            $problema = $this->odometro->problema(
                (int) $data['unidad_id'],
                (string) $data['fecha'],
                (int) $data['km'],
                (string) ($data['nota_odometro'] ?? '')
            );
            if ($problema !== null) {
                return ['data' => null, 'errores' => ['km' => $problema]];
            }
            $this->odometro->anotar((int) $data['unidad_id'], (string) $data['fecha'], (int) $data['km']);
        }
        return $resultado;
    }

    protected function insertar(array $item, array $user): void
    {
        $this->mantenimientos->guardar($item['data'], $user, 'carga masiva');
    }

    /** Los errores del servicio vienen con nombre de campo; el informe cita columnas. */
    protected function equivalencias(): array
    {
        return [
            'unidad_id'             => 'placa',
            'tipo_mantenimiento_id' => 'tipo',
            'taller_id'             => 'taller',
            'moneda_id'             => 'moneda',
            'tasa_usada'            => 'tasa',
        ];
    }

    /** Unidades activas de las estaciones donde el usuario puede escribir. */
    private function unidadesEscribibles(array $user): array
    {
        return array_values(array_filter(
            $this->unidades->listar(null, [], true),
            static fn(array $u): bool => can_write_station($user, (int) $u['estacion_id'])
        ));
    }

    protected function ayuda(): array
    {
        return [
            'fecha'         => 'Obligatoria. El día de la intervención. No puede ser futura.',
            'placa'         => 'Obligatoria. Tiene que ser una unidad de tu estación (hoja Listas).',
            'tipo'          => 'Obligatorio. De la hoja Listas. El tipo decide si reinicia el ciclo de servicio.',
            'descripcion'   => 'Opcional. Qué se hizo: «cambio de aceite y filtros».',
            'taller'        => 'Opcional, pero tiene que existir ya en la estación de esa unidad. '
                             . 'Si falta, créalo antes en Mantenimientos › Talleres.',
            'costo'         => 'Opcional. Solo el número. Si aún no hay factura, déjalo vacío: un cero '
                             . 'mentiría en los totales.',
            'moneda'        => 'Obligatoria si hay costo. Código de la hoja Listas (USD, GTQ, HNL…).',
            'tasa'          => 'Opcional. La tasa de la factura. Vacío usa la vigente de esa moneda, '
                             . 'que para un gasto viejo puede no ser la correcta.',
            'factura'       => 'Opcional. Número de factura o comprobante.',
            'km'            => 'Opcional. El odómetro ese día; alimenta el control sin capturarlo aparte.',
            'observaciones' => 'Opcional. Lo que haya que recordar de ese trabajo.',
            'nota_odometro' => 'Solo si el kilometraje baja respecto a otra lectura: explica por qué '
                             . '(«odómetro reemplazado»). Sin la nota, la fila se rechaza.',
        ];
    }

    protected function ejemplo(array $listas): array
    {
        return [
            'fecha'         => '15/03/2025',
            'placa'         => $listas['placa'][0] ?? 'P123ABC',
            'tipo'          => $listas['tipo'][0] ?? 'Revisión',
            'descripcion'   => 'Cambio de aceite, filtros y revisión de frenos',
            'taller'        => 'K&C',
            'costo'         => '3500',
            'moneda'        => $listas['moneda'][0] ?? 'USD',
            'tasa'          => '36.80',
            'factura'       => 'FAC-00123',
            'km'            => '145000',
            'observaciones' => 'Pendiente cambiar llanta delantera derecha',
            'nota_odometro' => '',
        ];
    }
}
