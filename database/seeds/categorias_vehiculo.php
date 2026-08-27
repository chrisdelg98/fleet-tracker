<?php
/**
 * Categorías de vehículo = tipo de vehículo (plan §5.3, §5.5). Flags:
 * - es_flota_operativa: default del check en_disponibilidad.
 * - requiere_furgon: si jala un furgón/contenedor con placa propia (placa_furgon obligatoria).
 * - es_motriz: si se mueve sola (cabezal, camión) o es arrastrada (furgón, contenedor, chasis).
 * Cada fila: [nombre, es_flota_operativa, requiere_furgon, es_motriz, orden].
 */

declare(strict_types=1);

return [
    ['Cabezal',     1, 0, 1, 1],
    ['Camión',      1, 0, 1, 2],
    ['Pick-up',     0, 0, 1, 3],
    ['Automóvil',   0, 0, 1, 4],
    ['Motocicleta', 0, 0, 1, 5],
    ['Furgón',      1, 0, 0, 6],
    ['Contenedor',  1, 0, 0, 7],
    ['Chasis',      1, 0, 0, 8],
];
