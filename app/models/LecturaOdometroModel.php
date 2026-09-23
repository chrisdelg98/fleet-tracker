<?php
/**
 * Lecturas de odómetro: el hecho "esta unidad marcaba tantos km tal día".
 *
 * Es historial y no una columna con el último valor, porque con la serie se puede saber cuántos
 * km hace al mes una unidad y estimar la FECHA del próximo servicio. "Le faltan 12 días" sirve
 * para agendar taller; "le faltan 1,527 km" no, porque nadie sabe cuándo los va a rodar.
 */

declare(strict_types=1);

final class LecturaOdometroModel
{
    public function __construct(private PDO $pdo)
    {
    }

    /** La lectura más reciente de una unidad (la de mayor fecha; a igual fecha, la última creada). */
    public function ultima(int $unidadId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM lecturas_odometro WHERE unidad_id = :u ORDER BY fecha DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':u' => $unidadId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * La lectura vigente en una fecha dada: sirve para validar que una lectura vieja no rompa
     * la serie cuando se registra un mantenimiento con fecha anterior.
     */
    public function ultimaHasta(int $unidadId, string $fecha): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM lecturas_odometro
              WHERE unidad_id = :u AND fecha <= :f
              ORDER BY fecha DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':u' => $unidadId, ':f' => $fecha]);
        return $stmt->fetch() ?: null;
    }

    /** La primera lectura posterior a esa fecha, para no dejar la serie decreciente hacia adelante. */
    public function primeraDesde(int $unidadId, string $fecha): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM lecturas_odometro
              WHERE unidad_id = :u AND fecha > :f
              ORDER BY fecha ASC, id ASC LIMIT 1'
        );
        $stmt->execute([':u' => $unidadId, ':f' => $fecha]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Última lectura de cada unidad, en una sola consulta.
     *
     * La captura masiva la necesita para mostrar de qué número viene cada placa: sin eso nadie
     * sabe si lo que escribe tiene sentido, y un 14,500 donde iban 145,000 pasa sin más.
     *
     * @return array<int, array{km:int, fecha:string}>
     */
    public function ultimasPorUnidad(): array
    {
        $sql = 'SELECT l.unidad_id, l.km, l.fecha
                  FROM lecturas_odometro l
                  JOIN (SELECT unidad_id, MAX(fecha) AS f FROM lecturas_odometro GROUP BY unidad_id) u
                    ON u.unidad_id = l.unidad_id AND u.f = l.fecha
                 ORDER BY l.id';
        $out = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $f) {
            $out[(int) $f['unidad_id']] = ['km' => (int) $f['km'], 'fecha' => (string) $f['fecha']];
        }
        return $out;
    }

    public function crear(array $data, ?int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lecturas_odometro (unidad_id, fecha, km, origen, mantenimiento_id, nota, created_by)
             VALUES (:unidad_id, :fecha, :km, :origen, :mantenimiento_id, :nota, :por)'
        );
        $stmt->execute([
            ':unidad_id' => $data['unidad_id'],
            ':fecha' => $data['fecha'],
            ':km' => $data['km'],
            ':origen' => $data['origen'],
            ':mantenimiento_id' => $data['mantenimiento_id'] ?? null,
            ':nota' => $data['nota'] ?? null,
            ':por' => $usuarioId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Historial de una unidad, para la ficha. */
    public function deUnidad(int $unidadId, int $limite = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM lecturas_odometro WHERE unidad_id = :u
              ORDER BY fecha DESC, id DESC LIMIT ' . max(1, $limite)
        );
        $stmt->execute([':u' => $unidadId]);
        return $stmt->fetchAll();
    }
}
