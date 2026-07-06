<?php
/**
 * Estaciones de Centroamérica con su timezone IANA (plan §10, §5.1). El país se resuelve
 * por codigo_iso al sembrar. Cada fila: [nombre, codigo, codigo_iso_pais, timezone].
 */

declare(strict_types=1);

return [
    ['EFL Trucking',            'TRK', 'SV', 'America/El_Salvador'],
    ['EFL Global El Salvador',  'SAL', 'SV', 'America/El_Salvador'],
    ['EFL Global Guatemala',    'GUA', 'GT', 'America/Guatemala'],
    ['EFL Global Honduras',     'TGU', 'HN', 'America/Tegucigalpa'],
    ['EFL Global Nicaragua',    'MGA', 'NI', 'America/Managua'],
    ['EFL Global Costa Rica',   'SJO', 'CR', 'America/Costa_Rica'],
    ['EFL Global Panamá',       'PTY', 'PA', 'America/Panama'],
];
