-- 020_descripcion_equipo_capacidad.sql — Alinea los catálogos de tipos de equipo
-- (naturaleza del contenedor) y capacidades (tamaño) con los campos del censo de flota:
-- descripción y orden. `tipos_equipo` no tenía `orden`; se agrega para poder ordenarlo
-- como el resto de catálogos. El nombre sigue siendo el valor mostrado en los desplegables.
ALTER TABLE tipos_equipo
    ADD COLUMN descripcion VARCHAR(255) NULL AFTER nombre,
    ADD COLUMN orden       INT NOT NULL DEFAULT 0 AFTER descripcion;

ALTER TABLE capacidades
    ADD COLUMN descripcion VARCHAR(255) NULL AFTER nombre;
