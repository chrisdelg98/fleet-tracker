-- 036_movimientos_flota_externa.sql — El sistema deja de registrar solo viajes de flota propia
-- y pasa a acumular todo lo que mueve el equipo de transporte: local e internacional, con
-- unidad propia o con la de un tercero.
--
-- Tres columnas nuevas y una tabla. Ninguna se le pide al usuario en el caso normal: la
-- estación sale de la unidad, la clase se deduce de la ruta y la operación nace en el valor
-- más frecuente.

-- La unidad deja de ser obligatoria: un flete hecho enteramente por un tercero no tiene
-- unidad nuestra. La regla que la sustituye vive en MovimientoService: todo movimiento lleva
-- al menos una de las dos cosas, unidad propia o datos de tercero.
ALTER TABLE movimientos
    MODIFY unidad_id BIGINT UNSIGNED NULL;

ALTER TABLE movimientos
    -- Qué estación gestionó el flete. No es "de quién es el camión": un movimiento sin unidad
    -- propia no tiene de dónde deducirla, y una estación que vende fletes sin tener flota
    -- necesita que su trabajo sea atribuible o no aparece en ningún reporte.
    ADD COLUMN estacion_id BIGINT UNSIGNED NULL AFTER unidad_id,
    -- LOCAL no sale en el tablero de disponibilidad, pero sigue ocupando la unidad: si va de
    -- la bodega a la fábrica el martes, ese camión no está para salir de viaje el martes.
    ADD COLUMN clase ENUM('VIAJE','LOCAL') NOT NULL DEFAULT 'VIAJE' AFTER estado,
    -- El IN/EX del reporte corporativo. Es de quién es la CARGA, no de quién es el camión:
    -- un IN se puede hacer con un camión contratado y un EX con flota propia.
    -- 0 = IN (la carga es nuestra), que es cuatro o cinco veces más frecuente.
    ADD COLUMN servicio_a_tercero TINYINT(1) NOT NULL DEFAULT 0 AFTER clase,
    ADD KEY idx_movimientos_estacion (estacion_id),
    ADD KEY idx_movimientos_clase (clase),
    ADD CONSTRAINT fk_movimientos_estacion
        FOREIGN KEY (estacion_id) REFERENCES estaciones (id) ON DELETE RESTRICT;

-- Lo ya registrado se le atribuye a la estación de su unidad, que es de donde salía antes.
UPDATE movimientos m
  JOIN unidades u ON u.id = m.unidad_id
   SET m.estacion_id = u.estacion_id
 WHERE m.estacion_id IS NULL;

-- Datos del camión de tercero. Van en tabla aparte y no en ocho columnas nulas de movimientos
-- —que ya tiene veintitantas— porque solo aplican a una parte de los movimientos.
--
-- No hay catálogo de proveedores a propósito: el dato vive aquí y el formulario lo recuerda
-- por placa. La "base de datos de flota externa" emerge del uso en vez de mantenerse a mano,
-- y así no queda un cementerio de fichas de un solo uso ni camiones ajenos ensuciando el
-- inventario, la pantalla de flota y los importadores.
CREATE TABLE movimiento_tercero (
    movimiento_id        BIGINT UNSIGNED NOT NULL,
    proveedor            VARCHAR(150)    NOT NULL,
    placa_motriz         VARCHAR(30)     NULL,
    placa_arrastre       VARCHAR(30)     NULL,
    piloto               VARCHAR(150)    NULL,
    documento            VARCHAR(40)     NULL,
    telefonos            VARCHAR(255)    NULL,
    codigo_nacional      VARCHAR(40)     NULL,
    codigo_internacional VARCHAR(40)     NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (movimiento_id),
    -- Los dos índices son los que hacen instantáneo el autocompletado que evita retipear.
    KEY idx_tercero_proveedor (proveedor),
    KEY idx_tercero_placa (placa_motriz),
    CONSTRAINT fk_tercero_movimiento FOREIGN KEY (movimiento_id) REFERENCES movimientos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
