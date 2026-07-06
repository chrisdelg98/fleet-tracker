<?php
/**
 * Histórico de actividad (plan §7.7). Se apoya en la bitácora (§5.9), que registra toda
 * escritura con su autor, momento y snapshot antes/después — permite ver el ciclo completo
 * de un movimiento (creación y cambios de estado, quién y cuándo). Filtros combinables y
 * export. Los timestamps están en UTC.
 */

declare(strict_types=1);

final class HistoricoService
{
    public const POR_PAGINA_OPCIONES = [10, 20, 50, 100];
    public const POR_PAGINA_DEFAULT = 20;

    public function __construct(private PDO $pdo)
    {
    }

    /** Normaliza el tamaño de página a una de las opciones permitidas. */
    public static function porPaginaValido(int $n): int
    {
        return in_array($n, self::POR_PAGINA_OPCIONES, true) ? $n : self::POR_PAGINA_DEFAULT;
    }

    /**
     * Lista la bitácora AGRUPADA por entidad (una fila por movimiento/unidad/… con su última
     * actividad), más el historial completo de cada grupo de la página para el modal.
     *
     * @return array{filas: array, eventos: array, total: int, pagina: int, paginas: int}
     */
    public function listar(array $filtros, int $pagina = 1, int $porPagina = self::POR_PAGINA_DEFAULT): array
    {
        $porPagina = self::porPaginaValido($porPagina);
        [$where, $params] = $this->where($filtros);

        $total = $this->contarGrupos($where, $params);
        $pagina = max(1, $pagina);
        $offset = ($pagina - 1) * $porPagina;

        // Un grupo por (entidad, entidad_id); se ordena por el evento más reciente.
        $stmt = $this->pdo->prepare(
            "SELECT g.entidad, g.entidad_id, g.eventos, g.primera, g.ultima,
                    ult.accion AS ultima_accion, ultu.nombre AS ultimo_usuario
               FROM (
                    SELECT b.entidad, b.entidad_id, COUNT(*) AS eventos,
                           MIN(b.timestamp) AS primera, MAX(b.timestamp) AS ultima, MAX(b.id) AS ultimo_id
                      FROM bitacora b
                      {$where}
                     GROUP BY b.entidad, b.entidad_id
                     ORDER BY ultimo_id DESC
                     LIMIT " . $porPagina . " OFFSET " . $offset . "
               ) g
               JOIN bitacora ult ON ult.id = g.ultimo_id
               LEFT JOIN usuarios ultu ON ultu.id = ult.usuario_id
              ORDER BY g.ultimo_id DESC"
        );
        $stmt->execute($params);
        $grupos = $stmt->fetchAll();

        return [
            'filas'      => $grupos,
            'eventos'    => $this->eventosDe($grupos),
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => max(1, (int) ceil($total / $porPagina)),
            'por_pagina' => $porPagina,
        ];
    }

    /**
     * Historial completo (todos los eventos, sin filtrar) de los grupos de la página, en orden
     * cronológico. Devuelve un mapa "{entidad}#{entidad_id}" => [eventos].
     */
    private function eventosDe(array $grupos): array
    {
        if ($grupos === []) {
            return [];
        }
        $pares = [];
        $params = [];
        foreach ($grupos as $i => $g) {
            $pares[] = "(:e{$i}, :i{$i})";
            $params[":e{$i}"] = $g['entidad'];
            $params[":i{$i}"] = (int) $g['entidad_id'];
        }
        $in = implode(', ', $pares);
        $stmt = $this->pdo->prepare(
            "SELECT b.entidad, b.entidad_id, b.timestamp, b.accion, b.detalle, u.nombre AS usuario
               FROM bitacora b
               LEFT JOIN usuarios u ON u.id = b.usuario_id
              WHERE (b.entidad, b.entidad_id) IN ({$in})
              ORDER BY b.id ASC"
        );
        $stmt->execute($params);

        $mapa = [];
        foreach ($stmt->fetchAll() as $ev) {
            $mapa[$ev['entidad'] . '#' . $ev['entidad_id']][] = $ev;
        }
        return $mapa;
    }

    /** Todas las filas que cumplen el filtro (para export), sin paginar. */
    public function exportar(array $filtros): array
    {
        [$where, $params] = $this->where($filtros);
        $stmt = $this->pdo->prepare(
            "SELECT b.timestamp, u.nombre AS usuario, b.entidad, b.entidad_id, b.accion, b.detalle
               FROM bitacora b LEFT JOIN usuarios u ON u.id = b.usuario_id
               {$where} ORDER BY b.id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function contarGrupos(string $where, array $params): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM bitacora b{$where} GROUP BY b.entidad, b.entidad_id
             ) g"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function where(array $f): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];
        if (!empty($f['desde'])) {
            $where .= ' AND b.timestamp >= :desde';
            $params[':desde'] = substr((string) $f['desde'], 0, 10) . ' 00:00:00';
        }
        if (!empty($f['hasta'])) {
            $where .= ' AND b.timestamp <= :hasta';
            $params[':hasta'] = substr((string) $f['hasta'], 0, 10) . ' 23:59:59';
        }
        if (!empty($f['entidad'])) {
            $where .= ' AND b.entidad = :entidad';
            $params[':entidad'] = $f['entidad'];
        }
        if (!empty($f['accion'])) {
            $where .= ' AND b.accion = :accion';
            $params[':accion'] = $f['accion'];
        }
        if (!empty($f['usuario_id'])) {
            $where .= ' AND b.usuario_id = :usuario';
            $params[':usuario'] = (int) $f['usuario_id'];
        }
        if (!empty($f['entidad_id'])) {
            $where .= ' AND b.entidad_id = :eid';
            $params[':eid'] = (int) $f['entidad_id'];
        }
        return [$where, $params];
    }
}
