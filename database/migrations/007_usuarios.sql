-- 007_usuarios.sql — Usuarios y roles (plan §5.2). estacion_id nullable solo para
-- ADMIN_GLOBAL y CONSULTA_REGIONAL (validación de coherencia rol/estación en backend).
-- created_by (auto-referencia) se agrega en 008; el primer admin se siembra con NULL.
CREATE TABLE usuarios (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre        VARCHAR(150)    NOT NULL,
    email         VARCHAR(190)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    rol           ENUM('ADMIN_GLOBAL','ENCARGADO','CONSULTA_BASICO','CONSULTA_INVENTARIO','CONSULTA_REGIONAL') NOT NULL,
    estacion_id   BIGINT UNSIGNED NULL,
    activo        TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by    BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email),
    KEY idx_usuarios_estacion (estacion_id),
    CONSTRAINT fk_usuarios_estacion FOREIGN KEY (estacion_id) REFERENCES estaciones (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
