-- 026_movimientos_con_cliente.sql — Marca que el equipo se queda en poder del cliente hasta
-- el fin de la reserva (renta o espera de descarga), en vez de volver con el cabezal. Se
-- declara al reservar cuando ya se sabe; si no, el sistema lo deduce al liberar el motriz.
ALTER TABLE movimientos
    ADD COLUMN queda_con_cliente TINYINT(1) NOT NULL DEFAULT 0 AFTER retorno_disponible;
