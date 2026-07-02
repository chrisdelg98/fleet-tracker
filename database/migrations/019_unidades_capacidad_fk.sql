-- 019_unidades_capacidad_fk.sql — La capacidad de la unidad pasa de texto libre a FK al
-- catálogo capacidades (plan §5.5). Se elimina la columna de texto anterior; nullable
-- porque no todo vehículo la necesita (una motocicleta no).
ALTER TABLE unidades
    DROP COLUMN capacidad,
    ADD COLUMN capacidad_id BIGINT UNSIGNED NULL AFTER en_disponibilidad,
    ADD KEY idx_unidades_capacidad (capacidad_id),
    ADD CONSTRAINT fk_unidades_capacidad FOREIGN KEY (capacidad_id) REFERENCES capacidades (id) ON DELETE RESTRICT;
