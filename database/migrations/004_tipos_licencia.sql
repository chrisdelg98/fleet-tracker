-- 004_tipos_licencia.sql — Catálogo de tipos de licencia (plan §5.3).
CREATE TABLE tipos_licencia (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre     VARCHAR(100)    NOT NULL,
    activo     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
