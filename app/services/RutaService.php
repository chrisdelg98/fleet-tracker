<?php
/**
 * Reglas de negocio de Rutas (plan §5.6, §7.4). Compartidas: cualquier Encargado o Admin
 * las crea (no hay dueño de estación). tipo_ruta se auto-deriva (origen == destino →
 * NACIONAL). Escritura en transacción con bitácora.
 */

declare(strict_types=1);

final class RutaService
{
    public function __construct(private PDO $pdo, private RutaModel $rutas)
    {
    }

    public function crear(array $input, array $user): int
    {
        $data = $this->validar($input);
        return tx($this->pdo, function () use ($data, $user): int {
            $id = $this->rutas->crear($data, $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'ruta', $id, AccionBitacora::CREAR, ['despues' => $data]);
            return $id;
        });
    }

    public function actualizar(int $id, array $input, array $user): void
    {
        $actual = $this->rutas->find($id);
        if ($actual === null || (int) $actual['activo'] !== 1) {
            json_error('Ruta no encontrada', 404);
        }
        $data = $this->validar($input);
        tx($this->pdo, function () use ($id, $data, $actual, $user): void {
            $this->rutas->actualizar($id, $data);
            registrar_bitacora($this->pdo, $user['id'], 'ruta', $id, AccionBitacora::EDITAR, [
                'antes' => $this->snapshot($actual), 'despues' => $data,
            ]);
        });
    }

    public function eliminar(int $id, array $user): void
    {
        $actual = $this->rutas->find($id);
        if ($actual === null || (int) $actual['activo'] !== 1) {
            json_error('Ruta no encontrada', 404);
        }
        tx($this->pdo, function () use ($id, $user): void {
            $this->rutas->softDelete($id);
            registrar_bitacora($this->pdo, $user['id'], 'ruta', $id, AccionBitacora::ELIMINAR, [
                'antes' => ['activo' => 1], 'despues' => ['activo' => 0],
            ]);
        });
    }

    private function validar(array $input): array
    {
        $v = new Validator($input);
        $v->required('nombre', 'El nombre')->maxLen('nombre', 200, 'El nombre')
          ->required('pais_origen_id', 'El país de origen')->positiveInt('pais_origen_id', 'El país de origen')
          ->required('ciudad_origen', 'La ciudad de origen')->maxLen('ciudad_origen', 120, 'La ciudad de origen')
          ->required('pais_destino_id', 'El país de destino')->positiveInt('pais_destino_id', 'El país de destino')
          ->required('ciudad_destino', 'La ciudad de destino')->maxLen('ciudad_destino', 120, 'La ciudad de destino');
        $v->validateOrFail();

        $origen  = (int) $v->value('pais_origen_id');
        $destino = (int) $v->value('pais_destino_id');
        $validos = paises_ids_validos();
        if (!in_array($origen, $validos, true) || !in_array($destino, $validos, true)) {
            json_unprocessable(['pais_origen_id' => 'País de origen o destino inválido.']);
        }

        $distancia = $v->value('distancia_km');
        $horas     = $v->value('horas_transito_estimadas');

        return [
            'nombre'                   => $v->value('nombre'),
            'pais_origen_id'           => $origen,
            'ciudad_origen'            => $v->value('ciudad_origen'),
            'pais_destino_id'          => $destino,
            'ciudad_destino'           => $v->value('ciudad_destino'),
            'distancia_km'             => is_numeric($distancia) ? (float) $distancia : null,
            // tipo_ruta auto-derivado (plan §5.6): mismo país => NACIONAL.
            'tipo_ruta'                => $origen === $destino ? TipoRuta::NACIONAL : TipoRuta::INTERNACIONAL,
            'horas_transito_estimadas' => is_numeric($horas) ? (float) $horas : null,
        ];
    }

    private function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'nombre', 'pais_origen_id', 'ciudad_origen', 'pais_destino_id', 'ciudad_destino',
            'distancia_km', 'tipo_ruta', 'horas_transito_estimadas',
        ]));
    }
}
