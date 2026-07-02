<?php
/**
 * Tipos de equipo = naturaleza del contenedor/equipo (plan §5.3, §5.5). El tamaño va en
 * el catálogo de capacidades. Cada fila: [nombre, descripcion, orden].
 */

declare(strict_types=1);

return [
    ['Standard',       'Standard dry container',                1],
    ['High Cube',      'High cube container (9.5ft height)',    2],
    ['Reefer',         'Refrigerated container',                3],
    ['Open Top',       'Open top container',                    4],
    ['Flat Rack',      'Flat rack container',                   5],
    ['Tank Container', 'Tank container for liquids',            6],
    ['Ventilated',     'Ventilated container for perishables',  7],
    ['Insulated',      'Insulated container',                   8],
    ['Collapsible',    'Collapsible container',                 9],
    ['Double Door',    'Double door container',                 10],
    ['Hard Top',       'Hard top container',                    11],
    ['Platform',       'Platform container',                    12],
    ['N/A',            'Not applicable (Air/Road)',             13],
];
