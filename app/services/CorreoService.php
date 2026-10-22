<?php
/**
 * Envío mínimo de correo por SMTP usando la configuración del .env. No depende de
 * librerías externas, en línea con el stack del proyecto.
 */

declare(strict_types=1);

final class CorreoService
{
    public function __construct(private array $config)
    {
    }

    public function configured(): bool
    {
        return $this->faltantes() === [];
    }

    /**
     * Claves de configuración sin las que no se puede enviar.
     *
     * Se devuelven por nombre porque el mensaje viejo nombraba las dos siempre, y con eso
     * no había forma de saber cuál de las dos era la que faltaba.
     *
     * @return list<string>
     */
    private function faltantes(): array
    {
        $faltan = [];
        if (trim((string) ($this->config['host'] ?? '')) === '') {
            $faltan[] = 'MAIL_HOST';
        }
        if (trim((string) ($this->config['from'] ?? '')) === '') {
            $faltan[] = 'MAIL_FROM (o MAIL_FROM_ADDRESS)';
        }
        return $faltan;
    }

    /**
     * @param string|null $responderA Dirección a la que va la respuesta del destinatario.
     *                                Sin ella, un cliente que contesta el aviso escribe al
     *                                buzón automático del sistema, que nadie lee.
     */
    public function send(string $to, string $subject, string $html, string $text = '', ?string $responderA = null): void
    {
        $errores = $this->enviarLote([[
            'to' => $to, 'subject' => $subject, 'html' => $html,
            'text' => $text, 'responder_a' => $responderA,
        ]]);
        if ($errores[0] !== null) {
            throw new RuntimeException($errores[0]);
        }
    }

    /**
     * Varios mensajes sobre una sola sesión SMTP, cada uno aislado del resto.
     *
     * Las dos cosas importan y son el mismo arreglo. Antes se abría una conexión por mensaje
     * —una reserva con tres contactos y su copia eran cuatro AUTH seguidos—, que es justo lo
     * que un servidor compartido empieza a frenar. Y el fallo de un destinatario se llevaba
     * por delante a los que faltaban: si la primera dirección rebotaba, los demás y la copia
     * del autor no se intentaban siquiera.
     *
     * Cuando algo falla se reintenta ese mensaje una vez sobre una sesión nueva, porque el
     * motivo más común —la sesión se cayó o el servidor cortó por ritmo— se arregla solo al
     * reconectar. Si vuelve a fallar, se anota y se sigue con el siguiente.
     *
     * @param list<array{to:string,subject:string,html:string,text?:string,responder_a?:?string}> $mensajes
     * @return list<string|null> el motivo del fallo de cada mensaje, en el mismo orden; null si salió.
     */
    public function enviarLote(array $mensajes): array
    {
        $faltan = $this->faltantes();
        if ($faltan !== []) {
            throw new RuntimeException('Falta configurar ' . implode(' y ', $faltan) . ' en el .env.');
        }

        $from = trim((string) $this->config['from']);
        $fromName = trim((string) ($this->config['from_name'] ?? 'Flete Finder'));

        $errores = [];
        $socket = null;
        try {
            foreach (array_values($mensajes) as $i => $m) {
                $texto = (string) ($m['text'] ?? '');
                $payload = $this->buildMessage(
                    $from,
                    $fromName,
                    $m['to'],
                    $m['subject'],
                    $m['html'],
                    $texto !== '' ? $texto : strip_tags($m['html']),
                    $m['responder_a'] ?? null
                );

                $errores[$i] = null;
                for ($intento = 1; $intento <= 2; $intento++) {
                    try {
                        // Antes de hablar con nadie: una línea demasiado larga la acepta el
                        // servidor y la rechaza después, al encaminarla, cuando ya nadie está
                        // mirando. Comprobarlo aquí lo convierte en un error inmediato.
                        $this->assertLineasCortas($payload);
                        if (!is_resource($socket)) {
                            $socket = $this->abrirSesion();
                        }
                        $this->entregar($socket, $from, (string) $m['to'], $payload);
                        $errores[$i] = null;
                        break;
                    } catch (Throwable $e) {
                        $errores[$i] = $e->getMessage();
                        // La sesión queda en un estado que no se puede dar por bueno: se cierra
                        // para que el siguiente intento —de este mensaje o del próximo— arranque
                        // limpio en vez de heredar una transacción a medias.
                        $this->cerrar($socket);
                        $socket = null;
                    }
                }
            }
        } finally {
            $this->cerrar($socket, true);
        }

        return $errores;
    }

    /** Conexión, saludo, TLS y autenticación: todo lo que se hace una vez por sesión. */
    private function abrirSesion()
    {
        $socket = $this->connect();
        $this->expect($socket, [220]);
        $this->command($socket, 'EHLO localhost', [250]);

        $encryption = strtolower((string) ($this->config['encryption'] ?? ''));
        $port = (int) ($this->config['port'] ?? 25);
        if ($encryption === 'tls' || ($encryption === '' && $port === 587)) {
            $this->command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo activar TLS para el correo saliente.');
            }
            $this->command($socket, 'EHLO localhost', [250]);
        }

        $username = trim((string) ($this->config['username'] ?? ''));
        $password = (string) ($this->config['password'] ?? '');
        if ($username !== '') {
            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($username), [334]);
            $this->command($socket, base64_encode($password), [235]);
        }

        return $socket;
    }

    /** Un mensaje dentro de una sesión ya abierta. */
    private function entregar($socket, string $from, string $to, string $payload): void
    {
        $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        $this->command($socket, 'DATA', [354]);
        $this->write($socket, $payload . "\r\n.\r\n");
        $this->expect($socket, [250]);
    }

    /** El QUIT es cortesía: si la sesión ya está rota, cerrar el socket basta. */
    private function cerrar($socket, bool $despedirse = false): void
    {
        if (!is_resource($socket)) {
            return;
        }
        if ($despedirse) {
            try {
                $this->command($socket, 'QUIT', [221]);
            } catch (Throwable) {
                // da igual: se cierra de todos modos
            }
        }
        @fclose($socket);
    }

    private function connect()
    {
        $host = trim((string) $this->config['host']);
        $port = (int) ($this->config['port'] ?? 25);
        $transport = strtolower((string) ($this->config['encryption'] ?? '')) === 'ssl' ? 'ssl://' : '';

        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            throw new RuntimeException('No se pudo conectar al servidor SMTP: ' . $errstr . ' (' . $errno . ').');
        }

        stream_set_timeout($socket, 15);
        return $socket;
    }

    private function buildMessage(string $from, string $fromName, string $to, string $subject, string $html, string $text, ?string $responderA = null): string
    {
        $boundary = 'b' . bin2hex(random_bytes(12));
        // Message-ID propio y único. Un mensaje sin él es de lo primero que penalizan los
        // filtros —Microsoft 365 entre ellos—, y como la penalización es por puntos, el mismo
        // aviso entra en una bandeja y en otra se va a correo no deseado. También es lo que
        // usa el cliente para no mostrar dos veces el mismo correo.
        $dominio = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $this->mimeHeader($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->mimeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if ($responderA !== null && trim($responderA) !== '') {
            $headers[] = 'Reply-To: <' . trim($responderA) . '>';
        }

        // Base64 y no 8bit: el HTML de un aviso se genera en una sola línea larguísima y SMTP
        // limita la línea a 998 octetos (el Exim del servidor rechazaba ya a los 2048).
        // Codificado, ninguna línea pasa de 76 pase lo que pase con el contenido.
        $body = [];
        $body[] = '--' . $boundary;
        $body[] = 'Content-Type: text/plain; charset=UTF-8';
        $body[] = 'Content-Transfer-Encoding: base64';
        $body[] = '';
        $body[] = $this->base64($text);
        $body[] = '--' . $boundary;
        $body[] = 'Content-Type: text/html; charset=UTF-8';
        $body[] = 'Content-Transfer-Encoding: base64';
        $body[] = '';
        $body[] = $this->base64($html);
        $body[] = '--' . $boundary . '--';

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);
    }

    private function mimeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * Ninguna línea puede pasar de 998 octetos (RFC 5321 §4.5.3.1).
     *
     * Con el cuerpo en base64 esto no debería ocurrir nunca: es una red de seguridad contra
     * una cabecera larga o un cambio futuro en cómo se arma el mensaje. Falla aquí a
     * propósito, porque el otro camino es que el correo se acepte y se pierda al entregarlo.
     */
    private function assertLineasCortas(string $payload): void
    {
        foreach (explode("\r\n", $payload) as $n => $linea) {
            if (strlen($linea) > 998) {
                throw new RuntimeException(sprintf(
                    'El mensaje tiene una línea de %d caracteres (línea %d) y el máximo del protocolo es 998; '
                    . 'el servidor lo aceptaría y luego no podría entregarlo.',
                    strlen($linea),
                    $n + 1
                ));
            }
        }
    }

    /**
     * Cuerpo en base64, cortado en líneas de 76.
     *
     * Sustituye al relleno de puntos que hacía falta con 8bit: el alfabeto base64 no incluye
     * el punto, así que ninguna línea puede empezar por uno y hacerse pasar por el fin de
     * los datos. Los saltos se normalizan a CRLF antes de codificar, que es lo que espera
     * quien decodifique al otro lado.
     */
    private function base64(string $value): string
    {
        $normalizado = str_replace(["\r\n", "\r"], "\n", $value);
        $crlf = str_replace("\n", "\r\n", $normalizado);
        return rtrim(chunk_split(base64_encode($crlf), 76, "\r\n"), "\r\n");
    }

    private function command($socket, string $command, array $codes): string
    {
        $this->write($socket, $command . "\r\n");
        return $this->expect($socket, $codes);
    }

    private function write($socket, string $payload): void
    {
        fwrite($socket, $payload);
    }

    private function expect($socket, array $codes): string
    {
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('Error SMTP: ' . trim($response));
        }
        return $response;
    }
}