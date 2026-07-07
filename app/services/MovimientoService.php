<?php
/**
 * Motor de movimientos (plan §5.7, §6, reglas §8). Contiene la máquina de estados, la
 * validación de no-traslape (dentro de transacción con bloqueo) y las conversiones de
 * fecha local↔UTC. Toda escritura corre en transacción con su fila de bitácora.
 *
 * Máquina de estados (§6): RESERVADO → PROGRAMADO → EN_TRANSITO → COMPLETADO; desde
 * cualquier activo se puede CANCELAR (motivo obligatorio). COMPLETADO/CANCELADO son finales.
 */

declare(strict_types=1);

final class MovimientoService
{
    public function __construct(
        private PDO $pdo,
        private MovimientoModel $movimientos,
        private UnidadModel $unidades,
        private RutaModel $rutas,
        private PilotoModel $pilotos,
        private ?NotificacionService $notificaciones = null
    ) {
    }

    /** Crea un movimiento/reserva. Corta con 403/422/409 según permiso, validación o traslape. */
    public function crear(array $input, array $user): int
    {
        $unidad = $this->unidadParaMovimiento((int) ($input['unidad_id'] ?? 0), $user);
        $tz = $this->estacionTz((int) $unidad['estacion_id']);

        $estado = $input['estado'] ?? EstadoMovimiento::RESERVADO;
        if (!in_array($estado, [EstadoMovimiento::RESERVADO, EstadoMovimiento::PROGRAMADO], true)) {
            json_unprocessable(['estado' => 'Un movimiento se crea como RESERVADO o PROGRAMADO.']);
        }

        $data = $this->validarPlan($input, $tz) + [
            'unidad_id'      => (int) $unidad['id'],
            'estado'         => $estado,
            'piloto_id'      => $this->pilotoOpcional($input, $unidad),
        ];

        $id = tx($this->pdo, function () use ($data, $user, $tz): int {
            $this->assertSinTraslape((int) $data['unidad_id'], $data['fecha_salida'], $data['fecha_fin_estimada'], null, $tz);
            $id = $this->movimientos->crear($data, $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $id, AccionBitacora::CREAR, ['despues' => $data]);
            return $id;
        });

        if ((int) $data['retorno_disponible'] === 1) {
            $this->notificaciones?->notificarRetornoDisponible($id);
        }
        return $id;
    }

    /**
     * Traslapes de un rango propuesto para una unidad — solo lectura, para el aviso en vivo
     * del formulario. No lanza si faltan/están mal los datos: devuelve [] (sin aviso).
     *
     * @return array<int, array{id:int, estado:string, desde:string, hasta:string}>
     */
    public function conflictosPropuestos(array $q): array
    {
        $unidadId = (int) ($q['unidad_id'] ?? 0);
        $salida = trim((string) ($q['fecha_salida'] ?? ''));
        $fin = trim((string) ($q['fecha_fin_estimada'] ?? ''));
        if ($unidadId <= 0 || $salida === '' || $fin === '') {
            return [];
        }
        $unidad = $this->unidades->find($unidadId);
        if ($unidad === null) {
            return [];
        }
        $tz = $this->estacionTz((int) $unidad['estacion_id']);
        try {
            $salidaUtc = local_to_utc($salida, $tz)->format('Y-m-d H:i:s');
            $finUtc = local_to_utc($fin, $tz)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return [];
        }
        if ($finUtc <= $salidaUtc) {
            return [];
        }
        $exceptId = isset($q['except_id']) && $q['except_id'] !== '' ? (int) $q['except_id'] : null;

        return array_map(static fn(array $c): array => [
            'id'     => (int) $c['id'],
            'estado' => (string) $c['estado'],
            'desde'  => format_local($c['fecha_salida'], $tz, 'd M H:i'),
            'hasta'  => format_local($c['fecha_fin_estimada'], $tz, 'd M H:i'),
        ], $this->movimientos->conflictos($unidadId, $salidaUtc, $finUtc, $exceptId));
    }

    /** Edita el plan (ruta/fechas/flags) de un movimiento aún activo; re-valida no-traslape. */
    public function editar(int $id, array $input, array $user): void
    {
        $mov = $this->cargarActivo($id, $user);
        $unidad = $this->unidades->find((int) $mov['unidad_id']);
        $tz = $this->estacionTz((int) $unidad['estacion_id']);

        $data = $this->validarPlan($input, $tz) + [
            'estado'    => $mov['estado'],
            'piloto_id' => $mov['piloto_id'],
        ];

        tx($this->pdo, function () use ($id, $mov, $data, $tz, $user): void {
            $this->assertSinTraslape((int) $mov['unidad_id'], $data['fecha_salida'], $data['fecha_fin_estimada'], $id, $tz);
            $this->movimientos->actualizarPlan($id, $data);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $id, AccionBitacora::EDITAR, [
                'antes'   => $this->snapshot($mov),
                'despues' => $data,
            ]);
        });

        if ((int) $data['retorno_disponible'] === 1) {
            $this->notificaciones?->notificarRetornoDisponible($id);
        }
    }

    /** RESERVADO → PROGRAMADO. */
    public function confirmar(int $id, array $user): void
    {
        $this->transicion($id, $user, EstadoMovimiento::RESERVADO, EstadoMovimiento::PROGRAMADO);
    }

    /** PROGRAMADO → EN_TRANSITO (requiere piloto, regla 11). */
    public function marcarSalida(int $id, array $input, array $user): void
    {
        $mov = $this->cargarActivo($id, $user);
        if ($mov['estado'] !== EstadoMovimiento::PROGRAMADO) {
            json_error('Solo un movimiento PROGRAMADO puede marcar salida.', 409);
        }

        $pilotoId = $mov['piloto_id'] ?? ($input['piloto_id'] ?? null);
        if (empty($pilotoId)) {
            json_unprocessable(['piloto_id' => 'Debes asignar un piloto para marcar la salida.']);
        }
        $estacion = $this->unidadEstacion((int) $mov['unidad_id']);
        $piloto = $this->pilotos->find((int) $pilotoId);
        if ($piloto === null || (int) $piloto['activo'] !== 1 || (int) $piloto['estacion_id'] !== $estacion) {
            json_unprocessable(['piloto_id' => 'El piloto no es válido para esta unidad.']);
        }

        tx($this->pdo, function () use ($id, $mov, $pilotoId, $user): void {
            $this->movimientos->cambiarEstado($id, EstadoMovimiento::EN_TRANSITO, ['piloto_id' => (int) $pilotoId]);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $id, AccionBitacora::CAMBIO_ESTADO, [
                'antes'   => ['estado' => $mov['estado']],
                'despues' => ['estado' => EstadoMovimiento::EN_TRANSITO, 'piloto_id' => (int) $pilotoId],
            ]);
        });
    }

    /** EN_TRANSITO → COMPLETADO (fecha_fin_real = ahora UTC). */
    public function marcarLlegada(int $id, array $user): void
    {
        $mov = $this->cargarActivo($id, $user);
        if ($mov['estado'] !== EstadoMovimiento::EN_TRANSITO) {
            json_error('Solo un movimiento EN_TRANSITO puede marcar llegada.', 409);
        }
        $ahora = now_utc();
        tx($this->pdo, function () use ($id, $mov, $ahora, $user): void {
            $this->movimientos->cambiarEstado($id, EstadoMovimiento::COMPLETADO, ['fecha_fin_real' => $ahora]);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $id, AccionBitacora::CAMBIO_ESTADO, [
                'antes'   => ['estado' => $mov['estado']],
                'despues' => ['estado' => EstadoMovimiento::COMPLETADO, 'fecha_fin_real' => $ahora],
            ]);
        });

        $this->notificaciones?->notificarUnidadLiberadaPorUnidad((int) $mov['unidad_id']);
    }

    /** Activo → CANCELADO (motivo obligatorio, regla 6). */
    public function cancelar(int $id, array $input, array $user): void
    {
        $mov = $this->cargarActivo($id, $user);
        $motivo = trim((string) ($input['motivo_cancelacion'] ?? ''));
        if ($motivo === '') {
            json_unprocessable(['motivo_cancelacion' => 'El motivo de cancelación es obligatorio.']);
        }
        tx($this->pdo, function () use ($id, $mov, $motivo, $user): void {
            $this->movimientos->cambiarEstado($id, EstadoMovimiento::CANCELADO, ['motivo_cancelacion' => $motivo]);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $id, AccionBitacora::CANCELAR, [
                'antes'   => ['estado' => $mov['estado']],
                'despues' => ['estado' => EstadoMovimiento::CANCELADO, 'motivo_cancelacion' => $motivo],
            ]);
        });
    }

    /**
     * Aparta el retorno de un movimiento de ida (plan §6, regla 8): registra el país que lo
     * toma en la ida y crea un NUEVO movimiento de regreso (destino → origen) sobre la misma
     * unidad, sujeto a la validación de no-traslape. Todo en una transacción.
     */
    public function apartarRetorno(int $idIda, array $input, array $user): int
    {
        $ida = $this->movimientos->find($idIda);
        if ($ida === null) {
            json_error('Movimiento no encontrado', 404);
        }
        if ((int) $ida['retorno_disponible'] !== 1) {
            json_unprocessable(['retorno' => 'Este movimiento no ofrece retorno disponible.']);
        }
        if ($ida['pais_solicita_retorno_id'] !== null) {
            json_error('El retorno ya fue apartado por otra estación.', 409);
        }

        $unidad = $this->unidades->find((int) $ida['unidad_id']);
        $this->assertPuedeEscribir($user, (int) $unidad['estacion_id']);
        $tz = $this->estacionTz((int) $unidad['estacion_id']);

        // Quién apartó el retorno: por defecto, el país destino de la ida (donde está el equipo).
        $paisSolicita = !empty($input['pais_solicita_retorno_id'])
            ? (int) $input['pais_solicita_retorno_id']
            : (int) $ida['pais_destino_id'];
        if (!in_array($paisSolicita, paises_ids_validos(), true)) {
            json_unprocessable(['pais_solicita_retorno_id' => 'País solicitante inválido.']);
        }

        $plan = $this->validarPlan([
            'fecha_salida'       => $input['fecha_salida'] ?? null,
            'fecha_fin_estimada' => $input['fecha_fin_estimada'] ?? null,
            'pais_origen_id'     => (int) $ida['pais_destino_id'], // el equipo regresa desde el destino
            'pais_destino_id'    => (int) $ida['pais_origen_id'],  // hacia el origen
            'referencia_cw'      => $input['referencia_cw'] ?? null,
            'reservado_para'     => $input['reservado_para'] ?? null,
            'notas'              => $input['notas'] ?? ("Retorno del movimiento #{$idIda}"),
        ], $tz);

        $regreso = $plan + [
            'unidad_id' => (int) $ida['unidad_id'],
            'estado'    => EstadoMovimiento::RESERVADO,
            'piloto_id' => null,
        ];

        return tx($this->pdo, function () use ($regreso, $idIda, $paisSolicita, $user, $tz): int {
            $this->assertSinTraslape((int) $regreso['unidad_id'], $regreso['fecha_salida'], $regreso['fecha_fin_estimada'], null, $tz);
            $idRegreso = $this->movimientos->crear($regreso, $user['id']);
            $this->movimientos->marcarRetornoTomado($idIda, $paisSolicita);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $idIda, AccionBitacora::EDITAR, [
                'despues' => ['pais_solicita_retorno_id' => $paisSolicita, 'movimiento_regreso' => $idRegreso],
            ]);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $idRegreso, AccionBitacora::CREAR, [
                'despues' => ['retorno_de' => $idIda] + $regreso,
            ]);
            return $idRegreso;
        });
    }

    // ── Internos ──

    private function transicion(int $id, array $user, string $desde, string $hacia): void
    {
        $mov = $this->cargarActivo($id, $user);
        if ($mov['estado'] !== $desde) {
            json_error("Transición no válida desde el estado actual ({$mov['estado']}).", 409);
        }
        tx($this->pdo, function () use ($id, $mov, $hacia, $user): void {
            $this->movimientos->cambiarEstado($id, $hacia);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $id, AccionBitacora::CAMBIO_ESTADO, [
                'antes'   => ['estado' => $mov['estado']],
                'despues' => ['estado' => $hacia],
            ]);
        });
    }

    /** Carga un movimiento que debe estar en un estado activo (no final) y autoriza estación. */
    private function cargarActivo(int $id, array $user): array
    {
        $mov = $this->movimientos->find($id);
        if ($mov === null) {
            json_error('Movimiento no encontrado', 404);
        }
        if (in_array($mov['estado'], EstadoMovimiento::FINALES, true)) {
            json_error('El movimiento está en un estado final e inmutable.', 409);
        }
        $this->assertPuedeEscribir($user, $this->unidadEstacion((int) $mov['unidad_id']));
        return $mov;
    }

    /** Valida la unidad destino del movimiento (existe, operable, propia estación). */
    private function unidadParaMovimiento(int $unidadId, array $user): array
    {
        $unidad = $this->unidades->find($unidadId);
        if ($unidad === null || (int) $unidad['activo'] !== 1) {
            json_unprocessable(['unidad_id' => 'La unidad no existe.']);
        }
        $this->assertPuedeEscribir($user, (int) $unidad['estacion_id']);
        if ((int) $unidad['en_disponibilidad'] !== 1) {
            json_unprocessable(['unidad_id' => 'La unidad es solo inventario; no admite movimientos (regla 13).']);
        }
        if ($unidad['estado_vehiculo'] === EstadoVehiculo::DE_BAJA) {
            json_unprocessable(['unidad_id' => 'La unidad está DE_BAJA; no admite movimientos.']);
        }
        return $unidad;
    }

    /** Valida ruta/fechas y devuelve el plan normalizado (fechas ya en UTC). */
    private function validarPlan(array $input, string $tz): array
    {
        $v = new Validator($input);
        $v->required('fecha_salida', 'La fecha de salida')
          ->required('fecha_fin_estimada', 'La fecha de liberación')
          ->maxLen('referencia_cw', 120, 'La referencia CW')
          ->maxLen('reservado_para', 150, 'El campo reservado para');
        $v->validateOrFail();

        $salidaUtc = $this->aUtc($v->value('fecha_salida'), $tz, 'fecha_salida');
        $finUtc    = $this->aUtc($v->value('fecha_fin_estimada'), $tz, 'fecha_fin_estimada');
        if ($finUtc <= $salidaUtc) {
            json_unprocessable(['fecha_fin_estimada' => 'La liberación debe ser posterior a la salida.']);
        }

        // Ruta: de catálogo (copia países/tipo) o personalizada (países explícitos).
        $rutaId = $v->value('ruta_id');
        if ($rutaId !== null && $rutaId !== '') {
            $ruta = $this->rutas->find((int) $rutaId);
            if ($ruta === null || (int) $ruta['activo'] !== 1) {
                json_unprocessable(['ruta_id' => 'La ruta seleccionada no existe.']);
            }
            $paisOrigen  = (int) $ruta['pais_origen_id'];
            $paisDestino = (int) $ruta['pais_destino_id'];
            $custOrigen = $custDestino = null;
        } else {
            $custOrigen  = $this->nullable($v->value('ruta_custom_origen'));
            $custDestino = $this->nullable($v->value('ruta_custom_destino'));
            $paisOrigen  = (int) $v->value('pais_origen_id');
            $paisDestino = (int) $v->value('pais_destino_id');
            $validos = paises_ids_validos();
            if (!in_array($paisOrigen, $validos, true) || !in_array($paisDestino, $validos, true)) {
                json_unprocessable(['pais_origen_id' => 'Indica país de origen y destino válidos, o elige una ruta del catálogo.']);
            }
        }

        return [
            'ruta_id'             => $rutaId !== null && $rutaId !== '' ? (int) $rutaId : null,
            'ruta_custom_origen'  => $custOrigen ?? null,
            'ruta_custom_destino' => $custDestino ?? null,
            'pais_origen_id'      => $paisOrigen,
            'pais_destino_id'     => $paisDestino,
            'tipo_ruta'           => $paisOrigen === $paisDestino ? TipoRuta::NACIONAL : TipoRuta::INTERNACIONAL,
            'fecha_salida'        => $salidaUtc,
            'fecha_fin_estimada'  => $finUtc,
            'referencia_cw'       => $this->nullable($v->value('referencia_cw')),
            'retorno_disponible'  => array_key_exists('retorno_disponible', $input) ? (int) (bool) $input['retorno_disponible'] : 0,
            'reservado_para'      => $this->nullable($v->value('reservado_para')),
            'notas'               => $this->nullable($v->value('notas')),
        ];
    }

    /** Corta con 409 si el rango se traslapa con otro movimiento activo de la unidad. */
    private function assertSinTraslape(int $unidadId, string $salidaUtc, string $finUtc, ?int $exceptId, string $tz): void
    {
        $conflictos = $this->movimientos->conflictos($unidadId, $salidaUtc, $finUtc, $exceptId);
        if ($conflictos === []) {
            return;
        }
        $c = $conflictos[0];
        $desde = format_local($c['fecha_salida'], $tz, 'd M H:i');
        $hasta = format_local($c['fecha_fin_estimada'], $tz, 'd M H:i');
        json_error(
            "La unidad ya tiene un movimiento {$c['estado']} del {$desde} al {$hasta} (mov. #{$c['id']}).",
            409,
            "Traslape con el movimiento #{$c['id']}."
        );
    }

    private function pilotoOpcional(array $input, array $unidad): ?int
    {
        $pilotoId = $input['piloto_id'] ?? null;
        if (empty($pilotoId)) {
            return null;
        }
        $piloto = $this->pilotos->find((int) $pilotoId);
        if ($piloto === null || (int) $piloto['activo'] !== 1 || (int) $piloto['estacion_id'] !== (int) $unidad['estacion_id']) {
            json_unprocessable(['piloto_id' => 'El piloto no pertenece a la estación de la unidad.']);
        }
        return (int) $pilotoId;
    }

    private function aUtc(?string $local, string $tz, string $campo): string
    {
        try {
            return local_to_utc((string) $local, $tz)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            json_unprocessable([$campo => 'Fecha/hora no válida.']);
        }

        return '';
    }

    private function estacionTz(int $estacionId): string
    {
        $stmt = $this->pdo->prepare('SELECT timezone FROM estaciones WHERE id = :id');
        $stmt->execute([':id' => $estacionId]);
        return (string) ($stmt->fetchColumn() ?: 'UTC');
    }

    private function unidadEstacion(int $unidadId): int
    {
        $u = $this->unidades->find($unidadId);
        return (int) ($u['estacion_id'] ?? 0);
    }

    private function assertPuedeEscribir(array $user, int $estacionId): void
    {
        if (!can_write_station($user, $estacionId)) {
            json_error('No autorizado sobre esta estación', 403);
        }
    }

    private function snapshot(array $mov): array
    {
        return array_intersect_key($mov, array_flip([
            'ruta_id', 'pais_origen_id', 'pais_destino_id', 'tipo_ruta',
            'fecha_salida', 'fecha_fin_estimada', 'referencia_cw', 'retorno_disponible', 'reservado_para',
        ]));
    }

    private function nullable(?string $v): ?string
    {
        return $v === null || $v === '' ? null : $v;
    }
}
