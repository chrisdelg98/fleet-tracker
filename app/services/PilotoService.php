<?php
/**
 * Reglas de negocio de Pilotos (plan §5.4, §7.3). Escritura solo sobre la propia estación
 * (plan §4); toda escritura en transacción con bitácora. La alerta de licencia por vencer
 * es de presentación (se calcula en la vista); aquí solo se valida el dato.
 */

declare(strict_types=1);

final class PilotoService
{
    public function __construct(
        private PDO $pdo,
        private PilotoModel $pilotos,
        private CatalogoModel $catalogos
    ) {
    }

    public function crear(array $input, array $user): int
    {
        $data = $this->validar($input);
        $this->assertPuedeEscribir($user, (int) $data['estacion_id']);

        $unidades = $this->unidadesDe($input, null);

        return tx($this->pdo, function () use ($data, $unidades, $user): int {
            $id = $this->pilotos->crear($data, $user['id']);
            $this->pilotos->setUnidadesAsignadas($id, $unidades);
            registrar_bitacora($this->pdo, $user['id'], 'piloto', $id, AccionBitacora::CREAR, [
                'despues' => $data + ['unidades_asignadas' => count($unidades)],
            ]);
            return $id;
        });
    }

    public function actualizar(int $id, array $input, array $user): void
    {
        $actual = $this->pilotos->find($id);
        if ($actual === null || (int) $actual['activo'] !== 1) {
            json_error('Piloto no encontrado', 404);
        }
        $this->assertPuedeEscribir($user, (int) $actual['estacion_id']);

        $data = $this->validar($input, $id);
        $this->assertPuedeEscribir($user, (int) $data['estacion_id']);

        $unidades = $this->unidadesDe($input, $id);

        tx($this->pdo, function () use ($id, $data, $unidades, $actual, $user): void {
            $this->pilotos->actualizar($id, $data);
            $this->pilotos->setUnidadesAsignadas($id, $unidades);
            registrar_bitacora($this->pdo, $user['id'], 'piloto', $id, AccionBitacora::EDITAR, [
                'antes'   => $this->snapshot($actual),
                'despues' => $data,
            ]);
        });
    }

    public function eliminar(int $id, array $user): void
    {
        $actual = $this->pilotos->find($id);
        if ($actual === null || (int) $actual['activo'] !== 1) {
            json_error('Piloto no encontrado', 404);
        }
        $this->assertPuedeEscribir($user, (int) $actual['estacion_id']);

        tx($this->pdo, function () use ($id, $user): void {
            $this->pilotos->softDelete($id);
            registrar_bitacora($this->pdo, $user['id'], 'piloto', $id, AccionBitacora::ELIMINAR, [
                'antes' => ['activo' => 1], 'despues' => ['activo' => 0],
            ]);
        });
    }

    public function listar(array $user, ?int $estacionFiltro = null, array $filtros = []): array
    {
        $estacion = $user['rol'] === Rol::ADMIN_GLOBAL ? $estacionFiltro : (int) $user['estacion_id'];
        return $this->pilotos->listar($estacion, $filtros);
    }

    private function validar(array $input, ?int $exceptId = null): array
    {
        ['data' => $data, 'errores' => $errores] = $this->evaluar($input, $exceptId);
        if ($errores !== []) {
            json_unprocessable($errores);
        }
        return $data;
    }

    /**
     * Aplica las reglas del piloto SIN cortar la petición y devuelve
     * ['data' => array|null, 'errores' => array<campo, mensaje>].
     *
     * Igual que en unidades: la carga masiva valida por aquí, así el Excel no puede aceptar
     * nada que el formulario rechace.
     */
    public function evaluar(array $input, ?int $exceptId = null): array
    {
        $v = new Validator($input);
        $v->required('nombre', 'El nombre')->maxLen('nombre', 150, 'El nombre')
          ->maxLen('documento_identidad', 40, 'El documento de identificación')
          ->maxLen('telefonos', 255, 'Los teléfonos')
          ->required('tipo_licencia_id', 'El tipo de licencia')->positiveInt('tipo_licencia_id', 'El tipo de licencia')
          ->required('no_licencia', 'El número de licencia')->maxLen('no_licencia', 60, 'El número de licencia')
          ->date('licencia_vence', 'El vencimiento de licencia')
          ->maxLen('codigo_nacional', 40, 'El código de transporte nacional')
          ->maxLen('codigo_internacional', 40, 'El código de transporte internacional')
          ->required('estacion_id', 'La estación')->positiveInt('estacion_id', 'La estación');
        if ($v->fails()) {
            return ['data' => null, 'errores' => $v->errors()];
        }

        $errores = [];
        if ($this->catalogos->find('tipos_licencia', (int) $v->value('tipo_licencia_id')) === null) {
            $errores['tipo_licencia_id'] = 'El tipo de licencia no existe.';
        }

        // La licencia y el documento identifican a la persona: si se repiten, es la misma
        // dos veces. Sin esta comprobación, subir el mismo archivo duplicaría la plantilla.
        $licencia = (string) $v->value('no_licencia');
        if ($this->pilotos->existeCon('no_licencia', $licencia, $exceptId)) {
            $errores['no_licencia'] = 'Ya hay un piloto con ese número de licencia.';
        }
        $documento = $this->nullable($v->value('documento_identidad'));
        if ($documento !== null && $this->pilotos->existeCon('documento_identidad', $documento, $exceptId)) {
            $errores['documento_identidad'] = 'Ya hay un piloto con ese documento de identificación.';
        }

        if ($errores !== []) {
            return ['data' => null, 'errores' => $errores];
        }

        $vence = $v->value('licencia_vence');
        $data = [
            // El nombre del piloto va en mayúsculas, como en las hojas de control de la
            // operación: así una misma persona no aparece escrita de tres formas distintas.
            'nombre'               => mb_strtoupper((string) $v->value('nombre'), 'UTF-8'),
            'documento_identidad'  => $documento,
            'telefonos'            => $this->nullable($v->value('telefonos')),
            'tipo_licencia_id'     => (int) $v->value('tipo_licencia_id'),
            'no_licencia'          => $licencia,
            'licencia_vence'       => $vence !== null && $vence !== '' ? $vence : null,
            'codigo_nacional'      => $this->nullable($v->value('codigo_nacional')),
            'codigo_internacional' => $this->nullable($v->value('codigo_internacional')),
            'estacion_id'          => (int) $v->value('estacion_id'),
        ];

        return ['data' => $data, 'errores' => []];
    }

    /**
     * Unidades que el formulario o el Excel quieren asignar a este piloto. Corta con 422 si
     * alguna no existe, no es suya o ya la lleva otro motorista.
     *
     * @return int[]
     */
    private function unidadesDe(array $input, ?int $pilotoId): array
    {
        ['unidades' => $ids, 'errores' => $errores] = $this->evaluarUnidades($input, $pilotoId);
        if ($errores !== []) {
            json_unprocessable($errores);
        }
        return $ids;
    }

    /**
     * Igual que unidadesDe() pero sin cortar: lo usa la carga masiva para reportar la fila.
     *
     * @return array{unidades:int[], errores:array<string,string>}
     */
    public function evaluarUnidades(array $input, ?int $pilotoId): array
    {
        $ids = $input['unidades'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return ['unidades' => [], 'errores' => []];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        // Una unidad solo tiene un piloto habitual: si ya la lleva otro, no se le quita
        // en silencio. La persona decide si reasigna desde la ficha de la unidad.
        $ocupadas = $this->pilotos->unidadesConOtroPiloto($ids, $pilotoId);
        if ($ocupadas !== []) {
            $detalle = implode(', ', array_map(
                static fn(array $u): string => $u['placa_unidad'] . ' (la lleva ' . $u['piloto'] . ')',
                $ocupadas
            ));
            return ['unidades' => [], 'errores' => ['unidades' => "Ya tienen piloto asignado: {$detalle}."]];
        }
        return ['unidades' => $ids, 'errores' => []];
    }

    private function nullable(?string $valor): ?string
    {
        $valor = $valor !== null ? trim($valor) : '';
        return $valor === '' ? null : $valor;
    }

    private function assertPuedeEscribir(array $user, int $estacionId): void
    {
        if (!can_write_station($user, $estacionId)) {
            json_error('No autorizado sobre esta estación', 403);
        }
    }

    private function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'nombre', 'documento_identidad', 'telefonos', 'tipo_licencia_id', 'no_licencia',
            'licencia_vence', 'codigo_nacional', 'codigo_internacional', 'estacion_id',
        ]));
    }
}
