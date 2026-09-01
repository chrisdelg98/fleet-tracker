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
            ['clave' => 'unidades',             'label' => 'Unidades asignadas (placas separadas por ;)', 'ancho' => 34],
        ];
    }

    protected function etiquetaFila(array $cruda): string
    {
        return trim((string) ($cruda['nombre'] ?? ''));
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
        $resultado = $this->pilotos->evaluar($input, null);

        // Si la fila es un piloto que ya existe, sus unidades ya son suyas: no son un choque
        // aparte. Reportarlo también convertiría un solo hecho —"este piloto ya está
        // cargado"— en dos líneas que dicen lo mismo con otras palabras.
        $existente = $this->pilotoExistente($input);

        // La asignación de unidades se valida con las mismas reglas del formulario.
        ['errores' => $errores] = $this->pilotos->evaluarUnidades($input, $existente);
        if ($errores !== []) {
            $resultado['errores'] += $errores;
            $resultado['data'] = null;
        }
        return $resultado;
    }

    /** Id del piloto ya registrado al que corresponde esta fila, si lo hay. */
    private function pilotoExistente(array $input): ?int
    {
        $porLicencia = $this->pilotoModel->quienTiene('no_licencia', (string) ($input['no_licencia'] ?? ''));
        if ($porLicencia !== null) {
            return $porLicencia['id'];
        }
        $documento = trim((string) ($input['documento_identidad'] ?? ''));
        if ($documento === '') {
            return null;
        }
        return $this->pilotoModel->quienTiene('documento_identidad', $documento)['id'] ?? null;
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
            return $this->sinUnidadesRepetidas(parent::analizar($ruta, $nombreOriginal, $user));
        } finally {
            $this->etiquetas = null;
        }
    }

    /**
     * Una unidad no puede aparecer en dos filas: sería el mismo cabezal con dos motoristas.
     *
     * Esto no lo ve la comprobación contra la base porque durante el análisis todavía no se
     * ha insertado nada, así que se contrasta el archivo consigo mismo.
     */
    private function sinUnidadesRepetidas(array $informe): array
    {
        $vistas = [];
        foreach ($informe['filas'] as $item) {
            foreach ($item['input']['unidades'] ?? [] as $id) {
                $vistas[$id][] = $item['fila'];
            }
        }
        $repetidas = array_filter($vistas, static fn(array $filas): bool => count($filas) > 1);
        if ($repetidas === []) {
            return $informe;
        }

        $placaDe = array_flip($this->indices([
            'rol' => Rol::ADMIN_GLOBAL, 'estacion_id' => null,
        ])['unidades']);

        $etiquetas = [];
        foreach ($informe['filas'] as $item) {
            $etiquetas[$item['fila']] = $this->etiquetaFila($item['cruda']);
        }

        $conflictivas = [];
        foreach ($repetidas as $id => $filas) {
            foreach ($filas as $fila) {
                $conflictivas[$fila][] = strtoupper((string) ($placaDe[$id] ?? $id));
            }
        }

        foreach ($conflictivas as $fila => $placas) {
            $informe['errores'][] = [
                'fila' => $fila,
                'registro' => $etiquetas[$fila] ?? '',
                'columna' => 'Unidades asignadas (placas separadas por ;)',
                'valor' => implode(', ', array_unique($placas)),
                'mensaje' => 'Esa unidad también aparece en otra fila del archivo: solo puede tener un piloto.',
            ];
        }
        // Las filas en conflicto dejan de ser válidas; con errores no se carga nada de todos
        // modos, pero el recuento de "listos" tiene que decir la verdad.
        $informe['filas'] = array_values(array_filter(
            $informe['filas'],
            static fn(array $item): bool => !isset($conflictivas[$item['fila']])
        ));
        return $informe;
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

        // Las hojas de control reales traen el cabezal y el furgón del motorista en columnas
        // aparte. Como ahora cada uno es una unidad propia, aquí se pegan juntos separados
        // por «;» y los dos quedan asignados al piloto.
        $input['unidades'] = $this->unidades($cruda, $indices, $errores);

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

    /**
     * Placas separadas por «;» (o coma) → ids de unidad. Una placa desconocida se reporta con
     * su nombre: es lo que el usuario escribió y lo que tiene que corregir en su hoja.
     *
     * @return int[]
     */
    private function unidades(array $cruda, array $indices, array &$errores): array
    {
        if (($cruda['unidades'] ?? '') === '') {
            return [];
        }
        $ids = [];
        foreach (preg_split('/[;,]/u', $cruda['unidades']) as $placa) {
            $placa = trim((string) $placa);
            // "N/A" es lo que la gente escribe cuando esa fila no lleva equipo de arrastre.
            if ($placa === '' || $this->normalizar($placa) === 'n/a') {
                continue;
            }
            $id = $indices['unidades'][$this->normalizar($placa)] ?? null;
            if ($id === null) {
                $errores['unidades'] = "No existe ninguna unidad con placa «{$placa}».";
                continue;
            }
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    // ── Catálogos ──

    protected function indices(array $user): array
    {
        $unidades = [];
        foreach ($this->unidadesEscribibles($user) as $u) {
            $unidades[$this->normalizar($u['placa_unidad'])] = (int) $u['id'];
        }

        return $this->indiceEstaciones($this->catalogos) + [
            'tipos_licencia' => $this->indicePorNombre($this->catalogos, 'tipos_licencia'),
            'unidades'       => $unidades,
        ];
    }

    /** Unidades activas donde el usuario puede escribir. */
    private function unidadesEscribibles(array $user): array
    {
        if ($user['rol'] === Rol::ADMIN_GLOBAL) {
            return $this->pdo->query(
                'SELECT id, placa_unidad FROM unidades WHERE activo = 1 ORDER BY placa_unidad'
            )->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, placa_unidad FROM unidades WHERE activo = 1 AND estacion_id = :e ORDER BY placa_unidad'
        );
        $stmt->execute([':e' => $user['estacion_id']]);
        return $stmt->fetchAll();
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
            'unidades'             => 'Opcional. Placas separadas por punto y coma: cabezal, furgón, contenedor… '
                                    . 'Ej.: C88198; RE15878. Una unidad que ya lleve otro piloto se rechaza.',
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
            'unidades'             => 'C88198; RE15878',
        ];
    }

    // ── Inserción ──

    protected function insertar(array $item, array $user): void
    {
        $data = $item['data'];
        $id = $this->pilotoModel->crear($data, $user['id']);
        $this->pilotoModel->setUnidadesAsignadas($id, $item['input']['unidades'] ?? []);
        registrar_bitacora($this->pdo, $user['id'], 'piloto', $id, AccionBitacora::CREAR, [
            'despues' => $data,
            'origen'  => 'carga masiva',
        ]);
    }
}
