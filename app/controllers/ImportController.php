<?php
/**
 * Carga masiva: descarga de la plantilla y subida del archivo en dos pasos (analizar →
 * confirmar). Sirve a cualquier entidad; el importador concreto se inyecta.
 *
 * Aquí solo viven la autorización, la recepción del archivo y el formato de la respuesta.
 * Las reglas están en el importador y, a través de él, en el servicio de la entidad.
 */

declare(strict_types=1);

final class ImportController
{
    /**
     * @param ImportadorExcel $service    importador de la entidad
     * @param string          $archivo    base del nombre del archivo descargado ("flota")
     * @param string          $singular   "unidad" / "piloto", para los mensajes
     * @param string          $plural     "unidades" / "pilotos"
     * @param bool            $femenino   concordancia: "cargadas" vs "cargados"
     */
    public function __construct(
        private ImportadorExcel $service,
        private string $archivo,
        private string $singular,
        private string $plural,
        private bool $femenino = false
    ) {
    }

    /** Concuerda un adjetivo en género y número: ('cargad', 3) → "cargadas" o "cargados". */
    private function concuerda(string $raiz, int $n): string
    {
        return $raiz . ($this->femenino ? 'a' : 'o') . ($n === 1 ? '' : 's');
    }

    /** GET /{modulo}/plantilla.xlsx */
    public function plantilla(): void
    {
        $user = require_login_web();
        if (!in_array($user['rol'], [Rol::ADMIN_GLOBAL, Rol::ENCARGADO], true)) {
            http_response_code(403);
            echo 'No tienes permiso para dar de alta registros.';
            return;
        }

        $bytes = $this->service->plantilla($user);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="plantilla-' . $this->archivo . '-' . date('Ymd') . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
        echo $bytes;
    }

    /** POST /api/{modulo}/importar — analiza; con confirmar=1 además inserta. */
    public function importar(): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $archivo = $this->archivoRecibido();

        try {
            if (($_POST['confirmar'] ?? '') !== '1') {
                $informe = $this->service->analizar($archivo['tmp_name'], $archivo['name'], $user);
                json_ok([
                    'confirmado' => false,
                    'listas'     => count($informe['filas']),
                    'errores'    => $informe['errores'],
                    'vista'      => array_map(
                        static fn(array $f): array => ['fila' => $f['fila']] + $f['cruda'],
                        array_slice($informe['filas'], 0, 20)
                    ),
                ], $this->resumen($informe));
            }

            $resultado = $this->service->importar($archivo['tmp_name'], $archivo['name'], $user);
            if ($resultado['errores'] !== []) {
                json_ok(
                    ['confirmado' => false, 'listas' => 0, 'errores' => $resultado['errores'], 'vista' => []],
                    'No se cargó nada: el archivo tiene errores.'
                );
            }
            $n = $resultado['importadas'];
            json_ok(
                ['confirmado' => true, 'importadas' => $n, 'errores' => [], 'vista' => []],
                $n . ' ' . ($n === 1 ? $this->singular : $this->plural) . ' ' . $this->concuerda('cargad', $n) . '.'
            );
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 422);
        }
    }

    /** Valida la subida en sí (tamaño, error de transporte) antes de mirar el contenido. */
    private function archivoRecibido(): array
    {
        $archivo = $_FILES['archivo'] ?? null;
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            json_error('Adjunta el archivo con los datos.', 422);
        }
        if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE) {
            json_error('El archivo supera el tamaño permitido.', 422);
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            json_error('No se pudo recibir el archivo. Inténtalo de nuevo.', 422);
        }
        if ($archivo['size'] > ImportadorExcel::MAX_BYTES) {
            json_error('El archivo supera los ' . (ImportadorExcel::MAX_BYTES / 1024 / 1024) . ' MB.', 422);
        }
        return $archivo;
    }

    private function resumen(array $informe): string
    {
        $listas = count($informe['filas']);
        $conError = count(array_unique(array_column($informe['errores'], 'fila')));

        if ($informe['errores'] === []) {
            return $listas === 0
                ? 'El archivo no trae registros que cargar.'
                : $listas . ' ' . ($listas === 1 ? $this->singular : $this->plural)
                  . ' ' . $this->concuerda('list', $listas) . ' para cargar.';
        }
        return "Se encontraron problemas en {$conError} fila" . ($conError === 1 ? '' : 's')
            . '. No se cargará nada hasta corregirlos.';
    }
}
