-- 038_contratacion_externa.sql — Se retira `clase` (VIAJE/LOCAL) y entra lo que de verdad
-- distingue un flete comprado: qué se le contrató al proveedor.
--
-- `clase` (VIAJE/LOCAL) existía para que un movimiento corto no llenara el tablero, pero era
-- una clasificación que nadie entendía y que además apuntaba a la regla equivocada. Lo que
-- decide si un movimiento aparece en el tablero no es su tamaño, sino si tiene capacidad que
-- ofrecer: la unidad propia siempre la tiene, y un flete de tercero solo cuando el proveedor
-- avisa que vuelve con espacio.
--
-- La contratación sí se necesita, y dice qué se le compró al proveedor:
--   IDA      — se le compró el viaje de ida y nada más.
--   RETORNO  — se le compró su vuelta: venía de regreso y se aprovechó.
--   REDONDO  — ida y vuelta contratadas.
-- Lo habitual es que el proveedor cierre su ciclo al dejar la carga y no quede nada que
-- ofertar, pero no siempre: si dice que tiene retorno, eso sí es capacidad y se marca con
-- `retorno_disponible`, el mismo campo que usa la flota propia.
--
-- Va en movimiento_tercero y no en movimientos porque solo tiene sentido cuando hay proveedor:
-- una columna nula en todos los movimientos propios sería una pregunta sin respuesta posible.
ALTER TABLE movimiento_tercero
    ADD COLUMN contratacion ENUM('IDA','RETORNO','REDONDO') NOT NULL DEFAULT 'IDA' AFTER proveedor;

ALTER TABLE movimientos
    DROP KEY idx_movimientos_clase,
    DROP COLUMN clase;
