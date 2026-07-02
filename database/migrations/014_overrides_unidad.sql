-- 014_overrides_unidad.sql — Taller/bloqueos (plan §5.8). Prioridad máxima en el cálculo
-- de disponibilidad (§2). origen=AUTO_ESTADO lo crea el cambio de estado_vehiculo; MANUAL
-- son bloqueos administrativos. hasta NULL = indefinido hasta cerrar.
CREATE TABLE overrides_unidad (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unidad_id  BIGINT UNSIGNED NOT NULL,
    tipo       ENUM('EN_TALLER','BLOQUEADA') NOT NULL,
    origen     ENUM('AUTO_ESTADO','MANUAL')  NOT NULL,
    desde      DATETIME        NOT NULL,
    hasta      DATETIME        NULL,
    motivo     VARCHAR(255)    NOT NULL,
    cerrado    TINYINT(1)      NOT NULL DEFAULT 0,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_overrides_unidad_abierto (unidad_id, cerrado),
    KEY idx_overrides_ventana (desde, hasta),
    CONSTRAINT fk_overrides_unidad FOREIGN KEY (unidad_id) REFERENCES unidades (id) ON DELETE RESTRICT,
    CONSTRAINT fk_overrides_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
