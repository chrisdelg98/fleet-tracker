-- 021_suscripciones_correo.sql — Suscripciones de correo para Fase 4.
CREATE TABLE suscripciones_correo (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    tipo        ENUM('UNIDAD_LIBERADA','RETORNO_DISPONIBLE') NOT NULL,
    estacion_id BIGINT UNSIGNED NULL,
    pais_id     BIGINT UNSIGNED NULL,
    activo      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by  BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suscripcion_correo (user_id, tipo, estacion_id, pais_id),
    KEY idx_suscripcion_correo_estacion (tipo, estacion_id, activo),
    KEY idx_suscripcion_correo_pais (tipo, pais_id, activo),
    CONSTRAINT fk_suscripcion_correo_user FOREIGN KEY (user_id) REFERENCES usuarios (id) ON DELETE RESTRICT,
    CONSTRAINT fk_suscripcion_correo_estacion FOREIGN KEY (estacion_id) REFERENCES estaciones (id) ON DELETE RESTRICT,
    CONSTRAINT fk_suscripcion_correo_pais FOREIGN KEY (pais_id) REFERENCES paises (id) ON DELETE RESTRICT,
    CONSTRAINT fk_suscripcion_correo_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;