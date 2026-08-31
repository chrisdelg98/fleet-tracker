<?php
/**
 * Carga masiva de flota: descarga de la plantilla y subida del archivo en dos pasos
 * (analizar → confirmar). Aquí solo viven la autorización, la recepción del archivo y el
 * formato de la respuesta; las reglas están en FlotaImportService.
 */

declare(strict_types=1);

final class FlotaImportController
{
    public function __construct(private FlotaImportService $service)
    {
    }

    /** GET /flota/plantilla.xlsx */
    public function plantilla(): void
    {
        $user = require_login_web();
        if (!in_array($user['rol'], [Rol::ADMIN_GLOBAL, Rol::ENCARGADO], true)) {
            http_response_code(403);
            echo 'No tienes permiso para dar de alta unidades.';
            return;
        }

        $bytes = $this->service->plantilla($user);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="plantilla-flota-' . date('Ymd') . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
        echo $bytes;
    }

    /** POST /api/flota/importar — analiza; con confirmar=1 además inserta. */
    public function importar(): void
    {
        $user = require_role_api([Rol::ADMIN_GLOBAL, Rol::ENCARGADO]);
        $archivo = $this->archivoRecibido();

        try {
            $confirmar = ($_POST['confirmar'] ?? '') === '1';
            if (!$confirmar) {
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
                json_ok(['confirmado' => false, 'listas' => 0, 'errores' => $resultado['errores'], 'vista' => []],
                    'No se cargó nada: el archivo tiene errores.');
            }
            json_ok(
                ['confirmado' => true, 'importadas' => $resultado['importadas'], 'errores' => [], 'vista' => []],
                $resultado['importadas'] . ' unidad' . ($resultado['importadas'] === 1 ? '' : 'es') . ' cargada'
                    . ($resultado['importadas'] === 1 ? '' : 's') . '.'
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
            json_error('Adjunta el archivo con las unidades.', 422);
        }
        if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE) {
            json_error('El archivo supera el tamaño permitido.', 422);
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            json_error('No se pudo recibir el archivo. Inténtalo de nuevo.', 422);
        }
        if ($archivo['size'] > FlotaImportService::MAX_BYTES) {
            json_error('El archivo supera los ' . (FlotaImportService::MAX_BYTES / 1024 / 1024) . ' MB.', 422);
        }
        return $archivo;
    }

    private function resumen(array $informe): string
    {
        $listas = count($informe['filas']);
        $conError = count(array_unique(array_column($informe['errores'], 'fila')));

        if ($informe['errores'] === []) {
            return $listas === 0
                ? 'El archivo no trae unidades que cargar.'
                : "{$listas} unidad" . ($listas === 1 ? '' : 'es') . ' lista' . ($listas === 1 ? '' : 's') . ' para cargar.';
        }
        return "Se encontraron problemas en {$conError} fila" . ($conError === 1 ? '' : 's')
            . '. No se cargará nada hasta corregirlos.';
    }
}
