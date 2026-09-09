-- 039_retirar_contratacion.sql — Se retira `contratacion` (IDA/RETORNO/REDONDO).
--
-- Pedía al usuario clasificar cada flete comprado, pero la única pregunta con consecuencia
-- operativa —¿hay retorno que ofrecer?— ya la contesta la casilla «Retorno disponible», que
-- existe desde antes y sirve igual para flota propia y de tercero. Lo demás era un matiz de
-- compra que obligaba a decidir en cada carga para un dato que casi nadie iba a mirar.
--
-- Si algún día se quiere contar cuántos retornos se le compran a cada proveedor, sale más
-- barato derivarlo del enlace entre movimiento e ida/vuelta que volver a preguntarlo.
ALTER TABLE movimiento_tercero
    DROP COLUMN contratacion;
