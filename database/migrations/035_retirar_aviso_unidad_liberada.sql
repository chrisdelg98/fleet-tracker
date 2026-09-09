-- 035_retirar_aviso_unidad_liberada.sql — Se retira el aviso por correo de "unidad liberada".
--
-- Saltaba con cada llegada y con cada cambio de estado de la unidad, y el tablero ya muestra
-- la disponibilidad en vivo: era ruido, no información. Además su consulta repetía el mismo
-- marcador con nombre dos veces, así que ni siquiera llegaba a enviarse (HY093).
--
-- Las suscripciones de ese tipo se dan de baja en vez de borrarse: si mañana se rehace el
-- aviso, se sabe quién lo había pedido.
UPDATE suscripciones_correo
   SET activo = 0
 WHERE tipo = 'UNIDAD_LIBERADA'
   AND activo = 1;
