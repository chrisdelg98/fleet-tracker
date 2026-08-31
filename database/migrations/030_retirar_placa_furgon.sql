-- 030_retirar_placa_furgon.sql — Resto del modelo anterior. Cuando la 024 convirtió el furgón
-- en una unidad propia ("el cabezal deja de exigir placa de furgón"), la columna se quedó
-- puesta y las pantallas la siguieron pintando: un furgón podía existir a la vez como unidad
-- registrada y como texto dentro de un cabezal, sin que nada los relacionara.
--
-- Con qué se sustituye: la relación cabezal ↔ arrastre vive en movimiento_unidades (025), que
-- sí registra qué activo va con cuál, en qué viaje y hasta cuándo.
--
-- requiere_furgon se va con ella: era el interruptor que hacía obligatoria esa placa y ya
-- estaba en 0 en todas las categorías, así que no controlaba nada.
--
-- Verificado antes de aplicar: 0 unidades con placa_furgon y 0 categorías con requiere_furgon.
ALTER TABLE unidades
    DROP COLUMN placa_furgon;

ALTER TABLE categorias_vehiculo
    DROP COLUMN requiere_furgon;
