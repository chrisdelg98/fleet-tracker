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
        private MovimientoUnidadModel $apoyos,
        private UnidadModel $unidades,
        private RutaModel $rutas,
        private PilotoModel $pilotos,
        private ?NotificacionService $notificaciones = null,
        private ?MovimientoTerceroModel $terceros = null
    ) {
    }

    /**
     * Cómo le fue al aviso por correo de la última reserva creada.
     *
     * @var array{enviados: int, error: string|null}
     */
    private array $avisoCorreo = ['enviados' => 0, 'error' => null];

    /** @return array{enviados: int, error: string|null} */
    public function avisoCorreo(): array
    {
        return $this->avisoCorreo;
    }

    /**
     * Crea un movimiento. Corta con 403/422/409 según permiso, validación o traslape.
     *
     * La unidad ya no es obligatoria: un flete hecho enteramente por un tercero no tiene
     * ninguna nuestra. La sustituye una regla más floja y más cierta — **tiene que haber al
     * menos una de las dos cosas**, unidad propia o datos de tercero — y no son excluyentes:
     * tu furgón con un cabezal ajeno es una combinación legítima y frecuente.
     */
    public function crear(array $input, array $user): int
    {
        $tercero = $this->terceroValidado($input);
        $unidad = !empty($input['unidad_id'])
            ? $this->unidadParaMovimiento((int) $input['unidad_id'], $user)
            : null;

        if ($unidad === null && $tercero === null) {
            json_unprocessable([
                'unidad_id' => 'Indica la unidad, o los datos del tercero que hace el movimiento.',
            ]);
        }

        // Sin unidad propia no hay de dónde deducir la estación: la pone quien registra, y
        // por eso una estación que vende fletes sin flota sigue teniendo trabajo atribuible.
        $estacionId = $unidad !== null
            ? (int) $unidad['estacion_id']
            : $this->estacionDeRegistro($input, $user);
        $this->assertPuedeEscribir($user, $estacionId);
        $tz = $this->estacionTz($estacionId);

        $estado = $input['estado'] ?? EstadoMovimiento::RESERVADO;
        if (!in_array($estado, [EstadoMovimiento::RESERVADO, EstadoMovimiento::PROGRAMADO], true)) {
            json_unprocessable(['estado' => 'Un movimiento se crea como RESERVADO o PROGRAMADO.']);
        }

        $data = $this->validarPlan($input, $tz) + [
            'unidad_id'      => $unidad !== null ? (int) $unidad['id'] : null,
            'estacion_id'    => $estacionId,
            'estado'         => $estado,
            'piloto_id'      => $unidad !== null ? $this->pilotoOpcional($input, $unidad) : null,
        ];
        // Sin elección, la clase sale de la ruta y la operación nace en el caso más frecuente:
        // dos campos que en el uso normal nadie tiene que tocar.
        $data['clase'] = $this->claseValida($input['clase'] ?? null)
            ?? ClaseMovimiento::proponer((int) $data['pais_origen_id'], (int) $data['pais_destino_id']);
        $data['servicio_a_tercero'] = (int) (bool) ($input['servicio_a_tercero'] ?? 0);

        if ($unidad !== null) {
            $this->assertAlcanceInternacional((int) $unidad['id'], $data);
        }

        // Activos de apoyo: cabezal y/o chasis que acompañan a la unidad reservada. Ambos
        // opcionales — el cliente puede traer su propio cabezal y no todo equipo lleva chasis.
        $apoyos = $unidad !== null ? $this->apoyosValidados($input, $unidad, $user) : [];

        $id = tx($this->pdo, function () use ($data, $apoyos, $tercero, $user, $tz): int {
            // El traslape se calcula solo sobre lo nuestro: un camión ajeno no lo controlamos.
            if ($data['unidad_id'] !== null) {
                $this->assertSinTraslape((int) $data['unidad_id'], $data['fecha_salida'], $data['fecha_fin_estimada'], null, $tz);
            }
            $this->assertPilotoSinTraslape($data['piloto_id'], $data['fecha_salida'], $data['fecha_fin_estimada'], null, $tz);
            foreach ($apoyos as $apoyo) {
                $this->assertApoyoLibre($apoyo, $data['fecha_salida'], $data['fecha_fin_estimada'], null, $tz);
            }
            $id = $this->movimientos->crear($data, $user['id']);
            foreach ($apoyos as $apoyo) {
                $this->apoyos->agregar($id, (int) $apoyo['id'], $apoyo['rol'], $user['id']);
            }
            if ($tercero !== null) {
                $this->terceros?->guardar($id, $tercero);
            }
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $id, AccionBitacora::CREAR, [
                'despues' => $data + ['apoyos' => array_column($apoyos, 'placa_unidad')]
                    + ($tercero !== null ? ['tercero' => $tercero['proveedor']] : []),
            ]);
            return $id;
        });

        if ((int) $data['retorno_disponible'] === 1) {
            $this->notificaciones?->notificarRetornoDisponible($id);
        }
        // Fuera de la transacción y a prueba de fallos: la reserva ya es válida y está
        // guardada. Que el servidor de correo esté caído no puede deshacerla, pero sí tiene
        // que decirlo: un aviso que no sale y no avisa es peor que no tener avisos.
        $this->avisoCorreo = $this->notificaciones?->notificarReservaCreada(
            $id,
            $data['notificar_a'],
            'reserva.creada',
            $this->quien($user),
            (string) ($user['email'] ?? '')
        ) ?? ['enviados' => 0, 'error' => null];

        return $id;
    }

    /**
     * Traslapes de un rango propuesto, para el aviso en vivo del formulario: los de la unidad
     * y, si se eligió piloto, los suyos. Solo lectura; si faltan o están mal los datos devuelve
     * listas vacías (sin aviso) en lugar de lanzar.
     *
     * @return array{unidad: array<int, array<string, mixed>>, piloto: array<int, array<string, mixed>>}
     */
    public function conflictosPropuestos(array $q): array
    {
        $vacio = ['unidad' => [], 'piloto' => []];
        $unidadId = (int) ($q['unidad_id'] ?? 0);
        $salida = trim((string) ($q['fecha_salida'] ?? ''));
        $fin = trim((string) ($q['fecha_fin_estimada'] ?? ''));
        if ($unidadId <= 0 || $salida === '' || $fin === '') {
            return $vacio;
        }
        $unidad = $this->unidades->find($unidadId);
        if ($unidad === null) {
            return $vacio;
        }
        $tz = $this->estacionTz((int) $unidad['estacion_id']);
        try {
            $salidaUtc = local_to_utc($salida, $tz)->format('Y-m-d H:i:s');
            $finUtc = local_to_utc($fin, $tz)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return $vacio;
        }
        if ($finUtc <= $salidaUtc) {
            return $vacio;
        }
        $exceptId = isset($q['except_id']) && $q['except_id'] !== '' ? (int) $q['except_id'] : null;

        $formatear = static fn(array $c): array => [
            'id'     => (int) $c['id'],
            'estado' => (string) $c['estado'],
            'desde'  => format_local($c['fecha_salida'], $tz, 'd M H:i'),
            'hasta'  => format_local($c['fecha_fin_estimada'], $tz, 'd M H:i'),
        ];

        $pilotoId = (int) ($q['piloto_id'] ?? 0);
        $piloto = $pilotoId > 0 ? $this->pilotos->find($pilotoId) : null;

        return [
            'unidad' => array_map($formatear, $this->movimientos->conflictos($unidadId, $salidaUtc, $finUtc, $exceptId)),
            'piloto' => $piloto === null
                ? []
                : array_map(
                    static fn(array $c): array => $formatear($c) + ['piloto' => (string) $piloto['nombre']],
                    $this->movimientos->conflictosPiloto($pilotoId, $salidaUtc, $finUtc, $exceptId)
                ),
        ];
    }

    /**
     * Vuelve a mandar el aviso de una reserva ya existente.
     *
     * Existe para poder comprobar el correo sin inventar reservas de prueba, que ocupan
     * unidades y ensucian el histórico. Va a los contactos guardados en el movimiento: son
     * personas reales, así que quien lo dispara tiene que saber a cuántas alcanza.
     *
     * @return array{enviados: int, error: string|null, destinatarios: int}
     */
    public function reenviarAviso(int $id, array $user): array
    {
        $mov = $this->movimientos->find($id);
        if ($mov === null) {
            json_error('Movimiento no encontrado', 404);
        }
        $this->assertPuedeEscribir($user, $this->unidadEstacion((int) $mov['unidad_id']));

        $contactos = CatalogoAdminService::correos((string) ($mov['notificar_a'] ?? ''));
        if ($contactos === []) {
            json_unprocessable(['notificar_a' => 'Esa reserva no tiene contactos a quienes avisar.']);
        }

        $r = $this->notificaciones?->notificarReservaCreada(
            $id,
            $mov['notificar_a'],
            'reserva.reenviada',
            $this->quien($user),
            (string) ($user['email'] ?? '')
        ) ?? ['enviados' => 0, 'error' => 'No hay servicio de correo configurado.'];

        return $r + ['destinatarios' => count($contactos)];
    }

    /** Edita el plan (ruta/fechas/flags) de un movimiento aún activo; re-valida no-traslape. */
    public function editar(int $id, array $input, array $user): void
    {
        $mov = $this->cargarActivo($id, $user);
        $unidad = $this->unidades->find((int) $mov['unidad_id']);
        $tz = $this->estacionTz((int) $unidad['estacion_id']);

        $data = $this->validarPlan($input, $tz) + [
            // El estado no se edita: se mueve por la máquina de estados (confirmar, salida,
            // llegada). Si se pudiera cambiar aquí, editar sería la puerta de atrás de §6.
            'estado'    => $mov['estado'],
            // Ausente la clave, se conserva el piloto; presente y vacía, se quita. Son cosas
            // distintas: "no me toques esto" y "quítalo".
            'piloto_id' => array_key_exists('piloto_id', $input)
                ? $this->pilotoOpcional($input, $unidad)
                : ($mov['piloto_id'] !== null ? (int) $mov['piloto_id'] : null),
        ];

        // Clase y operación se conservan si no vienen: son clasificaciones del movimiento, y
        // una edición de ruta o de piloto no tiene por qué reclasificarlo.
        $data['clase'] = $this->claseValida($input['clase'] ?? null) ?? (string) $mov['clase'];
        $data['servicio_a_tercero'] = array_key_exists('servicio_a_tercero', $input)
            ? (int) (bool) $input['servicio_a_tercero']
            : (int) $mov['servicio_a_tercero'];

        // Con la reserva ya confirmada, mover la liberación exige un motivo y va por
        // reprogramarFin (regla del plan §6). Editar no puede saltarse eso, así que a partir
        // de PROGRAMADO las fechas se conservan aunque el formulario mande otras.
        if ($mov['estado'] !== EstadoMovimiento::RESERVADO) {
            $data['fecha_salida'] = $mov['fecha_salida'];
            $data['fecha_fin_estimada'] = $mov['fecha_fin_estimada'];
        }

        $this->assertAlcanceInternacional((int) $mov['unidad_id'], $data);

        tx($this->pdo, function () use ($id, $mov, $data, $tz, $user): void {
            $this->assertSinTraslape((int) $mov['unidad_id'], $data['fecha_salida'], $data['fecha_fin_estimada'], $id, $tz);
            $this->assertPilotoSinTraslape(
                $data['piloto_id'] !== null ? (int) $data['piloto_id'] : null,
                $data['fecha_salida'],
                $data['fecha_fin_estimada'],
                $id,
                $tz
            );
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

    /**
     * RESERVADO/PROGRAMADO → EN_TRANSITO (requiere piloto, regla 11).
     *
     * Se admite salir desde RESERVADO sin confirmar antes: marcar la salida es una afirmación
     * más fuerte que confirmar —la unidad ya se fue—, así que exigir los dos pasos solo agrega
     * clics. La bitácora guarda el salto tal cual (RESERVADO → EN_TRANSITO), sin inventar una
     * confirmación que nadie hizo.
     */
    public function marcarSalida(int $id, array $input, array $user): void
    {
        $mov = $this->cargarActivo($id, $user);
        if (!in_array($mov['estado'], [EstadoMovimiento::RESERVADO, EstadoMovimiento::PROGRAMADO], true)) {
            json_error('Solo un movimiento reservado o programado puede marcar salida.', 409);
        }

        $pilotoId = $mov['piloto_id'] ?? ($input['piloto_id'] ?? null);
        $estacion = $this->unidadEstacion((int) $mov['unidad_id']);

        // Un furgón o un contenedor no lleva piloto: lo conduce quien va en el cabezal. Solo
        // se exige piloto si en el viaje va un motorizado nuestro (plan §6, regla 11).
        if (empty($pilotoId) && $this->llevaMotriz($mov)) {
            json_unprocessable(['piloto_id' => 'Debes asignar un piloto para marcar la salida.']);
        }
        if (!empty($pilotoId)) {
            $piloto = $this->pilotos->find((int) $pilotoId);
            if ($piloto === null || (int) $piloto['activo'] !== 1 || (int) $piloto['estacion_id'] !== $estacion) {
                json_unprocessable(['piloto_id' => 'El piloto no es válido para esta unidad.']);
            }
        }

        $tz = $this->estacionTz($estacion);

        tx($this->pdo, function () use ($id, $mov, $pilotoId, $user, $tz): void {
            // Al marcar salida se puede asignar un piloto distinto: vuelve a validarse aquí.
            $this->assertPilotoSinTraslape(
                $pilotoId !== null ? (int) $pilotoId : null,
                $mov['fecha_salida'],
                $mov['fecha_fin_estimada'],
                $id,
                $tz
            );
            $this->movimientos->cambiarEstado($id, EstadoMovimiento::EN_TRANSITO, [
                'piloto_id' => $pilotoId !== null ? (int) $pilotoId : null,
            ]);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $id, AccionBitacora::CAMBIO_ESTADO, [
                'antes'   => ['estado' => $mov['estado']],
                'despues' => ['estado' => EstadoMovimiento::EN_TRANSITO, 'piloto_id' => $pilotoId !== null ? (int) $pilotoId : null],
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
     * Prórroga en ruta: el cliente pide más días y el fin estimado se corre. No cambia el
     * estado ni el resto del plan, exige motivo y queda en bitácora con el antes/después.
     * Sin esto la unidad aparecería "con demora" por una extensión pactada (plan §2).
     */
    public function reprogramarFin(int $id, array $input, array $user): void
    {
        $mov = $this->cargarActivo($id, $user);
        if (!in_array($mov['estado'], [EstadoMovimiento::PROGRAMADO, EstadoMovimiento::EN_TRANSITO], true)) {
            json_error('Solo se reprograma un movimiento programado o en tránsito.', 409);
        }

        $motivo = trim((string) ($input['motivo'] ?? ''));
        if ($motivo === '') {
            json_unprocessable(['motivo' => 'El motivo del cambio de fecha es obligatorio.']);
        }

        $tz = $this->estacionTz($this->unidadEstacion((int) $mov['unidad_id']));
        $finUtc = $this->aUtc($input['fecha_fin_estimada'] ?? null, $tz, 'fecha_fin_estimada');
        if ($finUtc <= $mov['fecha_salida']) {
            json_unprocessable(['fecha_fin_estimada' => 'El fin estimado debe ser posterior a la salida.']);
        }
        if ($finUtc === $mov['fecha_fin_estimada']) {
            json_unprocessable(['fecha_fin_estimada' => 'La nueva fecha es igual a la actual.']);
        }

        tx($this->pdo, function () use ($id, $mov, $finUtc, $motivo, $tz, $user): void {
            // Alargar el viaje puede pisar la siguiente reserva de la unidad o del piloto.
            $this->assertSinTraslape((int) $mov['unidad_id'], $mov['fecha_salida'], $finUtc, $id, $tz);
            $this->assertPilotoSinTraslape(
                $mov['piloto_id'] !== null ? (int) $mov['piloto_id'] : null,
                $mov['fecha_salida'],
                $finUtc,
                $id,
                $tz
            );
            $this->movimientos->actualizarFinEstimado($id, $finUtc);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $id, AccionBitacora::EDITAR, [
                'antes'   => ['fecha_fin_estimada' => $mov['fecha_fin_estimada']],
                'despues' => ['fecha_fin_estimada' => $finUtc, 'motivo' => $motivo],
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
        if ($ida['movimiento_regreso_id'] !== null) {
            json_error('El retorno ya fue apartado.', 409);
        }

        $unidad = $this->unidades->find((int) $ida['unidad_id']);
        $this->assertPuedeEscribir($user, (int) $unidad['estacion_id']);
        $tz = $this->estacionTz((int) $unidad['estacion_id']);

        // Quién lo aprovecha es opcional: el retorno puede tomarlo un cliente externo, que no
        // corresponde a ningún país del catálogo. Su nombre va en "reservado_para".
        $paisSolicita = !empty($input['pais_solicita_retorno_id']) ? (int) $input['pais_solicita_retorno_id'] : null;
        if ($paisSolicita !== null && !in_array($paisSolicita, paises_ids_validos(), true)) {
            json_unprocessable(['pais_solicita_retorno_id' => 'País solicitante inválido.']);
        }

        // El regreso no siempre vuelve al origen: desde GT pueden mandarlo a HN. Por eso el
        // destino es elegible y solo se propone el origen de la ida como valor por defecto.
        $destinoRegreso = !empty($input['pais_destino_id'])
            ? (int) $input['pais_destino_id']
            : (int) $ida['pais_origen_id'];
        if (!in_array($destinoRegreso, paises_ids_validos(), true)) {
            json_unprocessable(['pais_destino_id' => 'País de destino inválido.']);
        }

        $plan = $this->validarPlan([
            'fecha_salida'       => $input['fecha_salida'] ?? null,
            'fecha_fin_estimada' => $input['fecha_fin_estimada'] ?? null,
            'pais_origen_id'     => (int) $ida['pais_destino_id'], // el equipo regresa desde donde está
            'pais_destino_id'    => $destinoRegreso,
            'ruta_custom_destino' => $input['ruta_custom_destino'] ?? null,
            'referencia_cw'      => $input['referencia_cw'] ?? null,
            'reservado_para'     => $input['reservado_para'] ?? null,
            'notas'              => $input['notas'] ?? ("Retorno del movimiento #{$idIda}"),
        ], $tz);

        $regreso = $plan + [
            'unidad_id' => (int) $ida['unidad_id'],
            'estado'    => EstadoMovimiento::RESERVADO,
            'piloto_id' => null,
        ];
        $this->assertAlcanceInternacional((int) $ida['unidad_id'], $regreso);

        return tx($this->pdo, function () use ($regreso, $idIda, $paisSolicita, $user, $tz): int {
            $this->assertSinTraslape((int) $regreso['unidad_id'], $regreso['fecha_salida'], $regreso['fecha_fin_estimada'], null, $tz);
            $idRegreso = $this->movimientos->crear($regreso, $user['id']);
            $this->movimientos->marcarRetornoTomado($idIda, $paisSolicita, $idRegreso);
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

    /**
     * Una unidad sin permiso internacional no puede cruzar la frontera.
     *
     * El tipo de ruta no se pide: sale de comparar origen y destino, así que da igual si el
     * viaje entró por una ruta del catálogo o a mano. El permiso se relee de la base y no del
     * formulario, que es justo lo que un cliente de API no manda.
     */
    private function assertAlcanceInternacional(int $unidadId, array $plan): void
    {
        if ($plan['tipo_ruta'] !== TipoRuta::INTERNACIONAL) {
            return;
        }
        if (!$this->unidades->puedeInternacional($unidadId)) {
            json_unprocessable([
                'pais_destino_id' => 'La unidad no tiene permiso para salir del país: origen y destino deben ser el mismo.',
            ]);
        }
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
          ->maxLen('reservado_para', 150, 'El campo reservado para')
          ->maxLen('notificar_a', 500, 'Los correos a notificar');
        $malos = CatalogoAdminService::correosInvalidos((string) ($input['notificar_a'] ?? ''));
        if ($malos !== []) {
            $v->addError('notificar_a', 'No parecen correos válidos: ' . implode(', ', $malos) . '.');
        }
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
            'queda_con_cliente'   => array_key_exists('queda_con_cliente', $input) ? (int) (bool) $input['queda_con_cliente'] : 0,
            'reservado_para'      => $this->nullable($v->value('reservado_para')),
            'notificar_a'         => implode(', ', CatalogoAdminService::correos((string) ($input['notificar_a'] ?? ''))) ?: null,
            'notas'               => $this->nullable($v->value('notas')),
        ];
    }

    /**
     * Suelta un activo de apoyo del movimiento sin cerrarlo: el cabezal vuelve a base y el
     * equipo se queda con el cliente. No se borra la fila, para que el histórico conserve
     * que ese activo sí hizo el viaje.
     */
    public function liberarApoyo(int $movimientoId, int $unidadId, array $user): void
    {
        $mov = $this->cargarActivo($movimientoId, $user);
        $fila = $this->apoyos->find($movimientoId, $unidadId);
        if ($fila === null) {
            json_error('Ese activo no forma parte del movimiento.', 404);
        }
        if ($fila['liberado_en'] !== null) {
            json_error('Ese activo ya fue liberado.', 409);
        }

        tx($this->pdo, function () use ($movimientoId, $unidadId, $mov, $user): void {
            $this->apoyos->liberar($movimientoId, $unidadId);
            $unidad = $this->unidades->find($unidadId);
            registrar_bitacora($this->pdo, $user['id'], 'movimiento', $movimientoId, AccionBitacora::EDITAR, [
                'antes'   => ['estado' => $mov['estado']],
                'despues' => ['activo_liberado' => $unidad['placa_unidad'] ?? $unidadId],
            ]);
        });
    }

    /**
     * Normaliza y valida los activos de apoyo recibidos del formulario.
     *
     * @return array<int, array{id:int, rol:string, placa_unidad:string}>
     */
    private function apoyosValidados(array $input, array $unidad, array $user): array
    {
        $ids = array_filter([
            RolUnidadMovimiento::MOTRIZ   => $input['apoyo_motriz_id'] ?? null,
            RolUnidadMovimiento::ARRASTRE => $input['apoyo_arrastre_id'] ?? null,
        ]);

        $out = [];
        foreach ($ids as $rol => $id) {
            $id = (int) $id;
            if ($id === (int) $unidad['id']) {
                json_unprocessable(['apoyos' => 'Un activo no puede acompañarse a sí mismo.']);
            }
            $apoyo = $this->unidades->find($id);
            if ($apoyo === null || (int) $apoyo['activo'] !== 1) {
                json_unprocessable(['apoyos' => 'El activo de apoyo no existe.']);
            }
            if ((int) $apoyo['estacion_id'] !== (int) $unidad['estacion_id']) {
                json_unprocessable(['apoyos' => 'El activo de apoyo es de otra estación.']);
            }
            if ($apoyo['estado_vehiculo'] !== EstadoVehiculo::OPERATIVO) {
                json_unprocessable(['apoyos' => "{$apoyo['placa_unidad']} no está operativa."]);
            }
            $this->assertApoyoCompatible($rol, $unidad, $apoyo);
            $out[] = ['id' => $id, 'rol' => $rol, 'placa_unidad' => $apoyo['placa_unidad']];
        }
        return $out;
    }

    /**
     * La categoría manda: un camión se mueve solo y no engancha nada, un cabezal jala pero no
     * necesita otro cabezal. El formulario ya bloquea los campos que no aplican, pero la regla
     * vive aquí: la API se puede llamar directo y un formulario en caché manda lo que sea.
     */
    private function assertApoyoCompatible(string $rol, array $unidad, array $apoyo): void
    {
        if ($rol === RolUnidadMovimiento::MOTRIZ) {
            if ((int) $unidad['es_motriz'] === 1) {
                json_unprocessable(['apoyos' => "{$unidad['placa_unidad']} se mueve sola: no lleva cabezal."]);
            }
            if ((int) $apoyo['es_motriz'] !== 1) {
                json_unprocessable(['apoyos' => "{$apoyo['placa_unidad']} no se mueve sola: no puede ir como cabezal."]);
            }
            return;
        }

        if ((int) $unidad['admite_arrastre'] !== 1) {
            json_unprocessable(['apoyos' => "{$unidad['placa_unidad']} no lleva equipo enganchado."]);
        }
        if ((int) $apoyo['es_motriz'] === 1) {
            json_unprocessable(['apoyos' => "{$apoyo['placa_unidad']} se mueve sola: no puede ir enganchada."]);
        }
    }

    /** Corta con 409 si el activo de apoyo ya está comprometido en ese rango. */
    private function assertApoyoLibre(array $apoyo, string $salidaUtc, string $finUtc, ?int $exceptId, string $tz): void
    {
        // Puede estar ocupado como protagonista de otro viaje o como apoyo de otro.
        $comoUnidad = $this->movimientos->conflictos((int) $apoyo['id'], $salidaUtc, $finUtc, $exceptId);
        $comoApoyo  = $this->apoyos->conflictos((int) $apoyo['id'], $salidaUtc, $finUtc, $exceptId);
        $conflicto = $comoUnidad[0] ?? $comoApoyo[0] ?? null;
        if ($conflicto === null) {
            return;
        }
        $desde = format_local($conflicto['fecha_salida'], $tz, 'd M H:i');
        $hasta = format_local($conflicto['fecha_fin_estimada'], $tz, 'd M H:i');
        json_error(
            "{$apoyo['placa_unidad']} ya está en un movimiento {$conflicto['estado']} del {$desde} al {$hasta} (mov. #{$conflicto['id']}).",
            409,
            "Traslape del activo de apoyo con el movimiento #{$conflicto['id']}."
        );
    }

    /** ¿El viaje incluye un motorizado nuestro (la unidad misma o un cabezal de apoyo)? */
    private function llevaMotriz(array $mov): bool
    {
        $unidad = $this->unidades->find((int) $mov['unidad_id']);
        if ($unidad !== null && (int) $unidad['es_motriz'] === 1) {
            return true;
        }
        foreach ($this->apoyos->porMovimiento((int) $mov['id']) as $apoyo) {
            if ($apoyo['rol'] === RolUnidadMovimiento::MOTRIZ && $apoyo['liberado_en'] === null) {
                return true;
            }
        }
        return false;
    }

    /** Corta con 409 si el rango se traslapa con otro movimiento activo de la unidad. */
    private function assertSinTraslape(int $unidadId, string $salidaUtc, string $finUtc, ?int $exceptId, string $tz): void
    {
        // La unidad puede estar comprometida como protagonista de otro viaje o acompañando
        // a otro activo: ambas ocupaciones cuentan igual.
        $conflictos = $this->movimientos->conflictos($unidadId, $salidaUtc, $finUtc, $exceptId);
        if ($conflictos === []) {
            $conflictos = $this->apoyos->conflictos($unidadId, $salidaUtc, $finUtc, $exceptId);
        }
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

    /**
     * Corta con 409 si el piloto ya va en otro movimiento activo en ese rango. El piloto es
     * opcional en un movimiento: si no hay, no hay nada que validar.
     */
    private function assertPilotoSinTraslape(?int $pilotoId, string $salidaUtc, string $finUtc, ?int $exceptId, string $tz): void
    {
        if ($pilotoId === null) {
            return;
        }
        $conflictos = $this->movimientos->conflictosPiloto($pilotoId, $salidaUtc, $finUtc, $exceptId);
        if ($conflictos === []) {
            return;
        }
        $c = $conflictos[0];
        $piloto = $this->pilotos->find($pilotoId);
        $nombre = $piloto['nombre'] ?? 'El piloto';
        $desde = format_local($c['fecha_salida'], $tz, 'd M H:i');
        $hasta = format_local($c['fecha_fin_estimada'], $tz, 'd M H:i');
        json_error(
            "{$nombre} ya va en un movimiento {$c['estado']} del {$desde} al {$hasta} (mov. #{$c['id']}).",
            409,
            "Traslape de piloto con el movimiento #{$c['id']}."
        );
    }

    /**
     * Datos del camión de tercero, o null si el movimiento no lleva uno.
     *
     * Solo el proveedor es obligatorio: es lo mínimo para que el registro sirva de algo —saber
     * con quién se trabajó— y pedir más convertiría en trámite lo que tiene que ser rápido.
     *
     * @return array<string, string>|null
     */
    private function terceroValidado(array $input): ?array
    {
        $campos = ['proveedor', 'placa_motriz', 'placa_arrastre', 'piloto',
                   'documento', 'telefonos', 'codigo_nacional', 'codigo_internacional'];

        $datos = [];
        foreach ($campos as $campo) {
            $valor = trim((string) ($input[$campo] ?? ''));
            if ($valor !== '') {
                // Las placas en mayúsculas, como las de la flota propia: si no, la misma placa
                // escrita de dos formas serían dos camiones y el autocompletado no la encuentra.
                $datos[$campo] = in_array($campo, ['placa_motriz', 'placa_arrastre', 'piloto'], true)
                    ? mb_strtoupper($valor, 'UTF-8')
                    : $valor;
            }
        }
        if ($datos === []) {
            return null;
        }
        if (!isset($datos['proveedor'])) {
            json_unprocessable(['proveedor' => 'Indica de qué proveedor es la unidad.']);
        }
        return $datos;
    }

    /** Clase pedida, si es una de las válidas; null para que decida quien llame. */
    private function claseValida($valor): ?string
    {
        $valor = is_string($valor) ? trim($valor) : '';
        return in_array($valor, ClaseMovimiento::values(), true) ? $valor : null;
    }

    /**
     * Estación a la que se le atribuye un movimiento sin unidad propia: la que se indique o,
     * si no, la de quien registra. Un admin global sin estación tiene que decirla.
     */
    private function estacionDeRegistro(array $input, array $user): int
    {
        $pedida = (int) ($input['estacion_id'] ?? 0);
        if ($pedida > 0) {
            return $pedida;
        }
        $propia = (int) ($user['estacion_id'] ?? 0);
        if ($propia > 0) {
            return $propia;
        }
        json_unprocessable(['estacion_id' => 'Indica a qué estación corresponde el movimiento.']);
    }

    /** Con qué nombre queda en la bitácora quien disparó un aviso. */
    private function quien(array $user): string
    {
        return (string) ($user['nombre'] ?? $user['email'] ?? $user['id'] ?? '');
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
            'fecha_salida', 'fecha_fin_estimada', 'referencia_cw', 'retorno_disponible', 'reservado_para', 'notificar_a',
        ]));
    }

    private function nullable(?string $v): ?string
    {
        return $v === null || $v === '' ? null : $v;
    }
}
