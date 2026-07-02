-- 018_capacidades.sql — Catálogo de capacidades (plan §5.3, §5.5). La capacidad deja de
-- ser texto libre y pasa a lista controlada: tamaños de contenedor (20', 40', 45') y
-- toneladas de camión (8 TON, 12 TON…). Administrable desde Catálogos.
CREATE TABLE capacidades (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre     VARCHAR(60)     NOT NULL,
    orden      INT             NOT NULL DEFAULT 0,
    activo     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_capacidades_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
