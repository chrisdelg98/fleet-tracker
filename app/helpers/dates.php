<?php
/**
 * Fechas y zonas horarias (AGENTS.md §Convenciones 1, plan §3.2).
 * La BD almacena SIEMPRE en UTC; la conversión a la hora local de la estación ocurre
 * solo en la capa de presentación. Nunca usar date() sin timezone explícito.
 */

declare(strict_types=1);

/** Convierte una fecha/hora UTC (como viene de la BD) a la timezone IANA de la estación. */
function utc_to_local(string $utcDateTime, string $timezone): DateTimeImmutable
{
    return (new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($timezone));
}

/** Convierte una fecha/hora en la timezone de la estación a UTC (para persistir). */
function local_to_utc(string $localDateTime, string $timezone): DateTimeImmutable
{
    return (new DateTimeImmutable($localDateTime, new DateTimeZone($timezone)))
        ->setTimezone(new DateTimeZone('UTC'));
}

/** Formatea una fecha/hora UTC en la hora local de la estación (default: 2026-07-15 12:00). */
function format_local(string $utcDateTime, string $timezone, string $format = 'Y-m-d H:i'): string
{
    return utc_to_local($utcDateTime, $timezone)->format($format);
}

/** Ahora en UTC, listo para columnas DATETIME. */
function now_utc(string $format = 'Y-m-d H:i:s'): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format($format);
}
