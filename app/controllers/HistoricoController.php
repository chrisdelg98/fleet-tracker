<?php
/**
 * Histórico de actividad (plan §7.7). Tabla paginada de la bitácora con filtros combinables
 * y export CSV. Área de gestión: visible para Admin Global y Encargados.
 */

declare(strict_types=1);

final class HistoricoController
{
    private const ACCESO = [Rol::ADMIN_GLOBAL, Rol::ENCARGADO];

    public function __construct(private HistoricoService $service, private UsuarioModel $usuarios)
    {
    }

    public static function tieneAcceso(array $user): bool
    {
        return in_array($user['rol'], self::ACCESO, true);
    }

    public function index(): void
    {
        $user = require_login_web();
        if (!self::tieneAcceso($user)) {
            http_response_code(403);
            echo 'No tienes acceso al histórico.';
            return;
        }
        $filtros = $this->filtros($_GET);
        $pagina = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
        $porPagina = HistoricoService::porPaginaValido((int) ($_GET['por_pagina'] ?? 0));
        render('historico/index', [
            'usuario'   => $user,
            'filtros'   => $filtros,
            'resultado' => $this->service->listar($filtros, $pagina, $porPagina),
            'usuarios'  => $this->usuarios->listar(),
            'entidades' => ['movimiento', 'unidad', 'piloto', 'ruta', 'override', 'estacion', 'usuario'],
            'acciones'  => [AccionBitacora::CREAR, AccionBitacora::EDITAR, AccionBitacora::CAMBIO_ESTADO, AccionBitacora::CANCELAR, AccionBitacora::ELIMINAR],
        ], 'Histórico · Disponibilidad de Flota');
    }

    public function export(): void
    {
        $user = require_login_web();
        if (!self::tieneAcceso($user)) {
            http_response_code(403);
            echo 'No tienes acceso al histórico.';
            return;
        }
        $filas = $this->service->exportar($this->filtros($_GET));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="historico-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Fecha (UTC)', 'Usuario', 'Entidad', 'ID', 'Acción', 'Detalle']);
        foreach ($filas as $f) {
            fputcsv($out, [$f['timestamp'], $f['usuario'], $f['entidad'], $f['entidad_id'], $f['accion'], $f['detalle']]);
        }
        fclose($out);
    }

    private function filtros(array $q): array
    {
        return [
            'desde'      => $q['desde'] ?? null,
            'hasta'      => $q['hasta'] ?? null,
            'entidad'    => $q['entidad'] ?? null,
            'accion'     => $q['accion'] ?? null,
            'usuario_id' => $q['usuario_id'] ?? null,
            'entidad_id' => $q['entidad_id'] ?? null,
            'por_pagina' => $q['por_pagina'] ?? null,
        ];
    }
}
