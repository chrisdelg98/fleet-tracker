<?php
/**
 * Reglas de negocio de Estaciones (plan §5.1, administración exclusiva del Admin Global).
 * timezone debe ser un identificador IANA válido; código único. Escritura en transacción
 * con bitácora. Sin borrado físico: se desactiva (activo = 0).
 */

declare(strict_types=1);

final class EstacionService
{
    public function __construct(private PDO $pdo, private EstacionModel $estaciones)
    {
    }

    public function crear(array $input, array $user): int
    {
        $data = $this->validar($input, null);
        return tx($this->pdo, function () use ($data, $user): int {
            $id = $this->estaciones->crear($data, $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'estacion', $id, AccionBitacora::CREAR, ['despues' => $data]);
            return $id;
        });
    }

    public function actualizar(int $id, array $input, array $user): void
    {
        $actual = $this->estaciones->find($id);
        if ($actual === null) {
            json_error('Estación no encontrada', 404);
        }
        $data = $this->validar($input, $id);
        tx($this->pdo, function () use ($id, $data, $actual, $user): void {
            $this->estaciones->actualizar($id, $data);
            registrar_bitacora($this->pdo, $user['id'], 'estacion', $id, AccionBitacora::EDITAR, [
                'antes' => $this->snapshot($actual), 'despues' => $data,
            ]);
        });
    }

    /** Activa/desactiva (no borra: el histórico y los usuarios la referencian). */
    public function cambiarActivo(int $id, bool $activo, array $user): void
    {
        $actual = $this->estaciones->find($id);
        if ($actual === null) {
            json_error('Estación no encontrada', 404);
        }
        tx($this->pdo, function () use ($id, $actual, $activo, $user): void {
            $this->estaciones->setActivo($id, $activo);
            registrar_bitacora($this->pdo, $user['id'], 'estacion', $id, AccionBitacora::EDITAR, [
                'antes' => ['activo' => (int) $actual['activo']], 'despues' => ['activo' => $activo ? 1 : 0],
            ]);
        });
    }

    private function validar(array $input, ?int $exceptId): array
    {
        $v = new Validator($input);
        $v->required('nombre', 'El nombre')->maxLen('nombre', 150, 'El nombre')
          ->required('codigo', 'El código')->maxLen('codigo', 10, 'El código')
          ->required('pais_id', 'El país')->positiveInt('pais_id', 'El país')
          ->required('timezone', 'La zona horaria')->maxLen('timezone', 64, 'La zona horaria');
        $v->validateOrFail();

        $codigo = strtoupper((string) $v->value('codigo'));
        if ($this->estaciones->codigoExiste($codigo, $exceptId)) {
            json_unprocessable(['codigo' => 'Ya existe una estación con ese código.']);
        }
        if (!in_array((int) $v->value('pais_id'), paises_ids_validos(), true)) {
            json_unprocessable(['pais_id' => 'El país seleccionado no existe.']);
        }
        if (!in_array($v->value('timezone'), timezone_identifiers_list(), true)) {
            json_unprocessable(['timezone' => 'La zona horaria no es un identificador IANA válido.']);
        }

        return [
            'nombre'   => $v->value('nombre'),
            'pais_id'  => (int) $v->value('pais_id'),
            'codigo'   => $codigo,
            'timezone' => $v->value('timezone'),
        ];
    }

    private function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip(['nombre', 'pais_id', 'codigo', 'timezone']));
    }
}
