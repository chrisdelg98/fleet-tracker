-- 005_categorias_vehiculo.sql — Catálogo de categorías de vehículo (plan §5.3).
-- es_flota_operativa alimenta el default heredado del check en_disponibilidad (§5.5).
CREATE TABLE categorias_vehiculo (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre             VARCHAR(100)    NOT NULL,
    es_flota_operativa TINYINT(1)      NOT NULL DEFAULT 1,
    orden              INT             NOT NULL DEFAULT 0,
    activo             TINYINT(1)      NOT NULL DEFAULT 1,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by         BIGINT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
