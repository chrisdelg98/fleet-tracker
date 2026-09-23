<?php
/**
 * Coherencia del odómetro DENTRO de un archivo que todavía no se ha insertado.
 *
 * El servicio compara cada lectura contra las que ya están en la base, pero en una carga masiva
 * las filas del archivo aún no existen: sin esto, un archivo con dos años de historia podría
 * traer 120,000 km en marzo y 95,000 en junio y pasar entero, porque ninguna de las dos se ve
 * desde la base. Lo que se guarda aquí es el archivo en curso, y se descarta en cada análisis.
 *
 * Se comparan las dos direcciones contra todo lo anotado hasta ese momento, así que cada par
 * incoherente se detecta al llegar a la segunda de las dos filas, esté el archivo ordenado o no.
 */

declare(strict_types=1);

final class OdometroEnArchivo
{
    /** @var array<int, array<string, int>> unidad => fecha => km */
    private array $filas = [];

    /** Se llama al empezar cada análisis: el archivo anterior no tiene nada que decir de este. */
    public function reiniciar(): void
    {
        $this->filas = [];
    }

    /** ¿Ya venía esta unidad con esta misma fecha más arriba? */
    public function fechaRepetida(int $unidadId, string $fecha): bool
    {
        return isset($this->filas[$unidadId][$fecha]);
    }

    /**
     * El mismo criterio del servicio, pero contra las filas del archivo: el odómetro solo sube,
     * y quien explica en la nota por qué bajó, pasa.
     */
    public function problema(int $unidadId, string $fecha, int $km, string $nota): ?string
    {
        if ($nota !== '') {
            return null;
        }
        foreach ($this->filas[$unidadId] ?? [] as $otraFecha => $otroKm) {
            if ($otraFecha < $fecha && $km < $otroKm) {
                return 'En este archivo, el ' . $otraFecha . ' ya marcaba ' . number_format($otroKm)
                    . ' km. El odómetro no puede bajar.';
            }
            if ($otraFecha > $fecha && $km > $otroKm) {
                return 'En este archivo, el ' . $otraFecha . ' marca ' . number_format($otroKm)
                    . ' km, menos que esta fila. Revisa la fecha o el kilometraje.';
            }
        }
        return null;
    }

    public function anotar(int $unidadId, string $fecha, int $km): void
    {
        $this->filas[$unidadId][$fecha] = $km;
    }
}
