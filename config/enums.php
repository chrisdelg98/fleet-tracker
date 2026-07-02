<?php
/**
 * Enums del sistema como constantes (AGENTS.md §Convenciones 3, plan §5/§6).
 * Fuente única: usar siempre por constante, nunca strings mágicos. Cada clase expone
 * values() para validar entrada contra el conjunto permitido (guardar 422 en backend).
 */

declare(strict_types=1);

/** Roles de usuario (plan §4, §5.2). */
final class Rol
{
    public const ADMIN_GLOBAL       = 'ADMIN_GLOBAL';
    public const ENCARGADO          = 'ENCARGADO';
    public const CONSULTA_BASICO     = 'CONSULTA_BASICO';
    public const CONSULTA_INVENTARIO = 'CONSULTA_INVENTARIO';
    public const CONSULTA_REGIONAL   = 'CONSULTA_REGIONAL';

    /** Roles a los que la estación es opcional (nullable en usuarios.estacion_id). */
    public const SIN_ESTACION = [self::ADMIN_GLOBAL, self::CONSULTA_REGIONAL];

    public static function values(): array
    {
        return [
            self::ADMIN_GLOBAL,
            self::ENCARGADO,
            self::CONSULTA_BASICO,
            self::CONSULTA_INVENTARIO,
            self::CONSULTA_REGIONAL,
        ];
    }
}

/** Región geográfica de un país, ordena el desplegable (plan §5.3). */
final class RegionPais
{
    public const CENTROAMERICA = 'CENTROAMERICA';
    public const NORTEAMERICA  = 'NORTEAMERICA';
    public const SURAMERICA    = 'SURAMERICA';
    public const CARIBE        = 'CARIBE';

    public static function values(): array
    {
        return [self::CENTROAMERICA, self::NORTEAMERICA, self::SURAMERICA, self::CARIBE];
    }
}

/** Condición física/operativa del vehículo (plan §5.5). */
final class EstadoVehiculo
{
    public const OPERATIVO        = 'OPERATIVO';
    public const EN_MANTENIMIENTO = 'EN_MANTENIMIENTO';
    public const INOPERATIVO      = 'INOPERATIVO';
    public const DE_BAJA          = 'DE_BAJA';

    /** Estados que exigen estado_notas y generan override automático (plan §5.5, regla 17/18). */
    public const REQUIERE_NOTAS = [self::EN_MANTENIMIENTO, self::INOPERATIVO, self::DE_BAJA];

    public static function values(): array
    {
        return [self::OPERATIVO, self::EN_MANTENIMIENTO, self::INOPERATIVO, self::DE_BAJA];
    }
}

/** Tipo de ruta (plan §5.6/§5.7). Auto-derivable: origen == destino => NACIONAL. */
final class TipoRuta
{
    public const NACIONAL      = 'NACIONAL';
    public const INTERNACIONAL = 'INTERNACIONAL';

    public static function values(): array
    {
        return [self::NACIONAL, self::INTERNACIONAL];
    }
}

/** Estados de un movimiento (plan §6). */
final class EstadoMovimiento
{
    public const RESERVADO   = 'RESERVADO';
    public const PROGRAMADO  = 'PROGRAMADO';
    public const EN_TRANSITO = 'EN_TRANSITO';
    public const COMPLETADO  = 'COMPLETADO';
    public const CANCELADO   = 'CANCELADO';

    /** Ocupan la unidad para el cálculo de disponibilidad y la regla de no-traslape (plan §2, §5.7). */
    public const ACTIVOS = [self::RESERVADO, self::PROGRAMADO, self::EN_TRANSITO];

    /** Estados finales e inmutables (plan §6). */
    public const FINALES = [self::COMPLETADO, self::CANCELADO];

    public static function values(): array
    {
        return [self::RESERVADO, self::PROGRAMADO, self::EN_TRANSITO, self::COMPLETADO, self::CANCELADO];
    }
}

/** Tipo de override de unidad (plan §5.8). */
final class TipoOverride
{
    public const EN_TALLER = 'EN_TALLER';
    public const BLOQUEADA = 'BLOQUEADA';

    public static function values(): array
    {
        return [self::EN_TALLER, self::BLOQUEADA];
    }
}

/** Origen del override: generado por cambio de estado o bloqueo manual (plan §5.8). */
final class OrigenOverride
{
    public const AUTO_ESTADO = 'AUTO_ESTADO';
    public const MANUAL      = 'MANUAL';

    public static function values(): array
    {
        return [self::AUTO_ESTADO, self::MANUAL];
    }
}

/** Estados calculados de disponibilidad de una unidad (plan §2). NO se persisten:
 *  son el resultado del cálculo. Definidos aquí para chips de UI y filtros del dashboard. */
final class EstadoDisponibilidad
{
    public const DISPONIBLE         = 'DISPONIBLE';
    public const RESERVADA          = 'RESERVADA';
    public const EN_TRANSITO        = 'EN_TRANSITO';
    public const TALLER_BLOQUEADA   = 'TALLER_BLOQUEADA';

    public static function values(): array
    {
        return [self::DISPONIBLE, self::RESERVADA, self::EN_TRANSITO, self::TALLER_BLOQUEADA];
    }
}

/** Acciones registradas en bitácora (plan §5.9). */
final class AccionBitacora
{
    public const CREAR         = 'CREAR';
    public const EDITAR        = 'EDITAR';
    public const CAMBIO_ESTADO = 'CAMBIO_ESTADO';
    public const CANCELAR      = 'CANCELAR';
    public const ELIMINAR      = 'ELIMINAR';
}
