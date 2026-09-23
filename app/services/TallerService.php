<?php
/**
 * Reglas de los talleres. Cada estación gestiona los suyos, y el nombre se compara normalizado
 * para que «K&C», «K & C» y «KyC» no acaben siendo tres talleres en los reportes.
 */

declare(strict_types=1);

final class TallerService
{
    public function __construct(private PDO $pdo, private TallerModel $talleres)
    {
    }

    public function crear(array $input, array $user): int
    {
        $data = $this->validado($input, $user, null);

        return tx($this->pdo, function () use ($data, $user): int {
            $id = $this->talleres->crear($data, $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'taller', $id, AccionBitacora::CREAR, ['despues' => $data]);
            return $id;
        });
    }

    public function editar(int $id, array $input, array $user): void
    {
        $actual = $this->taller($id, $user);
        // La estación no se cambia al editar: un taller que "se mueve" de estación dejaría el
        // historial diciendo que esas reparaciones se hicieron donde nunca se hicieron.
        $data = $this->validado($input + ['estacion_id' => $actual['estacion_id']], $user, $id);

        tx($this->pdo, function () use ($id, $actual, $data, $user): void {
            $this->talleres->actualizar($id, $data);
            registrar_bitacora($this->pdo, $user['id'], 'taller', $id, AccionBitacora::EDITAR, [
                'antes' => ['nombre' => $actual['nombre'], 'es_propio' => (int) $actual['es_propio']],
                'despues' => $data,
            ]);
        });
    }

    public function cambiarActivo(int $id, bool $activo, array $user): void
    {
        $actual = $this->taller($id, $user);
        tx($this->pdo, function () use ($id, $actual, $activo, $user): void {
            $this->talleres->setActivo($id, $activo);
            registrar_bitacora($this->pdo, $user['id'], 'taller', $id, AccionBitacora::EDITAR, [
                'antes' => ['activo' => (int) $actual['activo']], 'despues' => ['activo' => $activo ? 1 : 0],
            ]);
        });
    }

    /** El taller, comprobando que quien lo pide pueda escribir en su estación. */
    public function taller(int $id, array $user): array
    {
        $taller = $this->talleres->find($id);
        if ($taller === null) {
            json_error('Taller no encontrado', 404);
        }
        if (!can_write_station($user, (int) $taller['estacion_id'])) {
            json_error('No autorizado sobre esta estación', 403);
        }
        return $taller;
    }

    /**
     * Encuentra por nombre o crea. Lo usan la importación y el registro rápido desde el modal:
     * escribir el nombre de un taller nuevo no debería obligar a salir a otra pantalla.
     */
    public function encontrarOCrear(string $nombre, int $estacionId, ?int $usuarioId): ?int
    {
        $nombre = NombreCatalogo::mostrar($nombre);
        $clave = NombreCatalogo::clave($nombre);
        if ($clave === '') {
            return null;
        }
        $existente = $this->talleres->porClave($estacionId, $clave);
        if ($existente !== null) {
            if ((int) $existente['activo'] !== 1) {
                $this->talleres->setActivo((int) $existente['id'], true);
            }
            return (int) $existente['id'];
        }
        $id = $this->talleres->crear([
            'estacion_id' => $estacionId, 'nombre' => $nombre, 'clave' => $clave,
            'es_propio' => 0, 'telefonos' => null, 'notas' => null,
        ], $usuarioId);
        registrar_bitacora($this->pdo, $usuarioId, 'taller', $id, AccionBitacora::CREAR, [
            'despues' => ['nombre' => $nombre], 'origen' => 'registro de mantenimiento',
        ]);
        return $id;
    }

    /** @return array<string, mixed> */
    private function validado(array $input, array $user, ?int $exceptId): array
    {
        $estacionId = (int) ($input['estacion_id'] ?? 0);
        if ($estacionId <= 0) {
            $estacionId = (int) ($user['estacion_id'] ?? 0);
        }
        if ($estacionId <= 0) {
            json_unprocessable(['estacion_id' => 'Indica a qué estación pertenece el taller.']);
        }
        if (!can_write_station($user, $estacionId)) {
            json_error('No autorizado sobre esta estación', 403);
        }

        $nombre = NombreCatalogo::mostrar((string) ($input['nombre'] ?? ''));
        if ($nombre === '') {
            json_unprocessable(['nombre' => 'Escribe el nombre del taller.']);
        }
        if (mb_strlen($nombre) > 150) {
            json_unprocessable(['nombre' => 'El nombre no puede pasar de 150 caracteres.']);
        }
        $clave = NombreCatalogo::clave($nombre);
        $otro = $this->talleres->porClave($estacionId, $clave);
        if ($otro !== null && (int) $otro['id'] !== $exceptId) {
            json_unprocessable(['nombre' => "Esa estación ya tiene un taller «{$otro['nombre']}»."]);
        }

        return [
            'estacion_id' => $estacionId,
            'nombre' => $nombre,
            'clave' => $clave,
            'es_propio' => !empty($input['es_propio']) ? 1 : 0,
            'telefonos' => $this->nullable($input['telefonos'] ?? null, 255),
            'notas' => $this->nullable($input['notas'] ?? null, 255),
        ];
    }

    private function nullable(?string $valor, int $max): ?string
    {
        $valor = trim((string) $valor);
        return $valor === '' ? null : mb_substr($valor, 0, $max);
    }
}
