<?php
/**
 * Reglas del módulo de mantenimientos.
 *
 * Tres cosas viven aquí porque son decisiones de negocio, no de almacenamiento:
 *   1. La conversión a dólares, que guarda la tasa usada para que un total viejo no cambie.
 *   2. La validación del odómetro, creciente pero con válvula de escape cuando se reemplaza.
 *   3. El semáforo, que distingue "vencido" de "nunca se registró", que no es lo mismo.
 */

declare(strict_types=1);

final class MantenimientoService
{
    /** Estados del semáforo, del más urgente al más tranquilo. */
    public const SIN_PLAN       = 'SIN_PLAN';
    public const FALTA_KM       = 'FALTA_KM';
    public const VENCIDO        = 'VENCIDO';
    public const PROXIMO        = 'PROXIMO';
    public const AL_DIA         = 'AL_DIA';

    public function __construct(
        private PDO $pdo,
        private MantenimientoModel $mantenimientos,
        private LecturaOdometroModel $lecturas,
        private UnidadModel $unidades,
        private TallerService $talleres,
        private OverrideModel $overrides
    ) {
    }

    public static function estados(): array
    {
        return [
            self::VENCIDO        => 'Vencidos',
            self::PROXIMO        => 'Próximos',
            self::AL_DIA         => 'Al día',
            self::FALTA_KM       => 'Falta kilometraje',
            self::SIN_PLAN       => 'Sin plan',
        ];
    }

    // ── Registro de intervenciones ──

    public function crear(array $input, array $user): int
    {
        $data = $this->validado($input, $user, null);

        return tx($this->pdo, fn(): int => $this->guardar($data, $user, 'registro'));
    }

    /**
     * Inserta una intervención ya validada. Va sin transacción propia porque la carga masiva
     * mete cientos de filas dentro de una sola, y anidar transacciones no es posible en PDO.
     */
    public function guardar(array $data, array $user, string $origen): int
    {
        $id = $this->mantenimientos->crear($data, $user['id']);
        // La intervención alimenta el odómetro: registrar el km aquí evita capturarlo dos
        // veces, que es la forma segura de que una de las dos se deje de hacer.
        if ($data['km'] !== null) {
            $this->lecturas->crear([
                'unidad_id' => $data['unidad_id'],
                'fecha' => $data['fecha'],
                'km' => $data['km'],
                'origen' => $origen === 'registro' ? 'MANTENIMIENTO' : 'IMPORTACION',
                'mantenimiento_id' => $id,
                'nota' => $data['nota_odometro'] ?? null,
            ], $user['id']);
        }
        // Meter la unidad al taller es parte del mismo hecho: si se hiciera aparte, el gasto y
        // la disponibilidad podrían contradecirse, que es justo lo que pasaba antes.
        if ($data['en_taller_desde'] !== null) {
            $this->meterAlTaller($id, $data, $user);
        }

        registrar_bitacora($this->pdo, $user['id'], 'mantenimiento', $id, AccionBitacora::CREAR, [
            'despues' => $data,
            'origen' => $origen,
        ]);
        return $id;
    }

    public function editar(int $id, array $input, array $user): void
    {
        $actual = $this->intervencion($id, $user);
        $data = $this->validado($input, $user, $id);

        tx($this->pdo, function () use ($id, $actual, $data, $user): void {
            $this->mantenimientos->actualizar($id, $data);
            registrar_bitacora($this->pdo, $user['id'], 'mantenimiento', $id, AccionBitacora::EDITAR, [
                'antes' => array_intersect_key($actual, array_flip(MantenimientoModel::CAMPOS)),
                'despues' => $data,
            ]);
        });
    }

    public function eliminar(int $id, array $user): void
    {
        $actual = $this->intervencion($id, $user);
        tx($this->pdo, function () use ($id, $actual, $user): void {
            // Las lecturas que nacieron de esta intervención se van con ella (ON DELETE CASCADE):
            // sin el hecho que las originó, serían un kilometraje sin respaldo.
            $this->mantenimientos->eliminar($id);
            registrar_bitacora($this->pdo, $user['id'], 'mantenimiento', $id, AccionBitacora::ELIMINAR, [
                'antes' => array_intersect_key($actual, array_flip(MantenimientoModel::CAMPOS)),
            ]);
        });
    }

    /** La intervención, comprobando permiso sobre la estación de su unidad. */
    public function intervencion(int $id, array $user): array
    {
        $fila = $this->mantenimientos->find($id);
        if ($fila === null) {
            json_error('Mantenimiento no encontrado', 404);
        }
        if (!can_write_station($user, (int) $fila['estacion_id'])) {
            json_error('No autorizado sobre esta estación', 403);
        }
        return $fila;
    }

    // ── Lecturas de odómetro ──

    /** Registra una lectura suelta. Corta con 422 si algo no cuadra. */
    public function registrarLectura(array $input, array $user): int
    {
        $unidad = $this->unidadEscribible((int) ($input['unidad_id'] ?? 0), $user);
        $fecha = $this->fecha($input['fecha'] ?? null);
        $km = $this->kilometraje($input['km'] ?? null, true);
        $nota = trim((string) ($input['nota'] ?? ''));

        $problema = $this->problemaOdometro((int) $unidad['id'], $fecha, $km, $nota);
        if ($problema !== null) {
            json_unprocessable(['km' => $problema]);
        }

        return tx($this->pdo, fn(): int => $this->lecturas->crear([
            'unidad_id' => (int) $unidad['id'],
            'fecha' => $fecha,
            'km' => $km,
            'origen' => 'MANUAL',
            'nota' => $nota === '' ? null : $nota,
        ], $user['id']));
    }

    /**
     * Captura masiva: una fila por unidad, todo de un golpe.
     *
     * Es la operación más frecuente del módulo —mensual, sobre toda la flota—, así que las filas
     * vacías se ignoran en vez de dar error: nadie llena las treinta y tres cada mes. Y una placa
     * con un problema se reporta sin tumbar a las demás: si cortara, habría que volver a teclear
     * todo lo que ya estaba bien.
     *
     * @return array{guardadas: int, errores: array<int, string>}
     */
    public function registrarLecturas(array $filas, string $fecha, array $user): array
    {
        $fecha = $this->fecha($fecha);
        $validas = [];
        $errores = [];

        foreach ($filas as $unidadId => $fila) {
            $unidadId = (int) $unidadId;
            $crudo = trim((string) ($fila['km'] ?? ''));
            if ($crudo === '') {
                continue;
            }
            $nota = trim((string) ($fila['nota'] ?? ''));
            $unidad = $this->unidades->find($unidadId);
            if ($unidad === null || (int) $unidad['activo'] !== 1 || !can_write_station($user, (int) $unidad['estacion_id'])) {
                $errores[$unidadId] = 'No se puede registrar kilometraje de esa unidad.';
                continue;
            }
            $km = str_replace([',', ' ', '.'], '', $crudo);
            if (!ctype_digit($km)) {
                $errores[$unidadId] = 'El kilometraje solo lleva números.';
                continue;
            }
            $problema = $this->problemaOdometro($unidadId, $fecha, (int) $km, $nota);
            if ($problema !== null) {
                $errores[$unidadId] = $problema;
                continue;
            }
            $validas[] = ['unidad_id' => $unidadId, 'fecha' => $fecha, 'km' => (int) $km,
                          'origen' => 'MANUAL', 'nota' => $nota === '' ? null : $nota];
        }

        if ($validas === []) {
            return ['guardadas' => 0, 'errores' => $errores];
        }
        tx($this->pdo, function () use ($validas, $user): void {
            foreach ($validas as $lectura) {
                $this->lecturas->crear($lectura, $user['id']);
            }
        });
        return ['guardadas' => count($validas), 'errores' => $errores];
    }

    /**
     * Abre el override que quita disponibilidad y marca el vehículo en mantenimiento.
     *
     * El motivo sale del propio trabajo —taller y descripción—, así que el tablero explica por
     * qué la unidad no está disponible sin que nadie lo escriba dos veces.
     */
    private function meterAlTaller(int $id, array $data, array $user): void
    {
        $taller = $data['taller_id'] !== null ? $this->fila('talleres', (int) $data['taller_id']) : null;
        $motivo = trim(($taller['nombre'] ?? 'Taller') . ' · ' . ($data['descripcion'] ?? 'Mantenimiento'));

        $overrideId = $this->overrides->abrir(
            (int) $data['unidad_id'],
            TipoOverride::EN_TALLER,
            OrigenOverride::AUTO_ESTADO,
            mb_substr($motivo, 0, 255),
            $user['id']
        );
        $this->mantenimientos->actualizar($id, array_merge($data, ['override_id' => $overrideId]));
        $this->unidades->actualizarEstado((int) $data['unidad_id'], EstadoVehiculo::EN_MANTENIMIENTO, $motivo);
    }

    /**
     * Marca la salida del taller: la unidad vuelve a estar disponible.
     *
     * Es el cierre del mismo hecho, no una acción de otra pantalla. Por eso cierra el override,
     * devuelve el vehículo a operativo y fecha la salida en una sola transacción.
     */
    public function marcarSalida(int $id, array $user): void
    {
        $m = $this->intervencion($id, $user);
        if ($m['en_taller_desde'] === null || $m['en_taller_hasta'] !== null) {
            json_unprocessable(['id' => 'Esta unidad no está en el taller.']);
        }

        tx($this->pdo, function () use ($id, $m, $user): void {
            $this->mantenimientos->cerrarTaller($id, now_utc());
            $this->overrides->cerrarAutomaticosAbiertos((int) $m['unidad_id']);
            $this->unidades->actualizarEstado((int) $m['unidad_id'], EstadoVehiculo::OPERATIVO, null);
            registrar_bitacora($this->pdo, $user['id'], 'mantenimiento', $id, AccionBitacora::CAMBIO_ESTADO, [
                'antes'   => ['en_taller_hasta' => null],
                'despues' => ['en_taller_hasta' => now_utc(), 'estado_vehiculo' => EstadoVehiculo::OPERATIVO],
            ]);
        });
    }

    /**
     * Las reglas de una lectura, SIN cortar la petición: la puerta que usa la carga masiva.
     *
     * @return array{data: ?array<string,mixed>, errores: array<string,string>}
     */
    public function evaluarLectura(array $input, array $user): array
    {
        $errores = [];
        $unidad = $this->unidadValida($input['unidad_id'] ?? null, $user, $errores);
        $fecha = $this->fechaValida($input['fecha'] ?? null, $errores);
        $km = $this->kmValido($input['km'] ?? null, true, $errores);
        $nota = trim((string) ($input['nota'] ?? ''));

        if ($unidad !== null && $fecha !== null && $km !== null) {
            $problema = $this->problemaOdometro((int) $unidad['id'], $fecha, $km, $nota);
            if ($problema !== null) {
                $errores['km'] = $problema;
            }
        }
        if ($errores !== []) {
            return ['data' => null, 'errores' => $errores];
        }

        return ['data' => [
            'unidad_id' => (int) $unidad['id'],
            'fecha' => $fecha,
            'km' => $km,
            'origen' => 'IMPORTACION',
            'nota' => $nota === '' ? null : $nota,
        ], 'errores' => []];
    }

    /** Inserta una lectura ya validada, sin transacción propia (ver guardar()). */
    public function guardarLectura(array $data, array $user): int
    {
        return $this->lecturas->crear($data, $user['id']);
    }

    /**
     * El plan que sigue cada categoría.
     *
     * Se asigna desde la categoría y no desde el plan porque la pregunta que se hace la gente es
     * «¿qué plan sigue el cabezal?», y desde el plan había que abrirlos todos para responderla.
     * Un desplegable por fila además hace evidente la regla: una categoría sigue un plan, o
     * ninguno, y lo que se queda sin plan no sale en el semáforo.
     *
     * @param array<int, int|null> $mapa categoría => plan, o null para dejarla sin plan
     */
    public function asignarPlanes(array $mapa, array $user): void
    {
        $validos = [];
        foreach ($mapa as $categoriaId => $planId) {
            $categoriaId = (int) $categoriaId;
            $planId = (int) $planId > 0 ? (int) $planId : null;
            if ($planId !== null && $this->fila('planes_mantenimiento', $planId) === null) {
                json_unprocessable(['plan' => 'Uno de los planes elegidos ya no existe.']);
            }
            $validos[$categoriaId] = $planId;
        }

        tx($this->pdo, function () use ($validos, $user): void {
            $stmt = $this->pdo->prepare('UPDATE categorias_vehiculo SET plan_mantenimiento_id = :p WHERE id = :id');
            foreach ($validos as $categoriaId => $planId) {
                $stmt->execute([':p' => $planId, ':id' => $categoriaId]);
            }
            registrar_bitacora($this->pdo, $user['id'], 'planes_por_categoria', 0, AccionBitacora::EDITAR, [
                'despues' => $validos,
            ]);
        });
    }

    // ── Control: el semáforo ──

    /**
     * Estado de cada unidad, ya calculado.
     *
     * @return array{filas: array, conteo: array<string, int>}
     */
    public function control(array $filtros): array
    {
        $hoy = new DateTimeImmutable('today');
        $filas = [];
        $conteo = array_fill_keys(array_keys(self::estados()), 0);

        foreach ($this->mantenimientos->control($filtros) as $u) {
            $fila = $this->conEstado($u, $hoy);
            $conteo[$fila['estado']]++;
            if (($filtros['estado'] ?? '') !== '' && $fila['estado'] !== $filtros['estado']) {
                continue;
            }
            $filas[] = $fila;
        }

        // Lo urgente primero: dentro de cada estado, lo que lleva más tiempo vencido.
        $orden = [self::VENCIDO => 0, self::PROXIMO => 1, self::FALTA_KM => 2, self::AL_DIA => 3, self::SIN_PLAN => 4];
        usort($filas, static function (array $a, array $b) use ($orden): int {
            return [$orden[$a['estado']], $a['faltan_km'] ?? PHP_INT_MAX]
                <=> [$orden[$b['estado']], $b['faltan_km'] ?? PHP_INT_MAX];
        });

        return ['filas' => $filas, 'conteo' => $conteo];
    }

    /**
     * Las derivaciones de una unidad: qué le falta y en qué estado está.
     *
     * Sin lectura o sin servicio previo no hay "vencido": hay un vacío de datos. Mezclarlos es lo
     * que hace que la hoja actual marque «−8,000» a seis unidades que nunca tuvieron servicio, y
     * que por eso nadie mire las alertas.
     */
    private function conEstado(array $u, DateTimeImmutable $hoy): array
    {
        $kmActual   = $u['km_actual'] !== null ? (int) $u['km_actual'] : null;
        $ultimoKm   = $u['ultimo_km'] !== null ? (int) $u['ultimo_km'] : null;
        $intervalo  = (int) ($u['intervalo_km'] ?? 0);
        $intervaloD = $u['intervalo_dias'] !== null ? (int) $u['intervalo_dias'] : null;

        $proximoKm = $ultimoKm !== null && $intervalo > 0 ? $ultimoKm + $intervalo : null;
        $faltanKm  = $proximoKm !== null && $kmActual !== null ? $proximoKm - $kmActual : null;

        $proximaFecha = null;
        $faltanDias = null;
        if (!empty($u['ultimo_fecha']) && $intervaloD !== null && $intervaloD > 0) {
            $proximaFecha = (new DateTimeImmutable((string) $u['ultimo_fecha']))->modify("+{$intervaloD} days");
            $faltanDias = (int) $hoy->diff($proximaFecha)->format('%r%a');
        }

        // Ritmo con las lecturas de los últimos 90 días; con una sola lectura no hay ritmo.
        $ritmo = null;
        $fechaEstimada = null;
        if ($kmActual !== null && $u['km_ref'] !== null && $u['fecha_ref'] !== null && $u['km_fecha'] !== null) {
            $dias = (int) (new DateTimeImmutable((string) $u['fecha_ref']))
                ->diff(new DateTimeImmutable((string) $u['km_fecha']))->format('%a');
            $recorrido = $kmActual - (int) $u['km_ref'];
            if ($dias > 0 && $recorrido > 0) {
                $ritmo = $recorrido / $dias;
                if ($faltanKm !== null && $faltanKm > 0) {
                    $fechaEstimada = $hoy->modify('+' . (int) ceil($faltanKm / $ritmo) . ' days');
                }
            }
        }

        $estado = $this->estadoDe(
            $faltanKm,
            $faltanDias,
            (int) ($u['umbral_km'] ?? 0),
            (int) ($u['umbral_dias'] ?? 0),
            $kmActual,
            $ultimoKm,
            $u['plan'] !== null && $intervalo > 0
        );

        return $u + [
            'proximo_km' => $proximoKm,
            'faltan_km' => $faltanKm,
            'proxima_fecha' => $proximaFecha?->format('Y-m-d'),
            'faltan_dias' => $faltanDias,
            'ritmo_km_dia' => $ritmo,
            'fecha_estimada' => $fechaEstimada?->format('Y-m-d'),
            'estado' => $estado,
        ];
    }

    private function estadoDe(?int $faltanKm, ?int $faltanDias, int $umbralKm, int $umbralDias, ?int $kmActual, ?int $ultimoKm, bool $conPlan): string
    {
        // Sin plan no hay nada que vencer: una plataforma no lleva cambio de aceite, y contarla
        // como alerta es lo que hacía que nadie mirara el semáforo. No estar en ningún plan ya
        // es la respuesta; no hace falta marcarlo aparte.
        if (!$conPlan) {
            return self::SIN_PLAN;
        }
        // Falta el dato, no el taller: sin lectura o sin servicio previo no se puede afirmar nada.
        if ($kmActual === null || $ultimoKm === null) {
            if ($faltanDias === null) {
                return self::FALTA_KM;
            }
        }
        // Manda el peor de los dos, km o días: lo que se cumpla primero es lo que vence.
        if (($faltanKm !== null && $faltanKm <= 0) || ($faltanDias !== null && $faltanDias <= 0)) {
            return self::VENCIDO;
        }
        if (($faltanKm !== null && $faltanKm <= $umbralKm) || ($faltanDias !== null && $faltanDias <= $umbralDias)) {
            return self::PROXIMO;
        }
        return $faltanKm === null && $faltanDias === null ? self::FALTA_KM : self::AL_DIA;
    }

    // ── Validación ──

    /**
     * Las reglas de una intervención, SIN cortar la petición.
     *
     * Es el único sitio donde viven: el formulario entra por validado() —que convierte estos
     * errores en un 422— y la carga masiva entra por aquí directamente, porque necesita seguir
     * revisando las demás filas en vez de abortar en la primera. Si se duplicaran, el Excel
     * acabaría aceptando lo que el formulario rechaza.
     *
     * @return array{data: ?array<string,mixed>, errores: array<string,string>}
     */
    public function evaluar(array $input, array $user, ?int $exceptId = null): array
    {
        $errores = [];

        $unidad = $this->unidadValida($input['unidad_id'] ?? null, $user, $errores);
        $fecha = $this->fechaValida($input['fecha'] ?? null, $errores);

        $tipo = null;
        $tipoId = (int) ($input['tipo_mantenimiento_id'] ?? 0);
        if ($tipoId <= 0) {
            $errores['tipo_mantenimiento_id'] = 'Elige el tipo de mantenimiento.';
        } else {
            $tipo = $this->fila('tipos_mantenimiento', $tipoId);
            if ($tipo === null) {
                $errores['tipo_mantenimiento_id'] = 'Ese tipo de mantenimiento no existe.';
            }
        }

        // El taller puede venir elegido o escrito: si es nuevo se crea en la estación de la unidad.
        $tallerId = null;
        if (!empty($input['taller_id'])) {
            $taller = $this->talleres->taller((int) $input['taller_id'], $user);
            if ($unidad !== null && (int) $taller['estacion_id'] !== (int) $unidad['estacion_id']) {
                $errores['taller_id'] = "«{$taller['nombre']}» es de otra estación.";
            } else {
                $tallerId = (int) $taller['id'];
            }
        } elseif (trim((string) ($input['taller_nuevo'] ?? '')) !== '' && $unidad !== null) {
            $tallerId = $this->talleres->encontrarOCrear((string) $input['taller_nuevo'], (int) $unidad['estacion_id'], $user['id']);
        }

        $km = $this->kmValido($input['km'] ?? null, false, $errores);
        $nota = trim((string) ($input['nota_odometro'] ?? ''));
        if ($km !== null && $fecha !== null && $unidad !== null && $exceptId === null) {
            $problema = $this->problemaOdometro((int) $unidad['id'], $fecha, $km, $nota);
            if ($problema !== null) {
                $errores['km'] = $problema;
            }
        }

        ['costo' => $costo, 'moneda_id' => $monedaId, 'tasa' => $tasa, 'usd' => $usd] =
            $this->costoEnDolares($input, $errores);

        if ($errores !== []) {
            return ['data' => null, 'errores' => $errores];
        }

        return ['data' => [
            'unidad_id' => (int) $unidad['id'],
            'fecha' => $fecha,
            'tipo_mantenimiento_id' => $tipoId,
            'descripcion' => $this->texto($input['descripcion'] ?? null, 255),
            'taller_id' => $tallerId,
            'costo' => $costo,
            'moneda_id' => $monedaId,
            'tasa_usada' => $tasa,
            'costo_usd' => $usd,
            'factura' => $this->texto($input['factura'] ?? null, 60),
            'km' => $km,
            // Se hereda del tipo, pero el formulario puede cambiarlo: una reparación grande a
            // veces incluye el cambio de aceite, y obligar a registrar dos filas por eso es la
            // fricción que hace que la gente deje de registrar.
            'reinicia_ciclo' => array_key_exists('reinicia_ciclo', $input)
                ? (int) (bool) $input['reinicia_ciclo']
                : (int) $tipo['es_preventivo'],
            'observaciones' => $this->texto($input['observaciones'] ?? null, 255),
            'nota_odometro' => $nota === '' ? null : $nota,
            // Un cambio de aceite de dos horas no saca la unidad de disponibilidad; una
            // reparación de tres días sí. Lo decide quien registra, no el tipo de trabajo.
            'en_taller_desde' => !empty($input['en_taller']) ? now_utc() : null,
            'en_taller_hasta' => null,
            'override_id' => null,
        ], 'errores' => []];
    }

    /** @return array<string, mixed> listo para el modelo */
    private function validado(array $input, array $user, ?int $exceptId): array
    {
        // El 403 se separa del 422 antes de evaluar: no es que el dato esté mal escrito, es que
        // esa unidad no es de tu estación, y la pantalla responde distinto a cada cosa.
        $unidad = $this->unidades->find((int) ($input['unidad_id'] ?? 0));
        if ($unidad !== null && !can_write_station($user, (int) $unidad['estacion_id'])) {
            json_error('No autorizado sobre esta estación', 403);
        }

        ['data' => $data, 'errores' => $errores] = $this->evaluar($input, $user, $exceptId);
        if ($errores !== []) {
            json_unprocessable($errores);
        }
        return $data;
    }

    /**
     * Convierte el costo a dólares guardando la tasa usada.
     *
     * @return array{costo: ?float, moneda_id: ?int, tasa: ?float, usd: ?float}
     */
    private function costoEnDolares(array $input, array &$errores): array
    {
        $vacio = ['costo' => null, 'moneda_id' => null, 'tasa' => null, 'usd' => null];

        $crudo = trim((string) ($input['costo'] ?? ''));
        if ($crudo === '') {
            // Sin factura todavía: el trabajo se registra igual, y un cero mentiría en los totales.
            return $vacio;
        }
        $costo = (float) str_replace([',', ' '], ['.', ''], $crudo);
        if ($costo < 0) {
            $errores['costo'] = 'El costo no puede ser negativo.';
            return $vacio;
        }

        $monedaId = (int) ($input['moneda_id'] ?? 0);
        $moneda = $monedaId > 0 ? $this->fila('monedas', $monedaId) : null;
        if ($moneda === null) {
            $errores['moneda_id'] = 'Elige la moneda del costo.';
            return $vacio;
        }
        // La tasa puede venir editada desde el formulario (la de la factura); si no, la vigente.
        $tasa = trim((string) ($input['tasa_usada'] ?? '')) !== ''
            ? (float) str_replace(',', '.', (string) $input['tasa_usada'])
            : (float) $moneda['por_dolar'];
        if ($tasa <= 0) {
            $errores['tasa_usada'] = 'La tasa de cambio tiene que ser mayor que cero.';
            return $vacio;
        }

        return [
            'costo' => round($costo, 2),
            'moneda_id' => (int) $moneda['id'],
            'tasa' => $tasa,
            'usd' => round($costo / $tasa, 2),
        ];
    }

    /**
     * El odómetro solo sube. Cuando baja no se rechaza el dato: se exige una nota.
     *
     * Rechazarlo sin más bloquearía la captura de la primera unidad con odómetro reemplazado, que
     * es justo el caso que hoy se anota a mano como "Odo/Malo" en la hoja.
     */
    public function problemaOdometro(int $unidadId, string $fecha, int $km, string $nota): ?string
    {
        if ($nota !== '') {
            return null;   // la nota es la válvula: quien explica por qué bajó, pasa
        }
        $previa = $this->lecturas->ultimaHasta($unidadId, $fecha);
        if ($previa !== null && $km < (int) $previa['km']) {
            return 'El odómetro marcaba ' . number_format((float) $previa['km']) . ' km el ' . $previa['fecha']
                . '. Si se reemplazó o estaba dañado, escríbelo en la nota.';
        }
        $siguiente = $this->lecturas->primeraDesde($unidadId, $fecha);
        if ($siguiente !== null && $km > (int) $siguiente['km']) {
            return 'Hay una lectura posterior menor (' . number_format((float) $siguiente['km']) . ' km el '
                . $siguiente['fecha'] . '). Revisa la fecha, o explica el cambio en la nota.';
        }
        return null;
    }

    private function unidadEscribible(int $id, array $user): array
    {
        $unidad = $id > 0 ? $this->unidades->find($id) : null;
        if ($unidad === null || (int) $unidad['activo'] !== 1) {
            json_unprocessable(['unidad_id' => 'Elige la unidad.']);
        }
        if (!can_write_station($user, (int) $unidad['estacion_id'])) {
            json_error('No autorizado sobre esta estación', 403);
        }
        return $unidad;
    }

    /** Igual que unidadEscribible pero acumulando: la carga masiva no puede cortar. */
    private function unidadValida(mixed $id, array $user, array &$errores): ?array
    {
        $unidad = (int) $id > 0 ? $this->unidades->find((int) $id) : null;
        if ($unidad === null || (int) $unidad['activo'] !== 1) {
            $errores['unidad_id'] = 'Elige la unidad.';
            return null;
        }
        if (!can_write_station($user, (int) $unidad['estacion_id'])) {
            $errores['unidad_id'] = 'No tienes permiso sobre la estación de esa unidad.';
            return null;
        }
        return $unidad;
    }

    private function fecha(?string $valor): string
    {
        $errores = [];
        $fecha = $this->fechaValida($valor, $errores);
        if ($errores !== []) {
            json_unprocessable($errores);
        }
        return $fecha;
    }

    private function fechaValida(mixed $valor, array &$errores): ?string
    {
        $valor = trim((string) $valor);
        $fecha = DateTime::createFromFormat('Y-m-d', $valor);
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            $errores['fecha'] = 'Indica la fecha (AAAA-MM-DD).';
            return null;
        }
        if ($valor > (new DateTimeImmutable('today'))->format('Y-m-d')) {
            $errores['fecha'] = 'La fecha no puede ser futura.';
            return null;
        }
        return $valor;
    }

    private function kilometraje(mixed $valor, bool $obligatorio): ?int
    {
        $errores = [];
        $km = $this->kmValido($valor, $obligatorio, $errores);
        if ($errores !== []) {
            json_unprocessable($errores);
        }
        return $km;
    }

    private function kmValido(mixed $valor, bool $obligatorio, array &$errores): ?int
    {
        // Los separadores de millar se quitan: la hoja de hoy trae "120,500" y "120.500".
        $crudo = str_replace([',', ' ', '.'], '', trim((string) $valor));
        if ($crudo === '') {
            if ($obligatorio) {
                $errores['km'] = 'Escribe el kilometraje.';
            }
            return null;
        }
        if (!ctype_digit($crudo)) {
            $errores['km'] = 'El kilometraje solo lleva números.';
            return null;
        }
        return (int) $crudo;
    }

    private function texto(?string $valor, int $max): ?string
    {
        $valor = trim((string) $valor);
        return $valor === '' ? null : mb_substr($valor, 0, $max);
    }

    /** Una fila de catálogo activa, por id. */
    private function fila(string $tabla, int $id): ?array
    {
        // El nombre de tabla no viene del usuario: son dos constantes de este archivo.
        $stmt = $this->pdo->prepare("SELECT * FROM {$tabla} WHERE id = :id AND activo = 1 LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
