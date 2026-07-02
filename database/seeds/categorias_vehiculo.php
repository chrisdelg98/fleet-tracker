<?php
/**
 * Categorías de vehículo con su flag es_flota_operativa (plan §10, §5.5). El flag alimenta
 * el default heredado del check en_disponibilidad. Cada fila: [nombre, es_flota_operativa, orden].
 */

declare(strict_types=1);

return [
    ['Cabezal',     1, 1],
    ['Camión',      1, 2],
    ['Furgón',      1, 3],
    ['Pick-up',     0, 4],
    ['Automóvil',   0, 5],
    ['Motocicleta', 0, 6],
];
