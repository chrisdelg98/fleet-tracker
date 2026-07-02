-- 015_bitacora.sql — Audit log (plan §5.9). Toda escritura del sistema deja una fila.
-- Es el registro de auditoría en sí: lleva su propio timestamp y usuario_id (autor),
-- por eso no repite el bloque created_by/updated_at genérico. usuario_id nullable para
-- eventos generados por el sistema. Inmutable: solo INSERT.
CREATE TABLE bitacora (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NULL,
    entidad    VARCHAR(40)     NOT NULL,
    entidad_id BIGINT UNSIGNED NOT NULL,
    accion     VARCHAR(40)     NOT NULL,
    detalle    JSON            NULL,
    timestamp  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bitacora_entidad (entidad, entidad_id),
    KEY idx_bitacora_timestamp (timestamp),
    KEY idx_bitacora_usuario (usuario_id),
    CONSTRAINT fk_bitacora_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
