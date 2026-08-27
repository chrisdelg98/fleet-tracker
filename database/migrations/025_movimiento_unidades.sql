-- 025_movimiento_unidades.sql — Un viaje mueve varios activos: la unidad reservada (el
-- protagonista, que sigue en movimientos.unidad_id) más el cabezal y/o el chasis que la
-- arrastran. Ambos son opcionales: el cliente puede traer su propio cabezal, y no todo
-- contenedor necesita chasis.
--
-- liberado_en marca cuándo ese activo se soltó del viaje (el cabezal vuelve a base y el
-- contenedor se queda con el cliente). No se borra la fila: el histórico debe conservar
-- que ese activo sí participó.
CREATE TABLE movimiento_unidades (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    movimiento_id BIGINT UNSIGNED NOT NULL,
    unidad_id     BIGINT UNSIGNED NOT NULL,
    rol           ENUM('MOTRIZ','ARRASTRE') NOT NULL,
    liberado_en   DATETIME        NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mov_unidad (movimiento_id, unidad_id),
    KEY idx_mov_unidades_unidad (unidad_id),
    KEY idx_mov_unidades_liberado (liberado_en),
    CONSTRAINT fk_mov_unidades_movimiento FOREIGN KEY (movimiento_id) REFERENCES movimientos (id) ON DELETE CASCADE,
    CONSTRAINT fk_mov_unidades_unidad FOREIGN KEY (unidad_id) REFERENCES unidades (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_unidades_usuario FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ocupación nueva: el equipo está en poder del cliente (rentado, o esperando descarga).
-- Un solo estado paraguas; el matiz real vive en el motivo, que ya es obligatorio.
ALTER TABLE overrides_unidad
    MODIFY COLUMN tipo ENUM('EN_TALLER','BLOQUEADA','EN_CLIENTE') NOT NULL;
