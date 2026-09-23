<?php
/**
 * Carga masiva de proveedores: una fila por camión.
 *
 * El proveedor se escribe en cada fila y se reconoce por su clave, así que diez filas con
 * «Transportes López» y «TRANSPORTES LOPEZ, S.A. DE C.V.» dan un solo proveedor con diez
 * camiones. Las reglas del camión son las mismas del formulario (ProveedorService).
 */

declare(strict_types=1);

final class ProveedorImportService extends ImportadorExcel
{
    public function __construct(PDO $pdo, private ProveedorService $proveedores)
    {
        parent::__construct($pdo);
    }

    protected function entidad(): string
    {
        return 'Proveedores';
    }

    protected function columnas(): array
    {
        return [
            ['clave' => 'proveedor',            'label' => 'Proveedor',                'ancho' => 32],
            ['clave' => 'placa_motriz',         'label' => 'Placa cabezal',            'ancho' => 16],
            ['clave' => 'placa_arrastre',       'label' => 'Placa furgón',             'ancho' => 16],
            ['clave' => 'piloto',               'label' => 'Motorista',                'ancho' => 28],
            ['clave' => 'licencia',             'label' => 'Licencia',                 'ancho' => 18],
            ['clave' => 'documento',            'label' => 'Documento',                'ancho' => 18],
            ['clave' => 'telefonos',            'label' => 'Teléfono',                 'ancho' => 20],
            ['clave' => 'codigo_nacional',      'label' => 'Código nacional',          'ancho' => 18],
            ['clave' => 'codigo_internacional', 'label' => 'Código internacional',     'ancho' => 20],
        ];
    }

    /** En el informe, la fila se nombra por su placa: es lo que identifica al camión. */
    protected function etiquetaFila(array $cruda): string
    {
        $placa = NombreCatalogo::placa((string) ($cruda['placa_motriz'] ?? ''));
        return $placa !== '' ? $placa : trim((string) ($cruda['proveedor'] ?? ''));
    }

    /** La placa repetida en el mismo archivo es el mismo camión dos veces. */
    protected function clavesUnicas(): array
    {
        return ['placa_motriz'];
    }

    /** Sin listas desplegables: el proveedor puede ser nuevo, así que no se limita a los que hay. */
    protected function listas(array $user): array
    {
        return [];
    }

    protected function indices(array $user): array
    {
        return [];
    }

    protected function traducir(array $cruda, array $indices, array $user): array
    {
        $errores = [];
        if (trim($cruda['proveedor']) === '') {
            $errores['proveedor'] = 'Indica de qué proveedor es el camión.';
        }
        return [$cruda, $errores];
    }

    protected function evaluarReglas(array $input, array $user): array
    {
        $resultado = $this->proveedores->evaluarCamion($input);
        if ($resultado['data'] !== null) {
            $resultado['data']['proveedor'] = NombreCatalogo::mostrar($input['proveedor']);
        }
        return $resultado;
    }

    protected function insertar(array $item, array $user): void
    {
        // Mismo camino que la reserva: encuentra o crea el proveedor por su clave y da de alta
        // el camión. Dos filas con el proveedor escrito distinto caen en el mismo, porque la
        // primera ya lo creó dentro de esta misma transacción.
        $this->proveedores->paraReserva($item['data'], $user['id'], true, 'carga masiva');
    }

    protected function ayuda(): array
    {
        return [
            'proveedor'            => 'Obligatorio. Si ya existe se usa ese aunque esté escrito distinto '
                                    . '(mayúsculas, tildes, «S.A. de C.V.»); si no, se crea.',
            'placa_motriz'         => 'Obligatoria. Identifica al camión: no puede repetirse ni existir ya.',
            'placa_arrastre'       => 'Opcional. Furgón, contenedor o chasis que suele llevar.',
            'piloto'               => 'Opcional. Motorista habitual; en cada reserva se puede cambiar.',
            'licencia'             => 'Opcional.',
            'documento'            => 'Opcional. DUI, cédula o pasaporte.',
            'telefonos'            => 'Opcional. Varios números separados por coma.',
            'codigo_nacional'      => 'Opcional. Código que habilita mover carga dentro del país.',
            'codigo_internacional' => 'Opcional. Código que habilita cruzar frontera con carga.',
        ];
    }

    protected function ejemplo(array $listas): array
    {
        return [
            'proveedor'            => 'Transportes López',
            'placa_motriz'         => 'C555111',
            'placa_arrastre'       => 'RE777222',
            'piloto'               => 'Juan Pérez',
            'licencia'             => 'LIC-889900',
            'documento'            => '04561234-5',
            'telefonos'            => '7000-1111',
            'codigo_nacional'      => 'SV01234',
            'codigo_internacional' => 'SVC05678',
        ];
    }
}
