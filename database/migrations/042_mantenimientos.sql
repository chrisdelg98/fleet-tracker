-- 042_mantenimientos.sql — Módulo de mantenimientos: dos tablas de hechos y sus catálogos.
--
-- Las tres hojas de Excel que se llevan hoy no son tres cosas: son UNA tabla de hechos y dos
-- proyecciones de ella. Por eso aquí solo se guardan los hechos —lo que ocurrió y cuánto marcaba
-- el odómetro— y todo lo demás (próximo servicio, cuánto falta, semáforo, costo acumulado) se
-- calcula al leer. Guardar un total es condenarse a recalcularlo cada vez que se corrige una fila.

-- ── Talleres ──
-- Son los proveedores de taller de cada estación, y da igual si el trabajo lo hace el taller
-- propio o uno subcontratado: los dos son un taller más de la lista. La marca `es_propio` existe
-- solo para poder contestar después "cuánto se hizo adentro y cuánto se pagó afuera".
--
-- Llevan estación porque un taller de Honduras no le sirve a El Salvador, y porque cada encargado
-- gestiona los suyos. La `clave` es el nombre normalizado (sin mayúsculas, tildes ni puntuación):
-- es lo que evita que "K&C", "K & C" y "KyC" terminen siendo tres talleres distintos.
CREATE TABLE talleres (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    estacion_id BIGINT UNSIGNED NOT NULL,
    nombre      VARCHAR(150)    NOT NULL,
    clave       VARCHAR(150)    NOT NULL,
    es_propio   TINYINT(1)      NOT NULL DEFAULT 0,
    telefonos   VARCHAR(255)    NULL,
    notas       VARCHAR(255)    NULL,
    activo      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by  BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    -- El mismo nombre puede existir en dos estaciones (una cadena con sucursales); lo que no
    -- puede es repetirse dentro de una.
    UNIQUE KEY uq_talleres_estacion_clave (estacion_id, clave),
    KEY idx_talleres_estacion_activo (estacion_id, activo),
    CONSTRAINT fk_talleres_estacion FOREIGN KEY (estacion_id) REFERENCES estaciones (id) ON DELETE RESTRICT,
    CONSTRAINT fk_talleres_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tipos de mantenimiento ──
-- `reinicia_ciclo` es el detalle que hace funcionar todo: solo el cambio de aceite reinicia el
-- contador del próximo servicio. Si unas llantas o un radiador lo reiniciaran, las alertas
-- quedarían siempre en verde, que es peor que no tenerlas.
CREATE TABLE tipos_mantenimiento (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(100)    NOT NULL,
    reinicia_ciclo TINYINT(1)      NOT NULL DEFAULT 0,
    orden          INT             NOT NULL DEFAULT 0,
    activo         TINYINT(1)      NOT NULL DEFAULT 1,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by     BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_tipos_mantenimiento_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tipos_mantenimiento (nombre, reinicia_ciclo, orden) VALUES
    ('Revisión (cambio de aceite y filtros)', 1, 1),
    ('Reparación', 0, 2),
    ('Neumáticos', 0, 3);

-- ── Planes de mantenimiento ──
-- Cada cuánto toca servicio. Hoy la hoja le suma 8,000 km a todas las unidades por igual, pero un
-- pick-up y un cabezal de 1.7 millones de km no comparten plan, y el aceite también se vence por
-- tiempo aunque la unidad no ruede. Los umbrales viven aquí y no en el código: son lo que decide
-- cuándo el semáforo pasa a amarillo, y eso se ajusta viendo cómo se comporta la flota.
CREATE TABLE planes_mantenimiento (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(100)    NOT NULL,
    intervalo_km   INT UNSIGNED    NOT NULL,
    intervalo_dias INT UNSIGNED    NULL,
    umbral_km      INT UNSIGNED    NOT NULL DEFAULT 1000,
    umbral_dias    INT UNSIGNED    NOT NULL DEFAULT 15,
    por_defecto    TINYINT(1)      NOT NULL DEFAULT 0,
    activo         TINYINT(1)      NOT NULL DEFAULT 1,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by     BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_planes_por_defecto (por_defecto, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sin intervalo en días: es el que se usa hoy. Si mañana quieren que el aceite también venza por
-- tiempo, se escribe aquí y las alertas lo toman sin tocar código.
INSERT INTO planes_mantenimiento (nombre, intervalo_km, intervalo_dias, umbral_km, umbral_dias, por_defecto) VALUES
    ('Estándar · 8,000 km', 8000, NULL, 1000, 15, 1);

-- La unidad puede tener su propio plan; nula usa el de por defecto, que es lo normal.
ALTER TABLE unidades
    ADD COLUMN plan_mantenimiento_id BIGINT UNSIGNED NULL AFTER capacidad_id,
    ADD CONSTRAINT fk_unidades_plan FOREIGN KEY (plan_mantenimiento_id) REFERENCES planes_mantenimiento (id) ON DELETE RESTRICT;

-- ── Monedas ──
-- El sistema es multi-país y hasta ahora no guardaba moneda en ningún lado: sumar córdobas con
-- dólares da un número sin significado. `por_dolar` es cuántas unidades equivalen a un dólar.
CREATE TABLE monedas (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo         CHAR(3)         NOT NULL,
    nombre         VARCHAR(100)    NOT NULL,
    por_dolar      DECIMAL(14,6)   NOT NULL DEFAULT 1,
    orden          INT             NOT NULL DEFAULT 0,
    activo         TINYINT(1)      NOT NULL DEFAULT 1,
    actualizada_en DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by     BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_monedas_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las tasas se siembran en 1 a propósito, salvo el dólar: un número inventado aquí acabaría en un
-- reporte como si fuera cierto. Se ponen desde Administración › Catálogos antes de usarlas.
INSERT INTO monedas (codigo, nombre, por_dolar, orden) VALUES
    ('USD', 'Dólar estadounidense', 1, 1),
    ('HNL', 'Lempira hondureño', 1, 2),
    ('GTQ', 'Quetzal guatemalteco', 1, 3),
    ('NIO', 'Córdoba nicaragüense', 1, 4),
    ('CRC', 'Colón costarricense', 1, 5);

-- ── Hecho 1: las intervenciones ──
-- No lleva estación: se hereda de la unidad por JOIN. Una columna propia se desincronizaría el
-- día que una unidad cambie de estación, y entonces el historial diría dos cosas distintas.
CREATE TABLE mantenimientos (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unidad_id              BIGINT UNSIGNED NOT NULL,
    fecha                  DATE            NOT NULL,
    tipo_mantenimiento_id  BIGINT UNSIGNED NOT NULL,
    descripcion            VARCHAR(255)    NULL,
    taller_id              BIGINT UNSIGNED NULL,
    -- El costo es nulo mientras no haya factura: registrar el trabajo no puede esperar a que
    -- llegue el papel, y un cero mentiría en los totales.
    costo                  DECIMAL(12,2)   NULL,
    moneda_id              BIGINT UNSIGNED NULL,
    -- La tasa con la que se convirtió, guardada por fila: así el total del año pasado no cambia
    -- cuando el córdoba se mueva. Sin esto, cada reporte daría un número distinto.
    tasa_usada             DECIMAL(14,6)   NULL,
    costo_usd              DECIMAL(12,2)   NULL,
    factura                VARCHAR(60)     NULL,
    km                     INT UNSIGNED    NULL,
    -- Heredado del tipo pero editable: una reparación grande a veces incluye el cambio de aceite,
    -- y obligar a registrar dos filas por eso es la fricción que hace que nadie registre.
    reinicia_ciclo         TINYINT(1)      NOT NULL DEFAULT 0,
    observaciones          VARCHAR(255)    NULL,
    created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by             BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_mantenimientos_unidad_fecha (unidad_id, fecha),
    KEY idx_mantenimientos_fecha (fecha),
    KEY idx_mantenimientos_taller (taller_id),
    KEY idx_mantenimientos_ciclo (unidad_id, reinicia_ciclo, fecha),
    CONSTRAINT fk_mantenimientos_unidad FOREIGN KEY (unidad_id) REFERENCES unidades (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mantenimientos_tipo FOREIGN KEY (tipo_mantenimiento_id) REFERENCES tipos_mantenimiento (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mantenimientos_taller FOREIGN KEY (taller_id) REFERENCES talleres (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mantenimientos_moneda FOREIGN KEY (moneda_id) REFERENCES monedas (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mantenimientos_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Hecho 2: las lecturas de odómetro ──
-- Va en tabla aparte y no como una columna `unidades.km_actual` porque una columna solo contesta
-- "cuánto marca hoy". Con historial se sabe cuántos km hace al mes esa unidad y se puede estimar
-- la FECHA del próximo servicio. "Le faltan 12 días" sirve para agendar taller; "le faltan 1,527
-- km" no, porque nadie sabe cuándo los va a rodar.
CREATE TABLE lecturas_odometro (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unidad_id        BIGINT UNSIGNED NOT NULL,
    fecha            DATE            NOT NULL,
    km               INT UNSIGNED    NOT NULL,
    origen           ENUM('MANUAL','MANTENIMIENTO','IMPORTACION','VIAJE') NOT NULL DEFAULT 'MANUAL',
    mantenimiento_id BIGINT UNSIGNED NULL,
    -- Donde vive "odómetro reemplazado": el kilometraje se valida como creciente, y cuando baja
    -- se exige una nota en vez de rechazar el dato. Sin esa válvula, la primera unidad con
    -- odómetro nuevo bloquea la captura.
    nota             VARCHAR(160)    NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by       BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_lecturas_unidad_fecha (unidad_id, fecha, id),
    CONSTRAINT fk_lecturas_unidad FOREIGN KEY (unidad_id) REFERENCES unidades (id) ON DELETE RESTRICT,
    CONSTRAINT fk_lecturas_mantenimiento FOREIGN KEY (mantenimiento_id) REFERENCES mantenimientos (id) ON DELETE CASCADE,
    CONSTRAINT fk_lecturas_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
