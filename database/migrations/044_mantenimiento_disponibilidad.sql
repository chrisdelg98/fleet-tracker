-- 044_mantenimiento_disponibilidad.sql — Un mantenimiento es un hecho con fecha; la
-- disponibilidad necesita un intervalo con entrada y salida. Sin estas columnas el módulo no
-- podía decir cuándo vuelve la unidad, así que el taller se gestionaba aparte, en Flota, y lo
-- mismo acababa registrado en dos sitios.
--
-- Con esto, meter una unidad al taller es UN solo hecho: se guarda el mantenimiento, se abre el
-- override que quita disponibilidad y el estado del vehículo cambia, todo en la misma
-- transacción. La disponibilidad se sigue calculando igual (plan §2): nadie la edita a mano.

ALTER TABLE mantenimientos
    ADD COLUMN en_taller_desde DATETIME NULL AFTER fecha,
    ADD COLUMN en_taller_hasta DATETIME NULL AFTER en_taller_desde,
    ADD COLUMN override_id BIGINT UNSIGNED NULL AFTER en_taller_hasta,
    ADD KEY idx_mantenimientos_en_taller (unidad_id, en_taller_hasta),
    ADD CONSTRAINT fk_mantenimientos_override FOREIGN KEY (override_id)
        REFERENCES overrides_unidad (id) ON DELETE SET NULL;
