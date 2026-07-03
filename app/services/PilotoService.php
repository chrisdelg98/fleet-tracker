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

        return tx($this->pdo, function () use ($data, $user): int {
            $id = $this->pilotos->crear($data, $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'piloto', $id, AccionBitacora::CREAR, ['despues' => $data]);
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

        $data = $this->validar($input);
        $this->assertPuedeEscribir($user, (int) $data['estacion_id']);

        tx($this->pdo, function () use ($id, $data, $actual, $user): void {
            $this->pilotos->actualizar($id, $data);
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

    private function validar(array $input): array
    {
        $v = new Validator($input);
        $v->required('nombre', 'El nombre')->maxLen('nombre', 150, 'El nombre')
          ->required('tipo_licencia_id', 'El tipo de licencia')->positiveInt('tipo_licencia_id', 'El tipo de licencia')
          ->required('no_licencia', 'El número de licencia')->maxLen('no_licencia', 60, 'El número de licencia')
          ->date('licencia_vence', 'El vencimiento de licencia')
          ->required('estacion_id', 'La estación')->positiveInt('estacion_id', 'La estación');
        $v->validateOrFail();

        if ($this->catalogos->find('tipos_licencia', (int) $v->value('tipo_licencia_id')) === null) {
            json_unprocessable(['tipo_licencia_id' => 'El tipo de licencia no existe.']);
        }

        $vence = $v->value('licencia_vence');
        return [
            'nombre'           => $v->value('nombre'),
            'tipo_licencia_id' => (int) $v->value('tipo_licencia_id'),
            'no_licencia'      => $v->value('no_licencia'),
            'licencia_vence'   => $vence !== null && $vence !== '' ? $vence : null,
            'estacion_id'      => (int) $v->value('estacion_id'),
        ];
    }

    private function assertPuedeEscribir(array $user, int $estacionId): void
    {
        if (!can_write_station($user, $estacionId)) {
            json_error('No autorizado sobre esta estación', 403);
        }
    }

    private function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip(['nombre', 'tipo_licencia_id', 'no_licencia', 'licencia_vence', 'estacion_id']));
    }
}
