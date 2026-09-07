<?php
/**
 * Bitácora de correo saliente.
 *
 * Va a un archivo y no a una tabla a propósito: se escribe justo cuando algo puede estar
 * fallando —la base caída, una transacción a medias— y un registro que depende de lo mismo
 * que intenta vigilar no sirve para diagnosticar.
 *
 * Lo que se anota es que el servidor de correo **aceptó** el mensaje, que es lo único que
 * este proceso presencia: la entrega al buzón ocurre después y fuera de aquí. Confundir las
 * dos cosas fue lo que hizo que un correo rechazado al encaminarse figurara como enviado.
 *
 * **No se guardan las direcciones**, solo cuántas eran. Basta para saber si el servidor pudo
 * enviar, y evita dejar correos de clientes en un archivo de texto que nadie audita.
 *
 * Una línea por evento en JSON, que sobrevive a un motivo de error con comas, saltos o
 * comillas —justo lo que devuelve un SMTP cuando se queja—.
 */

declare(strict_types=1);

final class CorreoLogService
{
    /** Al pasarse, el archivo se aparta como .1 y se empieza uno nuevo. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    private string $archivo;

    public function __construct(?string $archivo = null)
    {
        $this->archivo = $archivo ?? BASE_PATH . '/storage/logs/correo.log';
    }

    /**
     * Anota un intento de envío. Nunca lanza: si no se puede escribir el registro, eso no
     * puede tumbar la operación que lo generó.
     *
     * @param array{evento: string, referencia?: string, destinatarios: int,
     *              error?: string|null, usuario?: string|null} $datos
     */
    public function registrar(array $datos): void
    {
        try {
            $this->rotarSiHaceFalta();
            $linea = json_encode([
                'fecha'         => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'evento'        => (string) $datos['evento'],
                'referencia'    => (string) ($datos['referencia'] ?? ''),
                'destinatarios' => (int) $datos['destinatarios'],
                'ok'            => empty($datos['error']),
                'error'         => $datos['error'] ?? null,
                'usuario'       => $datos['usuario'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $dir = dirname($this->archivo);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            // LOCK_EX: dos peticiones simultáneas no pueden entrelazar sus líneas.
            @file_put_contents($this->archivo, $linea . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            error_log('No se pudo escribir la bitácora de correo: ' . $e->getMessage());
        }
    }

    /**
     * Página de eventos, del más reciente al más antiguo.
     *
     * @return array{filas: list<array>, total: int, paginas: int, pagina: int}
     */
    public function leer(int $pagina = 1, int $porPagina = 100, string $q = '', bool $soloFallidos = false): array
    {
        $lineas = is_file($this->archivo)
            ? (file($this->archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            : [];

        $filas = [];
        foreach (array_reverse($lineas) as $linea) {
            $fila = json_decode($linea, true);
            if (!is_array($fila)) {
                continue;   // línea a medias por un corte de escritura: se ignora, no se rompe
            }
            if ($soloFallidos && !empty($fila['ok'])) {
                continue;
            }
            if ($q !== '' && stripos($linea, $q) === false) {
                continue;
            }
            $filas[] = $fila;
        }

        $total = count($filas);
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));

        return [
            'filas'   => array_slice($filas, ($pagina - 1) * $porPagina, $porPagina),
            'total'   => $total,
            'paginas' => $paginas,
            'pagina'  => $pagina,
        ];
    }

    /** Cuántos envíos fallaron de los últimos $n, para el resumen de la pantalla. */
    public function fallosRecientes(int $n = 100): int
    {
        $ultimos = $this->leer(1, $n)['filas'];
        return count(array_filter($ultimos, static fn(array $f): bool => empty($f['ok'])));
    }

    private function rotarSiHaceFalta(): void
    {
        if (is_file($this->archivo) && filesize($this->archivo) > self::MAX_BYTES) {
            @rename($this->archivo, $this->archivo . '.1');
        }
    }
}
