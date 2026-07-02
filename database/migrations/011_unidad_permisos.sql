-- 011_unidad_permisos.sql — Puente N:M unidades<->permisos_especiales (plan §5.5).
CREATE TABLE unidad_permisos (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unidad_id           BIGINT UNSIGNED NOT NULL,
    permiso_especial_id BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unidad_permiso (unidad_id, permiso_especial_id),
    KEY idx_unidad_permisos_permiso (permiso_especial_id),
    CONSTRAINT fk_unidad_permisos_unidad FOREIGN KEY (unidad_id) REFERENCES unidades (id) ON DELETE RESTRICT,
    CONSTRAINT fk_unidad_permisos_permiso FOREIGN KEY (permiso_especial_id) REFERENCES permisos_especiales (id) ON DELETE RESTRICT,
    CONSTRAINT fk_unidad_permisos_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
