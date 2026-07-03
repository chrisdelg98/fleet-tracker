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
    private const POR_PAGINA = 50;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{filas: array, total: int, pagina: int, paginas: int} */
    public function listar(array $filtros, int $pagina = 1): array
    {
        [$where, $params] = $this->where($filtros);

        $total = $this->contar($where, $params);
        $pagina = max(1, $pagina);
        $offset = ($pagina - 1) * self::POR_PAGINA;

        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.usuario_id, b.entidad, b.entidad_id, b.accion, b.detalle, b.timestamp,
                    u.nombre AS usuario
               FROM bitacora b
               LEFT JOIN usuarios u ON u.id = b.usuario_id
               {$where}
              ORDER BY b.id DESC
              LIMIT " . self::POR_PAGINA . " OFFSET " . $offset
        );
        $stmt->execute($params);

        return [
            'filas'   => $stmt->fetchAll(),
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
        ];
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

    private function contar(string $where, array $params): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM bitacora b{$where}");
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
