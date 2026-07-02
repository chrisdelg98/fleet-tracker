-- 006_estaciones.sql — Estaciones/sedes (plan §5.1). created_by queda nullable y
-- sin FK aquí: se agrega en 008 tras crear usuarios (ciclo estaciones<->usuarios).
CREATE TABLE estaciones (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre     VARCHAR(150)    NOT NULL,
    pais_id    BIGINT UNSIGNED NOT NULL,
    codigo     VARCHAR(10)     NOT NULL,
    timezone   VARCHAR(64)     NOT NULL,
    activo     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_estaciones_codigo (codigo),
    KEY idx_estaciones_pais (pais_id),
    CONSTRAINT fk_estaciones_pais FOREIGN KEY (pais_id) REFERENCES paises (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
