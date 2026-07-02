<?php
/**
 * Estaciones de Centroamérica con su timezone IANA (plan §10, §5.1). El país se resuelve
 * por codigo_iso al sembrar. Cada fila: [nombre, codigo, codigo_iso_pais, timezone].
 */

declare(strict_types=1);

return [
    ['Ciudad de Guatemala', 'GUA', 'GT', 'America/Guatemala'],
    ['San Salvador',        'SAL', 'SV', 'America/El_Salvador'],
    ['Ciudad de Panamá',    'PAN', 'PA', 'America/Panama'],
];
