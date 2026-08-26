-- 022_movimientos_regreso.sql — Vincula el movimiento de ida con su movimiento de regreso
-- (plan §Fase 3: "movimiento de regreso vinculado" y métrica §11 de retornos aprovechados).
-- Hasta ahora el vínculo solo quedaba en la bitácora, que no es consultable para la UI ni
-- para reportes. El destino del regreso vive en el propio movimiento de regreso: no tiene por
-- qué ser el país de origen de la ida (SV → GT puede regresar como GT → HN).
ALTER TABLE movimientos
    ADD COLUMN movimiento_regreso_id BIGINT UNSIGNED NULL AFTER pais_solicita_retorno_id,
    ADD KEY idx_movimientos_regreso (movimiento_regreso_id),
    ADD CONSTRAINT fk_movimientos_regreso
        FOREIGN KEY (movimiento_regreso_id) REFERENCES movimientos (id) ON DELETE SET NULL;
