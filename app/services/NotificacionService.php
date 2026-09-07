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
        private string $appUrl
    ) {
    }

    public function notificarUnidadLiberadaPorUnidad(int $unidadId): void
    {
        $this->safe(function () use ($unidadId): void {
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
            }
        });
    }

    public function notificarRetornoDisponible(int $movimientoId): void
    {
        $this->safe(function () use ($movimientoId): void {
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
            }
        });
    }

    /**
     * Confirmación de reserva a los contactos que se indicaron al crearla.
     *
     * A diferencia del resto de avisos, los destinatarios no vienen de las suscripciones sino
     * del propio movimiento: quien reserva decide a quién avisar de ESE viaje, que puede ser
     * un cliente externo sin usuario en el sistema.
     */
    /** @return string|null null si se envió (o no había a quién); el motivo del fallo si no. */
    public function notificarReservaCreada(int $movimientoId, ?string $destinatarios): ?string
    {
        $correos = CatalogoAdminService::correos((string) $destinatarios);
        if ($correos === []) {
            return null;
        }

        return $this->safe(function () use ($movimientoId, $correos): void {
            // El aviso lo lee quien recibe la unidad: necesita saber qué llega y quién la trae,
            // con los datos con que se identifica al motorista en la frontera y en la báscula.
            $stmt = $this->pdo->prepare(
                'SELECT m.id, m.estado, m.fecha_salida, m.fecha_fin_estimada, m.reservado_para,
                        m.referencia_cw,
                        u.placa_unidad, cu.es_motriz AS unidad_es_motriz,
                        e.timezone, e.codigo AS estacion_codigo,
                        p.nombre AS piloto, p.no_licencia, p.documento_identidad, p.telefonos,
                        p.codigo_nacional, p.codigo_internacional,
                        pais_e.etiqueta_codigo_nacional, pais_e.etiqueta_codigo_internacional,
                        po.codigo_iso AS origen, pd.codigo_iso AS destino
                   FROM movimientos m
                   JOIN unidades u ON u.id = m.unidad_id
                   JOIN categorias_vehiculo cu ON cu.id = u.categoria_vehiculo_id
                   JOIN estaciones e ON e.id = u.estacion_id
                   JOIN paises pais_e ON pais_e.id = e.pais_id
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
            $ruta   = ($m['origen'] ?? '?') . ' → ' . ($m['destino'] ?? '?');

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
                'Salida'        => $salida,
                'Entrega estimada' => $fin,
                'Reservado para' => $m['reservado_para'],
                'Referencia CW' => $m['referencia_cw'],
            ], static fn($v): bool => trim((string) $v) !== '');
            $detalle = '<table style="border-collapse:collapse">';
            foreach ($filas as $k => $v) {
                $detalle .= '<tr><td style="padding:4px 12px 4px 0;color:#5b6470">' . e($k) . '</td>'
                    . '<td style="padding:4px 0"><strong>' . e((string) $v) . '</strong></td></tr>';
            }
            $detalle .= '</table>';

            $subject = 'Reserva confirmada · ' . $m['placa_unidad'] . ' · ' . $ruta;
            $html = $this->emailTemplate(
                'Reserva confirmada',
                '<p>Se programó el siguiente movimiento.</p>' . $detalle,
                rtrim($this->appUrl, '/') . '/timeline',
                'Ver el timeline'
            );
            $text = "Reserva confirmada
"
                . implode("
", array_map(static fn($k, $v): string => "{$k}: {$v}", array_keys($filas), $filas));

            foreach ($correos as $correo) {
                $this->correo->send($correo, $subject, $html, $text);
            }
        });
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

    private function emailTemplate(string $title, string $body, string $link, string $cta): string
    {
        return '<html lang="es"><body style="font-family:Segoe UI,Arial,sans-serif;background:#f4f6f8;padding:24px;color:#1c2733">'
            . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #dde3ea;border-radius:8px;padding:24px">'
            . '<h1 style="margin:0 0 16px;font-size:24px;color:#1f4e79">' . e($title) . '</h1>'
            . $body
            . '<p style="margin:24px 0 0"><a href="' . e($link) . '" style="display:inline-block;background:#1f4e79;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:600">' . e($cta) . '</a></p>'
            . '</div></body></html>';
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