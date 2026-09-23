<?php
/**
 * Carga masiva de kilometraje: una fila por lectura de odómetro.
 *
 * Es el complemento del historial. Sirve para traer de golpe las lecturas de los dos años que
 * están en la hoja —una columna por mes— y también para el mes corriente cuando alguien prefiere
 * llenar un Excel antes que la pantalla de captura.
 *
 * El odómetro solo sube, y eso se revisa contra lo guardado (MantenimientoService) y contra las
 * demás filas del archivo (OdometroEnArchivo). La nota es la misma válvula de escape de siempre.
 */

declare(strict_types=1);

final class LecturaImportService extends ImportadorExcel
{
    private OdometroEnArchivo $odometro;

    public function __construct(
        PDO $pdo,
        private MantenimientoService $mantenimientos,
        private UnidadModel $unidades
    ) {
        parent::__construct($pdo);
        $this->odometro = new OdometroEnArchivo();
    }

    protected function entidad(): string
    {
        return 'Kilometraje';
    }

    protected function columnas(): array
    {
        return [
            ['clave' => 'placa', 'label' => 'Placa',       'ancho' => 14],
            ['clave' => 'fecha', 'label' => 'Fecha',       'ancho' => 12],
            ['clave' => 'km',    'label' => 'Kilometraje', 'ancho' => 14],
            ['clave' => 'nota',  'label' => 'Nota',        'ancho' => 40],
        ];
    }

    protected function etiquetaFila(array $cruda): string
    {
        $placa = NombreCatalogo::placa((string) ($cruda['placa'] ?? ''));
        return $placa !== '' ? $placa : trim((string) ($cruda['fecha'] ?? ''));
    }

    /**
     * Ninguna columna sola: la placa se repite (una lectura por mes) y la fecha también (toda la
     * flota el mismo día). Lo que no puede repetirse es la pareja, y eso se revisa por fila.
     */
    protected function clavesUnicas(): array
    {
        return [];
    }

    protected function listas(array $user): array
    {
        return ['placa' => array_column($this->unidadesEscribibles($user), 'placa_unidad')];
    }

    protected function indices(array $user): array
    {
        $this->odometro->reiniciar();

        $unidades = [];
        foreach ($this->unidadesEscribibles($user) as $u) {
            $unidades[NombreCatalogo::placa((string) $u['placa_unidad'])] = (int) $u['id'];
        }
        return ['unidades' => $unidades];
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
            $errores['fecha'] = 'Escribe la fecha de la lectura.';
        } else {
            $fecha = $this->fechaIso((string) $cruda['fecha']);
            if ($fecha === null) {
                $errores['fecha'] = "«{$cruda['fecha']}» no es una fecha. Usa el formato DD/MM/AAAA.";
            }
        }

        // Dos lecturas de la misma unidad el mismo día: una de las dos sobra, y elegir cuál no
        // es cosa del sistema.
        if ($unidadId !== null && $fecha !== null && $this->odometro->fechaRepetida($unidadId, $fecha)) {
            $errores['fecha'] = "Ya hay otra lectura de {$placa} con fecha {$fecha} en este archivo.";
        }

        return [[
            'unidad_id' => $unidadId,
            'fecha'     => $fecha,
            'km'        => $cruda['km'],
            'nota'      => $cruda['nota'],
        ], $errores];
    }

    protected function evaluarReglas(array $input, array $user): array
    {
        $resultado = $this->mantenimientos->evaluarLectura($input, $user);

        $data = $resultado['data'];
        if ($data !== null) {
            $problema = $this->odometro->problema(
                (int) $data['unidad_id'],
                (string) $data['fecha'],
                (int) $data['km'],
                (string) ($data['nota'] ?? '')
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
        $this->mantenimientos->guardarLectura($item['data'], $user);
    }

    protected function equivalencias(): array
    {
        return ['unidad_id' => 'placa'];
    }

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
            'placa' => 'Obligatoria. Una unidad de tu estación (hoja Listas).',
            'fecha' => 'Obligatoria. El día de la lectura. No puede ser futura ni repetirse para '
                     . 'la misma unidad dentro del archivo.',
            'km'    => 'Obligatorio. Solo el número; los puntos y comas de millar se ignoran.',
            'nota'  => 'Solo si el odómetro bajó respecto a otra lectura: explica por qué '
                     . '(«odómetro reemplazado»). Sin la nota, la fila se rechaza.',
        ];
    }

    protected function ejemplo(array $listas): array
    {
        return [
            'placa' => $listas['placa'][0] ?? 'P123ABC',
            'fecha' => '31/08/2025',
            'km'    => '145000',
            'nota'  => '',
        ];
    }
}
