-- 040_tercero_licencia.sql — Número de licencia del motorista del proveedor.
--
-- Es uno de los datos con que se identifica al motorista en la frontera y en la báscula, igual
-- que en la ficha de un piloto propio. Va junto al resto del camión de tercero y se recuerda por
-- placa como los demás, así que se escribe una sola vez por motorista.
ALTER TABLE movimiento_tercero
    ADD COLUMN licencia VARCHAR(40) NULL AFTER piloto;
