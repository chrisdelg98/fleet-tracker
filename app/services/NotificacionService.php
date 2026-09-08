<?php
/**
 * Orquesta las notificaciones de Fase 4. Los envíos automáticos nunca deben romper
 * la operación principal: si el correo falla, se registra el error y el negocio sigue.
 */

declare(strict_types=1);

final class NotificacionService
{
    public function __construct(
        private PDO $pdo,
        private SuscripcionCorreoModel $suscripciones,
        private CorreoService $correo,
        private string $appUrl,
        private ?CorreoLogService $bitacora = null
    ) {
    }

    /**
     * Deja constancia del intento. Opcional para no obligar a quien solo instancia el
     * servicio en una prueba, y sin direcciones: solo cuántas eran y si el servidor pudo.
     */
    private function anotar(string $evento, string $referencia, int $enviados, ?string $error, ?string $usuario = null): void
    {
        // Sin envíos y sin error no hubo intento: casi todos los avisos automáticos terminan
        // así por falta de suscriptores, y anotarlos ahogaría lo que sí importa.
        if ($enviados === 0 && $error === null) {
            return;
        }
        $this->bitacora?->registrar([
            'evento'        => $evento,
            'referencia'    => $referencia,
            'destinatarios' => $enviados,
            'error'         => $error,
            'usuario'       => $usuario,
        ]);
    }

    public function notificarUnidadLiberadaPorUnidad(int $unidadId): void
    {
        $enviados = 0;
        $error = $this->safe(function () use ($unidadId, &$enviados): void {
            $unidad = $this->unidadDisponible($unidadId);
            if ($unidad === null) {
                return;
            }

            $destinatarios = $this->suscripciones->destinatariosUnidadLiberada((int) $unidad['estacion_id']);
            if ($destinatarios === []) {
                return;
            }

            $link = rtrim($this->appUrl, '/') . '/?estacion_id=' . (int) $unidad['estacion_id'] . '&fecha=' . rawurlencode((new DateTimeImmutable('now'))->format('Y-m-d'));
            $subject = 'Unidad liberada en ' . $unidad['estacion_codigo'] . ' · ' . $unidad['placa_unidad'];
            $html = $this->emailTemplate(
                'Unidad liberada',
                '<p>La unidad <strong>' . e($unidad['placa_unidad']) . '</strong> quedó disponible en <strong>' . e($unidad['estacion_nombre']) . '</strong>.</p>'
                . '<p>Puedes abrir el dashboard filtrado para esa estación desde el siguiente enlace.</p>',
                $link,
                'Ver disponibilidad'
            );
            $text = "La unidad {$unidad['placa_unidad']} quedó disponible en {$unidad['estacion_nombre']} ({$unidad['estacion_codigo']}).\n{$link}";

            foreach ($destinatarios as $dest) {
                $this->correo->send((string) $dest['email'], $subject, $html, $text);
                $enviados++;
            }
        });
        $this->anotar('unidad.liberada', 'Unidad #' . $unidadId, $enviados, $error);
    }

    public function notificarRetornoDisponible(int $movimientoId): void
    {
        $enviados = 0;
        $error = $this->safe(function () use ($movimientoId, &$enviados): void {
            $mov = $this->movimientoRetorno($movimientoId);
            if ($mov === null) {
                return;
            }

            $destinatarios = $this->suscripciones->destinatariosRetorno((int) $mov['pais_origen_id']);
            if ($destinatarios === []) {
                return;
            }

            $fecha = substr((string) $mov['fecha_fin_estimada'], 0, 10);
            $link = rtrim($this->appUrl, '/') . '/?solo_retorno=1&retorno_desde=' . (int) $mov['pais_destino_id'] . '&fecha=' . rawurlencode($fecha);
            $subject = 'Retorno disponible hacia ' . $mov['pais_origen_nombre'] . ' · ' . $mov['placa_unidad'];
            $html = $this->emailTemplate(
                'Retorno disponible',
                '<p>La unidad <strong>' . e($mov['placa_unidad']) . '</strong> tendrá retorno disponible de <strong>' . e($mov['pais_destino_nombre']) . '</strong> hacia <strong>' . e($mov['pais_origen_nombre']) . '</strong>.</p>'
                . '<p>El movimiento origen está programado para liberarse el <strong>' . e(substr((string) $mov['fecha_fin_estimada'], 0, 16)) . ' UTC</strong>.</p>',
                $link,
                'Ver retornos'
            );
            $text = "La unidad {$mov['placa_unidad']} tendrá retorno disponible de {$mov['pais_destino_nombre']} hacia {$mov['pais_origen_nombre']}.\n{$link}";

            foreach ($destinatarios as $dest) {
                $this->correo->send((string) $dest['email'], $subject, $html, $text);
                $enviados++;
            }
        });
        $this->anotar('retorno.disponible', 'Movimiento #' . $movimientoId, $enviados, $error);
    }

    /**
     * Confirmación de reserva a los contactos que se indicaron al crearla.
     *
     * A diferencia del resto de avisos, los destinatarios no vienen de las suscripciones sino
     * del propio movimiento: quien reserva decide a quién avisar de ESE viaje, que puede ser
     * un cliente externo sin usuario en el sistema.
     *
     * Son tres desenlaces distintos y quien llama tiene que poder contarlos aparte: se envió,
     * no había a quién, o falló. "Enviado" y "nadie a quien avisar" no son lo mismo.
     *
     * @return array{enviados: int, error: string|null}
     */
    public function notificarReservaCreada(
        int $movimientoId,
        ?string $destinatarios,
        string $evento = 'reserva.creada',
        ?string $usuario = null,
        ?string $correoUsuario = null
    ): array {
        $correos = CatalogoAdminService::correos((string) $destinatarios);
        if ($correos === []) {
            // Sin destinatarios no hubo intento: anotarlo llenaría la bitácora de silencios.
            return ['enviados' => 0, 'error' => null];
        }

        // Por referencia: si el tercer correo falla, los dos primeros ya salieron y el
        // aviso tiene que decir eso, no dar el envío entero por perdido.
        $enviados = 0;
        $error = $this->safe(function () use ($movimientoId, $correos, $correoUsuario, &$enviados): void {
            // El aviso lo lee quien recibe la unidad: necesita saber qué llega y quién la trae,
            // con los datos con que se identifica al motorista en la frontera y en la báscula.
            $stmt = $this->pdo->prepare(
                'SELECT m.id, m.estado, m.fecha_salida, m.fecha_fin_estimada, m.reservado_para,
                        m.referencia_cw, m.ruta_custom_origen, m.ruta_custom_destino,
                        r.ciudad_origen, r.ciudad_destino,
                        u.placa_unidad, cu.es_motriz AS unidad_es_motriz,
                        e.timezone, e.codigo AS estacion_codigo, e.nombre AS estacion_nombre,
                        p.nombre AS piloto, p.no_licencia, p.documento_identidad, p.telefonos,
                        p.codigo_nacional, p.codigo_internacional,
                        pais_e.etiqueta_codigo_nacional, pais_e.etiqueta_codigo_internacional,
                        po.codigo_iso AS origen, pd.codigo_iso AS destino
                   FROM movimientos m
                   JOIN unidades u ON u.id = m.unidad_id
                   JOIN categorias_vehiculo cu ON cu.id = u.categoria_vehiculo_id
                   JOIN estaciones e ON e.id = u.estacion_id
                   JOIN paises pais_e ON pais_e.id = e.pais_id
                   LEFT JOIN rutas r ON r.id = m.ruta_id
                   LEFT JOIN pilotos p ON p.id = m.piloto_id
                   LEFT JOIN paises po ON po.id = m.pais_origen_id
                   LEFT JOIN paises pd ON pd.id = m.pais_destino_id
                  WHERE m.id = :id'
            );
            $stmt->execute([':id' => $movimientoId]);
            $m = $stmt->fetch();
            if ($m === false) {
                return;
            }

            // En la hora de la estación: quien recibe el aviso trabaja en ese huso, no en UTC.
            $salida = format_local($m['fecha_salida'], $m['timezone'], 'd/m/Y H:i');
            $fin    = format_local($m['fecha_fin_estimada'], $m['timezone'], 'd/m/Y H:i');
            // La ciudad viene de la ruta del catálogo o, si el viaje se armó a mano, del propio
            // movimiento. Un país solo ("SV → SV") no dice nada en un viaje nacional: quien
            // recibe necesita saber de qué ciudad a cuál.
            $lado = static function (?string $ciudad, ?string $pais): string {
                $ciudad = trim((string) $ciudad);
                return $ciudad === '' ? ($pais ?? '?') : $ciudad . ', ' . ($pais ?? '?');
            };
            $rutaCorta = ($m['origen'] ?? '?') . ' → ' . ($m['destino'] ?? '?');
            $ruta = $lado($m['ruta_custom_origen'] ?: $m['ciudad_origen'], $m['origen'])
                . ' → ' . $lado($m['ruta_custom_destino'] ?: $m['ciudad_destino'], $m['destino']);

            // Cabezal y furgón salen de los papeles del viaje: la unidad reservada es uno de los
            // dos según su categoría, y el acompañante es el otro.
            [$cabezal, $furgon] = $this->placasDelViaje($movimientoId, $m);

            $filas = array_filter([
                'Placa cabezal' => $cabezal,
                'Placa furgón'  => $furgon,
                'Motorista'     => $m['piloto'] ?: 'Por asignar',
                // Cada país llama distinto a sus dos códigos de transporte.
                ($m['etiqueta_codigo_nacional'] ?: 'Código nacional')           => $m['codigo_nacional'],
                ($m['etiqueta_codigo_internacional'] ?: 'Código internacional') => $m['codigo_internacional'],
                'Licencia'      => $m['no_licencia'],
                'Documento'     => $m['documento_identidad'],
                'Teléfono'      => $m['telefonos'],
                'Ruta'          => $ruta,
                'Salida estimada' => $salida,
                // No es cuándo se entrega la carga —eso puede ocurrir bastante antes— sino
                // cuándo vuelve a estar libre la unidad. Es el mismo campo que el formulario
                // llama "Se libera", y llamarlo "entrega" hacía prometer una fecha distinta.
                'Liberación estimada' => $fin,
                'Referencia CW' => $m['referencia_cw'],
                // Al final y solo si viene: cierra la ficha diciendo para quién es el viaje,
                // que es lo que se mira de último, cuando ya se sabe qué unidad llega.
                'Reservado para' => $m['reservado_para'],
            ], static fn($v): bool => trim((string) $v) !== '');
            // Las dos placas en el asunto: quien recibe busca por la que le dijeron, y no
            // siempre es la de la unidad reservada — a veces le dan la del furgón.
            $placas = implode(' · ', array_filter([$cabezal, $furgon])) ?: (string) $m['placa_unidad'];
            // El prefijo dice el estado real, no "confirmada": un apartado y un programado no
            // son lo mismo, y al reenviar un viaje ya en curso el asunto lo refleja.
            $subject = EstadoMovimiento::label((string) $m['estado'])
                . ' · ' . $m['estacion_nombre'] . ' · ' . $placas . ' · ' . $rutaCorta;
            // El encabezado dice de dónde viene la reserva: quien la recibe trabaja con varias
            // estaciones y lo primero que necesita saber es quién se la está mandando.
            $html = $this->emailTemplate(
                mb_strtoupper((string) $m['estacion_nombre'], 'UTF-8'),
                '<p style="margin:0 0 16px; text-align:center;">' . ($m['estado'] === EstadoMovimiento::RESERVADO
                    ? 'Se reservó el siguiente movimiento.'
                    : 'Se programó el siguiente movimiento.') . '</p>'
                    . $this->tablaDetalle($filas),
                null,
                '',
                'Reserva hecha'
            );
            // La versión en texto plano repite el encabezado: hay clientes de correo que
            // no muestran HTML, y ahí también tiene que verse de quién viene la reserva.
            $text = 'RESERVA HECHA ' . mb_strtoupper((string) $m['estacion_nombre'], 'UTF-8') . "

"
                . implode("
", array_map(static fn($k, $v): string => "{$k}: {$v}", array_keys($filas), $filas));

            // Reply-To a quien reservó: el remitente es un buzón automático que nadie lee, así
            // que sin esto la respuesta del cliente se pierde.
            foreach ($correos as $correo) {
                $this->correo->send($correo, $subject, $html, $text, $correoUsuario);
                $enviados++;
            }

            // Y una copia para quien la hizo. Va aparte y no como Cc porque se manda un correo
            // por destinatario —para que los contactos no se vean entre ellos—, y un Cc en cada
            // uno le dejaría tantas copias como contactos tenga la lista.
            $suyo = trim((string) $correoUsuario);
            if ($suyo !== '' && !in_array(strtolower($suyo), array_map('strtolower', $correos), true)) {
                $this->correo->send($suyo, 'Copia · ' . $subject, $html, $text);
                $enviados++;
            }
        });

        $this->anotar($evento, 'Movimiento #' . $movimientoId, $enviados, $error, $usuario);

        return ['enviados' => $enviados, 'error' => $error];
    }

    public function enviarPrueba(array $suscripcion, array $user): void
    {
        $to = trim((string) ($user['email'] ?? ''));
        if ($to === '') {
            throw new RuntimeException('Tu usuario no tiene un correo configurado.');
        }

        if ($suscripcion['tipo'] === SuscripcionCorreoModel::TIPO_UNIDAD_LIBERADA) {
            $link = rtrim($this->appUrl, '/') . '/?estacion_id=' . (int) $suscripcion['estacion_id'] . '&fecha=' . rawurlencode((new DateTimeImmutable('now'))->format('Y-m-d'));
            $subject = '[Prueba] Unidad liberada en ' . ($suscripcion['estacion_codigo'] ?? 'estación');
            $html = $this->emailTemplate(
                'Prueba de unidad liberada',
                '<p>Este es un correo de prueba para tu suscripción de unidad liberada en <strong>' . e(($suscripcion['estacion_nombre'] ?? 'la estación')) . '</strong>.</p>',
                $link,
                'Abrir dashboard'
            );
            $text = 'Prueba de unidad liberada. ' . $link;
            $this->correo->send($to, $subject, $html, $text);
            return;
        }

        $link = rtrim($this->appUrl, '/') . '/?solo_retorno=1&retorno_desde=' . (int) $suscripcion['pais_id'] . '&fecha=' . rawurlencode((new DateTimeImmutable('now'))->format('Y-m-d'));
        $subject = '[Prueba] Retorno disponible hacia ' . ($suscripcion['pais_nombre'] ?? 'el país');
        $html = $this->emailTemplate(
            'Prueba de retorno disponible',
            '<p>Este es un correo de prueba para tu suscripción de retornos hacia <strong>' . e(($suscripcion['pais_nombre'] ?? 'el país seleccionado')) . '</strong>.</p>',
            $link,
            'Abrir dashboard'
        );
        $text = 'Prueba de retorno disponible. ' . $link;
        $this->correo->send($to, $subject, $html, $text);
    }

    private function unidadDisponible(int $unidadId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.placa_unidad, u.estacion_id, e.codigo AS estacion_codigo, e.nombre AS estacion_nombre
               FROM unidades u
               JOIN estaciones e ON e.id = u.estacion_id
              WHERE u.id = :id
                AND u.activo = 1
                AND u.en_disponibilidad = 1
                AND u.estado_vehiculo = :operativo
              LIMIT 1'
        );
        $stmt->execute([':id' => $unidadId, ':operativo' => EstadoVehiculo::OPERATIVO]);
        $unidad = $stmt->fetch() ?: null;
        if ($unidad === null) {
            return null;
        }

        $ahora = now_utc();
        $override = $this->pdo->prepare(
            'SELECT 1 FROM overrides_unidad
              WHERE unidad_id = :id
                AND cerrado = 0
                AND desde <= :ahora
                AND (hasta IS NULL OR hasta >= :ahora)
              LIMIT 1'
        );
        $override->execute([':id' => $unidadId, ':ahora' => $ahora]);
        if ($override->fetchColumn() !== false) {
            return null;
        }

        $mov = $this->pdo->prepare(
            'SELECT 1 FROM movimientos
              WHERE unidad_id = :id
                AND estado IN (\'RESERVADO\', \'PROGRAMADO\', \'EN_TRANSITO\')
                AND fecha_salida <= :ahora
                AND fecha_fin_estimada >= :ahora
              LIMIT 1'
        );
        $mov->execute([':id' => $unidadId, ':ahora' => $ahora]);
        return $mov->fetchColumn() === false ? $unidad : null;
    }

    private function movimientoRetorno(int $movimientoId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.pais_origen_id, m.fecha_fin_estimada, u.placa_unidad,
                    po.nombre AS pais_origen_nombre, pd.nombre AS pais_destino_nombre
               FROM movimientos m
               JOIN unidades u ON u.id = m.unidad_id
               JOIN paises po ON po.id = m.pais_origen_id
               JOIN paises pd ON pd.id = m.pais_destino_id
              WHERE m.id = :id
                AND m.tipo_ruta = :tipo
                AND m.retorno_disponible = 1
                AND m.movimiento_regreso_id IS NULL
              LIMIT 1'
        );
        $stmt->execute([':id' => $movimientoId, ':tipo' => TipoRuta::INTERNACIONAL]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Marco común de los correos.
     *
     * El botón es opcional: hay avisos que solo informan y no tienen a dónde llevarte. El
     * antetítulo también, y sirve para nombrar el título sin repetirlo dentro: "Reserva hecha
     * por" arriba y la estación debajo se lee de un golpe.
     */
    private function emailTemplate(
        string $title,
        string $body,
        ?string $link = null,
        string $cta = '',
        string $antetitulo = ''
    ): string {
        $boton = $link === null || $cta === '' ? '' :
            '<p style="margin:24px 0 0"><a href="' . e($link) . '" style="display:inline-block;background:#1f4e79;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:600">' . e($cta) . '</a></p>';

        $encabezado = $antetitulo === ''
            ? '<h1 style="margin:0 0 16px;font-size:24px;color:#1f4e79">' . e($title) . '</h1>'
            : '<div style="text-align:center;margin:0 0 20px">'
                . '<div style="font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#5b6470">'
                . e($antetitulo) . '</div>'
                . '<h1 style="margin:4px 0 0;font-size:24px;color:#1f4e79;text-transform:uppercase;letter-spacing:0.02em">'
                . e($title) . '</h1></div>';

        return '<html lang="es"><body style="font-family:Segoe UI,Arial,sans-serif;background:#f4f6f8;padding:24px;color:#1c2733">'
            . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #dde3ea;border-radius:8px;padding:24px">'
            . $encabezado
            . $body
            . $boton
            . '</div></body></html>';
    }

    /**
     * Tabla de dos columnas con rejilla, como la que se llevaba a mano en la hoja de control.
     *
     * El borde no es decoración: sin él la vista salta de una fila a otra al leer en el
     * teléfono, y quien recibe esto está copiando placas y números de licencia. Todo va con
     * estilos en línea porque los clientes de correo descartan las hojas de estilo.
     *
     * @param array<string, string> $filas
     */
    private function tablaDetalle(array $filas): string
    {
        $borde = '1px solid #b9c2cc';
        $html = '<table role="presentation" cellpadding="0" cellspacing="0"'
            . ' style="border-collapse:collapse;width:100%;font-size:15px;line-height:1.45">';
        foreach ($filas as $etiqueta => $valor) {
            // Un salto por fila: el transporte ya no depende de ello desde que el cuerpo va
            // codificado, pero deja el fuente legible y no vuelve a nacer una línea enorme.
            $html .= "\n" . '<tr>'
                . '<td style="border:' . $borde . ';background:#eef2f6;padding:7px 12px;'
                . 'font-weight:600;color:#31404f;white-space:nowrap">' . e($etiqueta) . '</td>'
                . '<td style="border:' . $borde . ';padding:7px 12px;font-weight:700;color:#12202e">'
                . e((string) $valor) . '</td>'
                . '</tr>';
        }
        return $html . '</table>';
    }

    /**
     * Placas de cabezal y furgón de un movimiento.
     *
     * La unidad reservada puede ser cualquiera de las dos —se reserva el cabezal o se reserva
     * el furgón—, así que se decide por su categoría y el activo de apoyo ocupa el otro lugar.
     *
     * @return array{0: string, 1: string}
     */
    private function placasDelViaje(int $movimientoId, array $m): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mu.rol, u.placa_unidad
               FROM movimiento_unidades mu
               JOIN unidades u ON u.id = mu.unidad_id
              WHERE mu.movimiento_id = :id AND mu.liberado_en IS NULL'
        );
        $stmt->execute([':id' => $movimientoId]);
        $apoyos = [];
        foreach ($stmt->fetchAll() as $fila) {
            $apoyos[$fila['rol']] = (string) $fila['placa_unidad'];
        }

        $esMotriz = (int) $m['unidad_es_motriz'] === 1;
        $cabezal = $esMotriz ? (string) $m['placa_unidad'] : ($apoyos[RolUnidadMovimiento::MOTRIZ] ?? '');
        $furgon  = $esMotriz ? ($apoyos[RolUnidadMovimiento::ARRASTRE] ?? '') : (string) $m['placa_unidad'];

        return [$cabezal, $furgon];
    }

    /**
     * Un aviso que falla no puede tumbar la operación que lo disparó: la reserva ya se guardó.
     * Pero tampoco puede desaparecer —un SMTP mal configurado se veía igual que un envío
     * correcto—, así que devuelve el motivo para que quien llame decida si mostrarlo.
     *
     * @return string|null null si salió bien; el motivo del fallo si no.
     */
    private function safe(callable $callback): ?string
    {
        try {
            $callback();
            return null;
        } catch (Throwable $e) {
            error_log('Notificación Fase 4: ' . $e->getMessage());
            return $e->getMessage();
        }
    }
}