-- 012_rutas.sql — Catálogo de rutas establecidas (plan §5.6). Rutas compartidas entre
-- estaciones. tipo_ruta auto-derivable (origen==destino => NACIONAL) en backend.
CREATE TABLE rutas (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre                   VARCHAR(200)    NOT NULL,
    pais_origen_id           BIGINT UNSIGNED NOT NULL,
    ciudad_origen            VARCHAR(120)    NOT NULL,
    pais_destino_id          BIGINT UNSIGNED NOT NULL,
    ciudad_destino           VARCHAR(120)    NOT NULL,
    distancia_km             DECIMAL(8,2)    NULL,
    tipo_ruta                ENUM('NACIONAL','INTERNACIONAL') NOT NULL,
    horas_transito_estimadas DECIMAL(5,2)    NULL,
    activo                   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by               BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_rutas_origen_destino (pais_origen_id, pais_destino_id),
    KEY idx_rutas_pais_destino (pais_destino_id),
    CONSTRAINT fk_rutas_pais_origen FOREIGN KEY (pais_origen_id) REFERENCES paises (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rutas_pais_destino FOREIGN KEY (pais_destino_id) REFERENCES paises (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rutas_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
