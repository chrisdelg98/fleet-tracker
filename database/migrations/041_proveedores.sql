-- 041_proveedores.sql — Catálogo de proveedores de transporte y sus camiones.
--
-- Hasta ahora el proveedor era un texto libre escrito en cada reserva, y «TRANSPORTES ABC»,
-- «Transportes abc» y «TRANSPORTES ABC, S.A. DE C.V.» contaban como tres proveedores en las
-- estadísticas. Ahora cada proveedor es una fila, y lo que lo identifica es su `clave`: el nombre
-- en mayúsculas, sin tildes, sin puntuación, sin espacios y sin la forma jurídica. Dos
-- escrituras con la misma clave son el mismo proveedor, y la base no admite una segunda.
--
-- Es un catálogo compartido por todas las estaciones: varias contratan al mismo proveedor, y
-- solo así la estadística sale consolidada.
CREATE TABLE proveedores (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(150)    NOT NULL,
    clave           VARCHAR(150)    NOT NULL,
    activo          TINYINT(1)      NOT NULL DEFAULT 1,
    -- Al fusionar un duplicado con el correcto, el duplicado no se borra: queda desactivado y
    -- apuntando al que sobrevive. Así su forma de escribirse sigue reconociéndose —quien vuelva
    -- a teclearla llega al proveedor correcto— y el historial no pierde a nadie.
    fusionado_en_id BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by      BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_proveedores_clave (clave),
    KEY idx_proveedores_activo (activo),
    CONSTRAINT fk_proveedores_fusion FOREIGN KEY (fusionado_en_id) REFERENCES proveedores (id) ON DELETE RESTRICT,
    CONSTRAINT fk_proveedores_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un camión por placa de cabezal, y de un solo proveedor: la placa es lo que se escribe en la
-- reserva, y con ella se completa el resto. El motorista va con el camión porque es lo que más
-- se repite; si ese viaje lo hace otro, se corrige en la reserva y queda como el último.
CREATE TABLE proveedor_camiones (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proveedor_id         BIGINT UNSIGNED NOT NULL,
    placa_motriz         VARCHAR(30)     NOT NULL,
    placa_arrastre       VARCHAR(30)     NULL,
    piloto               VARCHAR(150)    NULL,
    licencia             VARCHAR(40)     NULL,
    documento            VARCHAR(40)     NULL,
    telefonos            VARCHAR(255)    NULL,
    codigo_nacional      VARCHAR(40)     NULL,
    codigo_internacional VARCHAR(40)     NULL,
    activo               TINYINT(1)      NOT NULL DEFAULT 1,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by           BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_proveedor_camiones_placa (placa_motriz),
    KEY idx_proveedor_camiones_proveedor (proveedor_id, activo),
    CONSTRAINT fk_proveedor_camiones_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id) ON DELETE RESTRICT,
    CONSTRAINT fk_proveedor_camiones_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada viaje con camión de proveedor queda enlazado a su proveedor: es lo que cuentan las
-- estadísticas. El texto `proveedor` se queda como copia de lo que había en ese viaje.
--
-- Las reservas anteriores se enlazan solas la primera vez que se abre la pantalla de
-- Proveedores: normalizar el nombre (tildes, puntuación, forma jurídica) no se puede hacer
-- bien en SQL, y hacerlo a medias aquí crearía justo los duplicados que esto viene a evitar.
ALTER TABLE movimiento_tercero
    ADD COLUMN proveedor_id BIGINT UNSIGNED NULL AFTER movimiento_id,
    ADD KEY idx_tercero_proveedor_id (proveedor_id),
    ADD CONSTRAINT fk_tercero_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id) ON DELETE RESTRICT;
