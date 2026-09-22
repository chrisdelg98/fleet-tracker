<?php
/**
 * Reglas de negocio de Usuarios (plan §4, §5.2, administración exclusiva del Admin Global).
 * - Coherencia rol/estación: ADMIN_GLOBAL y CONSULTA_REGIONAL sin estación; el resto con estación.
 * - Contraseña con password_hash; obligatoria al crear, opcional al editar. Nunca se registra
 *   en bitácora ni se devuelve al cliente.
 * Escritura en transacción con bitácora.
 */

declare(strict_types=1);

final class UsuarioService
{
    public function __construct(
        private PDO $pdo,
        private UsuarioModel $usuarios,
        private EstacionModel $estaciones
    ) {
    }

    public function crear(array $input, array $user): int
    {
        $data = $this->validar($input, null, true);
        $hash = password_hash((string) $input['password'], PASSWORD_DEFAULT);

        return tx($this->pdo, function () use ($data, $hash, $user): int {
            $id = $this->usuarios->crear($data, $hash, $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'usuario', $id, AccionBitacora::CREAR, [
                'despues' => $data, // sin contraseña
            ]);
            return $id;
        });
    }

    public function actualizar(int $id, array $input, array $user): void
    {
        $actual = $this->usuarios->findById($id);
        if ($actual === null) {
            json_error('Usuario no encontrado', 404);
        }
        $nuevaPassword = trim((string) ($input['password'] ?? ''));
        $data = $this->validar($input, $id, false);
        $this->assertQuedaQuienAdministre($id, $actual, $data['rol'], (int) $actual['activo'] === 1, $user);

        tx($this->pdo, function () use ($id, $data, $nuevaPassword, $actual, $user): void {
            $this->usuarios->actualizar($id, $data);
            if ($nuevaPassword !== '') {
                $this->usuarios->actualizarPassword($id, password_hash($nuevaPassword, PASSWORD_DEFAULT));
            }
            registrar_bitacora($this->pdo, $user['id'], 'usuario', $id, AccionBitacora::EDITAR, [
                'antes'   => $this->snapshot($actual),
                'despues' => $data + ['password_cambiada' => $nuevaPassword !== ''],
            ]);
        });
    }

    public function cambiarActivo(int $id, bool $activo, array $user): void
    {
        $actual = $this->usuarios->findById($id);
        if ($actual === null) {
            json_error('Usuario no encontrado', 404);
        }
        if ($id === (int) $user['id'] && !$activo) {
            json_error('No puedes desactivar tu propia cuenta', 422);
        }
        $this->assertQuedaQuienAdministre($id, $actual, (string) $actual['rol'], $activo, $user);
        tx($this->pdo, function () use ($id, $actual, $activo, $user): void {
            $this->usuarios->setActivo($id, $activo);
            registrar_bitacora($this->pdo, $user['id'], 'usuario', $id, AccionBitacora::EDITAR, [
                'antes' => ['activo' => (int) $actual['activo']], 'despues' => ['activo' => $activo ? 1 : 0],
            ]);
        });
    }

    /**
     * Nadie puede dejar al sistema sin Admin Global, ni quitarse a sí mismo la administración.
     *
     * La cuenta propia se protege aparte del recuento: aunque queden otros administradores,
     * degradarse a uno mismo por accidente deja fuera de Administración a quien está trabajando,
     * y recuperarlo exige que otro lo devuelva.
     */
    private function assertQuedaQuienAdministre(int $id, array $actual, string $rolNuevo, bool $seguiraActivo, array $user): void
    {
        $eraAdmin = $actual['rol'] === Rol::ADMIN_GLOBAL && (int) $actual['activo'] === 1;
        $seguiraAdmin = $rolNuevo === Rol::ADMIN_GLOBAL && $seguiraActivo;
        if (!$eraAdmin || $seguiraAdmin) {
            return;
        }
        if ($id === (int) $user['id']) {
            json_unprocessable(['rol' => 'No puedes quitarte a ti mismo el rol de Admin Global. Pídeselo a otro administrador.']);
        }
        if ($this->usuarios->adminsActivosSalvo($id) === 0) {
            json_unprocessable(['rol' => 'Es el único Admin Global activo: si le quitas el rol, nadie podría administrar el sistema.']);
        }
    }

    private function validar(array $input, ?int $exceptId, bool $creando): array
    {
        $v = new Validator($input);
        $v->required('nombre', 'El nombre')->maxLen('nombre', 150, 'El nombre')
          ->required('email', 'El correo')->maxLen('email', 190, 'El correo')
          ->required('rol', 'El rol')->inEnum('rol', Rol::values(), 'El rol');
        if ($creando) {
            $v->required('password', 'La contraseña');
        }
        $v->validateOrFail();

        $email = mb_strtolower((string) $v->value('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_unprocessable(['email' => 'El correo no tiene un formato válido.']);
        }
        if ($this->usuarios->emailExiste($email, $exceptId)) {
            json_unprocessable(['email' => 'Ya existe un usuario con ese correo.']);
        }

        $rol = $v->value('rol');
        $sinEstacion = in_array($rol, Rol::SIN_ESTACION, true);
        $estacionId = $v->value('estacion_id');

        // Coherencia rol/estación (plan §4).
        if ($sinEstacion) {
            $estacionId = null;
        } else {
            if ($estacionId === null || $estacionId === '' || !ctype_digit((string) $estacionId)) {
                json_unprocessable(['estacion_id' => 'Este rol requiere una estación.']);
            }
            $estacionId = (int) $estacionId;
            if ($this->estaciones->find($estacionId) === null) {
                json_unprocessable(['estacion_id' => 'La estación seleccionada no existe.']);
            }
        }

        return [
            'nombre'      => $v->value('nombre'),
            'email'       => $email,
            'rol'         => $rol,
            'estacion_id' => $estacionId,
        ];
    }

    private function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip(['nombre', 'email', 'rol', 'estacion_id', 'activo']));
    }
}
