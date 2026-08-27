-- 023_unidades_anio.sql — Año del vehículo. Está en el inventario real que lleva la operación
-- y se usa para decidir qué equipo mandar a un viaje largo; faltaba en el sistema.
ALTER TABLE unidades
    ADD COLUMN anio SMALLINT UNSIGNED NULL AFTER modelo;
