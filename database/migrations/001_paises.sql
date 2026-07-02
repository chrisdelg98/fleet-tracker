-- 001_paises.sql — Catálogo de países (plan §5.3). Todo campo de país en el
-- sistema es FK a esta tabla, nunca texto libre.
CREATE TABLE paises (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo_iso CHAR(2)         NOT NULL,
    nombre     VARCHAR(100)    NOT NULL,
    region     ENUM('CENTROAMERICA','NORTEAMERICA','SURAMERICA','CARIBE') NOT NULL,
    orden      INT             NOT NULL DEFAULT 0,
    activo     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_paises_codigo_iso (codigo_iso),
    KEY idx_paises_region_orden (region, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
