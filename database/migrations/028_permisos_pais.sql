-- 028_permisos_pais.sql — Un permiso especial es una autorización que emite una autoridad
-- nacional: "carga peligrosa" en SV no es el mismo trámite que en GT, pero sí es el mismo
-- para todas las estaciones de un país. NULL = aplica a toda la red (no todo permiso es
-- jurisdiccional). El formulario de unidades solo ofrece los del país de su estación.
ALTER TABLE permisos_especiales
    ADD COLUMN pais_id BIGINT UNSIGNED NULL AFTER descripcion,
    ADD KEY idx_permisos_pais (pais_id),
    ADD CONSTRAINT fk_permisos_pais FOREIGN KEY (pais_id) REFERENCES paises (id) ON DELETE RESTRICT;
