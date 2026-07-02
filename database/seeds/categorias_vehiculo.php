<?php
/**
 * Categorías de vehículo = tipo de vehículo (plan §5.3, §5.5). Flags:
 * - es_flota_operativa: default del check en_disponibilidad.
 * - requiere_furgon: si jala un furgón/contenedor con placa propia (placa_furgon obligatoria).
 * Cada fila: [nombre, es_flota_operativa, requiere_furgon, orden].
 */

declare(strict_types=1);

return [
    ['Cabezal',     1, 1, 1],
    ['Camión',      1, 0, 2],
    ['Pick-up',     0, 0, 3],
    ['Automóvil',   0, 0, 4],
    ['Motocicleta', 0, 0, 5],
];
