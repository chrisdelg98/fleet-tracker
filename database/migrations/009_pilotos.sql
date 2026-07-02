-- 009_pilotos.sql — Pilotos (plan §5.4). Soft-delete (activo): el histórico los
-- referencia, nunca se borran físicamente. licencia_vence nullable (alerta ≤30 días).
CREATE TABLE pilotos (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre           VARCHAR(150)    NOT NULL,
    tipo_licencia_id BIGINT UNSIGNED NOT NULL,
    no_licencia      VARCHAR(60)     NOT NULL,
    licencia_vence   DATE            NULL,
    estacion_id      BIGINT UNSIGNED NOT NULL,
    activo           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by       BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_pilotos_estacion (estacion_id),
    KEY idx_pilotos_tipo_licencia (tipo_licencia_id),
    KEY idx_pilotos_licencia_vence (licencia_vence),
    CONSTRAINT fk_pilotos_tipo_licencia FOREIGN KEY (tipo_licencia_id) REFERENCES tipos_licencia (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pilotos_estacion FOREIGN KEY (estacion_id) REFERENCES estaciones (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pilotos_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
