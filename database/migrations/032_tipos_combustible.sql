-- 032_tipos_combustible.sql — El inventario tiene que responder con qué anda cada unidad:
-- entra en el costo por viaje y condiciona dónde se puede repostar en ruta.
--
-- Va como catálogo y no como lista fija en el código porque la flota cambia de tecnología
-- (eléctricos, híbridos, GLP) y añadir uno debe ser un registro, no un despliegue.
CREATE TABLE tipos_combustible (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre     VARCHAR(60)     NOT NULL,
    orden      INT             NOT NULL DEFAULT 0,
    activo     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tipos_combustible_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tipos_combustible (nombre, orden) VALUES
    ('Diésel',      1),
    ('Gasolina',    2),
    ('Híbrido',     3),
    ('Eléctrico',   4),
    ('GLP',         5);

-- Opcional: el equipo de arrastre (furgón, contenedor, chasis) no consume nada.
ALTER TABLE unidades
    ADD COLUMN tipo_combustible_id BIGINT UNSIGNED NULL AFTER anio,
    ADD KEY idx_unidades_combustible (tipo_combustible_id),
    ADD CONSTRAINT fk_unidades_combustible
        FOREIGN KEY (tipo_combustible_id) REFERENCES tipos_combustible (id) ON DELETE RESTRICT;
