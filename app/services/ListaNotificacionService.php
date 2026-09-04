<?php
/**
 * Reglas de las listas de notificación.
 *
 * A diferencia de las rutas, que son compartidas, una lista pertenece a una estación: los
 * contactos de El Salvador no le sirven a Guatemala. El Admin Global además puede crear
 * listas corporativas (sin estación) que ven todos.
 *
 * Los correos se guardan normalizados —sin espacios ni duplicados— y se validan uno a uno:
 * con ocho direcciones en una celda, "revisa el formato" no ayuda a encontrar la mala.
 */

declare(strict_types=1);

final class ListaNotificacionService
{
    public function __construct(private PDO $pdo, private ListaNotificacionModel $listas)
    {
    }

    /** Estación a la que se limita el usuario, o null si ve todas. */
    public function alcance(array $user): ?int
    {
        return $user['rol'] === Rol::ADMIN_GLOBAL ? null : (int) $user['estacion_id'];
    }

    public function listar(array $user, ?string $q = null): array
    {
        return $this->listas->listar($this->alcance($user), $q);
    }

    public function crear(array $input, array $user): int
    {
        $data = $this->validar($input, $user, null);

        return tx($this->pdo, function () use ($data, $user): int {
            $id = $this->listas->crear($data, $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'lista_notificacion', $id, AccionBitacora::CREAR, ['despues' => $data]);
            return $id;
        });
    }

    public function actualizar(int $id, array $input, array $user): void
    {
        $actual = $this->listaPropia($id, $user);
        $data = $this->validar($input, $user, $id);

        tx($this->pdo, function () use ($id, $data, $actual, $user): void {
            $this->listas->actualizar($id, $data);
            registrar_bitacora($this->pdo, $user['id'], 'lista_notificacion', $id, AccionBitacora::EDITAR, [
                'antes' => $this->snapshot($actual), 'despues' => $data,
            ]);
        });
    }

    public function eliminar(int $id, array $user): void
    {
        $actual = $this->listaPropia($id, $user);

        tx($this->pdo, function () use ($id, $actual, $user): void {
            $this->listas->softDelete($id);
            registrar_bitacora($this->pdo, $user['id'], 'lista_notificacion', $id, AccionBitacora::ELIMINAR, [
                'antes' => $this->snapshot($actual), 'despues' => ['activo' => 0],
            ]);
        });
    }

    // ── Internos ──

    /** La lista existe y el usuario puede tocarla; si no, corta con 404/403. */
    private function listaPropia(int $id, array $user): array
    {
        $lista = $this->listas->find($id);
        if ($lista === null || (int) $lista['activo'] !== 1) {
            json_error('Lista no encontrada', 404);
        }
        // Una lista corporativa (sin estación) solo la toca el Admin Global: la comparten
        // todas las estaciones y no puede editarla quien solo responde por la suya.
        if ($lista['estacion_id'] === null) {
            if ($user['rol'] !== Rol::ADMIN_GLOBAL) {
                json_error('Solo un administrador global edita las listas corporativas', 403);
            }
        } elseif (!can_write_station($user, (int) $lista['estacion_id'])) {
            json_error('No autorizado sobre esta estación', 403);
        }
        return $lista;
    }

    private function validar(array $input, array $user, ?int $exceptId): array
    {
        $v = new Validator($input);
        $v->required('nombre', 'El nombre')->maxLen('nombre', 100, 'El nombre')
          ->required('correos', 'Los correos')->maxLen('correos', 500, 'Los correos');
        $v->validateOrFail();

        $errores = [];
        $malos = CatalogoAdminService::correosInvalidos((string) $v->value('correos'));
        if ($malos !== []) {
            $errores['correos'] = 'No parecen correos válidos: ' . implode(', ', $malos) . '.';
        }

        // El encargado siempre crea en su estación; el admin elige, y sin elegir es corporativa.
        $estacionId = $this->alcance($user);
        if ($estacionId === null) {
            $pedida = trim((string) ($input['estacion_id'] ?? ''));
            $estacionId = $pedida !== '' ? (int) $pedida : null;
        }

        $nombre = (string) $v->value('nombre');
        if ($errores === [] && $this->listas->nombreRepetido($estacionId, $nombre, $exceptId)) {
            $errores['nombre'] = 'Ya existe una lista con ese nombre en esta estación.';
        }
        if ($errores !== []) {
            json_unprocessable($errores);
        }

        return [
            'estacion_id' => $estacionId,
            'nombre'      => $nombre,
            'correos'     => implode(', ', CatalogoAdminService::correos((string) $v->value('correos'))),
        ];
    }

    private function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip(['estacion_id', 'nombre', 'correos']));
    }
}
