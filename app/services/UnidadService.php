<?php
/**
 * Reglas de negocio de Unidades (plan §5.5, reglas §8: 13, 14, 17, 18).
 * - en_disponibilidad hereda su default de categorias_vehiculo.es_flota_operativa (editable).
 * - El estado del vehículo se cambia por un diálogo único: notas obligatorias si ≠ OPERATIVO,
 *   y EN_MANTENIMIENTO/INOPERATIVO abren un override automático que OPERATIVO cierra.
 * - Soft-delete (activo = false) para registros erróneos; el retiro real es estado DE_BAJA.
 * Toda escritura corre en transacción junto con su fila de bitácora (integridad #1/#5).
 */

declare(strict_types=1);

final class UnidadService
{
    public function __construct(
        private PDO $pdo,
        private UnidadModel $unidades,
        private OverrideModel $overrides,
        private CatalogoModel $catalogos,
        private ?NotificacionService $notificaciones = null
    ) {
    }

    /** Crea una unidad. Devuelve su id. Corta con 403/422 ante permiso/validación. */
    public function crear(array $input, array $user): int
    {
        $data = $this->validar($input, null);
        $this->assertPuedeEscribir($user, (int) $data['estacion_id']);

        return tx($this->pdo, function () use ($data, $input, $user): int {
            $id = $this->unidades->crear($data, $user['id']);
            $this->unidades->setPermisos($id, $this->permisosDe($input), $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'unidad', $id, AccionBitacora::CREAR, ['despues' => $data]);
            return $id;
        });
    }

    /** Actualiza los datos maestros de la unidad (NO el estado del vehículo: eso va por diálogo). */
    public function actualizar(int $id, array $input, array $user): void
    {
        $actual = $this->unidades->find($id);
        if ($actual === null || (int) $actual['activo'] !== 1) {
            json_error('Unidad no encontrada', 404);
        }
        $this->assertPuedeEscribir($user, (int) $actual['estacion_id']);

        $data = $this->validar($input, $id);
        $this->assertPuedeEscribir($user, (int) $data['estacion_id']); // no puede moverla a una estación ajena

        // El estado del vehículo y sus notas se conservan; se cambian solo por el diálogo dedicado.
        $data['estado_vehiculo'] = $actual['estado_vehiculo'];
        $data['estado_notas']    = $actual['estado_notas'];

        tx($this->pdo, function () use ($id, $data, $input, $actual, $user): void {
            $this->unidades->actualizar($id, $data);
            $this->unidades->setPermisos($id, $this->permisosDe($input), $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'unidad', $id, AccionBitacora::EDITAR, [
                'antes'   => $this->snapshot($actual),
                'despues' => $data,
            ]);
        });
    }

    /**
     * Cambia el estado del vehículo (diálogo poka-yoke). notas obligatorias si ≠ OPERATIVO.
     * Gestiona el override automático (regla 18) solo para flota operativa (regla 13).
     */
    public function cambiarEstado(int $id, array $input, array $user): void
    {
        $unidad = $this->unidades->find($id);
        if ($unidad === null || (int) $unidad['activo'] !== 1) {
            json_error('Unidad no encontrada', 404);
        }
        $this->assertPuedeEscribir($user, (int) $unidad['estacion_id']);

        $v = new Validator($input);
        $v->required('estado_vehiculo', 'El estado')
          ->inEnum('estado_vehiculo', EstadoVehiculo::values(), 'El estado');
        $nuevo = $v->value('estado_vehiculo');
        $notas = $v->value('estado_notas');

        if ($nuevo !== null && in_array($nuevo, EstadoVehiculo::REQUIERE_NOTAS, true) && ($notas === null || $notas === '')) {
            $v->addError('estado_notas', 'Las notas son obligatorias cuando el vehículo no está operativo.');
        }
        $v->validateOrFail();

        $esOperativo = $nuevo === EstadoVehiculo::OPERATIVO;
        $notasFinal  = $esOperativo ? null : $notas; // al volver a OPERATIVO se limpia (queda en bitácora)

        tx($this->pdo, function () use ($id, $unidad, $nuevo, $notasFinal, $notas, $esOperativo, $user): void {
            $this->unidades->actualizarEstado($id, $nuevo, $notasFinal);

            if ($esOperativo) {
                $this->overrides->cerrarAutomaticosAbiertos($id);
            } elseif ((int) $unidad['en_disponibilidad'] === 1
                && in_array($nuevo, EstadoVehiculo::GENERA_OVERRIDE, true)
                && !$this->overrides->tieneAutomaticoAbierto($id)) {
                $this->overrides->abrir($id, TipoOverride::EN_TALLER, OrigenOverride::AUTO_ESTADO, (string) $notas, $user['id']);
            }

            registrar_bitacora($this->pdo, $user['id'], 'unidad', $id, AccionBitacora::CAMBIO_ESTADO, [
                'antes'   => ['estado_vehiculo' => $unidad['estado_vehiculo'], 'estado_notas' => $unidad['estado_notas']],
                'despues' => ['estado_vehiculo' => $nuevo, 'estado_notas' => $notasFinal],
            ]);
        });

        if ($esOperativo && (int) $unidad['en_disponibilidad'] === 1) {
            $this->notificaciones?->notificarUnidadLiberadaPorUnidad($id);
        }
    }

    /** Soft-delete (registros erróneos/duplicados). El retiro real es estado DE_BAJA. */
    public function eliminar(int $id, array $user): void
    {
        $unidad = $this->unidades->find($id);
        if ($unidad === null || (int) $unidad['activo'] !== 1) {
            json_error('Unidad no encontrada', 404);
        }
        $this->assertPuedeEscribir($user, (int) $unidad['estacion_id']);

        tx($this->pdo, function () use ($id, $unidad, $user): void {
            $this->unidades->softDelete($id);
            registrar_bitacora($this->pdo, $user['id'], 'unidad', $id, AccionBitacora::ELIMINAR, [
                'antes' => ['activo' => 1], 'despues' => ['activo' => 0],
            ]);
        });
    }

    /** Lista para la tabla de Flota. El encargado ve su estación; el admin puede filtrar. */
    public function listar(array $user, ?int $estacionFiltro = null, array $filtros = []): array
    {
        $estacion = $user['rol'] === Rol::ADMIN_GLOBAL ? $estacionFiltro : (int) $user['estacion_id'];
        return $this->unidades->listar($estacion, $filtros);
    }

    // ── Internos ──

    /** Valida y normaliza el input; resuelve el default de en_disponibilidad. Corta 422 si falla. */
    private function validar(array $input, ?int $exceptId): array
    {
        $v = new Validator($input);
        $v->required('placa_unidad', 'La placa')->maxLen('placa_unidad', 30, 'La placa')
          ->maxLen('placa_furgon', 30, 'La placa del furgón')
          ->maxLen('marca', 80, 'La marca')->maxLen('modelo', 80, 'El modelo')
          ->positiveInt('capacidad_id', 'La capacidad')
          ->required('categoria_vehiculo_id', 'La categoría')->positiveInt('categoria_vehiculo_id', 'La categoría')
          ->required('estacion_id', 'La estación')->positiveInt('estacion_id', 'La estación')
          ->positiveInt('tipo_equipo_id', 'El tipo de equipo')
          ->positiveInt('piloto_asignado_id', 'El piloto')
          ->inEnum('estado_vehiculo', EstadoVehiculo::values(), 'El estado');
        $v->validateOrFail();

        $placa = $v->value('placa_unidad');
        if ($this->unidades->placaExiste($placa, $exceptId)) {
            json_unprocessable(['placa_unidad' => 'Ya existe una unidad con esa placa.']);
        }

        $categoria = $this->catalogos->find('categorias_vehiculo', (int) $v->value('categoria_vehiculo_id'));
        if ($categoria === null) {
            json_unprocessable(['categoria_vehiculo_id' => 'La categoría seleccionada no existe.']);
        }

        // placa_furgon obligatoria si la categoría jala furgón (ej. Cabezal).
        if ((int) $categoria['requiere_furgon'] === 1 && $this->nullable($v->value('placa_furgon')) === null) {
            json_unprocessable(['placa_furgon' => 'Esta categoría requiere la placa del furgón.']);
        }

        $capacidadId = $v->value('capacidad_id') ? (int) $v->value('capacidad_id') : null;
        if ($capacidadId !== null && $this->catalogos->find('capacidades', $capacidadId) === null) {
            json_unprocessable(['capacidad_id' => 'La capacidad seleccionada no existe.']);
        }

        // en_disponibilidad: si el formulario lo envía, se respeta (excepción editable);
        // si no, hereda el default de la categoría (regla 14).
        $enDisponibilidad = array_key_exists('en_disponibilidad', $input)
            ? (int) (bool) $input['en_disponibilidad']
            : (int) $categoria['es_flota_operativa'];

        $estado = $v->value('estado_vehiculo') ?: EstadoVehiculo::OPERATIVO;

        return [
            'placa_unidad'          => $placa,
            'placa_furgon'          => $this->nullable($v->value('placa_furgon')),
            'marca'                 => $this->nullable($v->value('marca')),
            'modelo'                => $this->nullable($v->value('modelo')),
            'categoria_vehiculo_id' => (int) $v->value('categoria_vehiculo_id'),
            'en_disponibilidad'     => $enDisponibilidad,
            'capacidad_id'          => $capacidadId,
            'tipo_equipo_id'        => $v->value('tipo_equipo_id') ? (int) $v->value('tipo_equipo_id') : $this->tipoEquipoStandardId(),
            'estacion_id'           => (int) $v->value('estacion_id'),
            'piloto_asignado_id'    => $v->value('piloto_asignado_id') ? (int) $v->value('piloto_asignado_id') : null,
            'estado_vehiculo'       => $estado,
            'estado_notas'          => $this->nullable($v->value('estado_notas')),
        ];
    }

    /** @return int[] */
    private function permisosDe(array $input): array
    {
        $permisos = $input['permisos'] ?? [];
        return is_array($permisos) ? array_map('intval', $permisos) : [];
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
            'placa_unidad', 'placa_furgon', 'marca', 'modelo', 'categoria_vehiculo_id',
            'en_disponibilidad', 'capacidad_id', 'tipo_equipo_id', 'estacion_id', 'piloto_asignado_id',
        ]));
    }

    private function nullable(?string $v): ?string
    {
        return $v === null || $v === '' ? null : $v;
    }

    /** ID del tipo de equipo "Standard": default cuando el formulario lo deja vacío. Null si no existe. */
    private function tipoEquipoStandardId(): ?int
    {
        $id = $this->pdo->query("SELECT id FROM tipos_equipo WHERE nombre = 'Standard' AND activo = 1 LIMIT 1")->fetchColumn();
        return $id !== false ? (int) $id : null;
    }
}
