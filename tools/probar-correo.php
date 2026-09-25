<?php
/**
 * Diagnóstico del correo saliente.
 *
 * Un envío que falla dentro de la aplicación solo puede decir "no se pudo conectar". Esto
 * recorre los mismos pasos por separado —DNS, puerto, saludo, cifrado, autenticación— para
 * que el fallo señale a un culpable concreto en vez de a "el correo no anda".
 *
 * Uso:
 *   php tools/probar-correo.php                    solo diagnostica, no envía nada
 *   php tools/probar-correo.php alguien@dominio    además envía un correo de prueba
 *
 * Para tantear otro servidor sin tocar el .env:
 *   php tools/probar-correo.php --host=mail.dominio.com --port=587 --encryption=tls
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/env.php';
$env = load_env($root . '/.env');

$primero = static function (string ...$claves) use ($env): string {
    foreach ($claves as $clave) {
        $valor = trim((string) ($env[$clave] ?? ''));
        if ($valor !== '') {
            return $valor;
        }
    }
    return '';
};

// Las opciones de la línea de comandos pisan al .env: así se prueba un host alternativo
// sin editar el archivo de configuración del servidor.
$opciones = [];
$destino = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
        $opciones[$m[1]] = $m[2];
    } else {
        $destino = $arg;
    }
}

$host    = $opciones['host'] ?? $primero('MAIL_HOST');
$puerto  = (int) ($opciones['port'] ?? ($primero('MAIL_PORT') ?: '25'));
$cifrado = strtolower($opciones['encryption'] ?? $primero('MAIL_ENCRYPTION'));
$usuario = $primero('MAIL_USERNAME');
$clave   = (string) ($env['MAIL_PASSWORD'] ?? '');
$from    = $primero('MAIL_FROM', 'MAIL_FROM_ADDRESS');

$linea = static fn(string $k, string $v): string => sprintf("  %-14s %s\n", $k, $v);
echo "\n── Configuración leída de .env ──\n";
echo $linea('MAIL_HOST', $host ?: '«vacío»');
echo $linea('MAIL_PORT', (string) $puerto);
echo $linea('ENCRYPTION', $cifrado ?: '«vacío»');
echo $linea('MAIL_USERNAME', $usuario !== '' ? $usuario : '«vacío»');
echo $linea('MAIL_PASSWORD', $clave !== '' ? '(' . strlen($clave) . ' caracteres)' : '«vacío»');
echo $linea('remitente', $from ?: '«vacío»');

if ($host === '' || $from === '') {
    fwrite(STDERR, "\nFaltan MAIL_HOST o el remitente; nada que probar.\n");
    exit(1);
}

// ── 1. DNS ──
// El error "Network is unreachable" casi siempre es esto: el nombre resuelve a una IPv6 y la
// máquina no tiene ruta IPv6, así que el intento muere antes de tocar el puerto.
echo "\n── 1. A qué IP resuelve {$host} ──\n";
$v4 = @dns_get_record($host, DNS_A) ?: [];
$v6 = @dns_get_record($host, DNS_AAAA) ?: [];
foreach ($v4 as $r) {
    echo $linea('IPv4', $r['ip']);
}
foreach ($v6 as $r) {
    echo $linea('IPv6', $r['ipv6']);
}
if ($v4 === [] && $v6 === []) {
    echo "  (sin respuesta de DNS: el nombre puede no existir o el resolutor no contesta)\n";
}
if ($v6 !== [] && $v4 === []) {
    echo "  ⚠ Solo hay IPv6. Si la máquina no tiene salida IPv6, el error será exactamente\n"
       . "    'Network is unreachable'. Probá con la IPv4 del servidor o con 'localhost'.\n";
}

// Un dominio detrás de Cloudflare (o de cualquier proxy de HTTP) no lleva SMTP a ninguna
// parte: el 465 muere en el proxy. Se detecta por la IP y no por el panel, porque lo que
// manda es a dónde resuelve el nombre puesto en MAIL_HOST, no el registro de al lado.
$rangosProxy = '/^(104\.(1[6-9]|2[0-9]|3[01])\.|172\.(6[4-9]|7[01])\.|2606:4700)/';
$proxiadas = array_filter(
    array_merge(array_column($v4, 'ip'), array_column($v6, 'ipv6')),
    static fn(string $ip): bool => (bool) preg_match($rangosProxy, $ip)
);
if ($proxiadas !== []) {
    $sugerido = 'mail.' . implode('.', array_slice(explode('.', $host), -2));
    echo "  ⚠ Esas IP son de Cloudflare: el nombre está proxiado (nube naranja).\n"
       . "    El proxy solo transporta HTTP y HTTPS, así que el SMTP no llega al servidor por\n"
       . "    correctos que sean el usuario y la contraseña.\n"
       . "    Apuntá MAIL_HOST al nombre del servidor de correo, que suele estar en DNS only:\n"
       . "      MAIL_HOST={$sugerido}\n"
       . "    Para comprobarlo sin tocar nada: php tools/probar-correo.php --host={$sugerido}\n";
}

// ── 2. Puerto ──
$transporte = $cifrado === 'ssl' ? 'ssl://' : '';
echo "\n── 2. Conectar a {$transporte}{$host}:{$puerto} ──\n";
$inicio = microtime(true);
$socket = @stream_socket_client($transporte . $host . ':' . $puerto, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
$tardo = round((microtime(true) - $inicio) * 1000);

if (!is_resource($socket)) {
    echo "  ✗ No conecta tras {$tardo} ms: {$errstr} ({$errno})\n";
    echo match ($errno) {
        101, 10051 => "\n  'Network is unreachable' = la máquina ni siquiera tiene ruta hacia esa IP.\n"
            . "  No es la contraseña ni el puerto: es red. Suele ser IPv6 sin ruta, o el proveedor\n"
            . "  bloqueando la salida al 465/587. En un cPanel el correo casi siempre está en la\n"
            . "  misma máquina: probá MAIL_HOST=localhost.\n",
        110, 10060 => "\n  Tiempo agotado: hay ruta pero nadie contesta. Cortafuegos o puerto cerrado.\n",
        111, 10061 => "\n  Conexión rechazada: el puerto está cerrado en ese host.\n",
        default    => "\n  Revisá host, puerto y si el proveedor permite salida SMTP.\n",
    };
    exit(1);
}
echo "  ✓ Conectado en {$tardo} ms\n";

// ── 3. Conversación SMTP ──
stream_set_timeout($socket, 15);
$leer = static function ($socket): string {
    $out = '';
    while (($linea = fgets($socket, 515)) !== false) {
        $out .= $linea;
        if (strlen($linea) < 4 || $linea[3] !== '-') {
            break;
        }
    }
    return trim($out);
};
$mandar = static function ($socket, string $cmd, bool $secreto = false) use ($leer): string {
    fwrite($socket, $cmd . "\r\n");
    $r = $leer($socket);
    printf("  %-28s → %s\n", $secreto ? '(credencial)' : $cmd, strtok($r, "\n"));
    return $r;
};

echo "\n── 3. Diálogo SMTP ──\n";
echo '  ' . str_pad('(saludo del servidor)', 28) . ' → ' . strtok($leer($socket), "\n") . "\n";
$mandar($socket, 'EHLO fleet-tracker');

if ($cifrado === 'tls' || ($cifrado === '' && $puerto === 587)) {
    $mandar($socket, 'STARTTLS');
    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        echo "  ✗ No se pudo activar TLS (certificado o versión incompatible).\n";
        exit(1);
    }
    echo "  ✓ TLS activo\n";
    $mandar($socket, 'EHLO fleet-tracker');
}

if ($usuario !== '') {
    $r = $mandar($socket, 'AUTH LOGIN');
    if (str_starts_with($r, '334')) {
        $mandar($socket, base64_encode($usuario), true);
        $r = $mandar($socket, base64_encode($clave), true);
        echo str_starts_with($r, '235')
            ? "  ✓ Autenticación aceptada\n"
            : "  ✗ Autenticación rechazada: revisá MAIL_USERNAME y MAIL_PASSWORD.\n";
    }
}

if ($destino === null) {
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    echo "\n  Diagnóstico terminado. No se envió ningún correo.\n"
       . "  Para enviar uno de prueba: php tools/probar-correo.php tu@correo.com\n\n";
    exit(0);
}

// ── 4. Envío real ──
fwrite($socket, "QUIT\r\n");
fclose($socket);
echo "\n── 4. Enviando a {$destino} ──\n";
require_once $root . '/app/services/CorreoService.php';
$correo = new CorreoService([
    'host' => $host, 'port' => $puerto, 'username' => $usuario, 'password' => $clave,
    'from' => $from, 'from_name' => $primero('MAIL_FROM_NAME', 'APP_NAME') ?: 'Flete Finder',
    'encryption' => $cifrado,
]);
try {
    $correo->send($destino, 'Prueba de correo · Flete Finder',
        '<p>Si estás leyendo esto, el correo saliente funciona.</p>');
    echo "  ✓ Enviado. Revisá la bandeja (y la carpeta de no deseado).\n\n";
} catch (Throwable $e) {
    echo '  ✗ ' . $e->getMessage() . "\n\n";
    exit(1);
}
