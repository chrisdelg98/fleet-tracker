<?php
/**
 * Carga masiva de pilotos. Igual que la de flota: solo declara lo propio del piloto y hereda
 * de ImportadorExcel el recorrido de analizar, informar y confirmar en una transacción.
 *
 * Los dos códigos de transporte son el mismo concepto en toda la región (uno habilita mover
 * carga dentro del país y otro cruzar la frontera), pero cada país los llama a su manera. Si
 * todas las estaciones donde el usuario puede dar de alta son de un mismo país, la plantilla
 * usa el nombre local —"Código SV", "Código SVC"— para que se reconozca a simple vista.
 */

declare(strict_types=1);

final class PilotoImportService extends ImportadorExcel
{
    public function __construct(
        PDO $pdo,
        private PilotoService $pilotos,
        private PilotoModel $pilotoModel,
        private CatalogoModel $catalogos
    ) {
        parent::__construct($pdo);
    }

    /** Etiquetas de los códigos para el usuario actual; se fijan al construir la plantilla. */
    private ?array $etiquetas = null;

    protected function entidad(): string
    {
        return 'Pilotos';
    }

    protected function columnas(): array
    {
        $etiquetas = $this->etiquetas ?? [
            'nacional' => 'Código de transporte nacional',
            'internacional' => 'Código de transporte internacional',
        ];

        return [
            ['clave' => 'nombre',               'label' => 'Nombre',                    'ancho' => 26],
            ['clave' => 'documento_identidad',  'label' => 'Documento de identificación', 'ancho' => 22],
            ['clave' => 'telefonos',            'label' => 'Teléfonos',                 'ancho' => 26],
            ['clave' => 'tipo_licencia',        'label' => 'Tipo de licencia',          'ancho' => 20],
            ['clave' => 'no_licencia',          'label' => 'N.º de licencia',           'ancho' => 18],
            ['clave' => 'licencia_vence',       'label' => 'Vencimiento de licencia (AAAA-MM-DD)', 'ancho' => 26],
            ['clave' => 'codigo_nacional',      'label' => $etiquetas['nacional'],      'ancho' => 22],
            ['clave' => 'codigo_internacional', 'label' => $etiquetas['internacional'], 'ancho' => 22],
            ['clave' => 'estacion',             'label' => 'Estación',                  'ancho' => 22],
        ];
    }

    /** Ambos identifican a la persona: repetir cualquiera es cargar al mismo piloto dos veces. */
    protected function clavesUnicas(): array
    {
        return ['no_licencia', 'documento_identidad'];
    }

    protected function equivalencias(): array
    {
        return [
            'tipo_licencia_id' => 'tipo_licencia',
            'estacion_id'      => 'estacion',
        ];
    }

    protected function evaluarReglas(array $input, array $user): array
    {
        return $this->pilotos->evaluar($input, null);
    }

    // ── Plantilla con las etiquetas del país ──

    public function plantilla(array $user): string
    {
        $this->etiquetas = $this->etiquetasDe($user);
        try {
            return parent::plantilla($user);
        } finally {
            $this->etiquetas = null;
        }
    }

    public function analizar(string $ruta, string $nombreOriginal, array $user): array
    {
        // El encabezado que se compara tiene que ser el mismo que se descargó.
        $this->etiquetas = $this->etiquetasDe($user);
        try {
            return parent::analizar($ruta, $nombreOriginal, $user);
        } finally {
            $this->etiquetas = null;
        }
    }

    /**
     * Nombre local de los códigos si todas las estaciones donde el usuario puede escribir son
     * del mismo país; si abarca varios, el genérico, que es lo que significan en todas partes.
     */
    private function etiquetasDe(array $user): array
    {
        $paises = [];
        foreach ($this->catalogos->activos('estaciones') as $e) {
            if (can_write_station($user, (int) $e['id'])) {
                $paises[(int) $e['pais_id']] = true;
            }
        }
        $etiquetas = etiquetas_codigo_piloto();
        if (count($paises) === 1) {
            $pais = array_key_first($paises);
            if (isset($etiquetas[$pais])) {
                return $etiquetas[$pais];
            }
        }
        return ['nacional' => 'Código de transporte nacional', 'internacional' => 'Código de transporte internacional'];
    }

    // ── Traducción ──

    protected function traducir(array $cruda, array $indices, array $user): array
    {
        $errores = [];

        $input = [
            'nombre'               => $cruda['nombre'],
            'documento_identidad'  => $cruda['documento_identidad'],
            'telefonos'            => $cruda['telefonos'],
            'no_licencia'          => $cruda['no_licencia'],
            'codigo_nacional'      => $cruda['codigo_nacional'],
            'codigo_internacional' => $cruda['codigo_internacional'],
            'tipo_licencia_id'     => $this->resolver($cruda, $indices, 'tipo_licencia', 'tipos_licencia', 'los tipos de licencia', $errores),
            'estacion_id'          => $this->resolver($cruda, $indices, 'estacion', 'estaciones', 'las estaciones', $errores),
        ];

        if ($cruda['licencia_vence'] !== '') {
            $fecha = $this->fechaIso($cruda['licencia_vence']);
            if ($fecha === null) {
                $errores['licencia_vence'] = "«{$cruda['licencia_vence']}» no es una fecha. Escríbela como AAAA-MM-DD.";
            } else {
                $input['licencia_vence'] = $fecha;
            }
        }

        return [$input, $errores];
    }

    // ── Catálogos ──

    protected function indices(array $user): array
    {
        return $this->indiceEstaciones($this->catalogos) + [
            'tipos_licencia' => $this->indicePorNombre($this->catalogos, 'tipos_licencia'),
        ];
    }

    protected function listas(array $user): array
    {
        return [
            'tipo_licencia' => array_values(array_map(
                static fn(array $t): string => (string) $t['nombre'],
                $this->catalogos->activos('tipos_licencia')
            )),
            'estacion' => $this->estacionesEscribibles($this->catalogos, $user),
        ];
    }

    protected function ayuda(): array
    {
        return [
            'nombre'               => 'Obligatorio. Nombre completo del piloto.',
            'documento_identidad'  => 'Opcional pero recomendado. DUI, cédula o pasaporte. No puede repetirse.',
            'telefonos'            => 'Opcional. Campo libre: varios números separados por coma.',
            'tipo_licencia'        => 'Obligatorio. Elige de la lista desplegable.',
            'no_licencia'          => 'Obligatorio. Identifica al piloto: no puede repetirse ni existir ya.',
            'licencia_vence'       => 'Opcional. Formato AAAA-MM-DD (también se acepta una celda con formato de fecha).',
            'codigo_nacional'      => 'Opcional. Código que habilita mover carga dentro del país.',
            'codigo_internacional' => 'Opcional. Código que habilita cruzar frontera con carga.',
            'estacion'             => 'Obligatoria. Solo aparecen las estaciones donde puedes dar de alta.',
        ];
    }

    protected function ejemplo(array $listas): array
    {
        return [
            'nombre'               => 'Carlos Méndez',
            'documento_identidad'  => '01234567-8',
            'telefonos'            => '7777-0000, 2222-1111',
            'tipo_licencia'        => $listas['tipo_licencia'][0] ?? '',
            'no_licencia'          => 'P-001',
            'licencia_vence'       => (new DateTimeImmutable('+2 years'))->format('Y-m-d'),
            'codigo_nacional'      => '12345',
            'codigo_internacional' => 'SVC-98765',
            'estacion'             => $listas['estacion'][0] ?? '',
        ];
    }

    // ── Inserción ──

    protected function insertar(array $item, array $user): void
    {
        $data = $item['data'];
        $id = $this->pilotoModel->crear($data, $user['id']);
        registrar_bitacora($this->pdo, $user['id'], 'piloto', $id, AccionBitacora::CREAR, [
            'despues' => $data,
            'origen'  => 'carga masiva',
        ]);
    }
}
