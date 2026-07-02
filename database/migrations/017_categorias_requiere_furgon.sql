-- 017_categorias_requiere_furgon.sql — Bandera que indica si la categoría de vehículo
-- jala un furgón/contenedor con placa propia (ej. Cabezal). El formulario de unidad usa
-- este flag para volver obligatoria placa_furgon (poka-yoke), igual que es_flota_operativa
-- gobierna en_disponibilidad. Ver plan §5.5.
ALTER TABLE categorias_vehiculo
    ADD COLUMN requiere_furgon TINYINT(1) NOT NULL DEFAULT 0 AFTER es_flota_operativa;
