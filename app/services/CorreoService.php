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
        return trim((string) ($this->config['host'] ?? '')) !== ''
            && trim((string) ($this->config['from'] ?? '')) !== '';
    }

    public function send(string $to, string $subject, string $html, string $text = ''): void
    {
        if (!$this->configured()) {
            throw new RuntimeException('Configura MAIL_HOST y MAIL_FROM para enviar correos.');
        }

        $from = trim((string) $this->config['from']);
        $fromName = trim((string) ($this->config['from_name'] ?? 'Disponibilidad de Flota'));
        $payload = $this->buildMessage($from, $fromName, $to, $subject, $html, $text !== '' ? $text : strip_tags($html));

        $socket = $this->connect();
        try {
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

            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $this->write($socket, $payload . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
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

    private function buildMessage(string $from, string $fromName, string $to, string $subject, string $html, string $text): string
    {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $this->mimeHeader($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->mimeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = [];
        $body[] = '--' . $boundary;
        $body[] = 'Content-Type: text/plain; charset=UTF-8';
        $body[] = 'Content-Transfer-Encoding: 8bit';
        $body[] = '';
        $body[] = $this->dotStuff($text);
        $body[] = '--' . $boundary;
        $body[] = 'Content-Type: text/html; charset=UTF-8';
        $body[] = 'Content-Transfer-Encoding: 8bit';
        $body[] = '';
        $body[] = $this->dotStuff($html);
        $body[] = '--' . $boundary . '--';

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);
    }

    private function mimeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function dotStuff(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        return preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $normalized)) ?? $value;
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