<?php
/**
 * Categorías de vehículo = tipo de vehículo (plan §5.3, §5.5). Flags:
 * - es_flota_operativa: default del check en_disponibilidad.
 * - es_motriz: si se mueve sola (cabezal, camión) o es arrastrada (furgón, contenedor, chasis).
 * - admite_arrastre: si lleva otro activo enganchado. No se deduce de es_motriz: cabezal y
 *   camión se mueven los dos solos, pero solo el cabezal jala algo.
 * Cada fila: [nombre, es_flota_operativa, es_motriz, admite_arrastre, orden].
 */

declare(strict_types=1);

return [
    ['Cabezal',     1, 1, 1, 1],
    ['Camión',      1, 1, 0, 2],
    ['Pick-up',     0, 1, 0, 3],
    ['Automóvil',   0, 1, 0, 4],
    ['Motocicleta', 0, 1, 0, 5],
    ['Furgón',      1, 0, 0, 6],
    ['Contenedor',  1, 0, 1, 7],
    ['Chasis',      1, 0, 0, 8],
];
