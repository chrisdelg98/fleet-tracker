-- 037_movimientos_operacion.sql — La columna se llamaba `servicio_a_tercero`, que era una
-- descripción inventada por el sistema. La operación tiene nombre propio en la empresa y en el
-- reporte semanal: Interno y Externo, IN y EX.
--
-- Interno: la carga sale de nuestra bodega, pasa por ella o llega a ella.
-- Externo: es un flete para otra empresa y la unidad carga en sus instalaciones.
--
-- Renombrarla no es cosmética: quien lea la consulta del reporte va a ver `operacion = 'EX'` y
-- reconocerlo al instante, en vez de traducir mentalmente desde otro término.
--
-- Va en tres pasos y no con un CHANGE COLUMN a secas: convertir un TINYINT en ENUM hace que
-- MySQL lea el número como el ÍNDICE del enum, y el 0 no es un índice válido — todas las filas
-- existentes, que son justamente las internas, se habrían perdido.
ALTER TABLE movimientos
    ADD COLUMN operacion ENUM('IN','EX') NOT NULL DEFAULT 'IN' AFTER clase;

UPDATE movimientos SET operacion = 'EX' WHERE servicio_a_tercero = 1;

ALTER TABLE movimientos
    DROP COLUMN servicio_a_tercero;
