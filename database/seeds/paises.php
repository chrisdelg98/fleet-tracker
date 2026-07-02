<?php
/**
 * Catálogo de países de América (plan §5.3). Orden: Centroamérica, luego Norteamérica,
 * por último Suramérica. Cada fila: [codigo_iso, nombre, region, orden].
 */

declare(strict_types=1);

return [
    // Centroamérica
    ['GT', 'Guatemala',       RegionPais::CENTROAMERICA, 1],
    ['SV', 'El Salvador',     RegionPais::CENTROAMERICA, 2],
    ['HN', 'Honduras',        RegionPais::CENTROAMERICA, 3],
    ['NI', 'Nicaragua',       RegionPais::CENTROAMERICA, 4],
    ['CR', 'Costa Rica',      RegionPais::CENTROAMERICA, 5],
    ['PA', 'Panamá',          RegionPais::CENTROAMERICA, 6],
    ['BZ', 'Belice',          RegionPais::CENTROAMERICA, 7],
    // Norteamérica
    ['MX', 'México',          RegionPais::NORTEAMERICA, 8],
    ['US', 'Estados Unidos',  RegionPais::NORTEAMERICA, 9],
    ['CA', 'Canadá',          RegionPais::NORTEAMERICA, 10],
    // Suramérica
    ['CO', 'Colombia',        RegionPais::SURAMERICA, 11],
    ['VE', 'Venezuela',       RegionPais::SURAMERICA, 12],
    ['EC', 'Ecuador',         RegionPais::SURAMERICA, 13],
    ['PE', 'Perú',            RegionPais::SURAMERICA, 14],
    ['BO', 'Bolivia',         RegionPais::SURAMERICA, 15],
    ['CL', 'Chile',           RegionPais::SURAMERICA, 16],
    ['AR', 'Argentina',       RegionPais::SURAMERICA, 17],
    ['PY', 'Paraguay',        RegionPais::SURAMERICA, 18],
    ['UY', 'Uruguay',         RegionPais::SURAMERICA, 19],
    ['BR', 'Brasil',          RegionPais::SURAMERICA, 20],
    ['GY', 'Guyana',          RegionPais::SURAMERICA, 21],
    ['SR', 'Surinam',         RegionPais::SURAMERICA, 22],
];
